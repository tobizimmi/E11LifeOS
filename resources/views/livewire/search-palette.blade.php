<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Contact;
use App\Models\Item;
use App\Models\PaperlessInstance;
use App\Models\Place;
use App\Models\Recipe;
use App\Models\ShoppingListEntry;
use App\Models\Tag;
use App\Models\Trip;
use App\Support\Paperless\PaperlessClient;
use App\Support\Addons;
use App\Support\Modules\ModuleAccess;
use App\Support\BrainAi;
use App\Support\Portfolio\PortfolioLink;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    use InteractsWithHousehold;

    private const MIN_QUERY_LENGTH = 2;

    private const RESULTS_PER_GROUP = 5;

    public string $query = '';

    /**
     * Full-text hits from paperless (its OCR text) for $paperlessQuery – only fetched on request,
     * because paperless may sit behind the VPN and answer slowly.
     *
     * @var list<array{label: string, url: string}>|null
     */
    #[Locked]
    public ?array $paperlessResults = null;

    #[Locked]
    public string $paperlessQuery = '';

    #[Locked]
    public ?string $paperlessError = null;

    public function searchPaperless(): void
    {
        $term = trim($this->query);
        $instance = Auth::user() ? PaperlessInstance::defaultFor(Auth::user()) : null;
        $this->paperlessError = null;

        if (! $instance || mb_strlen($term) < self::MIN_QUERY_LENGTH) {
            return;
        }

        try {
            $client = PaperlessClient::for($instance, Auth::user());
            $this->paperlessResults = array_map(fn (array $document) => [
                'label' => '📄 '.($document['title'] ?: '#'.$document['id']).($document['created'] ? ' · '.\Illuminate\Support\Carbon::parse($document['created'])->format('d.m.Y') : ''),
                'url' => $client->documentUrl($document['id']),
            ], $client->search($term, 8));
        } catch (\Throwable $exception) {
            report($exception);
            $this->paperlessResults = [];
            $this->paperlessError = __('paperless is not reachable.');
        }

        $this->paperlessQuery = $term;
    }

    protected function requiresHousehold(): bool
    {
        return false;
    }

    /**
     * The palette is closed most of the time; a household broadcast must not re-run the search.
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return [];
    }

    /**
     * Search results grouped by module, each entry with a label and a target URL.
     *
     * @return array<string, array<int, array{label: string, url: string, private: bool}>>
     */
    private function results(): array
    {
        $term = trim($this->query);

        if ($this->householdId === null || mb_strlen($term) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $entryUrl = fn (Item $item) => route('collections.show', ['collection' => $item->collection_id, 'entry' => $item->id]);

        $groups = [
            __('Pages') => $this->itemResults(Item::TYPE_NOTE, $term, fn (Item $item) => route('pages.index', ['item' => $item->id])),
            __('To-Do') => $this->itemResults(Item::TYPE_TODO, $term, $entryUrl),
            __('Collections') => $this->itemResults(Item::TYPE_ENTRY, $term, $entryUrl),
            __('Inbox') => $this->itemResults(Item::TYPE_INBOX, $term, fn () => route('inbox.index')),
            __('Recipes') => Auth::user()->canUseAddon(Addons::RECIPES)
                ? Recipe::query()->visibleTo(Auth::user(), $this->householdId)->matching($term)->orderBy('title')->limit(self::RESULTS_PER_GROUP)->get()
                    ->map(fn (Recipe $recipe) => ['label' => '🍳 '.$recipe->title, 'url' => route('recipes.show', $recipe), 'private' => $recipe->isPrivate()])
                    ->all()
                : [],
            __('Portfolio') => Auth::user()->canUseAddon(Addons::PORTFOLIO)
                ? PortfolioLink::search(Auth::user(), $term, self::RESULTS_PER_GROUP)
                : [],
            __('Real estate') => \App\Support\RealEstate\RealEstateLink::search(Auth::user(), $this->householdId, $term, self::RESULTS_PER_GROUP),
            __('Shopping List') => ShoppingListEntry::where('household_id', $this->householdId)
                ->whereRaw('LOWER(label) LIKE ?', ['%'.mb_strtolower($term).'%'])
                ->orderBy('checked')
                ->limit(self::RESULTS_PER_GROUP)
                ->get()
                ->map(fn (ShoppingListEntry $entry) => [
                    'label' => $entry->label,
                    'url' => route('shopping-list.index'),
                    'private' => false,
                ])
                ->all(),
            __('Trips') => Trip::query()->visibleTo(Auth::user(), $this->householdId)
                ->where(fn ($query) => $query->whereRaw('LOWER(title) LIKE ?', ['%'.mb_strtolower($term).'%'])->orWhereRaw('LOWER(destination) LIKE ?', ['%'.mb_strtolower($term).'%']))
                ->orderByDesc('starts_on')->limit(self::RESULTS_PER_GROUP)->get()
                ->map(fn (Trip $trip) => ['label' => '🧳 '.$trip->title.' · '.$trip->starts_on->format('m/Y'), 'url' => route('trips.show', $trip), 'private' => $trip->visibility === Item::VISIBILITY_PRIVATE])
                ->all(),
            __('People') => Contact::query()->visibleTo(Auth::user(), $this->householdId)
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%'])->orderBy('name')->limit(self::RESULTS_PER_GROUP)->get()
                ->map(fn (Contact $contact) => ['label' => '👤 '.$contact->name, 'url' => route('people.index', ['person' => $contact->id]), 'private' => $contact->visibility === Item::VISIBILITY_PRIVATE])
                ->all(),
            __('Places') => Place::query()->visibleTo(Auth::user(), $this->householdId)
                ->where(fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%'])->orWhereRaw('LOWER(city) LIKE ?', ['%'.mb_strtolower($term).'%']))
                ->orderBy('name')->limit(self::RESULTS_PER_GROUP)->get()
                ->map(fn (Place $place) => ['label' => '📍 '.$place->name.($place->city ? ' · '.$place->city : ''), 'url' => route('places.index'), 'private' => $place->visibility === Item::VISIBILITY_PRIVATE])
                ->all(),
            __('Tags') => Tag::usedOnItemsVisibleTo(Auth::user(), $this->householdId)
                ->where('name', 'like', '%'.mb_strtolower(ltrim($term, '#')).'%')
                ->orderBy('name')
                ->limit(self::RESULTS_PER_GROUP)
                ->get()
                ->map(fn (Tag $tag) => [
                    'label' => '#'.$tag->name,
                    'url' => route('pages.index', ['tag' => $tag->name]),
                    'private' => false,
                ])
                ->all(),
        ];

        // Hits from areas locked for the member (or not licensed) stay hidden.
        $user = Auth::user();
        $groups = array_map(fn (array $entries) => array_values(array_filter($entries, fn (array $entry) => ! isset($entry['url']) || ModuleAccess::allowsUrl($user, (string) $entry['url']))), $groups);

        return array_filter($groups);
    }

    /**
     * @param  callable(Item): string  $url
     * @return array<int, array{label: string, url: string, private: bool}>
     */
    private function itemResults(string $type, string $term, callable $url): array
    {
        return $this->visibleItems($type)
            ->matching($term)
            ->latest('updated_at')
            ->limit(self::RESULTS_PER_GROUP)
            ->get()
            ->map(fn (Item $item) => [
                'label' => $item->title,
                'url' => $url($item),
                'private' => $item->isPrivate(),
            ])
            ->all();
    }

    /**
     * Quick actions on the typed text: create a task, a shopping item or an inbox entry.
     */
    public function createTask(): void
    {
        abort_if($this->householdId === null || trim($this->query) === '', 422);
        $task = \App\Support\QuickCapture::task(Auth::user(), $this->householdId, trim($this->query));
        $this->finish($task ? __('Task “:title” created.', ['title' => $task->title]) : __('Nothing to create.'));
    }

    /**
     * Files the typed text where it belongs (shopping list, expense, device, task or inbox).
     */
    public function smartCapture(): void
    {
        abort_if($this->householdId === null || trim($this->query) === '', 422);
        $this->finish(\App\Support\SmartCapture::capture(Auth::user(), $this->householdId, trim($this->query)) ?? __('Nothing to create.'));
    }

    public function addToShopping(): void
    {
        abort_if($this->householdId === null || trim($this->query) === '', 422);
        $list = \App\Models\ShoppingList::defaultFor($this->householdId);
        \App\Support\ShoppingLists::add($list, trim($this->query), null, Auth::id());
        $this->finish(__(':item is on the list “:list”.', ['item' => trim($this->query), 'list' => $list->name]));
    }

    public function addToInbox(): void
    {
        abort_if($this->householdId === null || trim($this->query) === '', 422);
        \App\Support\QuickCapture::inbox(Auth::user(), $this->householdId, trim($this->query));
        $this->finish(__('Saved to the inbox.'));
    }

    private function finish(string $message): void
    {
        $this->query = '';
        $this->dispatch('toast', message: $message);
        $this->dispatch('close-search');
        $this->dispatch('item-captured');
    }

    /**
     * Places to jump to, filtered by the typed text (all of them while nothing is typed).
     *
     * @return list<array{label: string, url: string, keywords: string}>
     */
    private function destinations(): array
    {
        $user = Auth::user();
        $places = [
            ['🏠 '.__('Dashboard'), route('dashboard'), 'home start overview übersicht'],
            ['✅ '.__('To-Do'), route('tasks.index'), 'tasks aufgaben todo'],
            ['📥 '.__('Inbox'), route('inbox.index'), 'inbox eingang'],
            ['📅 '.__('Calendar'), route('calendar.index'), 'kalender termine calendar monat woche'],
            ['🔔 '.__('Notifications'), route('notifications.index'), 'benachrichtigungen glocke notifications'],
            ['💎 '.__('Net worth'), route('net-worth.index'), 'vermögen nettovermögen net worth schulden'],
            ['📑 '.__('Contract costs'), route('contracts.index'), 'verträge kosten kündigung fristen contracts'],
            ['⚖️ '.__('Quote comparisons'), route('quotes.index'), 'angebote vergleich angebotsvergleich quotes kaufen anschaffung handwerker'],
            ['✉️ '.__('Letters'), route('letters.index'), 'brief kündigung widerspruch adresse letter vorlage'],
            ['🛒 '.__('Shopping List'), route('shopping-list.index'), 'einkauf shopping liste'],
            ['🏷️ '.__('Offers'), route('shopping-list.offers'), 'angebote prospekt offers'],
            ['📄 '.__('New page'), route('pages.index', ['create' => 1]), 'seite page notiz note neu'],
            ['🗂 '.__('Collections'), route('collections.index'), 'sammlungen collections'],
            ['✨ '.__('Ask your Second Brain'), route('brain.ask'), 'ki ai frage'],
            ['👥 '.__('Members'), route('household.show'), 'haushalt mitglieder members'],
            ['⚙️ '.__('Profile'), route('profile'), 'profil einstellungen settings'],
            ['🎁 '.__('Wishlist'), route('wishlist.index'), 'wunschliste wünsche kaufen leisten leistbar sparen anschaffung'],
            ['🧭 '.__('Cashflow analysis'), route('cashflow.index'), 'cashflow rich dad poor dad kiyosaki einnahmen ausgaben bilanz passives einkommen finanzielle freiheit'],
            ['💡 '.__('Money knowledge'), route('knowledge.index'), 'wissen tipps steuern börse finanzierung neuerungen news favoriten'],
            ['🗂️ '.__('Emergency folder'), route('emergency.index'), 'notfall notfallordner vorsorge vollmacht patientenverfügung testament ausweis reisepass vertrauensperson'],
            ['🚗 '.__('Vehicles'), route('vehicles.index'), 'auto fahrzeug tüv hu inspektion tanken fahrtenbuch reifen kfz'],
            ['🏡 '.__('At home'), route('house.index'), 'zuhause wartung heizung rauchmelder zähler zählerstand strom gas wasser verbrauch abschlag'],
            ['🎉 '.__('Year in review'), route('review.index'), 'jahresrückblick jahr rückblick statistik bilanz review'],
            ['🛡️ '.__('Provision check'), route('provision.index'), 'versicherung versicherungscheck haftpflicht berufsunfähigkeit rente rentenlücke altersvorsorge fire freiheit'],
            ['❤️ '.__('Health'), route('health.index'), 'gesundheit arzt vorsorge impfung medikamente notfallkarte blutgruppe allergie u-untersuchung'],
            ['🎂 '.__('People & occasions'), route('people.index'), 'geburtstag geburtstage jahrestag kontakte personen geschenk geschenkideen freunde familie'],
            ['✈️ '.__('Trips'), route('trips.index'), 'reise reisen urlaub packliste flug hotel buchung koffer ferien'],
            ['🗂️ '.__('Spaces'), route('spaces.index'), 'spaces workspaces bereiche arbeitsbereich projekte gruppieren'],
            ['⏱️ '.__('Working time & leave'), route('work.index'), 'arbeitszeit stunden überstunden urlaub resturlaub feiertage brückentage stempeln zeiterfassung krank'],
            ['🎓 '.__('Lessons learned'), route('learnings.index'), 'erkenntnisse lektionen gelernt erfahrungen fehler tagebuch wiederholen zweites gehirn prinzipien'],
            ['🗄️ '.__('Network drives'), route('drives.index'), 'nas synology qnap webdav netzlaufwerk nextcloud dateien vpn laufwerk'],
            ['👥 '.__('Groups & sharing'), route('groups.index'), 'gruppen teilen freigabe freunde verein privat haushalt datenschutz'],
            ['📍 '.__('Places'), route('places.index'), 'orte restaurants cafés bars merkliste ausflug lokal essen gehen empfehlung'],
            ['🧾 '.__('Split costs'), route('splits.index'), 'kosten teilen splid tricount abrechnung gruppe wg urlaub schulden ausgleich'],
            ['⭐ '.__('Kids & pocket money'), route('kids.index'), 'kinder kind taschengeld aufgaben ämtchen belohnung sparziel familie'],
            ['⚙️ '.__('Automations'), route('automations.index'), 'automation automationen wenn dann regeln urlaubsmodus modus anwesenheit'],
        ];

        if ($user->canUseAddon(Addons::SMART_HOME)) {
            $places[] = ['🔌 '.__('Smart home'), route('smart-home.index'), 'smart home homeassistant home assistant evcc licht schalter heizung pv wallbox laden energie strom'];
        }

        if ($user->canUseAddon(Addons::RECIPES)) {
            $places[] = ['🍳 '.__('Recipes'), route('recipes.index'), 'rezepte kochen recipes'];
            $places[] = ['📅 '.__('Meal plan'), route('recipes.plan'), 'wochenplan essen meal plan'];
            $places[] = ['➕ '.__('New recipe'), route('recipes.create'), 'rezept neu recipe'];
        }

        if ($user->canUseAddon(Addons::FINANCE)) {
            $places[] = ['💶 '.__('Finances'), route('finance.index'), 'finanzen geld konto finance'];
        }

        if ($user->canUseAddon(Addons::REAL_ESTATE)) {
            $places[] = ['🏠 '.__('Real estate'), route('real-estate.index'), 'immobilien haus miete'];
        }

        if ($user->canUseAddon(Addons::PORTFOLIO)) {
            $places[] = ['📈 '.__('Portfolio'), route('portfolio.index'), 'depot aktien portfolio'];
        }

        if ($user->canUseAddon(Addons::BUSINESS)) {
            $places[] = ['💼 '.__('Small business'), route('business.index'), 'business gewerbe'];
            $places[] = ['🧾 '.__('New invoice'), route('business.invoices.create'), 'rechnung invoice neu'];
        }

        if ($user->isAdmin()) {
            $places[] = ['🛠 '.__('Server settings'), route('admin.settings'), 'server admin einstellungen vpn'];
            $places[] = ['📊 '.__('Administration'), route('admin.overview'), 'admin übersicht dashboard nutzer speicher'];
            $places[] = ['🩺 '.__('System'), route('admin.system'), 'admin system zustand fehler'];
            $places[] = ['🏠 '.__('Households'), route('admin.households'), 'admin haushalte verwalten export löschen'];
        }

        $term = mb_strtolower(trim($this->query));

        return collect($places)
            ->map(fn (array $place) => ['label' => $place[0], 'url' => $place[1], 'keywords' => mb_strtolower($place[0].' '.$place[2])])
            ->filter(fn (array $place) => $term === '' || str_contains($place['keywords'], $term))
            ->filter(fn (array $place) => ModuleAccess::allowsUrl($user, $place['url']))
            ->take($term === '' ? 12 : 5)
            ->values()->all();
    }

    /**
     * Commands for automations, modes and favourite devices matching the typed text.
     *
     * @return list<array{label: string, method: string}>
     */
    private function commands(): array
    {
        $term = mb_strtolower(trim($this->query));

        if ($this->householdId === null || mb_strlen($term) < 2) {
            return [];
        }

        $commands = [];

        foreach (\App\Models\Automation::where('household_id', $this->householdId)->where('active', true)->orderBy('name')->get() as $automation) {
            if (str_contains(mb_strtolower($automation->name), $term)) {
                $commands[] = ['label' => '▶ '.__('Run automation “:name”', ['name' => $automation->name]), 'method' => 'runAutomation('.$automation->id.')'];
            }
        }

        foreach (\App\Models\HouseholdMode::forHousehold($this->householdId) as $mode) {
            if (str_contains(mb_strtolower($mode->name), $term)) {
                $commands[] = ['label' => $mode->icon.' '.($mode->active ? __('Switch off “:mode”', ['mode' => $mode->name]) : __('Switch on “:mode”', ['mode' => $mode->name])), 'method' => "toggleMode('".$mode->key."')"];
            }
        }

        if (Auth::user()->canUseAddon(Addons::SMART_HOME)) {
            foreach (\App\Models\SmartHomeEntity::where('household_id', $this->householdId)->get() as $entity) {
                if (($entity->isSwitchable() || $entity->isRunnable()) && str_contains(mb_strtolower($entity->name.' '.$entity->area), $term)) {
                    $commands[] = ['label' => $entity->icon().' '.($entity->isRunnable() ? __('Start :name', ['name' => $entity->name]) : ($entity->isOn() ? __('Switch off :name', ['name' => $entity->name]) : __('Switch on :name', ['name' => $entity->name]))), 'method' => 'switchDevice('.$entity->id.')'];
                }
            }
        }

        return array_slice($commands, 0, 6);
    }

    public function runAutomation(int $id, \App\Support\Automation\AutomationEngine $engine): void
    {
        $automation = \App\Models\Automation::where('household_id', $this->householdId)->find($id) ?? abort(404);
        $run = $engine->run($automation, __('Started by hand by :name', ['name' => Auth::user()->name]), manual: true);
        $this->finish($run->status === 'done' ? __('Done.') : __('Finished with errors – see the log.'));
    }

    public function toggleMode(string $key, \App\Support\Automation\AutomationEngine $engine): void
    {
        $mode = \App\Models\HouseholdMode::forHousehold($this->householdId)->firstWhere('key', $key) ?? abort(404);
        $engine->setMode($this->householdId, $key, ! $mode->active);
        $this->finish($mode->name.': '.($mode->active ? __('Off') : __('On')));
    }

    public function switchDevice(int $id): void
    {
        abort_unless(Auth::user()->canUseAddon(Addons::SMART_HOME), 403);
        $entity = \App\Models\SmartHomeEntity::where('household_id', $this->householdId)->with('connection')->find($id) ?? abort(404);

        try {
            \App\Support\SmartHome\EntityControl::press(Auth::user(), $entity);
            $this->finish($entity->name);
        } catch (\App\Support\SmartHome\SmartHomeException|\Illuminate\Http\Client\RequestException $exception) {
            $this->finish($exception->getMessage());
        }
    }

    public function with(): array
    {
        return [
            'commands' => $this->commands(),
            'destinations' => $this->householdId !== null ? $this->destinations() : [],
            'typed' => trim($this->query),
            'groups' => $this->results(),
            'searched' => mb_strlen(trim($this->query)) >= self::MIN_QUERY_LENGTH,
            'canAsk' => $this->householdId !== null && BrainAi::isConfigured(),
            'hasPaperless' => Auth::user() !== null && PaperlessInstance::defaultFor(Auth::user()) !== null,
        ];
    }
}; ?>

<div
    x-data="{
        open: false,
        mode: 'search',
        show(mode = 'search') {
            this.mode = mode;
            this.open = true;
            this.$nextTick(() => this.$refs.input.focus());
        },
        results() {
            return [...this.$refs.results.querySelectorAll('[data-search-result]')];
        },
        move(step) {
            const results = this.results();
            if (results.length === 0) return;
            const index = results.indexOf(document.activeElement);
            const next = index === -1 ? (step > 0 ? 0 : results.length - 1) : (index + step + results.length) % results.length;
            results[next].focus();
        },
    }"
    x-on:keydown.window.meta.k.prevent="show()"
    x-on:keydown.window.ctrl.k.prevent="show()"
    x-on:keydown.escape.window="open = false"
    x-on:open-search.window="show('search')"
    x-on:open-create.window="show('create')"
    x-on:close-search.window="open = false"
