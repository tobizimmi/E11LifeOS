<?php

use App\Events\HouseholdItemsChanged;
use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\StoresAttachments;
use App\Models\Attachment;
use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Models\ShoppingListEntry;
use App\Support\NoteMarkdown;
use App\Support\Recurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use StoresAttachments;
    use WithFileUploads;

    #[Locked]
    public int $collectionId;

    /**
     * "table" or "board"; empty = the collection's default (board for tasks).
     */
    #[Url(as: 'view', except: '')]
    public string $viewMode = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Sort column: "" (manual order), "title" or a property id.
     */
    #[Url(except: '')]
    public string $sort = '';

    #[Url(except: 'asc')]
    public string $direction = 'asc';

    /**
     * Select property the board is grouped by (id as string; empty = first select).
     */
    #[Url(as: 'group', except: '')]
    public string $groupBy = '';

    /**
     * Entry shown in the side panel (id as string, so a malformed URL can't break the page).
     */
    #[Url(as: 'entry', except: '')]
    public string $openEntryId = '';

    /**
     * Calendar month as "YYYY-MM" (empty = current month).
     */
    #[Url(except: '')]
    public string $month = '';

    /**
     * Calendar range: "month" or "week".
     */
    #[Url(as: 'range', except: 'month')]
    public string $calendarRange = 'month';

    /**
     * Any day of the calendar week as "YYYY-MM-DD" (empty = this week).
     */
    #[Url(except: '')]
    public string $week = '';

    /**
     * Date property the calendar uses (id as string; empty = first date property).
     */
    #[Url(as: 'date', except: '')]
    public string $dateBy = '';

    public bool $hideDone = false;

    /**
     * Only entries with this tag (empty = all).
     */
    #[Url(except: '')]
    public string $tag = '';

    public string $newTitle = '';

    public bool $editingBody = false;

    /**
     * Markdown of the open entry while editing (name shared with the pageEditor Alpine component).
     */
    public string $body = '';

    /**
     * File picked, dropped or pasted into the notes editor (see storeAttachment).
     */
    public $attachmentUpload = null;

    public bool $showSettings = false;

    public string $newPropertyName = '';

    public string $newPropertyType = CollectionProperty::TYPE_TEXT;

    public string $collectionName = '';

    public string $collectionIcon = '';

    public function mount(int $collection): void
    {
        $this->resolveHousehold();

        $model = $this->findCollection($collection);
        $this->collectionId = $model->id;
        $this->collectionName = $model->name;
        $this->collectionIcon = (string) $model->icon;
    }

    private function findCollection(?int $id = null): Collection
    {
        return Collection::query()
            ->visibleTo(Auth::user(), (int) $this->householdId)
            ->with('properties')
            ->findOrFail($id ?? $this->collectionId);
    }

    private function entries()
    {
        return Item::query()
            ->forHousehold((int) $this->householdId)
            ->visibleTo(Auth::user())
            ->where('collection_id', $this->collectionId);
    }

    private function findEntry(int $id): Item
    {
        return $this->entries()->findOrFail($id);
    }

    private function findProperty(int $id): CollectionProperty
    {
        return $this->findCollection()->properties->firstWhere('id', $id) ?? abort(404);
    }

    /**
     * @return array<int, int>
     */
    private function memberIds(): array
    {
        return \App\Models\HouseholdMember::where('household_id', $this->householdId)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    private function schemaChanged(): void
    {
        HouseholdItemsChanged::dispatch((int) $this->householdId, 'collection');
    }

    // ── Entries ──────────────────────────────────────────────────────────

    public function addEntry(?string $optionId = null, ?string $title = null): void
    {
        [$title, $tags] = Item::splitHashtags(trim($title ?? $this->newTitle));

        if ($title === '') {
            $this->addError('newTitle', __('Please enter a title.'));

            return;
        }

        $collection = $this->findCollection();
        $entry = new Item([
            'household_id' => $this->householdId,
            'collection_id' => $collection->id,
            'type' => $collection->isTasks() ? Item::TYPE_TODO : Item::TYPE_ENTRY,
            'visibility' => $collection->entryVisibility(),
            'title' => mb_substr($title, 0, 255),
            'created_by' => Auth::id(),
            'position' => (int) $this->entries()->max('position') + 1,
        ]);

        $groupProperty = $this->groupProperty($collection);

        if ($optionId !== null && $groupProperty) {
            $entry->setPropertyValue($groupProperty, $groupProperty->normalizeValue($optionId));
        }

        $entry->save();
        $entry->syncTagNames($this->tag !== '' ? [...$tags, $this->tag] : $tags);

        $this->reset('newTitle');
        $this->resetErrorBag('newTitle');
    }

    /**
     * Tags of an entry from the side panel (comma-separated, "#" optional).
     */
    public function updateTags(int $entryId, string $tags): void
    {
        $this->findEntry($entryId)->syncTagNames(explode(',', $tags));
    }

    /**
     * Calendar: new entry on a given day.
     */
    public function addEntryOnDate(string $date, string $title): void
    {
        $collection = $this->findCollection();
        $dateProperty = $this->dateProperty($collection) ?? abort(422);
        $date = $dateProperty->normalizeValue($date);
        $title = trim($title);

        if ($date === null || $title === '') {
            return;
        }

        $this->addEntry(null, $title);

        $entry = $this->entries()->latest('id')->firstOrFail();
        $entry->setPropertyValue($dateProperty, $date);
        $entry->save();
    }

    /**
     * Calendar drag & drop: the entry moves to $date ("" = remove the date).
     */
    public function moveToDate(int $entryId, string $date): void
    {
        $dateProperty = $this->dateProperty($this->findCollection()) ?? abort(422);
        $entry = $this->findEntry($entryId);

        $entry->setPropertyValue($dateProperty, $dateProperty->normalizeValue($date));
        $entry->save();
    }

    public function changeMonth(int $offset): void
    {
        if ($this->calendarRange === 'week') {
            $this->week = $this->calendarWeek()->addWeeks($offset)->format('Y-m-d');

            return;
        }

        $this->month = $this->calendarMonth()->addMonthsNoOverflow($offset)->format('Y-m');
    }

    public function currentMonth(): void
    {
        $this->reset('month', 'week');
    }

    /**
     * Switches between month and week, keeping the shown period roughly in place.
     */
    public function setCalendarRange(string $range): void
    {
        if ($range === 'week' && $this->calendarRange !== 'week') {
            $month = $this->calendarMonth();
            $this->week = $month->isSameMonth(now()) ? '' : $month->format('Y-m-d');
        } elseif ($range === 'month' && $this->calendarRange === 'week') {
            $this->month = $this->week !== '' ? $this->calendarWeek()->format('Y-m') : '';
        }

        $this->calendarRange = $range === 'week' ? 'week' : 'month';
    }

    /**
     * Monday of the shown calendar week.
     */
    private function calendarWeek(): Carbon
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->week) ? Carbon::createFromFormat('!Y-m-d', $this->week) : null;

        return ($date ?: now())->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    private function calendarMonth(): Carbon
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)
            ? Carbon::createFromFormat('!Y-m', $this->month)
            : now()->startOfMonth();
    }

    public function updateTitle(int $entryId, string $title): void
    {
        $title = trim($title);

        if ($title !== '') {
            $this->findEntry($entryId)->update(['title' => mb_substr($title, 0, 255)]);
        }
    }

    public function updateProperty(int $entryId, int $propertyId, mixed $value): void
    {
        $entry = $this->findEntry($entryId);
        $property = $this->findProperty($propertyId);

        $entry->setPropertyValue($property, $property->normalizeValue($value, $this->memberIds()));
        $entry->save();
        $this->announceReschedule($entry);
    }

    public function addOptionAndSet(int $entryId, int $propertyId, string $name): void
    {
        $entry = $this->findEntry($entryId);
        $property = $this->findProperty($propertyId);
        abort_unless($property->type === CollectionProperty::TYPE_SELECT, 422);

        $option = $property->addOption(mb_substr(trim($name), 0, 100));
        $entry->setPropertyValue($property, $option['id']);
        $entry->save();
        $this->schemaChanged();
    }

    public function toggleDone(int $entryId): void
    {
        $entry = $this->findEntry($entryId);
        $entry->update(['done' => ! $entry->done]);
        $this->announceReschedule($entry);
    }

    /**
     * Board drag & drop: the card lands in the column of $optionId ("" = no value) at $position.
     */
    public function moveCard(int $entryId, string $optionId, int $position): void
    {
        $collection = $this->findCollection();
        $groupProperty = $this->groupProperty($collection) ?? abort(422);
        $entry = $this->findEntry($entryId);

        $entry->setPropertyValue($groupProperty, $groupProperty->normalizeValue($optionId));
        $entry->save();
        $this->announceReschedule($entry);

        $column = $this->entries()->orderBy('position')->get()
            ->filter(fn (Item $item) => (string) $item->propertyValue($groupProperty) === (string) $entry->propertyValue($groupProperty))
            ->reject(fn (Item $item) => $item->id === $entry->id)
            ->values();
        $column->splice(max(0, min($position, $column->count())), 0, [$entry]);

        foreach ($column as $index => $item) {
            if ($item->position !== $index) {
                Item::whereKey($item->id)->update(['position' => $index]);
            }
        }
    }

    /**
     * Puts the entry's title on the household shopping list (e.g. a pantry item that runs low).
     */
    public function addToShoppingList(int $entryId): void
    {
        $entry = $this->findEntry($entryId);

        ShoppingListEntry::create([
            'household_id' => $this->householdId,
            'label' => mb_substr($entry->title, 0, 255),
            'created_by' => Auth::id(),
        ]);

        $this->dispatch('toast', message: __(':name is on the shopping list.', ['name' => $entry->title]));
    }

    public function deleteEntry(int $entryId): void
    {
        $this->findEntry($entryId)->delete();

        if ((int) $this->openEntryId === $entryId) {
            $this->closeEntry();
        }
    }

    public function openEntry(int $entryId): void
    {
        $this->openEntryId = (string) $this->findEntry($entryId)->id;
        $this->editingBody = false;
    }

    public function closeEntry(): void
    {
        $this->reset('openEntryId', 'editingBody', 'body');
    }

    public function editBody(): void
    {
        $this->body = $this->findEntry((int) $this->openEntryId)->body();
        $this->editingBody = true;
    }

    public function saveBody(): void
    {
        $this->validate(['body' => ['nullable', 'string', 'max:100000']]);

        $entry = $this->findEntry((int) $this->openEntryId);
        $entry->update(['content' => ['body' => $this->body]]);
        Attachment::attachReferencedTo($entry, Auth::user());

        $this->editingBody = false;
    }

    /**
     * @return array{markdown: string, url: string, name: string, isImage: bool}
     */
    public function storeAttachment(): array
    {
        return $this->storeAttachmentFor($this->findEntry((int) $this->openEntryId));
    }

    public function cancelBody(): void
    {
        $this->reset('editingBody', 'body');
    }

    /**
     * Checklist items in the open entry's text (same as on pages).
     */
    public function toggleTask(int $index): void
    {
        $entry = $this->findEntry((int) $this->openEntryId);
        $body = NoteMarkdown::toggleTask($entry->body(), $index);

        if ($body !== null) {
            $entry->update(['content' => ['body' => $body]]);
        }
    }

    public function toggleEntryVisibility(int $entryId): void
    {
        $entry = $this->findEntry($entryId);
        abort_unless($entry->visibilityCanBeChangedBy(Auth::user()) && ! $this->findCollection()->isPrivate(), 403);

        $entry->update(['visibility' => $entry->isPrivate() ? Item::VISIBILITY_SHARED : Item::VISIBILITY_PRIVATE]);
    }

    // ── Schema & settings ────────────────────────────────────────────────

    public function addProperty(): void
    {
        $this->validate([
            'newPropertyName' => ['required', 'string', 'max:100'],
            'newPropertyType' => ['required', Rule::in(CollectionProperty::TYPES)],
        ]);

        $collection = $this->findCollection();
        $collection->properties()->create([
            'name' => trim($this->newPropertyName),
            'type' => $this->newPropertyType,
            'options' => $this->newPropertyType === CollectionProperty::TYPE_SELECT ? [] : null,
            'position' => (int) $collection->properties->max('position') + 1,
        ]);

        $this->reset('newPropertyName', 'newPropertyType');
        $this->schemaChanged();
    }

    /**
     * Reminder e-mail for a date property: days before the date ("" = no reminder).
     */
    public function setPropertyReminder(int $propertyId, string $days): void
    {
        $property = $this->findProperty($propertyId);
        abort_unless($property->type === CollectionProperty::TYPE_DATE, 422);

        $property->update(['options' => $days === '' ? null : ['remind_days' => max(0, min(365, (int) $days))]]);
        $this->schemaChanged();
    }

    public function renameProperty(int $propertyId, string $name): void
    {
        $name = trim($name);

        if ($name !== '') {
            $this->findProperty($propertyId)->update(['name' => mb_substr($name, 0, 100)]);
            $this->schemaChanged();
        }
    }

    /**
     * Removes a property and its values. Built-in task properties stay.
     */
    public function deleteProperty(int $propertyId): void
    {
        $property = $this->findProperty($propertyId);
        abort_if($property->isSystem(), 403);

        Item::where('collection_id', $this->collectionId)->whereNotNull('properties')->get()->each(function (Item $item) use ($property) {
            if ($item->propertyValue($property) !== null) {
                $item->setPropertyValue($property, null);
                $item->saveQuietly();
            }
        });

        $property->delete();

        if ($this->sort === (string) $propertyId) {
            $this->reset('sort', 'direction');
        }

        if ($this->groupBy === (string) $propertyId) {
            $this->reset('groupBy');
        }

        $this->schemaChanged();
    }

    public function addOption(int $propertyId, string $name): void
    {
        $property = $this->findProperty($propertyId);
        abort_unless($property->type === CollectionProperty::TYPE_SELECT && ! $property->isSystem(), 403);

        if (trim($name) !== '') {
            $property->addOption(mb_substr(trim($name), 0, 100));
            $this->schemaChanged();
        }
    }

    public function renameOption(int $propertyId, string $optionId, string $name): void
    {
        $property = $this->findProperty($propertyId);
        $name = mb_substr(trim($name), 0, 100);

        if ($name === '' || ! $property->option($optionId)) {
            return;
        }

        $property->update(['options' => collect($property->selectOptions())
            ->map(fn (array $option) => $option['id'] === $optionId ? ['name' => $name] + $option : $option)
            ->all()]);
        $this->schemaChanged();
    }

    /**
     * Deletes an option; entries that used it lose the value. Not for the task status.
     */
    public function deleteOption(int $propertyId, string $optionId): void
    {
        $property = $this->findProperty($propertyId);
        abort_if($property->isSystem(), 403);

        $property->update(['options' => collect($property->selectOptions())->reject(fn (array $option) => $option['id'] === $optionId)->values()->all()]);

        Item::where('collection_id', $this->collectionId)->get()->each(function (Item $item) use ($property, $optionId) {
            if ($item->propertyValue($property) === $optionId) {
                $item->setPropertyValue($property, null);
                $item->saveQuietly();
            }
        });

        $this->schemaChanged();
    }

    public function saveCollectionDetails(): void
    {
        $validated = $this->validate([
            'collectionName' => ['required', 'string', 'max:100'],
            'collectionIcon' => ['nullable', 'string', 'max:16'],
        ]);

        $this->findCollection()->update([
            'name' => trim($validated['collectionName']),
            'icon' => trim((string) $validated['collectionIcon']) ?: null,
        ]);

        // Full navigation so the sidebar shows the new name.
        $this->redirectRoute('collections.show', ['collection' => $this->collectionId], navigate: true);
    }

    public function deleteCollection(): void
    {
        $collection = $this->findCollection();
        abort_if($collection->system_key !== null, 403);

        $collection->delete();

        $this->redirectRoute('collections.index', navigate: true);
    }

    public function sortBy(string $column): void
    {
        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = 'asc';
        }
    }

    // ── Rendering ────────────────────────────────────────────────────────

    private function dateProperty(Collection $collection): ?CollectionProperty
    {
        $dates = $collection->properties->where('type', CollectionProperty::TYPE_DATE);

        return $dates->firstWhere('id', (int) $this->dateBy) ?? $dates->firstWhere('system_key', 'due') ?? $dates->first();
    }

    private function groupProperty(Collection $collection): ?CollectionProperty
    {
        $selects = $collection->properties->where('type', CollectionProperty::TYPE_SELECT);

        return $selects->firstWhere('id', (int) $this->groupBy) ?? $selects->first();
    }

    /**
     * Weeks (Monday first) covering the calendar month (or the one week), each day with its
     * entries and, for repeating tasks, a preview of their upcoming dates.
     *
     * @return array{month: Carbon, isWeek: bool, weekStart: Carbon, weeks: array<int, array<int, array{date: string, day: int, inMonth: bool, isToday: bool, entries: array<int, Item>, previews: array<int, Item>}>>, undated: array<int, Item>}
     */
    private function calendarData($entries, CollectionProperty $dateProperty, ?CollectionProperty $repeatProperty = null): array
    {
        $isWeek = $this->calendarRange === 'week';
        $month = $isWeek ? $this->calendarWeek()->copy()->startOfMonth() : $this->calendarMonth();
        $byDate = $entries->groupBy(fn (Item $entry) => (string) $entry->propertyValue($dateProperty));
        $day = $isWeek ? $this->calendarWeek() : $month->copy()->startOfWeek(Carbon::MONDAY);
        $end = $isWeek ? $day->copy()->addDays(6) : $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $weekStart = $day->copy();
        $weeks = [];

        $previews = [];

        if ($repeatProperty) {
            foreach ($entries as $entry) {
                $rule = $entry->propertyValue($repeatProperty);
                $date = (string) $entry->propertyValue($dateProperty);

                if ($rule && $date !== '' && ! $entry->done) {
                    foreach (Recurrence::occurrencesBetween($date, $rule, $day, $end) as $upcoming) {
                        $previews[$upcoming][] = $entry;
                    }
                }
            }
        }


        while ($day->lte($end)) {
            $week = [];

            for ($i = 0; $i < 7; $i++) {
                $date = $day->format('Y-m-d');
                $week[] = [
                    'date' => $date,
                    'day' => $day->day,
                    'inMonth' => $isWeek || $day->month === $month->month,
                    'isToday' => $day->isToday(),
                    'entries' => ($byDate[$date] ?? collect())->all(),
                    'previews' => $previews[$date] ?? [],
                ];
                $day->addDay();
            }

            $weeks[] = $week;
        }

        return ['month' => $month, 'isWeek' => $isWeek, 'weekStart' => $weekStart, 'weeks' => $weeks, 'undated' => ($byDate[''] ?? collect())->all()];
    }

    public function with(): array
    {
        $collection = $this->findCollection();
        $properties = $collection->properties;
        $groupProperty = $this->groupProperty($collection);
        $dateProperty = $this->dateProperty($collection);
        $view = in_array($this->viewMode, ['table', 'board', 'calendar'], true) ? $this->viewMode : ($collection->isTasks() ? 'board' : 'table');

        if (($view === 'board' && ! $groupProperty) || ($view === 'calendar' && ! $dateProperty)) {
            $view = 'table';
        }

        $entries = $this->entries()->with('creator', 'tags')->orderBy('position')->orderBy('id')->get()
            ->when($this->tag !== '', fn ($entries) => $entries->filter(fn (Item $entry) => $entry->tags->contains('name', $this->tag)))
            ->when($this->search !== '', fn ($entries) => $entries->filter(
                fn (Item $entry) => str_contains(mb_strtolower($entry->title), mb_strtolower(trim($this->search)))
            ))
            ->when($this->hideDone && $collection->isTasks(), fn ($entries) => $entries->reject(fn (Item $entry) => $entry->done));

        if ($this->sort !== '') {
            $sortProperty = $properties->firstWhere('id', (int) $this->sort);
            $key = function (Item $entry) use ($sortProperty) {
                if (! $sortProperty) {
                    return mb_strtolower($entry->title);
                }

                $value = $entry->propertyValue($sortProperty);

                return match ($sortProperty->type) {
                    CollectionProperty::TYPE_SELECT => collect($sortProperty->selectOptions())->search(fn ($option) => $option['id'] === $value),
                    default => is_string($value) ? mb_strtolower($value) : $value,
                };
            };

            // Empty values always go last, whatever the direction.
            [$filled, $empty] = $entries->partition(fn (Item $entry) => $key($entry) !== null && $key($entry) !== false);
            $entries = ($this->direction === 'desc' ? $filled->sortByDesc($key) : $filled->sortBy($key))->concat($empty);
        }

        $openEntry = $this->openEntryId !== '' && ctype_digit($this->openEntryId)
            ? $this->entries()->with('creator', 'tags')->find((int) $this->openEntryId)
            : null;

        return [
            'collection' => $collection,
            'properties' => $properties,
            'entries' => $entries->values(),
            'currentView' => $view,
            'dateProperty' => $dateProperty,
            'dateProperties' => $properties->where('type', CollectionProperty::TYPE_DATE)->values(),
            'calendar' => $view === 'calendar' ? $this->calendarData($entries->values(), $dateProperty, $collection->isTasks() ? $collection->systemProperty('repeat') : null) : null,
            'groupProperty' => $groupProperty,
            'selectProperties' => $properties->where('type', CollectionProperty::TYPE_SELECT)->values(),
            'members' => \App\Models\User::whereIn('id', $this->memberIds())->orderBy('name')->get(['id', 'name']),
            'openEntry' => $openEntry,
            'collectionTags' => \App\Models\Tag::query()
                ->whereHas('items', fn ($items) => $items->whereIn('items.id', $this->entries()->select('items.id')))
                ->orderBy('name')
                ->pluck('name'),
            'openEntryHtml' => $openEntry ? NoteMarkdown::toHtml($openEntry, Auth::user(), interactiveTasks: true) : null,
            'propertyTypes' => [
                CollectionProperty::TYPE_TEXT => __('Text'),
                CollectionProperty::TYPE_NUMBER => __('Number'),
                CollectionProperty::TYPE_DATE => __('Date'),
                CollectionProperty::TYPE_SELECT => __('Select'),
                CollectionProperty::TYPE_CHECKBOX => __('Checkbox'),
                CollectionProperty::TYPE_PERSON => __('Person'),
                CollectionProperty::TYPE_URL => __('Link'),
            ],
            'pageTitles' => $this->editingBody
                ? Item::query()->forHousehold((int) $this->householdId)->visibleTo(Auth::user())->ofType(Item::TYPE_NOTE)->orderBy('title')->pluck('title')->unique()->values()->all()
                : [],
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Collections') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

            {{-- Title bar --}}
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                    <span aria-hidden="true">{{ $collection->displayIcon() }}</span>
                    {{ $collection->name }}
                    @if ($collection->isPrivate())
                        <x-private-badge />
                    @endif
                </h1>

                <div class="flex flex-wrap items-center gap-2">
                    @php
                        $views = array_filter([
                            'table' => '▤ '.__('Table'),
                            'board' => $selectProperties->isNotEmpty() ? '▥ '.__('Board') : null,
                            'calendar' => $dateProperty ? '📅 '.__('Calendar') : null,
                        ]);
                    @endphp
                    @if (count($views) > 1)
                        <div class="inline-flex rounded-md border border-gray-300 dark:border-gray-600 overflow-hidden text-sm" role="group" aria-label="{{ __('View') }}">
                            @foreach ($views as $viewKey => $viewLabel)
                                <button type="button" wire:click="$set('viewMode', '{{ $viewKey }}')" @class(['px-3 py-1.5', 'bg-indigo-600 text-white' => $currentView === $viewKey, 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300' => $currentView !== $viewKey]) aria-pressed="{{ $currentView === $viewKey ? 'true' : 'false' }}">{{ $viewLabel }}</button>
                            @endforeach
                        </div>
                    @endif
                    <x-secondary-button type="button" wire:click="$toggle('showSettings')">⚙ {{ __('Properties') }}</x-secondary-button>
                </div>
            </div>

            {{-- Settings: collection details + properties --}}
            @if ($showSettings)
                <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-5 space-y-5">
                    <form wire:submit="saveCollectionDetails" class="flex flex-wrap items-end gap-3">
                        <div class="w-20">
                            <x-input-label for="collectionIcon" :value="__('Icon')" />
                            <x-text-input wire:model="collectionIcon" id="collectionIcon" class="mt-1 block w-full text-center" maxlength="16" placeholder="{{ \App\Models\Collection::DEFAULT_ICON }}" />
                        </div>
                        <div class="flex-1 min-w-[12rem]">
                            <x-input-label for="collectionName" :value="__('Name')" />
                            <x-text-input wire:model="collectionName" id="collectionName" class="mt-1 block w-full" required />
                        </div>
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        @if ($collection->system_key === null)
                            <x-danger-button type="button" wire:click="deleteCollection" wire:confirm="{{ __('Delete this collection and all its entries?') }}">{{ __('Delete collection') }}</x-danger-button>
                        @endif
                    </form>
                    <x-input-error :messages="$errors->get('collectionName')" />

                    <div>
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('Properties') }}</h3>
                        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($properties as $property)
                                <li wire:key="property-{{ $property->id }}" class="py-2 space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <input
                                            type="text"
                                            value="{{ $property->name }}"
                                            aria-label="{{ __('Property name') }}"
                                            wire:change="renameProperty({{ $property->id }}, $event.target.value)"
                                            class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 text-sm"
                                        />
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $propertyTypes[$property->type] ?? $property->type }}</span>
                                        @if ($property->isSystem())
                                            <span class="text-xs text-gray-400">({{ __('built-in') }})</span>
                                        @else
                                            <button type="button" wire:click="deleteProperty({{ $property->id }})" wire:confirm="{{ __('Delete this property and its values?') }}" class="text-xs text-red-600 dark:text-red-400 underline">{{ __('Delete') }}</button>
                                        @endif
                                    </div>
                                    @if ($property->type === \App\Models\CollectionProperty::TYPE_DATE && $property->system_key === null)
                                        <label class="flex flex-wrap items-center gap-2 ps-2 text-xs text-gray-600 dark:text-gray-400">
                                            🔔 {{ __('E-mail reminder') }}
                                            <select wire:change="setPropertyReminder({{ $property->id }}, $event.target.value)" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 py-0.5 text-xs">
                                                <option value="" @selected($property->remindDays() === null)>{{ __('none') }}</option>
                                                @foreach ([0, 1, 3, 7, 14, 30, 60, 90] as $days)
                                                    <option value="{{ $days }}" @selected($property->remindDays() === $days)>{{ $days === 0 ? __('on the day') : trans_choice(':count day before|:count days before', $days) }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @endif
                                    @if ($property->type === \App\Models\CollectionProperty::TYPE_SELECT)
                                        <div class="flex flex-wrap items-center gap-2 ps-2">
                                            @foreach ($property->selectOptions() as $option)
                                                <span wire:key="option-{{ $property->id }}-{{ $option['id'] }}" class="inline-flex items-center gap-1">
                                                    <x-option-badge :option="$option" />
                                                    <button type="button" x-data x-on:click="const name = window.prompt(@js(__('Rename option:')), @js($option['name'])); if (name) { $wire.renameOption({{ $property->id }}, @js($option['id']), name) }" class="text-xs text-gray-400 hover:text-gray-700" title="{{ __('Rename') }}">✎</button>
                                                    @unless ($property->isSystem())
                                                        <button type="button" wire:click="deleteOption({{ $property->id }}, @js($option['id']))" wire:confirm="{{ __('Delete this option?') }}" class="text-xs text-gray-400 hover:text-red-600" title="{{ __('Delete') }}">✕</button>
                                                    @endunless
                                                </span>
                                            @endforeach
                                            @unless ($property->isSystem())
                                                <form x-data="{ name: '' }" x-on:submit.prevent="if (name.trim()) { $wire.addOption({{ $property->id }}, name); name = '' }" class="inline-flex">
                                                    <input x-model="name" type="text" placeholder="+ {{ __('Option') }}" aria-label="{{ __('New option') }}" class="w-28 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 py-0.5 text-xs" />
                                                </form>
                                            @endunless
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>

                        <form wire:submit="addProperty" class="mt-3 flex flex-wrap items-end gap-2">
                            <div>
                                <x-input-label for="newPropertyName" :value="__('New property')" />
                                <x-text-input wire:model="newPropertyName" id="newPropertyName" class="mt-1 block" placeholder="{{ __('e.g. Priority') }}" />
                            </div>
                            <div>
                                <x-input-label for="newPropertyType" :value="__('Type')" />
                                <select wire:model="newPropertyType" id="newPropertyType" class="mt-1 block rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 shadow-sm text-sm">
                                    @foreach ($propertyTypes as $type => $typeLabel)
                                        <option value="{{ $type }}">{{ $typeLabel }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <x-primary-button>{{ __('Add') }}</x-primary-button>
                        </form>
                        <x-input-error :messages="$errors->get('newPropertyName')" class="mt-1" />
                    </div>
                </section>
            @endif

            @if ($collectionTags->isNotEmpty())
                <div class="flex flex-wrap items-center gap-1" aria-label="{{ __('Filter by tag') }}">
                    @foreach ($collectionTags as $tagName)
                        <button type="button" wire:key="tag-filter-{{ $tagName }}" wire:click="$set('tag', @js($tag === $tagName ? '' : $tagName))" aria-pressed="{{ $tag === $tagName ? 'true' : 'false' }}" @class(['rounded-full px-2 py-0.5 text-xs', 'bg-indigo-600 text-white' => $tag === $tagName, 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' => $tag !== $tagName])>#{{ $tagName }}</button>
                    @endforeach
                </div>
            @endif

            {{-- Toolbar --}}
            <div class="flex flex-wrap items-center gap-3">
                <form wire:submit="addEntry()" class="flex flex-1 min-w-[16rem] gap-2">
                    <x-text-input wire:model="newTitle" class="block w-full" placeholder="{{ $collection->isTasks() ? __('New task... (#tags optional)') : __('New entry... (#tags optional)') }}" aria-label="{{ __('Title') }}" />
                    <x-primary-button>{{ __('Add') }}</x-primary-button>
                </form>
                <x-text-input wire:model.live.debounce.300ms="search" type="search" class="block w-48" placeholder="{{ __('Filter...') }}" aria-label="{{ __('Filter') }}" />
                @if ($collection->isTasks())
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model.live="hideDone" class="rounded border-gray-300 dark:border-gray-600 text-indigo-600" />
                        {{ __('Hide done') }}
                    </label>
                @endif
                @if ($currentView === 'board' && $selectProperties->count() > 1)
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        {{ __('Group by') }}
                        <select wire:model.live="groupBy" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm">
                            @foreach ($selectProperties as $selectProperty)
                                <option value="{{ $selectProperty->id }}">{{ $selectProperty->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            </div>
            <x-input-error :messages="$errors->get('newTitle')" />

            @if ($currentView === 'table')
                {{-- Table --}}
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-gray-200 dark:border-gray-700 text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <tr>
                                @if ($collection->isTasks())
                                    <th class="w-10 px-3 py-2"><span class="sr-only">{{ __('Done') }}</span></th>
                                @endif
                                <th class="px-3 py-2 min-w-[14rem]">
                                    <button type="button" wire:click="sortBy('title')" class="uppercase hover:text-gray-800 dark:hover:text-gray-200">
                                        {{ __('Title') }} {{ $sort === 'title' ? ($direction === 'asc' ? '↑' : '↓') : '' }}
                                    </button>
                                </th>
                                @foreach ($properties as $property)
                                    <th class="px-3 py-2 min-w-[10rem]" wire:key="th-{{ $property->id }}">
                                        <button type="button" wire:click="sortBy('{{ $property->id }}')" class="uppercase hover:text-gray-800 dark:hover:text-gray-200">
                                            {{ $property->name }} {{ $sort === (string) $property->id ? ($direction === 'asc' ? '↑' : '↓') : '' }}
                                        </button>
                                    </th>
                                @endforeach
                                <th class="w-10 px-3 py-2"><span class="sr-only">{{ __('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($entries as $entry)
                                <tr wire:key="row-{{ $entry->id }}" @class(['hover:bg-gray-50 dark:hover:bg-gray-700/40', 'bg-indigo-50/60 dark:bg-gray-700/60' => $openEntry?->id === $entry->id])>
                                    @if ($collection->isTasks())
                                        <td class="px-3 py-1.5">
                                            <input type="checkbox" wire:key="done-{{ $entry->id }}-{{ $entry->updated_at?->timestamp }}-{{ (int) $entry->done }}" wire:click="toggleDone({{ $entry->id }})" @checked($entry->done) aria-label="{{ __('Mark as done') }}" class="rounded border-gray-300 dark:border-gray-600 text-indigo-600" />
                                        </td>
                                    @endif
                                    <td class="px-3 py-1.5">
                                        <div class="flex items-center gap-2">
                                            <button type="button" wire:click="openEntry({{ $entry->id }})" @class(['text-start font-medium hover:underline', 'line-through text-gray-400' => $collection->isTasks() && $entry->done, 'text-gray-900 dark:text-gray-100' => ! ($collection->isTasks() && $entry->done)])>
                                                {{ $entry->title }}
                                            </button>
                                            @foreach ($entry->tags->sortBy('name') as $entryTag)
                                                <span class="text-xs text-indigo-600 dark:text-indigo-300">#{{ $entryTag->name }}</span>
                                            @endforeach
                                            @if ($entry->isPrivate() && ! $collection->isPrivate())
                                                <x-private-badge />
                                            @endif
                                            @if ($entry->body() !== '')
                                                <span class="text-gray-400" title="{{ __('Has notes') }}" aria-hidden="true">≡</span>
                                            @endif
                                        </div>
                                    </td>
                                    @foreach ($properties as $property)
                                        <td class="px-1 py-1" wire:key="cell-{{ $entry->id }}-{{ $property->id }}">
                                            <x-property-input :property="$property" :item="$entry" :members="$members" compact />
                                        </td>
                                    @endforeach
                                    <td class="px-3 py-1.5 text-end">
                                        <button type="button" wire:click="deleteEntry({{ $entry->id }})" wire:confirm="{{ __('Delete this entry?') }}" class="text-gray-400 hover:text-red-600" title="{{ __('Delete') }}">✕</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $properties->count() + 3 }}" class="px-3 py-6 text-center text-gray-500 dark:text-gray-400">
                                        {{ $search !== '' ? __('No entries match your filter.') : __('No entries yet.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($entries->isNotEmpty() && $properties->contains(fn ($property) => in_array($property->type, [\App\Models\CollectionProperty::TYPE_NUMBER, \App\Models\CollectionProperty::TYPE_CHECKBOX], true)))
                            <tfoot class="border-t-2 border-gray-200 dark:border-gray-700 text-xs text-gray-600 dark:text-gray-300">
                                <tr>
                                    @if ($collection->isTasks())
                                        <td></td>
                                    @endif
                                    <td class="px-3 py-2 font-medium">{{ trans_choice(':count entry|:count entries', $entries->count()) }}</td>
                                    @foreach ($properties as $property)
                                        <td class="px-3 py-2 font-semibold" wire:key="tf-{{ $property->id }}">
                                            @if (($aggregate = $property->aggregate($entries)) !== null)
                                                {{ $property->type === \App\Models\CollectionProperty::TYPE_NUMBER ? 'Σ ' : '' }}{{ $aggregate }}
                                            @endif
                                        </td>
                                    @endforeach
                                    <td></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            @elseif ($currentView === 'calendar')
                {{-- Calendar --}}
                <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-3 sm:p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="changeMonth(-1)" class="rounded-md border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm" aria-label="{{ $calendar['isWeek'] ? __('Previous week') : __('Previous month') }}">‹</button>
                            <h2 class="min-w-[10rem] text-center font-semibold text-gray-900 dark:text-gray-100">
                                @if ($calendar['isWeek'])
                                    {{ $calendar['weekStart']->translatedFormat('j. M') }} – {{ $calendar['weekStart']->copy()->addDays(6)->translatedFormat('j. M Y') }}
                                    <span class="text-xs font-normal text-gray-500">· {{ __('Week :number', ['number' => $calendar['weekStart']->isoWeek()]) }}</span>
                                @else
                                    {{ $calendar['month']->translatedFormat('F Y') }}
                                @endif
                            </h2>
                            <button type="button" wire:click="changeMonth(1)" class="rounded-md border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm" aria-label="{{ $calendar['isWeek'] ? __('Next week') : __('Next month') }}">›</button>
                            <button type="button" wire:click="currentMonth" class="text-sm text-indigo-600 dark:text-indigo-400 underline">{{ __('Today') }}</button>
                            <div class="ms-2 inline-flex rounded-md ring-1 ring-gray-300 dark:ring-gray-600 text-sm" role="group" aria-label="{{ __('Calendar range') }}">
                                <button type="button" wire:click="setCalendarRange('month')" aria-pressed="{{ $calendar['isWeek'] ? 'false' : 'true' }}" @class(['rounded-s-md px-2 py-1', 'bg-indigo-600 text-white' => ! $calendar['isWeek'], 'text-gray-700 dark:text-gray-300' => $calendar['isWeek']])>{{ __('Month') }}</button>
                                <button type="button" wire:click="setCalendarRange('week')" aria-pressed="{{ $calendar['isWeek'] ? 'true' : 'false' }}" @class(['rounded-e-md px-2 py-1', 'bg-indigo-600 text-white' => $calendar['isWeek'], 'text-gray-700 dark:text-gray-300' => ! $calendar['isWeek']])>{{ __('Week') }}</button>
                            </div>
                        </div>
                        @if ($dateProperties->count() > 1)
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                {{ __('Date field') }}
                                <select wire:model.live="dateBy" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm">
                                    @foreach ($dateProperties as $candidate)
                                        <option value="{{ $candidate->id }}" @selected($candidate->id === $dateProperty->id)>{{ $candidate->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endif
                    </div>

                    <div class="grid grid-cols-7 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                        @foreach ([__('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat'), __('Sun')] as $weekday)
                            <div class="py-1">{{ $weekday }}</div>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-7 border-s border-t border-gray-200 dark:border-gray-700">
                        @foreach ($calendar['weeks'] as $week)
                            @foreach ($week as $day)
                                <div
                                    wire:key="day-{{ $day['date'] }}"
                                    @class([
                                        'group relative border-e border-b border-gray-200 dark:border-gray-700 p-1',
                                        'min-h-[5.5rem]' => ! $calendar['isWeek'],
                                        'min-h-[16rem]' => $calendar['isWeek'],
                                        'bg-gray-50 dark:bg-gray-900/40' => ! $day['inMonth'],
                                    ])
                                >
                                    <div class="flex items-center justify-between">
                                        <span @class([
                                            'inline-flex h-6 w-6 items-center justify-center rounded-full text-xs',
                                            'bg-indigo-600 text-white font-semibold' => $day['isToday'],
                                            'text-gray-700 dark:text-gray-300' => ! $day['isToday'] && $day['inMonth'],
                                            'text-gray-400' => ! $day['isToday'] && ! $day['inMonth'],
                                        ])>{{ $day['day'] }}</span>
                                        <button
                                            type="button"
                                            x-data
                                            x-on:click="const title = window.prompt(@js(__('New entry on :date:', ['date' => \Illuminate\Support\Carbon::parse($day['date'])->translatedFormat('j. F')]))); if (title && title.trim()) { $wire.addEntryOnDate('{{ $day['date'] }}', title.trim()) }"
                                            class="px-1 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 sm:opacity-0 sm:group-hover:opacity-100 focus:opacity-100"
                                            title="{{ __('New entry on this day') }}"
                                        >+</button>
                                    </div>
                                    <ul
                                        x-sort="$wire.moveToDate($item, '{{ $day['date'] }}')"
                                        x-sort:group="calendar-{{ $collection->id }}"
                                        class="mt-0.5 min-h-[2rem] space-y-0.5"
                                    >
                                        @foreach ($day['entries'] as $calendarEntry)
                                            <x-calendar-chip :entry="$calendarEntry" :collection="$collection" :group-property="$groupProperty" />
                                        @endforeach
                                    </ul>
                                    @foreach ($day['previews'] as $previewEntry)
                                        <button type="button" wire:key="preview-{{ $day['date'] }}-{{ $previewEntry->id }}" wire:click="openEntry({{ $previewEntry->id }})" class="mt-0.5 flex w-full items-center gap-1 truncate rounded border border-dashed border-indigo-200 dark:border-indigo-800 px-1.5 py-0.5 text-start text-xs text-indigo-400 dark:text-indigo-300/70" title="{{ __('Repeats: :title', ['title' => $previewEntry->title]) }}">
                                            <span aria-hidden="true">↻</span><span class="truncate">{{ $previewEntry->title }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endforeach
                        @endforeach
                    </div>

                    <div class="mt-4">
                        <h3 class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Without :name', ['name' => $dateProperty->name]) }} ({{ count($calendar['undated']) }})</h3>
                        <ul
                            x-sort="$wire.moveToDate($item, '')"
                            x-sort:group="calendar-{{ $collection->id }}"
                            class="mt-1 max-h-48 min-h-[2.25rem] space-y-0.5 overflow-y-auto rounded-md border border-dashed border-gray-300 dark:border-gray-600 p-1 sm:max-w-sm"
                        >
                            @foreach ($calendar['undated'] as $calendarEntry)
                                <x-calendar-chip :entry="$calendarEntry" :collection="$collection" :group-property="$groupProperty" />
                            @endforeach
                        </ul>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Drag entries onto a day to give them a date.') }}</p>
                    </div>
                </section>
            @else
                {{-- Board --}}
                @php
                    $columns = collect($groupProperty->selectOptions())->map(fn ($option) => ['id' => $option['id'], 'option' => $option])
                        ->push(['id' => '', 'option' => null]);
                @endphp
                <div class="flex gap-4 overflow-x-auto pb-4 -mx-4 px-4 sm:mx-0 sm:px-0 snap-x">
                    @foreach ($columns as $column)
                        @php($cards = $entries->filter(fn ($entry) => (string) $entry->propertyValue($groupProperty) === $column['id']))
                        @continue($column['id'] === '' && $cards->isEmpty())
                        <section wire:key="column-{{ $groupProperty->id }}-{{ $column['id'] ?: 'none' }}" class="w-72 shrink-0 snap-start rounded-lg bg-gray-200/60 dark:bg-gray-800/60 p-2">
                            <header class="flex items-center justify-between px-1 pb-2">
                                @if ($column['option'])
                                    <x-option-badge :option="$column['option']" />
                                @else
                                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('No :name', ['name' => $groupProperty->name]) }}</span>
                                @endif
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $cards->count() }}
                                    @if (($numberProperty = $properties->firstWhere('type', \App\Models\CollectionProperty::TYPE_NUMBER)) && ($sum = $numberProperty->aggregate($cards)) !== null)
                                        · Σ {{ $sum }}
                                    @endif
                                </span>
                            </header>

                            <ul
                                x-sort="$wire.moveCard($item, @js($column['id']), $position)"
                                x-sort:group="board-{{ $groupProperty->id }}"
                                class="min-h-[3rem] space-y-2"
                            >
                                @foreach ($cards as $card)
                                    <li
                                        x-sort:item="{{ $card->id }}"
                                        wire:key="card-{{ $card->id }}"
                                        class="cursor-grab rounded-md bg-white dark:bg-gray-800 p-3 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700 hover:ring-indigo-300"
                                    >
                                        <button type="button" wire:click="openEntry({{ $card->id }})" x-sort:ignore @class(['block w-full text-start text-sm font-medium', 'line-through text-gray-400' => $collection->isTasks() && $card->done, 'text-gray-900 dark:text-gray-100' => ! ($collection->isTasks() && $card->done)])>
                                            {{ $card->title }}
                                        </button>
                                        <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                            @if ($card->isPrivate() && ! $collection->isPrivate())
                                                <x-private-badge />
                                            @endif
                                            @foreach ($card->tags->sortBy('name') as $cardTag)
                                                <span class="text-indigo-600 dark:text-indigo-300">#{{ $cardTag->name }}</span>
                                            @endforeach
                                            @foreach ($properties as $property)
                                                @continue($property->id === $groupProperty->id)
                                                @php($value = $card->propertyValue($property))
                                                @continue($value === null)
                                                @switch($property->type)
                                                    @case(\App\Models\CollectionProperty::TYPE_SELECT)
                                                        @if ($option = $property->option($value))
                                                            <x-option-badge :option="$option" />
                                                        @endif
                                                        @break
                                                    @case(\App\Models\CollectionProperty::TYPE_DATE)
                                                        <span @class(['rounded bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5', 'text-red-600 dark:text-red-400 font-medium' => $property->system_key === 'due' && ! $card->done && $value < now()->toDateString()])>
                                                            📅 {{ \Illuminate\Support\Carbon::parse($value)->translatedFormat('j. M') }}
                                                        </span>
                                                        @break
                                                    @case(\App\Models\CollectionProperty::TYPE_PERSON)
                                                        <span class="rounded bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5">👤 {{ $members->firstWhere('id', $value)?->name ?? '—' }}</span>
                                                        @break
                                                    @case(\App\Models\CollectionProperty::TYPE_CHECKBOX)
                                                        <span>☑ {{ $property->name }}</span>
                                                        @break
                                                    @default
                                                        <span class="truncate max-w-full">{{ $property->name }}: {{ $value }}</span>
                                                @endswitch
                                            @endforeach
                                        </div>
                                    </li>
                                @endforeach
                            </ul>

                            <form x-data="{ title: '' }" x-on:submit.prevent="if (title.trim()) { $wire.addEntry(@js($column['id'] ?: null), title); title = '' }" class="mt-2">
                                <input x-model="title" type="text" placeholder="+ {{ __('New') }}" aria-label="{{ __('New entry in this column') }}" class="block w-full rounded-md border-transparent bg-transparent text-sm placeholder-gray-500 focus:border-indigo-500 focus:bg-white dark:focus:bg-gray-900 focus:ring-indigo-500" />
                            </form>
                        </section>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Entry side panel --}}
    @if ($openEntry)
        <div class="fixed inset-0 z-40 bg-gray-900/30" wire:click="closeEntry" aria-hidden="true"></div>
        <aside
            class="fixed inset-y-0 end-0 z-40 w-full sm:max-w-xl overflow-y-auto bg-white dark:bg-gray-800 shadow-xl"
            role="dialog"
            aria-modal="true"
            aria-label="{{ $openEntry->title }}"
            x-data
            {{-- Escape closes the panel, but not while typing (e.g. closing the "/" menu of the editor). --}}
            x-on:keydown.escape.window="if (! $event.target.closest('.ProseMirror, input, textarea, select')) $wire.closeEntry()"
            wire:key="panel-{{ $openEntry->id }}"
        >
            <div class="sticky top-0 flex items-center justify-between gap-2 border-b border-gray-100 dark:border-gray-700 bg-white dark:bg-gray-800 px-5 py-3">
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $collection->displayIcon() }} {{ $collection->name }}</span>
                <div class="flex items-center gap-3 text-sm">
                    <button type="button" wire:click="addToShoppingList({{ $openEntry->id }})" class="text-gray-600 dark:text-gray-300 underline" title="{{ __('Add to shopping list') }}">🛒 <span class="hidden sm:inline">{{ __('Shopping list') }}</span></button>
                    <button type="button" wire:click="deleteEntry({{ $openEntry->id }})" wire:confirm="{{ __('Delete this entry?') }}" class="text-red-600 dark:text-red-400 underline">{{ __('Delete') }}</button>
                    <button type="button" wire:click="closeEntry" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200" aria-label="{{ __('Close') }}">✕</button>
                </div>
            </div>

            <div class="space-y-5 p-5">
                <input
                    type="text"
                    value="{{ $openEntry->title }}"
                    aria-label="{{ __('Title') }}"
                    wire:change="updateTitle({{ $openEntry->id }}, $event.target.value)"
                    class="block w-full border-0 border-b border-transparent px-0 text-2xl font-bold text-gray-900 dark:text-gray-100 bg-transparent focus:border-indigo-500 focus:ring-0"
                />

                <dl class="grid grid-cols-[8rem_1fr] items-center gap-x-3 gap-y-2 text-sm">
                    @if ($collection->isTasks())
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Done') }}</dt>
                        <dd><input type="checkbox" wire:key="panel-done-{{ $openEntry->id }}-{{ $openEntry->updated_at?->timestamp }}-{{ (int) $openEntry->done }}" wire:click="toggleDone({{ $openEntry->id }})" @checked($openEntry->done) aria-label="{{ __('Mark as done') }}" class="rounded border-gray-300 dark:border-gray-600 text-indigo-600" /></dd>
                    @endif
                    @foreach ($properties as $property)
                        <dt class="text-gray-500 dark:text-gray-400" wire:key="dt-{{ $property->id }}">{{ $property->name }}</dt>
                        <dd wire:key="dd-{{ $openEntry->id }}-{{ $property->id }}"><x-property-input :property="$property" :item="$openEntry" :members="$members" /></dd>
                    @endforeach
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Tags') }}</dt>
                    <dd>
                        <input
                            type="text"
                            value="{{ $openEntry->tags->sortBy('name')->pluck('name')->implode(', ') }}"
                            wire:change="updateTags({{ $openEntry->id }}, $event.target.value)"
                            wire:key="panel-tags-{{ $openEntry->id }}"
                            placeholder="{{ __('e.g. garden, urgent') }}"
                            aria-label="{{ __('Tags') }}"
                            class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 py-1 text-sm"
                        />
                    </dd>
                    @if (! $collection->isPrivate() && $openEntry->visibilityCanBeChangedBy(auth()->user()))
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Visibility') }}</dt>
                        <dd>
                            <button type="button" wire:click="toggleEntryVisibility({{ $openEntry->id }})" class="text-sm underline text-gray-700 dark:text-gray-300">
                                {{ $openEntry->isPrivate() ? __('Private – share with household') : __('Shared – make private') }}
                            </button>
                        </dd>
                    @endif
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Created') }}</dt>
                    <dd class="text-gray-600 dark:text-gray-300">{{ $openEntry->created_at->translatedFormat('j. F Y') }}@if ($openEntry->creator) · {{ $openEntry->creator->name }}@endif</dd>
                </dl>

                <section class="border-t border-gray-100 dark:border-gray-700 pt-4">
                    @if ($editingBody)
                        <form wire:submit="saveBody" class="space-y-3">
                            <div
                                x-data="pageEditor(@js([
                                    'pageTitles' => $pageTitles,
                                    'translations' => \App\Support\EditorTranslations::all(__('Notes')),
                                ]))"
                                wire:key="entry-editor-{{ $openEntry->id }}"
                            >
                                <div class="mb-1 flex justify-end">
                                    <button type="button" x-show="mode !== 'loading'" x-on:click="pickFile()" class="text-xs text-gray-500 dark:text-gray-400 underline">📎 {{ __('Image or file') }}</button>
                                    <input type="file" x-ref="file" class="hidden" x-on:change="uploadFiles($event.target.files); $event.target.value = ''" accept=".{{ implode(',.', \App\Models\Attachment::EXTENSIONS) }}" />
                                </div>
                                <div wire:ignore x-show="mode !== 'markdown'">
                                    <div x-ref="bubble" class="editor-bubble" style="visibility: hidden" role="toolbar" aria-label="{{ __('Formatting') }}">
                                        <button type="button" x-on:click="run('toggleBold')" :class="isActive('bold') && 'is-active'" title="{{ __('Bold') }}"><strong>B</strong></button>
                                        <button type="button" x-on:click="run('toggleItalic')" :class="isActive('italic') && 'is-active'" title="{{ __('Italic') }}"><em>I</em></button>
                                        <button type="button" x-on:click="run('toggleStrike')" :class="isActive('strike') && 'is-active'" title="{{ __('Strikethrough') }}"><s>S</s></button>
                                        <button type="button" x-on:click="setLink()" :class="isActive('link') && 'is-active'" title="{{ __('Link') }}">🔗</button>
                                        <button type="button" x-on:click="run('toggleTaskList')" :class="isActive('taskList') && 'is-active'" title="{{ __('Checklist') }}">☐</button>
                                    </div>
                                    <div x-ref="editor" class="editor-frame">
                                        <p x-show="mode === 'loading'" class="p-3 text-sm text-gray-400">{{ __('Loading editor …') }}</p>
                                    </div>
                                </div>
                                <p x-show="uploadStatus" x-cloak x-text="uploadStatus" class="mt-1 text-xs text-gray-500 dark:text-gray-400" role="status"></p>
                                <textarea x-show="mode === 'markdown'" x-cloak wire:model="body" rows="10" class="block w-full font-mono text-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm" aria-label="{{ __('Notes') }}"></textarea>
                            </div>
                            <div class="flex items-center gap-3">
                                <x-primary-button>{{ __('Save') }}</x-primary-button>
                                <button type="button" wire:click="cancelBody" class="text-sm text-gray-500 dark:text-gray-400 underline">{{ __('Cancel') }}</button>
                            </div>
                        </form>
                    @else
                        <div class="flex items-center justify-between gap-3 mb-2">
                            <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Notes') }}</h3>
                            <button type="button" wire:click="editBody" class="text-sm text-indigo-600 dark:text-indigo-400 underline">{{ __('Edit') }}</button>
                        </div>
                        @if ($openEntry->body() !== '')
                            <div class="note-content">{!! $openEntryHtml !!}</div>
                        @else
                            <button type="button" wire:click="editBody" class="text-sm text-gray-400 hover:text-gray-600">{{ __('Add notes, checklists or links …') }}</button>
                        @endif
                    @endif
                </section>
            </div>
        </aside>
    @endif
</div>
