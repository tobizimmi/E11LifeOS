<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\HouseholdMember;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeDashboard;
use App\Models\SmartHomeDashboardCard;
use App\Models\SmartHomeEntity;
use App\Models\SmartHomeReading;
use App\Support\SmartHome\EnergyRecorder;
use App\Support\SmartHome\EntityControl;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * One self-arranged Smart Home dashboard: cards for devices (display and size per card),
 * headings and the energy flow. Editing mode adds, arranges (drag & drop) and removes cards.
 */
new class extends Component
{
    use InteractsWithHousehold;

    #[Locked]
    public int $dashboardId;

    public bool $editing = false;

    /**
     * @var array{name: string, icon: string, personal: bool}
     */
    public array $settings = ['name' => '', 'icon' => '', 'personal' => false];

    public string $newEntity = '';

    public string $newArea = '';

    public string $newHeading = '';

    public function mount(int $dashboardId, bool $editing = false): void
    {
        $this->resolveHousehold();
        $this->dashboardId = $dashboardId;
        $dashboard = $this->dashboard();
        $this->editing = $editing && $this->mayEdit($dashboard);
        $this->settings = ['name' => $dashboard->name, 'icon' => (string) $dashboard->icon, 'personal' => $dashboard->personal];
    }

    private function dashboard(): SmartHomeDashboard
    {
        return SmartHomeDashboard::query()->visibleTo(Auth::user(), $this->householdId)->find($this->dashboardId) ?? abort(404);
    }

    private function mayEdit(SmartHomeDashboard $dashboard): bool
    {
        $isOwner = (bool) HouseholdMember::where('household_id', $this->householdId)->where('user_id', Auth::id())->first()?->isOwner();

        return $dashboard->isEditableBy(Auth::user(), $isOwner);
    }

    private function editable(): SmartHomeDashboard
    {
        $dashboard = $this->dashboard();
        abort_unless($this->mayEdit($dashboard), 403);

        return $dashboard;
    }

    private function findCard(int $id): SmartHomeDashboardCard
    {
        return $this->editable()->cards()->find($id) ?? abort(404);
    }

    /**
     * Tap on a tile: switch on/off, open/close or start a scene/script (critical devices confirmed).
     */
    public function pressEntity(int $id, bool $confirmed = false): void
    {
        $entity = SmartHomeEntity::where('household_id', $this->householdId)->with('connection')->find($id) ?? abort(404);

        if ($error = EntityControl::attempt(fn () => EntityControl::press(Auth::user(), $entity, $confirmed))) {
            $this->dispatch('toast', message: $error);
        }
    }

    /**
     * Slider of a tile: brightness, target temperature or cover position.
     */
    public function setEntityValue(int $id, float $value): void
    {
        $entity = SmartHomeEntity::where('household_id', $this->householdId)->with('connection')->find($id) ?? abort(404);

        if ($error = EntityControl::attempt(fn () => EntityControl::setValue(Auth::user(), $entity, $value))) {
            $this->dispatch('toast', message: $error);
        }
    }

    public function toggleEditing(): void
    {
        $this->editable();
        $this->editing = ! $this->editing;
    }

    public function saveSettings(): void
    {
        $dashboard = $this->editable();
        $this->validate([
            'settings.name' => ['required', 'string', 'max:60'],
            'settings.icon' => ['nullable', 'string', 'max:16'],
            'settings.personal' => ['boolean'],
        ]);

        $dashboard->update([
            'name' => trim($this->settings['name']),
            'icon' => trim($this->settings['icon']) ?: null,
            // Only the creator can make a dashboard personal – otherwise it would vanish for them.
            'personal' => $dashboard->created_by === Auth::id() ? (bool) $this->settings['personal'] : $dashboard->personal,
        ]);

        $this->dispatch('smart-home-dashboards-changed');
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function deleteDashboard(): void
    {
        $this->editable()->delete();
        $this->dispatch('smart-home-dashboard-deleted');
        // The parent page removes this component; rendering it again would find no dashboard.
        $this->skipRender();
    }

    /**
     * Adds a device with the display that suits it best.
     */
    public function addEntity(): void
    {
        $dashboard = $this->editable();
        $entity = SmartHomeEntity::where('household_id', $this->householdId)->find((int) $this->newEntity);

        if (! $entity) {
            return;
        }

        $this->appendCard($dashboard, ['smart_home_entity_id' => $entity->id, 'display' => SmartHomeDashboardCard::defaultDisplay($entity)]);
        $this->newEntity = '';
    }

    /**
     * Adds all devices of an area at once, under a heading with the area's name.
     */
    public function addArea(): void
    {
        $dashboard = $this->editable();
        $entities = SmartHomeEntity::where('household_id', $this->householdId)
            ->when($this->newArea === '', fn ($query) => $query->whereRaw('1 = 0'))
            ->where(fn ($query) => $this->newArea === '-' ? $query->whereNull('area')->orWhere('area', '') : $query->where('area', $this->newArea))
            ->orderBy('position')->orderBy('name')->get();

        if ($entities->isEmpty()) {
            return;
        }

        $this->appendCard($dashboard, ['kind' => 'heading', 'title' => $this->newArea === '-' ? __('Other') : $this->newArea, 'size' => 'full']);

        foreach ($entities as $entity) {
            $this->appendCard($dashboard, ['smart_home_entity_id' => $entity->id, 'display' => SmartHomeDashboardCard::defaultDisplay($entity)]);
        }

        $this->newArea = '';
    }

    public function addHeading(): void
    {
        $dashboard = $this->editable();
        $this->validate(['newHeading' => ['required', 'string', 'max:80']]);
        $this->appendCard($dashboard, ['kind' => 'heading', 'title' => trim($this->newHeading), 'size' => 'full']);
        $this->newHeading = '';
    }

    public function addEnergy(): void
    {
        $this->appendCard($this->editable(), ['kind' => 'energy', 'display' => 'value', 'size' => 'medium']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function appendCard(SmartHomeDashboard $dashboard, array $attributes): void
    {
        $dashboard->cards()->create($attributes + [
            'kind' => 'entity',
            'display' => 'tile',
            'size' => 'small',
            'position' => (int) $dashboard->cards()->max('position') + 1,
        ]);
    }

    /**
     * Changes display, size, title or the options (gauge range, chart period) of a card.
     */
    public function updateCard(int $id, string $field, string $value): void
    {
        $card = $this->findCard($id);

        if ($field === 'display') {
            abort_unless($card->entity && in_array($value, SmartHomeDashboardCard::displaysFor($card->entity), true), 422);
            $card->update(['display' => $value]);
        } elseif ($field === 'size') {
            abort_unless(in_array($value, SmartHomeDashboardCard::SIZES, true), 422);
            $card->update(['size' => $value]);
        } elseif ($field === 'title') {
            $card->update(['title' => mb_substr(trim($value), 0, 80) ?: null]);
        } elseif (in_array($field, ['min', 'max'], true)) {
            $card->update(['options' => array_merge($card->options ?? [], [$field => is_numeric($value) ? (float) $value : null])]);
        } elseif ($field === 'hours') {
            abort_unless(in_array((int) $value, SmartHomeDashboardCard::HISTORY_HOURS, true), 422);
            $card->update(['options' => array_merge($card->options ?? [], ['hours' => (int) $value])]);
        } else {
            abort(422);
        }
    }

    public function removeCard(int $id): void
    {
        $this->findCard($id)->delete();
    }

    /**
     * Drag & drop: puts the card at the new position and renumbers the others.
     */
    public function moveCard(int $id, int $position): void
    {
        $card = $this->findCard($id);
        $ids = $this->dashboard()->cards()->pluck('id')->reject(fn (int $other) => $other === $card->id)->values()->all();
        array_splice($ids, max(0, min($position, count($ids))), 0, [$card->id]);

        DB::transaction(function () use ($ids) {
            foreach ($ids as $index => $cardId) {
                SmartHomeDashboardCard::whereKey($cardId)->update(['position' => $index]);
            }
        });
    }

    public function with(): array
    {
        $dashboard = $this->dashboard()->load('cards.entity');
        $entities = $this->editing ? SmartHomeEntity::where('household_id', $this->householdId)->orderBy('area')->orderBy('name')->get() : collect();
        $historyCards = $dashboard->cards->filter(fn (SmartHomeDashboardCard $card) => $card->kind === 'entity' && $card->display === 'history' && $card->entity);

        return [
            'dashboard' => $dashboard,
            'mayEdit' => $this->mayEdit($dashboard),
            'mayControl' => EntityControl::mayControl(Auth::user(), $this->householdId),
            'entities' => $entities,
            'areas' => $entities->map(fn (SmartHomeEntity $entity) => $entity->area ?: '-')->unique()->sort()->values(),
            'hasEvcc' => SmartHomeConnection::where('household_id', $this->householdId)->where('type', SmartHomeConnection::EVCC)->exists(),
            'energy' => $dashboard->cards->contains('kind', 'energy') ? EnergyRecorder::latest($this->householdId) : [],
            'histories' => $historyCards->mapWithKeys(fn (SmartHomeDashboardCard $card) => [$card->id => $this->history($card)])->all(),
        ];
    }

    /**
     * Recorded values of a numeric device (every five minutes), thinned out to about 150 points.
     *
     * @return list<array{label: string, value: float}>
     */
    private function history(SmartHomeDashboardCard $card): array
    {
        $hours = (int) ($card->options['hours'] ?? 24);
        $readings = SmartHomeReading::where('household_id', $this->householdId)
            ->where('metric', 'ha:'.$card->entity?->entity_id)
            ->where('recorded_at', '>=', now()->subHours($hours))
            ->orderBy('recorded_at')->get(['value', 'recorded_at']);
        $step = max(1, (int) ceil($readings->count() / 150));

        return $readings->values()->filter(fn ($reading, int $index) => $index % $step === 0)
            ->map(fn (SmartHomeReading $reading) => ['label' => $reading->recorded_at->translatedFormat($hours > 24 ? 'D H:i' : 'H:i'), 'value' => (float) $reading->value])
            ->values()->all();
    }
}; ?>

@php
    $input = 'block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $small = 'rounded-md border-gray-300 py-1 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $watts = fn (?float $value) => $value === null ? '–' : (abs($value) >= 1000 ? number_format($value / 1000, 1, ',', '.').' kW' : number_format($value, 0, ',', '.').' W');
@endphp

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $dashboard->icon }} {{ $dashboard->name }}@if ($dashboard->personal) <span class="ms-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-normal text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ __('Only for me') }}</span>@endif</h3>
        @if ($mayEdit)
            <button type="button" wire:click="toggleEditing" @class(['rounded-full px-4 py-1.5 text-sm', 'bg-indigo-600 text-white' => $editing, 'bg-white text-gray-700 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => ! $editing])>{{ $editing ? '✓ '.__('Finish editing') : '✏️ '.__('Edit') }}</button>
        @endif
    </div>

    @if ($editing)
        <section class="space-y-4 rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
            <form wire:submit="saveSettings" class="grid gap-3 sm:grid-cols-[5rem_1fr_auto_auto] sm:items-end">
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Icon') }}<input type="text" wire:model="settings.icon" maxlength="16" class="mt-1 {{ $input }}" placeholder="🛋️"></label>
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="settings.name" maxlength="60" class="mt-1 {{ $input }}" required></label>
                @if ($dashboard->created_by === auth()->id())
                    <label class="flex items-center gap-2 pb-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="settings.personal" class="rounded border-gray-300 text-indigo-600 dark:border-gray-700 dark:bg-gray-900"> {{ __('Only for me') }}</label>
                @endif
                <x-primary-button>{{ __('Save') }}</x-primary-button>
                <x-input-error :messages="$errors->get('settings.name')" class="sm:col-span-4" />
            </form>

            <div class="grid gap-3 border-t border-gray-100 pt-4 sm:grid-cols-2 dark:border-gray-700">
                <form wire:submit="addEntity" class="flex items-end gap-2">
                    <label class="min-w-0 flex-1 text-sm text-gray-700 dark:text-gray-300">{{ __('Add device') }}
                        <select wire:model="newEntity" class="mt-1 {{ $input }}">
                            <option value="">{{ __('Choose…') }}</option>
                            @foreach ($entities->groupBy(fn ($entity) => $entity->area ?: __('Other')) as $area => $items)
                                <optgroup label="{{ $area }}">
                                    @foreach ($items as $entity)
                                        <option value="{{ $entity->id }}">{{ $entity->icon() }} {{ $entity->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>
                    <x-secondary-button type="submit">+</x-secondary-button>
                </form>
                <form wire:submit="addArea" class="flex items-end gap-2">
                    <label class="min-w-0 flex-1 text-sm text-gray-700 dark:text-gray-300">{{ __('Add whole area') }}
                        <select wire:model="newArea" class="mt-1 {{ $input }}">
                            <option value="">{{ __('Choose…') }}</option>
                            @foreach ($areas as $area)
                                <option value="{{ $area }}">{{ $area === '-' ? __('Other') : $area }}</option>
                            @endforeach
                        </select>
                    </label>
                    <x-secondary-button type="submit">+</x-secondary-button>
                </form>
                <form wire:submit="addHeading" class="flex items-end gap-2">
                    <label class="min-w-0 flex-1 text-sm text-gray-700 dark:text-gray-300">{{ __('Add heading') }}<input type="text" wire:model="newHeading" maxlength="80" class="mt-1 {{ $input }}" placeholder="{{ __('e.g. Ground floor') }}"></label>
                    <x-secondary-button type="submit">+</x-secondary-button>
                </form>
                <div class="flex items-end gap-2">
                    @if ($hasEvcc)
                        <x-secondary-button type="button" wire:click="addEnergy">⚡ {{ __('Add energy flow') }}</x-secondary-button>
                    @endif
                    <button type="button" wire:click="deleteDashboard" wire:confirm="{{ __('Delete this dashboard?') }}" class="ms-auto text-sm text-red-600 hover:underline dark:text-red-400">{{ __('Delete dashboard') }}</button>
                </div>
            </div>
            @if ($entities->isEmpty())
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Pick the devices to show under “Connections & devices”.') }}</p>
            @endif
        </section>
    @endif

    @if ($dashboard->cards->isEmpty())
        <div class="rounded-2xl bg-white p-6 text-center shadow-xs dark:bg-gray-800">
            <p class="text-3xl">🧩</p>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $mayEdit ? __('Add devices, a whole area, headings or the energy flow – then choose display and size for every card.') : __('This dashboard is still empty.') }}</p>
            @if ($mayEdit && ! $editing)
                <x-primary-button type="button" wire:click="toggleEditing" class="mt-4">{{ __('Edit') }}</x-primary-button>
            @endif
        </div>
    @else
        <div class="grid grid-flow-row-dense grid-cols-2 gap-3 sm:grid-cols-4" @if ($editing) x-sort="$wire.moveCard($item, $position)" @endif>
            @foreach ($dashboard->cards as $card)
                @if ($card->kind === 'entity' && ! $card->entity)
                    @continue
                @endif
                <div wire:key="card-{{ $card->id }}" @if ($editing) x-sort:item="{{ $card->id }}" @endif @class([$card->kind === 'heading' ? 'col-span-full' : $card->sizeClasses().' min-h-28', 'relative flex flex-col', 'rounded-2xl outline-2 outline-offset-2 outline-dashed outline-indigo-300 dark:outline-indigo-700' => $editing])>
                    @if ($card->kind === 'heading')
                        <h4 class="pt-3 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $card->title }}</h4>
                    @elseif ($card->kind === 'energy')
                        <div class="h-full rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                            <p class="text-sm text-gray-600 dark:text-gray-400">⚡ {{ $card->title ?: __('Energy flow') }}</p>
                            <dl class="mt-2 grid grid-cols-2 gap-2">
                                @foreach ([['☀️', __('PV'), $watts($energy['pv'] ?? null)], ['🏠', __('Consumption'), $watts($energy['home'] ?? null)], ['🔌', ($energy['grid'] ?? 0) < 0 ? __('Feed-in') : __('Grid'), $watts(isset($energy['grid']) ? abs($energy['grid']) : null)], ['🔋', __('Battery'), isset($energy['batterySoc']) ? number_format($energy['batterySoc'], 0).' %' : $watts($energy['battery'] ?? null)]] as [$icon, $label, $value])
                                    <div><dt class="text-xs text-gray-500 dark:text-gray-400">{{ $icon }} {{ $label }}</dt><dd class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $value }}</dd></div>
                                @endforeach
                            </dl>
                        </div>
                    @else
                        <x-smart-home.entity class="flex-1" :entity="$card->entity" :display="$card->display" :title="$card->title" :options="$card->options ?? []" :history="$histories[$card->id] ?? []" :may-control="$mayControl" @class(['pointer-events-none select-none' => $editing]) />
                    @endif

                    @if ($editing)
                        <div class="mt-1 space-y-1 rounded-xl bg-indigo-50 p-1.5 dark:bg-indigo-950/40">
                            <div class="flex flex-wrap items-center gap-1">
                                <span class="cursor-grab px-1 text-gray-500 dark:text-gray-400" x-sort:handle title="{{ __('Drag to move') }}" aria-hidden="true">⠿</span>
                                @if ($card->kind === 'entity')
                                    <select aria-label="{{ __('Display') }}" wire:change="updateCard({{ $card->id }}, 'display', $event.target.value)" class="{{ $small }} min-w-24 flex-1">
                                        @foreach (array_intersect_key(SmartHomeDashboardCard::displayLabels(), array_flip(SmartHomeDashboardCard::displaysFor($card->entity))) as $key => $label)
                                            <option value="{{ $key }}" @selected($card->display === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                @if ($card->kind !== 'heading')
                                    <select aria-label="{{ __('Size') }}" wire:change="updateCard({{ $card->id }}, 'size', $event.target.value)" class="{{ $small }} min-w-24 flex-1">
                                        @foreach (SmartHomeDashboardCard::sizeLabels() as $key => $label)
                                            <option value="{{ $key }}" @selected($card->size === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="flex-1"></span>
                                @endif
                                <button type="button" wire:click="removeCard({{ $card->id }})" class="rounded px-1.5 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30" aria-label="{{ __('Remove') }}">✕</button>
                            </div>
                            <div class="flex items-center gap-1">
                                <input type="text" value="{{ $card->title }}" placeholder="{{ $card->kind === 'entity' ? $card->entity->name : __('Title') }}" aria-label="{{ __('Title') }}" wire:change="updateCard({{ $card->id }}, 'title', $event.target.value)" class="{{ $small }} min-w-0 flex-1">
                                @if ($card->display === 'gauge')
                                    <input type="number" step="any" value="{{ $card->options['min'] ?? '' }}" placeholder="{{ __('Min') }}" aria-label="{{ __('Min') }}" wire:change="updateCard({{ $card->id }}, 'min', $event.target.value)" class="{{ $small }} w-20">
                                    <input type="number" step="any" value="{{ $card->options['max'] ?? '' }}" placeholder="{{ __('Max') }}" aria-label="{{ __('Max') }}" wire:change="updateCard({{ $card->id }}, 'max', $event.target.value)" class="{{ $small }} w-20">
                                @elseif ($card->display === 'history')
                                    <select aria-label="{{ __('Period') }}" wire:change="updateCard({{ $card->id }}, 'hours', $event.target.value)" class="{{ $small }}">
                                        @foreach (SmartHomeDashboardCard::HISTORY_HOURS as $hours)
                                            <option value="{{ $hours }}" @selected((int) ($card->options['hours'] ?? 24) === $hours)>{{ $hours >= 48 ? __(':count days', ['count' => $hours / 24]) : __(':count h', ['count' => $hours]) }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