>
    <div
        x-show="open"
        x-cloak
        x-transition.opacity
        class="fixed inset-0 z-50 bg-gray-900/50 flex items-start justify-center p-4 pt-[10vh]"
        x-on:click.self="open = false"
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('Search') }}"
    >
        <div class="w-full max-w-xl bg-white dark:bg-gray-800 rounded-lg shadow-xl overflow-hidden" x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)">
            <input
                x-ref="input"
                wire:model.live.debounce.250ms="query"
                type="search"
                placeholder="{{ __('Search, jump to or create …') }}"
                x-bind:placeholder="mode === 'create' ? @js(__('What would you like to create?')) : @js(__('Search, jump to or create …'))"
                class="w-full border-0 border-b border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 px-4 py-3 focus:ring-0"
                x-on:keydown.enter.prevent="results()[0]?.click()"
            />

            <div x-ref="results" class="max-h-[60vh] overflow-y-auto">
                @if ($typed === '')
                    {{-- Quick-create menu, shown when the palette is opened via the "+" (create) button. --}}
                    <div class="py-2" x-show="mode === 'create'" x-cloak>
                        <h4 class="px-4 py-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Create') }}</h4>
                        @foreach ([['notes.index', '✏️', __('New note')], ['tasks.index', '✅', __('New task')], ['shopping-list.index', '🛒', __('Shopping List')], ['calendar.index', '📅', __('New appointment')]] as [$createRoute, $createIcon, $createLabel])
                            <a href="{{ route($createRoute) }}" wire:navigate data-search-result x-on:click="open = false" class="flex items-center gap-2 px-4 py-2 text-gray-900 hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-hidden dark:text-gray-100 dark:hover:bg-gray-700 dark:focus:bg-gray-700">
                                <span aria-hidden="true">{{ $createIcon }}</span> <span class="truncate">{{ $createLabel }}</span>
                            </a>
                        @endforeach
                        <p class="px-4 pt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Or just type to capture it as a task, shopping item or inbox note.') }}</p>
                    </div>
                    {{-- Recently opened pages, kept in this browser only (see e11-recent in app.js). --}}
                    <div class="py-2" x-data="{ recent: window.E11Recent?.list() ?? [] }" x-on:open-search.window="recent = window.E11Recent?.list() ?? []" x-show="mode === 'search' && recent.length">
                        <h4 class="px-4 py-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Recently opened') }}</h4>
                        <template x-for="item in recent" :key="item.url">
                            <a :href="item.url" wire:navigate data-search-result x-on:click="open = false" class="flex items-center gap-2 px-4 py-2 text-gray-900 hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-hidden dark:text-gray-100 dark:hover:bg-gray-700 dark:focus:bg-gray-700">
                                <span class="text-gray-400" aria-hidden="true">↺</span> <span class="truncate" x-text="item.title"></span>
                            </a>
                        </template>
                    </div>
                @endif
                @if ($typed !== '')
                    <div class="py-2">
                        <h4 class="px-4 py-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Create') }}</h4>
                        @php($smart = $householdId ? \App\Support\SmartCapture::preview(auth()->user(), $householdId, $typed) : null)
                        @if ($smart && in_array($smart['kind'], ['expense', 'device', 'mode'], true))
                            <button type="button" wire:click="smartCapture" data-search-result class="flex w-full items-center gap-2 px-4 py-2 text-start font-medium text-indigo-700 hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-hidden dark:text-indigo-300 dark:hover:bg-gray-700 dark:focus:bg-gray-700">
                                <span aria-hidden="true">✨</span> <span class="truncate">{{ $smart['label'] }}: “{{ $typed }}”</span>
                            </button>
                        @endif
                        @foreach ([['createTask', '✅', __('Task: “:text”', ['text' => $typed])], ['addToShopping', '🛒', __('Shopping list: “:text”', ['text' => $typed])], ['addToInbox', '📥', __('Inbox: “:text”', ['text' => $typed])]] as [$method, $icon, $label])
                            <button type="button" wire:click="{{ $method }}" data-search-result class="flex w-full items-center gap-2 px-4 py-2 text-start text-gray-900 hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-hidden dark:text-gray-100 dark:hover:bg-gray-700 dark:focus:bg-gray-700">
                                <span aria-hidden="true">{{ $icon }}</span> <span class="truncate">{{ $label }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
                @if ($commands !== [])
                    <div class="py-2">
                        <h4 class="px-4 py-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Commands') }}</h4>
                        @foreach ($commands as $command)
                            <button type="button" wire:click="{{ $command['method'] }}" wire:key="command-{{ $loop->index }}" data-search-result class="flex w-full items-center gap-2 px-4 py-2 text-start text-gray-900 hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-hidden dark:text-gray-100 dark:hover:bg-gray-700 dark:focus:bg-gray-700"><span class="truncate">{{ $command['label'] }}</span></button>
                        @endforeach
                    </div>
                @endif
                @if ($destinations !== [])
                    <div class="py-2">
                        <h4 class="px-4 py-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Go to') }}</h4>
                        @foreach ($destinations as $destination)
                            <a href="{{ $destination['url'] }}" wire:navigate data-search-result x-on:click="open = false" class="flex items-center gap-2 px-4 py-2 text-gray-900 hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-hidden dark:text-gray-100 dark:hover:bg-gray-700 dark:focus:bg-gray-700">
                                <span class="truncate">{{ $destination['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
                @forelse ($groups as $group => $entries)
                    <div class="py-2" wire:key="search-group-{{ $loop->index }}">
                        <h4 class="px-4 py-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $group }}</h4>
                        @foreach ($entries as $entry)
                            <a
                                href="{{ $entry['url'] }}"
                                wire:navigate
                                data-search-result
                                x-on:click="open = false"
                                class="flex items-center gap-2 px-4 py-2 text-gray-900 dark:text-gray-100 hover:bg-indigo-50 dark:hover:bg-gray-700 focus:bg-indigo-50 dark:focus:bg-gray-700 focus:outline-hidden"
                            >
                                <span class="truncate">{{ $entry['label'] }}</span>
                                @if ($entry['private'])
                                    <x-private-badge />
                                @endif
                            </a>
                        @endforeach
                    </div>
                @empty
                    @if ($searched)
                        <p class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ __('No results.') }}</p>
                    @endif
                @endforelse
                @if ($searched && $hasPaperless)
                    @if ($paperlessResults !== null && $paperlessQuery === $typed)
                        <div class="py-2">
                            <h4 class="px-4 py-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('paperless (full text)') }}</h4>
                            @forelse ($paperlessResults as $document)
                                <a href="{{ $document['url'] }}" target="_blank" rel="noopener noreferrer" data-search-result class="flex items-center gap-2 px-4 py-2 text-gray-900 hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-hidden dark:text-gray-100 dark:hover:bg-gray-700 dark:focus:bg-gray-700"><span class="truncate">{{ $document['label'] }}</span></a>
                            @empty
                                <p class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">{{ $paperlessError ?? __('No documents found.') }}</p>
                            @endforelse
                        </div>
                    @else
                        <button type="button" wire:click="searchPaperless" wire:loading.attr="disabled" wire:target="searchPaperless" data-search-result class="flex w-full items-center gap-2 px-4 py-2 text-start text-gray-700 hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-hidden dark:text-gray-200 dark:hover:bg-gray-700 dark:focus:bg-gray-700">
                            <span aria-hidden="true" wire:loading.remove wire:target="searchPaperless">📄</span><span aria-hidden="true" wire:loading wire:target="searchPaperless">⏳</span>
                            <span class="truncate">{{ __('Search documents in paperless for “:text”', ['text' => $typed]) }}</span>
                        </button>
                    @endif
                @endif
                @if ($searched && $canAsk)
                    <a
                        href="{{ route('brain.ask', ['q' => trim($query)]) }}"
                        wire:navigate
                        data-search-result
                        x-on:click="open = false"
                        class="flex items-center gap-2 px-4 py-2 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-gray-700 focus:bg-indigo-50 dark:focus:bg-gray-700 focus:outline-hidden"
                    >✨ <span class="truncate">{{ __('Ask your Second Brain: “:question”', ['question' => trim($query)]) }}</span></a>
                @endif
            </div>

            <p class="px-4 py-2 text-xs text-gray-400 border-t border-gray-100 dark:border-gray-700">
                {{ __('↑↓ to navigate, Enter to open, Esc to close') }} · {{ __('? shows all shortcuts') }}
            </p>
        </div>
    </div>
</div>
