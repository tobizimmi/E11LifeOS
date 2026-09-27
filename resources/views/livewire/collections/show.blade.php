<?php

use App\Events\HouseholdItemsChanged;
use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\ProvidesLinkTargets;
use App\Livewire\Concerns\StoresAttachments;
use App\Livewire\Concerns\SuggestsTags;
use App\Models\Attachment;
use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\CollectionTemplate;
use App\Models\Item;
use App\Models\ShoppingListEntry;
use App\Support\NoteMarkdown;
use App\Models\CollectionView;
use App\Support\BrainAi;
use App\Support\AiException;
use App\Support\EntryAi;
use App\Support\EntryFilters;
use App\Support\Links;
use App\Support\Recurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use ProvidesLinkTargets;
    use StoresAttachments;
    use SuggestsTags;
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
     * AI tag suggestions for the open entry.
     *
     * @var array<int, string>
     */
    public array $entryTagSuggestions = [];

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

    /**
     * Property filters, see App\Support\EntryFilters.
     *
     * @var array<int, array{property: string, operator: string, value: string}>
     */
    #[Url(as: 'f', except: [])]
    public array $filters = [];

    /**
     * Saved view currently applied (id as string, empty = none).
     */
    #[Url(as: 'v', except: '')]
    public string $activeView = '';

    public bool $showFilters = false;

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

    public bool $showImport = false;

    public string $newPropertyName = '';

    public string $newPropertyType = CollectionProperty::TYPE_TEXT;

    public string $collectionName = '';

    public string $collectionIcon = '';

    public string $collectionDefaultView = '';

    /**
     * Properties not shown in table, cards and list (ids).
     *
     * @var array<int, int>
     */
    #[Url(as: 'hide', except: [])]
    public array $hiddenProperties = [];

    /**
     * Entry form (popup) for a new entry or editing one ($formEntryId).
     */
    public bool $showEntryForm = false;

    public ?int $formEntryId = null;

    /**
     * @var array{title: string, values: array<int|string, mixed>, tags: string, body: string}
     */
    public array $form = ['title' => '', 'values' => [], 'tags' => '', 'body' => ''];

    /**
     * Documents picked in the entry form (stored with the entry on save).
     *
     * @var array<int, mixed>
     */
    public array $formDocuments = [];

    /**
     * Documents added in the detail popup (stored right away).
     *
     * @var array<int, mixed>
     */
    public array $documentUploads = [];

    public ?string $aiFillMessage = null;

    public function mount(int $collection): void
    {
        $this->resolveHousehold();

        $model = $this->findCollection($collection);
        $this->collectionId = $model->id;
        $this->collectionName = $model->name;
        $this->collectionIcon = (string) $model->icon;
        $this->collectionDefaultView = (string) $model->default_view;
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

        foreach ($collection->properties as $property) {
            $entry->setPropertyValue($property, $property->normalizeValue($property->resolvedDefault(Auth::id()), $this->memberIds()));
        }

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

    public function suggestEntryTags(int $entryId): void
    {
        $entry = $this->findEntry($entryId);
        $this->entryTagSuggestions = $this->tagSuggestionsFor($entry->title, $entry->body(), $entry->tags->pluck('name')->all());
    }

    public function addEntryTag(int $entryId, string $name): void
    {
        $entry = $this->findEntry($entryId);
        $entry->syncTagNames([...$entry->tags->pluck('name')->all(), $name]);
        $this->entryTagSuggestions = array_values(array_diff($this->entryTagSuggestions, [$name]));
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
        abort_unless($property->hasOptions(), 422);

        $option = $property->addOption(mb_substr(trim($name), 0, 100));
        $entry->setPropertyValue($property, $property->type === CollectionProperty::TYPE_MULTI_SELECT
            ? [...(array) $entry->propertyValue($property), $option['id']]
            : $option['id']);
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
        $this->entryTagSuggestions = [];
    }

    public function closeEntry(): void
    {
        $this->reset('openEntryId', 'editingBody', 'body', 'entryTagSuggestions');
    }

    // ── Entry form (popup) ───────────────────────────────────────────────

    /**
     * Opens the form for a new entry, optionally pre-filled (board column, calendar day).
     */
    public function newEntry(?string $optionId = null, ?string $date = null): void
    {
        $collection = $this->findCollection();
        $values = $collection->properties
            ->mapWithKeys(fn (CollectionProperty $property) => [$property->id => $property->normalizeValue($property->resolvedDefault(Auth::id()), $this->memberIds())])
            ->filter(fn ($value) => $value !== null)->all();

        if ($optionId !== null && $optionId !== '' && ($groupProperty = $this->groupProperty($collection))) {
            $values[$groupProperty->id] = $groupProperty->normalizeValue($optionId);
        }

        if ($date !== null && ($dateProperty = $this->dateProperty($collection))) {
            $values[$dateProperty->id] = $dateProperty->normalizeValue($date);
        }

        $this->resetValidation();
        $this->form = ['title' => '', 'values' => $this->formValues($collection, $values), 'tags' => $this->tag, 'body' => ''];
        $this->formEntryId = null;
        $this->showEntryForm = true;
    }

    public function editEntry(int $entryId): void
    {
        $entry = $this->findEntry($entryId);
        $collection = $this->findCollection();

        $this->resetValidation();
        $this->form = [
            'title' => $entry->title,
            'values' => $this->formValues($collection, $entry->properties ?? []),
            'tags' => $entry->tags->sortBy('name')->pluck('name')->implode(', '),
            'body' => $entry->body(),
        ];
        $this->formEntryId = $entry->id;
        $this->showEntryForm = true;
    }

    public function closeEntryForm(): void
    {
        $this->reset('showEntryForm', 'formEntryId', 'form', 'formDocuments', 'aiFillMessage');
        $this->resetValidation();
    }

    public function removeFormDocument(int $index): void
    {
        unset($this->formDocuments[$index]);
        $this->formDocuments = array_values($this->formDocuments);
    }

    /**
     * "✨ Fill in from document": the AI reads the (last) picked PDF or photo and fills empty fields.
     */
    public function fillFormFromDocument(EntryAi $ai): void
    {
        $this->aiFillMessage = null;
        $this->validate(['formDocuments' => ['array', 'max:10'], 'formDocuments.*' => Attachment::rules()]);

        $document = collect($this->formDocuments)->reverse()->first(fn ($file) => in_array($file->getMimeType(), EntryAi::MEDIA_TYPES, true));

        if (! $document) {
            $this->aiFillMessage = __('Add a PDF or a photo of the document first.');

            return;
        }

        $collection = $this->findCollection();

        try {
            $result = $ai->extract($collection, (string) file_get_contents($document->getRealPath()), $document->getMimeType());
        } catch (AiException $exception) {
            $this->aiFillMessage = $exception->getMessage();

            return;
        }

        $filled = 0;
        $formatted = $this->formValues($collection, $result['values']);

        foreach (array_keys($result['values']) as $propertyId) {
            $current = $this->form['values'][$propertyId] ?? null;

            if ($current === null || $current === '' || $current === [] || $current === false) {
                $this->form['values'][$propertyId] = $formatted[$propertyId];
                $filled++;
            }
        }

        if (trim($this->form['title']) === '' && $result['title'] !== '') {
            $this->form['title'] = $result['title'];
            $filled++;
        }

        if (trim($this->form['body']) === '' && $result['notes'] !== '') {
            $this->form['body'] = $result['notes'];
        }

        $this->aiFillMessage = trans_choice('Filled in :count field from the document – please check.|Filled in :count fields from the document – please check.', $filled);
    }

    /**
     * Detail popup: files are stored as soon as they are picked.
     */
    public function updatedDocumentUploads(): void
    {
        $this->validate(['documentUploads' => ['array', 'max:10'], 'documentUploads.*' => Attachment::rules()]);
        $entry = $this->findEntry((int) $this->openEntryId);

        foreach ($this->documentUploads as $upload) {
            Attachment::storeUpload($upload, (int) $this->householdId, $entry->id, (int) Auth::id());
        }

        $this->dispatch('toast', message: trans_choice(':count document added|:count documents added', count($this->documentUploads)));
        $this->reset('documentUploads');
    }

    public function deleteDocument(int $attachmentId): void
    {
        Attachment::query()
            ->where('household_id', $this->householdId)
            ->whereIn('item_id', $this->entries()->select('items.id'))
            ->find($attachmentId)
            ?->delete() ?? abort(404);
    }

    /**
     * Saves the form; with $another the form stays open for the next new entry.
     */
    public function saveEntryForm(bool $another = false): void
    {
        $this->validate([
            'form.title' => ['required', 'string', 'max:255'],
            'form.tags' => ['nullable', 'string', 'max:1000'],
            'form.body' => ['nullable', 'string', 'max:100000'],
            'formDocuments' => ['array', 'max:10'],
            'formDocuments.*' => Attachment::rules(),
        ], attributes: ['form.title' => __('Title')]);

        $collection = $this->findCollection();
        $missing = $collection->properties->filter(fn (CollectionProperty $property) => $property->required
            && $property->normalizeValue($this->form['values'][$property->id] ?? null, $this->memberIds()) === null);

        if ($missing->isNotEmpty()) {
            foreach ($missing as $property) {
                $this->addError('form.values.'.$property->id, __('Please fill in “:field”.', ['field' => $property->name]));
            }

            return;
        }

        $entry = $this->formEntryId ? $this->findEntry($this->formEntryId) : new Item([
            'household_id' => $this->householdId,
            'collection_id' => $collection->id,
            'type' => $collection->isTasks() ? Item::TYPE_TODO : Item::TYPE_ENTRY,
            'visibility' => $collection->entryVisibility(),
            'created_by' => Auth::id(),
            'position' => (int) $this->entries()->max('position') + 1,
        ]);

        $entry->title = mb_substr(trim($this->form['title']), 0, 255);

        foreach ($collection->properties as $property) {
            $entry->setPropertyValue($property, $property->normalizeValue($this->form['values'][$property->id] ?? null, $this->memberIds()));
        }

        $entry->content = ['body' => (string) $this->form['body']] + ($entry->content ?? []);
        $isNew = ! $entry->exists;
        $entry->save();
        $entry->syncTagNames(explode(',', (string) $this->form['tags']));
        Attachment::attachReferencedTo($entry, Auth::user());

        foreach ($this->formDocuments as $document) {
            Attachment::storeUpload($document, (int) $this->householdId, $entry->id, (int) Auth::id());
        }

        $this->reset('formDocuments', 'aiFillMessage');
        $this->announceReschedule($entry);

        $this->dispatch('toast', message: $isNew ? __('Entry added') : __('Changes saved'));

        if ($another && $isNew) {
            $this->newEntry();

            return;
        }

        $this->closeEntryForm();
    }

    /**
     * "+" next to a select field in the form: new option, selected right away.
     */
    public function addFormOption(int $propertyId, string $name): void
    {
        $property = $this->findProperty($propertyId);
        abort_unless($property->hasOptions(), 422);

        $option = $property->addOption(mb_substr(trim($name), 0, 100));
        $this->form['values'][$property->id] = $property->type === CollectionProperty::TYPE_MULTI_SELECT
            ? [...(array) ($this->form['values'][$property->id] ?? []), $option['id']]
            : $option['id'];
        $this->schemaChanged();
    }

    /**
     * Form state for every property (multi-select and relation as lists, the rest as strings).
     *
     * @param  array<int|string, mixed>  $values
     * @return array<int, mixed>
     */
    private function formValues(Collection $collection, array $values): array
    {
        return $collection->properties->mapWithKeys(function (CollectionProperty $property) use ($values) {
            $value = $values[$property->id] ?? null;

            return [$property->id => match ($property->type) {
                CollectionProperty::TYPE_MULTI_SELECT, CollectionProperty::TYPE_RELATION => array_values((array) $value),
                CollectionProperty::TYPE_CHECKBOX => (bool) $value,
                default => $value === null ? '' : (string) $value,
            }];
        })->all();
    }

    public function toggleHiddenProperty(int $propertyId): void
    {
        $this->hiddenProperties = in_array($propertyId, $this->hiddenProperties, true)
            ? array_values(array_diff($this->hiddenProperties, [$propertyId]))
            : [...$this->hiddenProperties, $propertyId];
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
        $entry->update(['content' => ['body' => $this->body] + ($entry->content ?? [])]);
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
            $entry->update(['content' => ['body' => $body] + ($entry->content ?? [])]);
        }
    }

    public function toggleEntryVisibility(int $entryId): void
    {
        $entry = $this->findEntry($entryId);
        abort_unless($entry->visibilityCanBeChangedBy(Auth::user()) && ! $this->findCollection()->isPrivate(), 403);

        $entry->update(['visibility' => $entry->isPrivate() ? Item::VISIBILITY_SHARED : Item::VISIBILITY_PRIVATE]);
    }

    // ── Filters & saved views ────────────────────────────────────────────

    public function addFilter(): void
    {
        $property = $this->findCollection()->properties->first();
        $this->filters[] = ['property' => $property ? (string) $property->id : 'title', 'operator' => array_key_first(EntryFilters::operators($property)), 'value' => ''];
        $this->showFilters = true;
    }

    /**
     * Picking another property resets the operator to the first one that fits.
     */
    public function setFilterProperty(int $index, string $propertyId): void
    {
        $property = $propertyId === 'title' ? null : $this->findCollection()->properties->firstWhere('id', (int) $propertyId);
        $this->filters[$index] = ['property' => $property ? (string) $property->id : 'title', 'operator' => array_key_first(EntryFilters::operators($property)), 'value' => ''];
    }

    public function removeFilter(int $index): void
    {
        unset($this->filters[$index]);
        $this->filters = array_values($this->filters);
    }

    public function saveView(string $name): void
    {
        $name = mb_substr(trim($name), 0, 100);

        if ($name === '') {
            return;
        }

        $collection = $this->findCollection();
        $view = $collection->views()->create([
            'created_by' => Auth::id(),
            'name' => $name,
            'settings' => $this->currentViewSettings(),
            'position' => (int) $collection->views()->max('position') + 1,
        ]);

        $this->activeView = (string) $view->id;
    }

    /**
     * Overwrites the active view with the current layout and filters.
     */
    public function updateView(): void
    {
        $this->findView((int) $this->activeView)->update(['settings' => $this->currentViewSettings()]);
        $this->dispatch('toast', message: __('View saved.'));
    }

    public function applyView(int $viewId): void
    {
        $view = $this->findView($viewId);

        foreach (CollectionView::SETTINGS as $key) {
            $default = (new ReflectionProperty($this, $key))->getDefaultValue();
            $this->{$key} = $view->settings[$key] ?? $default;
        }

        $this->activeView = (string) $view->id;
        $this->showFilters = false;
    }

    /**
     * Back to the plain collection (no view, no filters).
     */
    public function clearView(): void
    {
        $this->reset(...CollectionView::SETTINGS);
        $this->reset('activeView');
    }

    public function deleteView(int $viewId): void
    {
        $this->findView($viewId)->delete();

        if ($this->activeView === (string) $viewId) {
            $this->reset('activeView');
        }
    }

    private function findView(int $viewId): CollectionView
    {
        return $this->findCollection()->views()->findOrFail($viewId);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentViewSettings(): array
    {
        $settings = [];

        foreach (CollectionView::SETTINGS as $key) {
            $settings[$key] = $this->{$key};
        }

        $settings['filters'] = EntryFilters::sanitize($this->filters, $this->findCollection()->properties);

        return $settings;
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
            'options' => in_array($this->newPropertyType, [CollectionProperty::TYPE_SELECT, CollectionProperty::TYPE_MULTI_SELECT], true) ? [] : null,
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
     * Moves a property one place up (-1) or down (1) in forms and views.
     */
    public function moveProperty(int $propertyId, int $direction): void
    {
        $properties = $this->findCollection()->properties->values();
        $index = $properties->search(fn (CollectionProperty $property) => $property->id === $propertyId);
        $target = $index === false ? null : $index + ($direction < 0 ? -1 : 1);

        if ($target === null || ! isset($properties[$target])) {
            return;
        }

        $ordered = $properties->all();
        [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];

        foreach ($ordered as $position => $property) {
            if ($property->position !== $position + 1) {
                $property->update(['position' => $position + 1]);
            }
        }

        $this->schemaChanged();
    }

    public function setPropertyRequired(int $propertyId, bool $required): void
    {
        $this->findProperty($propertyId)->update(['required' => $required]);
        $this->schemaChanged();
    }

    public function setPropertyHelp(int $propertyId, string $help): void
    {
        $this->findProperty($propertyId)->update(['help' => mb_substr(trim($help), 0, 255) ?: null]);
        $this->schemaChanged();
    }

    /**
     * Prefilled value for new entries. Dates accept "today" / "today+N", persons "me".
     */
    public function setPropertyDefault(int $propertyId, mixed $value): void
    {
        $property = $this->findProperty($propertyId);
        abort_if($property->type === CollectionProperty::TYPE_RELATION, 422);

        $default = match (true) {
            $value === null || $value === '' || $value === [] => null,
            $property->type === CollectionProperty::TYPE_DATE && is_string($value) && preg_match('/^today(\+\d{1,3})?$/', $value) === 1 => $value,
            $property->type === CollectionProperty::TYPE_PERSON && $value === 'me' => 'me',
            default => $property->normalizeValue($value, $this->memberIds()),
        };

        $property->update(['default_value' => $default]);
        $this->schemaChanged();
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
        abort_unless($property->hasOptions() && ! $property->isSystem(), 403);

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
            $value = $item->propertyValue($property);

            if ($value === $optionId || (is_array($value) && in_array($optionId, $value, true))) {
                $item->setPropertyValue($property, is_array($value) ? (array_values(array_diff($value, [$optionId])) ?: null) : null);
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
            'collectionDefaultView' => ['nullable', Rule::in(Collection::VIEWS)],
        ]);

        $this->findCollection()->update([
            'name' => trim($validated['collectionName']),
            'icon' => trim((string) $validated['collectionIcon']) ?: null,
            'default_view' => $validated['collectionDefaultView'] ?: null,
        ]);

        // Full navigation so the sidebar shows the new name.
        $this->redirectRoute('collections.show', ['collection' => $this->collectionId], navigate: true);
    }

    /**
     * Saves the fields and form settings (not the entries) as a template for new collections.
     */
    #[On('collection-imported')]
    public function closeImport(): void
    {
        $this->showImport = false;
    }

    public function saveAsTemplate(string $name): void
    {
        $collection = $this->findCollection();
        $name = mb_substr(trim($name), 0, 100);

        if ($name === '') {
            return;
        }

        CollectionTemplate::updateOrCreate(
            ['household_id' => $this->householdId, 'name' => $name],
            ['created_by' => Auth::id(), 'icon' => $collection->icon, 'definition' => CollectionTemplate::definitionOf($collection)],
        );

        $this->dispatch('toast', message: __('Saved as template “:name” – choose it under “New collection”.', ['name' => $name]));
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

    /**
     * Titles/icons of everything the relation properties of these entries point to (visible ones only).
     *
     * @param  \Illuminate\Support\Collection<int, CollectionProperty>  $properties
     * @param  \Illuminate\Support\Collection<int, Item>  $entries
     * @return array<string, array<string, mixed>>
     */
    private function relationNodes($properties, $entries): array
    {
        $relations = $properties->where('type', CollectionProperty::TYPE_RELATION);

        if ($relations->isEmpty()) {
            return [];
        }

        $refs = $entries->flatMap(fn (Item $entry) => $relations->flatMap(fn ($property) => (array) $entry->propertyValue($property)))
            ->map(fn ($key) => Links::parseKey($key))
            ->filter()
            ->values();

        return Links::nodes($refs, Auth::user(), (int) $this->householdId);
    }

    public function with(): array
    {
        $collection = $this->findCollection();
        $properties = $collection->properties;
        $groupProperty = $this->groupProperty($collection);
        $dateProperty = $this->dateProperty($collection);
        $view = in_array($this->viewMode, Collection::VIEWS, true)
            ? $this->viewMode
            : ($collection->default_view ?: match (true) {
                $collection->isTasks() => 'board',
                // Collections with deadlines (contracts, pantry …) read best as cards.
                $properties->contains(fn (CollectionProperty $property) => $property->remindDays() !== null) => 'cards',
                default => 'table',
            });

        if (($view === 'board' && ! $groupProperty) || ($view === 'calendar' && ! $dateProperty)) {
            $view = 'table';
        }

        $hidden = array_map('intval', $this->hiddenProperties);
        $visibleProperties = $properties->reject(fn (CollectionProperty $property) => in_array($property->id, $hidden, true))->values();

        $entries = $this->entries()->with('creator', 'tags')->withCount('attachments')->orderBy('position')->orderBy('id')->get()
            ->when($this->tag !== '', fn ($entries) => $entries->filter(fn (Item $entry) => $entry->tags->contains('name', $this->tag)))
            ->pipe(fn ($entries) => EntryFilters::apply($entries, EntryFilters::sanitize($this->filters, $properties), $properties, (int) Auth::id()))
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
                    CollectionProperty::TYPE_MULTI_SELECT => collect($sortProperty->selectOptions())->search(fn ($option) => $option['id'] === ((array) $value)[0] ?? null),
                    CollectionProperty::TYPE_RELATION => is_array($value) ? count($value) : null,
                    default => is_string($value) ? mb_strtolower($value) : $value,
                };
            };

            // Empty values always go last, whatever the direction.
            [$filled, $empty] = $entries->partition(fn (Item $entry) => $key($entry) !== null && $key($entry) !== false);
            $entries = ($this->direction === 'desc' ? $filled->sortByDesc($key) : $filled->sortBy($key))->concat($empty);
        }

        $openEntry = $this->openEntryId !== '' && ctype_digit($this->openEntryId)
            ? $this->entries()->with('creator', 'tags', 'attachments')->find((int) $this->openEntryId)
            : null;

        return [
            'collection' => $collection,
            'properties' => $properties,
            'visibleProperties' => $visibleProperties,
            'memberNames' => \App\Models\User::whereIn('id', $this->memberIds())->pluck('name', 'id')->all(),
            'formEntry' => $this->showEntryForm && $this->formEntryId ? $this->entries()->find($this->formEntryId) : null,
            'entries' => $entries->values(),
            'currentView' => $view,
            'dateProperty' => $dateProperty,
            'dateProperties' => $properties->where('type', CollectionProperty::TYPE_DATE)->values(),
            'calendar' => $view === 'calendar' ? $this->calendarData($entries->values(), $dateProperty, $collection->isTasks() ? $collection->systemProperty('repeat') : null) : null,
            'groupProperty' => $groupProperty,
            'selectProperties' => $properties->where('type', CollectionProperty::TYPE_SELECT)->values(),
            'members' => \App\Models\User::whereIn('id', $this->memberIds())->orderBy('name')->get(['id', 'name']),
            'openEntry' => $openEntry,
            'savedViews' => $collection->views,
            'aiAvailable' => BrainAi::isConfigured(),
            'activeViewModel' => $this->activeView !== '' ? $collection->views->firstWhere('id', (int) $this->activeView) : null,
            'filterRows' => collect($this->filters)->map(function ($filter) use ($properties) {
                $property = ($filter['property'] ?? 'title') === 'title' ? null : $properties->firstWhere('id', (int) $filter['property']);

                return ['filter' => $filter, 'property' => $property, 'operators' => EntryFilters::operators($property)];
            })->all(),
            'relationNodes' => $this->relationNodes($properties, $entries->concat($openEntry ? [$openEntry] : [])),
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
                CollectionProperty::TYPE_MULTI_SELECT => __('Multi-select'),
                CollectionProperty::TYPE_RELATION => __('Relation'),
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
                            'cards' => '▦ '.__('Cards'),
                            'list' => '☰ '.__('List'),
                            'table' => '▤ '.__('Table'),
                            'board' => $selectProperties->isNotEmpty() ? '▥ '.__('Board') : null,
                            'calendar' => $dateProperty ? '📅 '.__('Calendar') : null,
                        ]);
                    @endphp
                    @if (count($views) > 1)
                        <div class="inline-flex rounded-md border border-gray-300 dark:border-gray-600 overflow-hidden text-sm" role="group" aria-label="{{ __('View') }}">
                            @if ($collection->isTasks())
                                <a href="{{ route('tasks.index') }}" wire:navigate class="bg-white px-3 py-1.5 text-gray-700 dark:bg-gray-800 dark:text-gray-300">✅ {{ __('Task list') }}</a>
                            @endif
                            @foreach ($views as $viewKey => $viewLabel)
                                <button type="button" wire:click="$set('viewMode', '{{ $viewKey }}')" @class(['px-3 py-1.5', 'bg-indigo-600 text-white' => $currentView === $viewKey, 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300' => $currentView !== $viewKey]) aria-pressed="{{ $currentView === $viewKey ? 'true' : 'false' }}">{{ $viewLabel }}</button>
                            @endforeach
                        </div>
                    @endif
                    @if (in_array($currentView, ['table', 'cards', 'list'], true) && $properties->isNotEmpty())
                        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.stop="open = false">
                            <x-secondary-button type="button" x-on:click="open = ! open" ::aria-expanded="open">👁 {{ __('Fields') }}{{ $hiddenProperties !== [] ? ' ('.$visibleProperties->count().'/'.$properties->count().')' : '' }}</x-secondary-button>
                            <div x-show="open" x-cloak class="absolute end-0 z-30 mt-1 w-60 rounded-lg bg-white dark:bg-gray-800 p-2 shadow-lg ring-1 ring-gray-200 dark:ring-gray-700">
                                <p class="px-2 pb-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Shown in this view') }}</p>
                                @foreach ($properties as $property)
                                    <label wire:key="field-toggle-{{ $property->id }}" class="flex items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <input type="checkbox" @checked(! in_array($property->id, array_map('intval', $hiddenProperties), true)) wire:click="toggleHiddenProperty({{ $property->id }})" class="rounded border-gray-300 dark:border-gray-600 text-indigo-600" />
                                        <span class="text-gray-700 dark:text-gray-200">{{ $property->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <x-secondary-button type="button" wire:click="$set('showImport', true)" title="{{ __('Import CSV or Excel') }}">⬆ <span class="ms-1 hidden sm:inline">{{ __('Import') }}</span></x-secondary-button>
                    <x-secondary-button type="button" wire:click="$toggle('showSettings')">⚙ {{ __('Properties') }}</x-secondary-button>
                </div>
            </div>

            @include('collections.partials.settings')

            {{-- Saved views and filters --}}
            <div class="flex flex-wrap items-center gap-1 border-b border-gray-200 dark:border-gray-700 pb-2" role="tablist" aria-label="{{ __('Views') }}">
                <button type="button" role="tab" aria-selected="{{ $activeView === '' ? 'true' : 'false' }}" wire:click="clearView" @class(['rounded-md px-3 py-1 text-sm', 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' => $activeView === '', 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' => $activeView !== ''])>{{ __('All') }}</button>
                @foreach ($savedViews as $savedView)
                    <span wire:key="view-{{ $savedView->id }}" class="group inline-flex items-center">
                        <button type="button" role="tab" aria-selected="{{ $activeView === (string) $savedView->id ? 'true' : 'false' }}" wire:click="applyView({{ $savedView->id }})" @class(['rounded-md px-3 py-1 text-sm', 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' => $activeView === (string) $savedView->id, 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' => $activeView !== (string) $savedView->id])>{{ $savedView->name }}</button>
                        <button type="button" wire:click="deleteView({{ $savedView->id }})" wire:confirm="{{ __('Delete this view?') }}" class="px-1 text-xs text-gray-400 opacity-0 group-hover:opacity-100 focus:opacity-100 hover:text-red-600" aria-label="{{ __('Delete view :name', ['name' => $savedView->name]) }}">✕</button>
                    </span>
                @endforeach
                <span class="flex-1"></span>
                <button type="button" wire:click="$toggle('showFilters')" @class(['rounded-md px-3 py-1 text-sm ring-1', 'bg-indigo-50 text-indigo-700 ring-indigo-300 dark:bg-indigo-900/40 dark:text-indigo-200' => $filterRows !== [], 'text-gray-600 dark:text-gray-300 ring-gray-300 dark:ring-gray-600' => $filterRows === []]) aria-expanded="{{ $showFilters ? 'true' : 'false' }}">
                    ⚲ {{ __('Filter') }}@if ($filterRows !== []) ({{ count($filterRows) }})@endif
                </button>
                @if ($activeViewModel)
                    <button type="button" wire:click="updateView" class="rounded-md px-3 py-1 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">💾 {{ __('Update view') }}</button>
                @endif
                <button type="button" x-data x-on:click="const name = window.prompt(@js(__('Name of the view, e.g. “My tasks this week”:'))); if (name && name.trim()) { $wire.saveView(name.trim()) }" class="rounded-md px-3 py-1 text-sm text-indigo-600 dark:text-indigo-400 hover:bg-gray-100 dark:hover:bg-gray-700">+ {{ __('Save as view') }}</button>
            </div>

            @if ($showFilters || $filterRows !== [])
                <div class="rounded-lg bg-white dark:bg-gray-800 p-3 shadow-sm space-y-2" aria-label="{{ __('Filter') }}">
                    @foreach ($filterRows as $index => $row)
                        <div wire:key="filter-{{ $index }}" class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="w-10 text-xs text-gray-500">{{ $index === 0 ? __('Where') : __('and') }}</span>
                            <select wire:change="setFilterProperty({{ $index }}, $event.target.value)" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 py-1 text-sm" aria-label="{{ __('Property') }}">
                                <option value="title" @selected($row['property'] === null)>{{ __('Title') }}</option>
                                @foreach ($properties as $property)
                                    <option value="{{ $property->id }}" @selected($row['property']?->id === $property->id)>{{ $property->name }}</option>
                                @endforeach
                            </select>
                            <select wire:model.live="filters.{{ $index }}.operator" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 py-1 text-sm" aria-label="{{ __('Condition') }}">
                                @foreach ($row['operators'] as $operator => $operatorLabel)
                                    <option value="{{ $operator }}">{{ $operatorLabel }}</option>
                                @endforeach
                            </select>
                            @if (\App\Support\EntryFilters::needsValue($row['filter']['operator'] ?? ''))
                                @if ($row['property'] && $row['property']->hasOptions())
                                    <select wire:model.live="filters.{{ $index }}.value" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 py-1 text-sm" aria-label="{{ __('Value') }}">
                                        <option value="">—</option>
                                        @foreach ($row['property']->selectOptions() as $option)
                                            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($row['property'] && $row['property']->type === \App\Models\CollectionProperty::TYPE_PERSON)
                                    <select wire:model.live="filters.{{ $index }}.value" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 py-1 text-sm" aria-label="{{ __('Value') }}">
                                        <option value="">—</option>
                                        @foreach ($members as $member)
                                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <input
                                        type="{{ $row['property']?->type === \App\Models\CollectionProperty::TYPE_DATE ? 'date' : ($row['property']?->type === \App\Models\CollectionProperty::TYPE_NUMBER ? 'number' : 'text') }}"
                                        wire:model.live.debounce.400ms="filters.{{ $index }}.value"
                                        class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 py-1 text-sm"
                                        aria-label="{{ __('Value') }}"
                                    />
                                @endif
                            @endif
                            <button type="button" wire:click="removeFilter({{ $index }})" class="text-gray-400 hover:text-red-600" aria-label="{{ __('Remove filter') }}">✕</button>
                        </div>
                    @endforeach
                    <button type="button" wire:click="addFilter" class="text-sm text-indigo-600 dark:text-indigo-400 underline">+ {{ __('Add filter') }}</button>
                </div>
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
                <x-primary-button type="button" wire:click="newEntry" class="shrink-0">＋ {{ $collection->isTasks() ? __('New task') : __('New entry') }}</x-primary-button>
                <form wire:submit="addEntry()" class="flex flex-1 min-w-[14rem] gap-2">
                    <x-text-input id="new-entry-title" wire:model="newTitle" class="block w-full" placeholder="{{ __('Quick add: just type a title … (#tags optional)') }}" aria-label="{{ __('Quick add') }}" />
                    <x-voice-button for="new-entry-title" :submit="true" />
                    <x-secondary-button type="submit" class="shrink-0">{{ __('Add') }}</x-secondary-button>
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

            @php
                $toneClasses = [
                    'danger' => 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300',
                    'warning' => 'bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200',
                    'success' => 'text-green-700 dark:text-green-400',
                    'muted' => 'text-gray-400',
                    'normal' => 'text-gray-700 dark:text-gray-200',
                ];
                $deadlineProperty = $visibleProperties->first(fn ($property) => $property->type === \App\Models\CollectionProperty::TYPE_DATE && ($property->remindDays() !== null || $property->system_key === 'due'))
                    ?? $visibleProperties->firstWhere('type', \App\Models\CollectionProperty::TYPE_DATE);
                $badgeProperty = $visibleProperties->firstWhere('type', \App\Models\CollectionProperty::TYPE_SELECT);
                $sumProperties = $visibleProperties->where('type', \App\Models\CollectionProperty::TYPE_NUMBER);
            @endphp

            @if (in_array($currentView, ['cards', 'list'], true) && $entries->isNotEmpty())
                {{-- Summary: count and sums --}}
                <div class="flex flex-wrap items-center gap-x-5 gap-y-1 px-1 text-sm text-gray-500 dark:text-gray-400">
                    <span>{{ trans_choice(':count entry|:count entries', $entries->count()) }}</span>
                    @foreach ($sumProperties as $sumProperty)
                        @php $sum = $entries->sum(fn ($entry) => is_numeric($entry->propertyValue($sumProperty)) ? (float) $entry->propertyValue($sumProperty) : 0) @endphp
                        <span wire:key="sum-{{ $sumProperty->id }}">Σ {{ $sumProperty->name }}: <strong class="text-gray-800 dark:text-gray-100">{{ \App\Support\PropertyDisplay::number($sum, \App\Support\PropertyDisplay::isCurrency($sumProperty)) }}</strong></span>
                    @endforeach
                </div>
            @endif

            @if ($currentView === 'cards')
                {{-- Cards --}}
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @forelse ($entries as $entry)
                        @php
                            $badge = $badgeProperty ? \App\Support\PropertyDisplay::format($badgeProperty, $entry) : null;
                            $deadline = $deadlineProperty ? \App\Support\PropertyDisplay::format($deadlineProperty, $entry) : null;
                            $rows = $visibleProperties
                                ->reject(fn ($property) => $property->is($badgeProperty) || $property->is($deadlineProperty))
                                ->map(fn ($property) => ['property' => $property, 'display' => \App\Support\PropertyDisplay::format($property, $entry, $memberNames, $relationNodes)])
                                ->filter(fn ($row) => $row['display'] !== null)
                                ->take(5);
                            $isDone = $collection->isTasks() && $entry->done;
                        @endphp
                        <button type="button" wire:key="card-{{ $entry->id }}" wire:click="openEntry({{ $entry->id }})" class="group flex flex-col gap-3 rounded-2xl bg-white p-4 text-start shadow-sm ring-1 ring-gray-200 transition hover:shadow-md hover:ring-indigo-300 dark:bg-gray-800 dark:ring-gray-700 dark:hover:ring-indigo-500">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p @class(['font-semibold leading-snug break-words', 'line-through text-gray-400' => $isDone, 'text-gray-900 dark:text-gray-100' => ! $isDone])>{{ $entry->title }}</p>
                                    @if ($entry->tags->isNotEmpty() || ($entry->isPrivate() && ! $collection->isPrivate()))
                                        <p class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-indigo-600 dark:text-indigo-300">
                                            @foreach ($entry->tags->sortBy('name') as $entryTag)
                                                <span>#{{ $entryTag->name }}</span>
                                            @endforeach
                                            @if ($entry->isPrivate() && ! $collection->isPrivate())
                                                <x-private-badge />
                                            @endif
                                        </p>
                                    @endif
                                </div>
                                <span class="flex shrink-0 items-center gap-1.5">
                                    @if ($entry->attachments_count > 0)
                                        <span class="text-xs text-gray-400" title="{{ __('Documents') }}">📎 {{ $entry->attachments_count }}</span>
                                    @endif
                                    @if ($badge)
                                        <x-option-badge :option="$badge['options'][0]" />
                                    @endif
                                </span>
                            </div>

                            @if ($rows->isNotEmpty())
                                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                                    @foreach ($rows as $row)
                                        <dt class="text-gray-500 dark:text-gray-400">{{ $row['property']->name }}</dt>
                                        <dd class="min-w-0 truncate {{ $toneClasses[$row['display']['tone']] ?? '' }}">
                                            @if ($row['display']['options'] !== [])
                                                <span class="inline-flex flex-wrap gap-1">@foreach ($row['display']['options'] as $option)<x-option-badge :option="$option" />@endforeach</span>
                                            @else
                                                {{ $row['display']['text'] }}
                                            @endif
                                        </dd>
                                    @endforeach
                                </dl>
                            @endif

                            @if ($deadline)
                                <p class="mt-auto flex flex-wrap items-center gap-x-2 rounded-lg px-3 py-2 text-sm {{ $deadline['tone'] === 'normal' ? 'bg-gray-50 text-gray-700 dark:bg-gray-900/40 dark:text-gray-200' : $toneClasses[$deadline['tone']] }}">
                                    <span aria-hidden="true">{{ $deadlineProperty->remindDays() !== null ? '🔔' : '📅' }}</span>
                                    <span class="text-xs opacity-80">{{ $deadlineProperty->name }}</span>
                                    <span class="font-medium">{{ $deadline['text'] }}</span>
                                    <span class="text-xs opacity-80">· {{ $deadline['hint'] }}</span>
                                </p>
                            @endif
                        </button>
                    @empty
                        <div class="sm:col-span-2 xl:col-span-3 rounded-2xl border-2 border-dashed border-gray-200 p-10 text-center dark:border-gray-700">
                            <p class="text-gray-500 dark:text-gray-400">{{ $search !== '' ? __('No entries match your filter.') : __('No entries yet.') }}</p>
                            <x-primary-button type="button" wire:click="newEntry" class="mt-3">＋ {{ __('New entry') }}</x-primary-button>
                        </div>
                    @endforelse
                </div>
            @elseif ($currentView === 'list')
                {{-- List --}}
                <ul class="divide-y divide-gray-100 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 dark:divide-gray-700 dark:bg-gray-800 dark:ring-gray-700">
                    @forelse ($entries as $entry)
                        @php
                            $deadline = $deadlineProperty ? \App\Support\PropertyDisplay::format($deadlineProperty, $entry) : null;
                            $secondary = $visibleProperties
                                ->reject(fn ($property) => $property->is($deadlineProperty))
                                ->map(fn ($property) => \App\Support\PropertyDisplay::format($property, $entry, $memberNames, $relationNodes))
                                ->filter()
                                ->take(3);
                            $isDone = $collection->isTasks() && $entry->done;
                        @endphp
                        <li wire:key="list-{{ $entry->id }}">
                            <button type="button" wire:click="openEntry({{ $entry->id }})" class="flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <div class="min-w-0 flex-1">
                                    <p @class(['truncate font-medium', 'line-through text-gray-400' => $isDone, 'text-gray-900 dark:text-gray-100' => ! $isDone])>{{ $entry->title }}{{ $entry->attachments_count > 0 ? ' 📎' : '' }}</p>
                                    @if ($secondary->isNotEmpty())
                                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                            @foreach ($secondary as $display)
                                                @if ($display['options'] !== [])
                                                    @foreach ($display['options'] as $option)<x-option-badge :option="$option" />@endforeach
                                                @else
                                                    <span>{{ $display['text'] }}</span>
                                                @endif
                                                @if (! $loop->last)<span aria-hidden="true">·</span>@endif
                                            @endforeach
                                        </p>
                                    @endif
                                </div>
                                @if ($deadline)
                                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs {{ $deadline['tone'] === 'normal' ? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' : $toneClasses[$deadline['tone']] }}" title="{{ $deadlineProperty->name }}: {{ $deadline['text'] }}">{{ $deadline['text'] }} · {{ $deadline['hint'] }}</span>
                                @endif
                                <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">›</span>
                            </button>
                        </li>
                    @empty
                        <li class="p-10 text-center">
                            <p class="text-gray-500 dark:text-gray-400">{{ $search !== '' ? __('No entries match your filter.') : __('No entries yet.') }}</p>
                            <x-primary-button type="button" wire:click="newEntry" class="mt-3">＋ {{ __('New entry') }}</x-primary-button>
                        </li>
                    @endforelse
                </ul>
            @elseif ($currentView === 'table')
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
                                @foreach ($visibleProperties as $property)
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
                                    @foreach ($visibleProperties as $property)
                                        <td class="px-1 py-1" wire:key="cell-{{ $entry->id }}-{{ $property->id }}">
                                            <x-property-input :property="$property" :item="$entry" :members="$members" :nodes="$relationNodes" compact />
                                        </td>
                                    @endforeach
                                    <td class="px-3 py-1.5 text-end">
                                        <button type="button" wire:click="deleteEntry({{ $entry->id }})" wire:confirm="{{ __('Delete this entry?') }}" class="text-gray-400 hover:text-red-600" title="{{ __('Delete') }}">✕</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $visibleProperties->count() + 3 }}" class="px-3 py-6 text-center text-gray-500 dark:text-gray-400">
                                        {{ $search !== '' ? __('No entries match your filter.') : __('No entries yet.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($entries->isNotEmpty() && $visibleProperties->contains(fn ($property) => in_array($property->type, [\App\Models\CollectionProperty::TYPE_NUMBER, \App\Models\CollectionProperty::TYPE_CHECKBOX], true)))
                            <tfoot class="border-t-2 border-gray-200 dark:border-gray-700 text-xs text-gray-600 dark:text-gray-300">
                                <tr>
                                    @if ($collection->isTasks())
                                        <td></td>
                                    @endif
                                    <td class="px-3 py-2 font-medium">{{ trans_choice(':count entry|:count entries', $entries->count()) }}</td>
                                    @foreach ($visibleProperties as $property)
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
                                            wire:click="newEntry(null, '{{ $day['date'] }}')"
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
                        @php $cards = $entries->filter(fn ($entry) => (string) $entry->propertyValue($groupProperty) === $column['id']); @endphp
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
                                                @php $value = $card->propertyValue($property); @endphp
                                                @continue($value === null)
                                                @switch($property->type)
                                                    @case(\App\Models\CollectionProperty::TYPE_SELECT)
                                                        @if ($option = $property->option($value))
                                                            <x-option-badge :option="$option" />
                                                        @endif
                                                        @break
                                                    @case(\App\Models\CollectionProperty::TYPE_MULTI_SELECT)
                                                        @foreach ((array) $value as $optionId)
                                                            @if ($option = $property->option($optionId))
                                                                <x-option-badge :option="$option" />
                                                            @endif
                                                        @endforeach
                                                        @break
                                                    @case(\App\Models\CollectionProperty::TYPE_RELATION)
                                                        @foreach ((array) $value as $key)
                                                            @if ($node = $relationNodes[$key] ?? null)
                                                                <span class="rounded bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5">{{ $node['icon'] }} {{ $node['title'] }}</span>
                                                            @endif
                                                        @endforeach
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

                            <form x-data="{ title: '' }" x-on:submit.prevent="if (title.trim()) { $wire.addEntry(@js($column['id'] ?: null), title); title = '' }" class="mt-2 flex items-center gap-1">
                                <input x-model="title" type="text" placeholder="+ {{ __('New') }}" aria-label="{{ __('New entry in this column') }}" class="block w-full rounded-md border-transparent bg-transparent text-sm placeholder-gray-500 focus:border-indigo-500 focus:bg-white dark:focus:bg-gray-900 focus:ring-indigo-500" />
                                <button type="button" wire:click="newEntry(@js($column['id'] ?: null))" class="shrink-0 rounded-md px-2 py-1 text-xs text-gray-500 hover:bg-white dark:hover:bg-gray-800" title="{{ __('New entry with form') }}" aria-label="{{ __('New entry with form') }}">⤢</button>
                            </form>
                        </section>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Entry detail (popup) --}}
    @if ($openEntry && ! $showEntryForm)
        @php
            $details = $properties->map(fn ($property) => ['property' => $property, 'display' => \App\Support\PropertyDisplay::format($property, $openEntry, $memberNames, $relationNodes)]);
            $filled = $details->filter(fn ($row) => $row['display'] !== null);
            $emptyCount = $details->count() - $filled->count();
            $detailTones = [
                'danger' => 'text-red-700 dark:text-red-300',
                'warning' => 'text-amber-700 dark:text-amber-300',
                'success' => 'text-green-700 dark:text-green-400',
                'muted' => 'text-gray-400',
                'normal' => 'text-gray-900 dark:text-gray-100',
            ];
        @endphp
        <x-sheet close="closeEntry" :label="$openEntry->title" wire:key="entry-sheet-{{ $openEntry->id }}">
            <x-slot:header>
                <span aria-hidden="true">{{ $collection->displayIcon() }}</span>
                <span class="truncate">{{ $collection->name }}</span>
                @if ($openEntry->isPrivate() && ! $collection->isPrivate())
                    <x-private-badge />
                @endif
            </x-slot:header>

            <div class="space-y-6">
                <div class="flex items-start gap-3">
                    @if ($collection->isTasks())
                        <button type="button" wire:click="toggleDone({{ $openEntry->id }})" role="checkbox" aria-checked="{{ $openEntry->done ? 'true' : 'false' }}" aria-label="{{ __('Mark as done') }}" @class([
                            'mt-1.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 text-sm',
                            'border-green-600 bg-green-600 text-white' => $openEntry->done,
                            'border-gray-300 hover:bg-green-50 dark:border-gray-500' => ! $openEntry->done,
                        ])>{{ $openEntry->done ? '✓' : '' }}</button>
                    @endif
                    <h2 @class(['text-2xl font-bold leading-tight break-words', 'line-through text-gray-400' => $collection->isTasks() && $openEntry->done, 'text-gray-900 dark:text-gray-100' => ! ($collection->isTasks() && $openEntry->done)])>{{ $openEntry->title }}</h2>
                </div>

                @if ($filled->isNotEmpty())
                    <dl class="grid gap-3 sm:grid-cols-2">
                        @foreach ($filled as $row)
                            <div wire:key="detail-{{ $row['property']->id }}" @class(['rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-900/40', 'sm:col-span-2' => in_array($row['property']->type, [\App\Models\CollectionProperty::TYPE_MULTI_SELECT, \App\Models\CollectionProperty::TYPE_RELATION, \App\Models\CollectionProperty::TYPE_TEXT], true) && mb_strlen($row['display']['text']) > 40])>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $row['property']->name }}</dt>
                                <dd class="mt-1 {{ $detailTones[$row['display']['tone']] ?? '' }}">
                                    @if ($row['display']['options'] !== [])
                                        <span class="inline-flex flex-wrap gap-1.5">@foreach ($row['display']['options'] as $option)<x-option-badge :option="$option" />@endforeach</span>
                                    @elseif ($row['display']['url'])
                                        <a href="{{ $row['display']['url'] }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 underline dark:text-indigo-400">{{ $row['display']['text'] }} ↗</a>
                                    @elseif ($row['display']['nodes'] !== [])
                                        <span class="inline-flex flex-wrap gap-1.5">
                                            @foreach ($row['display']['nodes'] as $node)
                                                <a href="{{ $node['url'] }}" wire:navigate class="rounded-full bg-white px-2.5 py-0.5 text-sm ring-1 ring-gray-200 hover:ring-indigo-300 dark:bg-gray-800 dark:ring-gray-700">{{ $node['icon'] }} {{ $node['title'] }}</a>
                                            @endforeach
                                        </span>
                                    @else
                                        <span class="font-medium">{{ $row['display']['text'] }}</span>
                                        @if ($row['display']['hint'])
                                            <span class="text-sm opacity-80">· {{ $row['display']['hint'] }}</span>
                                        @endif
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
                @if ($emptyCount > 0)
                    <button type="button" wire:click="editEntry({{ $openEntry->id }})" class="-mt-3 text-sm text-gray-400 hover:text-indigo-600">{{ trans_choice(':count empty field – fill in|:count empty fields – fill in', $emptyCount) }} →</button>
                @endif

                <div>
                    <h3 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"># {{ __('Tags') }}</h3>
                        <div class="flex gap-1.5">
                            <input
                                type="text"
                                value="{{ $openEntry->tags->sortBy('name')->pluck('name')->implode(', ') }}"
                                wire:change="updateTags({{ $openEntry->id }}, $event.target.value)"
                                wire:key="panel-tags-{{ $openEntry->id }}-{{ md5($openEntry->tags->sortBy('name')->pluck('name')->implode(',')) }}"
                                placeholder="{{ __('e.g. garden, urgent') }}"
                                aria-label="{{ __('Tags') }}"
                                class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 py-1 text-sm"
                            />
                            @if ($aiAvailable)
                                <button type="button" wire:click="suggestEntryTags({{ $openEntry->id }})" wire:loading.attr="disabled" wire:target="suggestEntryTags" title="{{ __('Suggest tags') }}" aria-label="{{ __('Suggest tags') }}" class="shrink-0 rounded-md px-2 text-sm ring-1 ring-gray-300 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <span wire:loading.remove wire:target="suggestEntryTags">✨</span>
                                    <span wire:loading wire:target="suggestEntryTags">…</span>
                                </button>
                            @endif
                        </div>
                        @if ($entryTagSuggestions !== [])
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                @foreach ($entryTagSuggestions as $suggestion)
                                    <button type="button" wire:key="entry-tag-suggestion-{{ $suggestion }}" wire:click="addEntryTag({{ $openEntry->id }}, @js($suggestion))" class="rounded-full bg-indigo-50 dark:bg-indigo-900/40 px-2.5 py-0.5 text-xs text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100">+ #{{ $suggestion }}</button>
                                @endforeach
                            </div>
                        @endif
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">📎 {{ __('Documents') }}</h3>
                        <label class="cursor-pointer text-sm text-indigo-600 hover:underline dark:text-indigo-400">
                            + {{ __('Add files') }}
                            <input type="file" multiple wire:model="documentUploads" accept=".{{ implode(',.', \App\Models\Attachment::EXTENSIONS) }}" class="sr-only" />
                        </label>
                    </div>
                    <p wire:loading wire:target="documentUploads" class="text-xs text-gray-500">{{ __('Uploading …') }}</p>
                    <x-input-error :messages="array_merge($errors->get('documentUploads'), $errors->get('documentUploads.*'))" />
                    @if ($openEntry->attachments->isNotEmpty())
                        <ul class="grid gap-2 sm:grid-cols-2">
                            @foreach ($openEntry->attachments->sortByDesc('id') as $document)
                                <li wire:key="document-{{ $document->id }}" class="group flex items-center gap-3 rounded-xl bg-gray-50 p-2 dark:bg-gray-900/40">
                                    <a href="{{ $document->url() }}" target="_blank" rel="noopener" class="flex min-w-0 flex-1 items-center gap-3">
                                        @if ($document->isImage())
                                            <img src="{{ $document->url() }}" alt="" class="h-12 w-12 shrink-0 rounded-lg object-cover" loading="lazy" />
                                        @else
                                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-white text-2xl ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" aria-hidden="true">{{ $document->isPdf() ? '📄' : '📎' }}</span>
                                        @endif
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-medium text-gray-800 dark:text-gray-100">{{ $document->original_name }}</span>
                                            <span class="block text-xs text-gray-500">{{ $document->sizeLabel() }} · {{ $document->created_at->translatedFormat('j. M Y') }}</span>
                                        </span>
                                    </a>
                                    <button type="button" wire:click="deleteDocument({{ $document->id }})" wire:confirm="{{ __('Delete this document?') }}" class="px-1 text-gray-400 hover:text-red-600 sm:opacity-0 sm:group-hover:opacity-100" aria-label="{{ __('Delete') }}">×</button>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-sm text-gray-400">{{ __('No documents yet.') }}</p>
                    @endif
                </div>

                <div class="border-t border-gray-100 pt-4 dark:border-gray-700">
                    <livewire:links-panel type="item" :id="$openEntry->id" :wire:key="'links-entry-'.$openEntry->id" />
                </div>

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

                <div class="border-t border-gray-100 pt-4 dark:border-gray-700">
                    <livewire:entries.activity :item-id="$openEntry->id" :wire:key="'activity-entry-'.$openEntry->id" />
                </div>

                <p class="text-xs text-gray-400">
                    {{ __('Created') }} {{ $openEntry->created_at->translatedFormat('j. F Y') }}@if ($openEntry->creator) · {{ $openEntry->creator->name }}@endif
                    @if (! $collection->isPrivate() && $openEntry->visibilityCanBeChangedBy(auth()->user()))
                        · <button type="button" wire:click="toggleEntryVisibility({{ $openEntry->id }})" class="underline hover:text-gray-600">{{ $openEntry->isPrivate() ? __('Private – share with household') : __('Shared – make private') }}</button>
                    @endif
                </p>
            </div>

            <x-slot:footer>
                <x-primary-button type="button" wire:click="editEntry({{ $openEntry->id }})">✎ {{ __('Edit') }}</x-primary-button>
                <x-secondary-button type="button" wire:click="addToShoppingList({{ $openEntry->id }})" title="{{ __('Add to shopping list') }}">🛒 <span class="hidden sm:inline ms-1">{{ __('Shopping list') }}</span></x-secondary-button>
                <button type="button" wire:click="deleteEntry({{ $openEntry->id }})" wire:confirm="{{ __('Delete this entry?') }}" class="ms-auto rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">🗑 {{ __('Delete') }}</button>
            </x-slot:footer>
        </x-sheet>
    @endif

    {{-- Entry form (popup): new entry or edit --}}
    @if ($showEntryForm)
        <x-sheet close="closeEntryForm" :label="$formEntryId ? __('Edit entry') : __('New entry')" wire:key="entry-form-{{ $formEntryId ?? 'new' }}">
            <x-slot:header>
                <span aria-hidden="true">{{ $collection->displayIcon() }}</span>
                <span class="truncate">{{ $collection->name }} · <strong class="text-gray-800 dark:text-gray-100">{{ $formEntryId ? __('Edit entry') : ($collection->isTasks() ? __('New task') : __('New entry')) }}</strong></span>
            </x-slot:header>

            <form id="entry-form" wire:submit="saveEntryForm" class="space-y-5">
                <div>
                    <label for="form-title" class="sr-only">{{ __('Title') }}</label>
                    <div class="flex gap-2">
                        <input id="form-title" type="text" wire:model="form.title" autofocus x-init="$nextTick(() => $el.focus())" placeholder="{{ __('Title') }}" class="block w-full rounded-lg border-gray-300 text-lg font-semibold shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                        <x-voice-button for="form-title" />
                    </div>
                    <x-input-error :messages="$errors->get('form.title')" class="mt-1" />
                </div>

                @if ($properties->isNotEmpty())
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($properties as $property)
                            <x-property-field :property="$property" :members="$members" :nodes="$relationNodes" wire:key="form-field-{{ $property->id }}" />
                        @endforeach
                    </div>
                @endif

                <div class="rounded-xl border border-dashed border-gray-300 p-4 dark:border-gray-600">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">📎 {{ __('Documents') }}</p>
                        <label class="cursor-pointer rounded-lg px-3 py-1.5 text-sm text-indigo-600 ring-1 ring-indigo-200 hover:bg-indigo-50 dark:text-indigo-300 dark:ring-indigo-800 dark:hover:bg-gray-700">
                            + {{ __('Add files') }}
                            <input type="file" multiple wire:model="formDocuments" accept=".{{ implode(',.', \App\Models\Attachment::EXTENSIONS) }}" class="sr-only" />
                        </label>
                    </div>
                    <p wire:loading wire:target="formDocuments" class="mt-2 text-xs text-gray-500">{{ __('Uploading …') }}</p>
                    @if ($formDocuments !== [])
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($formDocuments as $index => $document)
                                <li wire:key="form-document-{{ $index }}" class="flex items-center gap-2">
                                    <span aria-hidden="true">{{ str_starts_with((string) $document->getMimeType(), 'image/') ? '🖼️' : '📄' }}</span>
                                    <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-200">{{ $document->getClientOriginalName() }}</span>
                                    <button type="button" wire:click="removeFormDocument({{ $index }})" class="text-gray-400 hover:text-red-600" aria-label="{{ __('Remove') }}">×</button>
                                </li>
                            @endforeach
                        </ul>
                        @if ($aiAvailable)
                            <button type="button" wire:click="fillFormFromDocument" wire:loading.attr="disabled" wire:target="fillFormFromDocument" class="mt-3 inline-flex items-center gap-2 rounded-lg bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-900/40 dark:text-indigo-200">
                                <span wire:loading.remove wire:target="fillFormFromDocument">✨ {{ __('Fill in from document') }}</span>
                                <span wire:loading wire:target="fillFormFromDocument">{{ __('Reading the document …') }}</span>
                            </button>
                        @endif
                    @else
                        <p class="mt-1 text-xs text-gray-400">{{ $aiAvailable ? __('Contract, policy, invoice or photo – the AI can fill in the fields from it.') : __('Contract, policy, invoice or photo.') }}</p>
                    @endif
                    @if ($aiFillMessage)
                        <p class="mt-2 text-sm text-gray-700 dark:text-gray-200" role="status">{{ $aiFillMessage }}</p>
                    @endif
                    <x-input-error :messages="array_merge($errors->get('formDocuments'), $errors->get('formDocuments.*'))" class="mt-1" />
                </div>

                <div>
                    <label for="form-tags" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"># {{ __('Tags') }}</label>
                    <input id="form-tags" type="text" wire:model="form.tags" placeholder="{{ __('e.g. garden, urgent') }}" class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                </div>

                <div>
                    <label for="form-body" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">📝 {{ __('Notes') }}</label>
                    <textarea id="form-body" rows="4" wire:model="form.body" placeholder="{{ __('Notes, details, checklists (- [ ] …) …') }}" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                    <p class="mt-1 text-xs text-gray-400">{{ __('Images and files can be added in the entry afterwards (Notes → Edit).') }}</p>
                </div>
            </form>

            <x-slot:footer>
                <x-primary-button type="submit" form="entry-form" wire:loading.attr="disabled" wire:target="saveEntryForm">{{ __('Save') }}</x-primary-button>
                @if (! $formEntryId)
                    <x-secondary-button type="button" wire:click="saveEntryForm(true)">{{ __('Save & add another') }}</x-secondary-button>
                @endif
                <button type="button" wire:click="closeEntryForm" class="ms-auto rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">{{ __('Cancel') }}</button>
            </x-slot:footer>
        </x-sheet>
    @endif
    @if ($showImport)
        <x-sheet close="closeImport" :label="__('Import entries')" wire:key="import-sheet">
            <x-slot:header>
                <span aria-hidden="true">{{ $collection->displayIcon() }}</span>
                <span class="truncate">{{ $collection->name }} · <strong class="text-gray-800 dark:text-gray-100">{{ __('Import entries') }}</strong></span>
            </x-slot:header>
            <livewire:collections.import :collection-id="$collection->id" :wire:key="'import-'.$collection->id" />
        </x-sheet>
    @endif
</div>
