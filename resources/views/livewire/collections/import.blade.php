<?php

use App\Events\HouseholdItemsChanged;
use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Support\Import\CollectionImporter;
use App\Support\Import\TableFileReader;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/*
 * Imports rows of a CSV or Excel file as entries of a collection, with a column mapping step.
 */
new class extends Component
{
    use InteractsWithHousehold;
    use WithFileUploads;

    #[Locked]
    public int $collectionId;

    public $file = null;

    /**
     * @var array<int, string>
     */
    public array $headers = [];

    /**
     * @var array<int, string>
     */
    public array $mapping = [];

    public int $rowCount = 0;

    public ?string $error = null;

    private function collection(): Collection
    {
        return Collection::query()->visibleTo($this->currentUser(), (int) $this->householdId)->with('properties')->find($this->collectionId) ?? abort(404);
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}|null
     */
    private function table(): ?array
    {
        if (! $this->file) {
            return null;
        }

        try {
            return app(TableFileReader::class)->read($this->file->getRealPath(), $this->file->getClientOriginalName());
        } catch (RuntimeException $exception) {
            $this->error = $exception->getMessage();

            return null;
        }
    }

    public function updatedFile(): void
    {
        $this->reset('headers', 'mapping', 'rowCount', 'error');
        $this->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx']], attributes: ['file' => __('File')]);

        $table = $this->table();

        if ($table) {
            $this->headers = $table['headers'];
            $this->rowCount = count($table['rows']);
            $this->mapping = CollectionImporter::suggestMapping($this->collection(), $table['headers']);
        }
    }

    public function import(): void
    {
        $table = $this->table();

        if (! $table) {
            $this->error ??= __('Please choose a file first.');

            return;
        }

        $collection = $this->collection();
        $valid = ['title', 'new', 'skip', ...$collection->properties->map(fn (CollectionProperty $property) => 'property:'.$property->id)->all()];
        $mapping = collect($table['headers'])->keys()->mapWithKeys(fn (int $index) => [$index => in_array($this->mapping[$index] ?? 'skip', $valid, true) ? $this->mapping[$index] : 'skip'])->all();

        $count = CollectionImporter::import($collection, $table['headers'], $table['rows'], $mapping, $this->currentUser(), (int) $this->householdId);

        HouseholdItemsChanged::dispatch((int) $this->householdId, 'collection');
        $this->reset('file', 'headers', 'mapping', 'rowCount', 'error');
        $this->dispatch('toast', message: trans_choice(':count entry imported|:count entries imported', $count));
        $this->dispatch('collection-imported');
    }

    public function with(): array
    {
        $collection = $this->collection();
        $table = $this->headers !== [] ? $this->table() : null;

        return [
            'collection' => $collection,
            'preview' => $table ? array_slice($table['rows'], 0, 5) : [],
            'properties' => $collection->properties->reject(fn (CollectionProperty $property) => $property->type === CollectionProperty::TYPE_RELATION),
        ];
    }
}; ?>

<div class="space-y-4">
    <div>
        <x-input-label for="import-file" :value="__('CSV or Excel file (.csv, .xlsx)')" />
        <input id="import-file" type="file" wire:model="file" accept=".csv,.txt,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
               class="mt-1 block w-full text-sm text-gray-700 file:me-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-indigo-700 dark:text-gray-300 dark:file:bg-gray-700 dark:file:text-gray-200">
        <div wire:loading wire:target="file" class="mt-1 text-xs text-gray-500">{{ __('Reading file …') }}</div>
        <x-input-error :messages="$errors->get('file')" class="mt-1" />
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('The first row must contain the column names. Up to 2,000 rows; dates like 24.12.2026 and amounts like 1.234,56 are recognised.') }}</p>
    </div>

    @if ($error)
        <p class="rounded-xl bg-red-50 px-4 py-2.5 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200" role="alert">{{ $error }}</p>
    @endif

    @if ($headers !== [])
        <div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Assign columns') }}</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count row found.|:count rows found.', $rowCount) }} {{ __('New fields get a type that fits the values (date, number, selection …).') }}</p>
            <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($headers as $index => $header)
                    <li wire:key="map-{{ $index }}" class="flex flex-wrap items-center gap-2 py-2">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $header }}</p>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ collect($preview)->pluck($index)->filter()->take(3)->implode(' · ') ?: '—' }}</p>
                        </div>
                        <span class="text-gray-400" aria-hidden="true">→</span>
                        <select wire:model="mapping.{{ $index }}" aria-label="{{ __('Target for :column', ['column' => $header]) }}" class="w-52 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                            <option value="title">{{ __('Title') }}</option>
                            <option value="new">＋ {{ __('New field “:name”', ['name' => \Illuminate\Support\Str::limit($header, 24)]) }}</option>
                            @foreach ($properties as $property)
                                <option value="property:{{ $property->id }}">{{ $property->name }}</option>
                            @endforeach
                            <option value="skip">{{ __('Do not import') }}</option>
                        </select>
                    </li>
                @endforeach
            </ul>
        </div>

        <x-primary-button type="button" wire:click="import" wire:loading.attr="disabled" wire:target="import">
            <span wire:loading.remove wire:target="import">⬆ {{ trans_choice('Import :count entry|Import :count entries', $rowCount) }}</span>
            <span wire:loading wire:target="import">{{ __('Importing …') }}</span>
        </x-primary-button>
    @endif
</div>
