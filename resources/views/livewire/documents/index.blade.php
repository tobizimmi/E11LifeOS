<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\PaperlessInstance;
use App\Support\Paperless\PaperlessClient;
use App\Support\Paperless\PaperlessException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Document archive: browse, search, filter and manage all paperless documents from inside the app.
 * The documents stay in paperless – this reads and edits them through the API (VPN-aware).
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public ?int $instanceId = null;

    #[Url(as: 'q')]
    public string $query = '';

    public ?int $tag = null;

    public ?int $correspondent = null;

    public ?int $documentType = null;

    public string $from = '';

    public string $to = '';

    public string $ordering = '-created';

    public int $page = 1;

    /** Document opened in the detail panel. */
    public ?int $selectedId = null;

    /**
     * The opened document, loaded once in open() and refreshed after edits – so the detail
     * panel does not re-fetch it from paperless on every render (slow over VPN).
     *
     * @var array{id: int, title: string, created: ?string, correspondent: ?int, document_type: ?int, tags: list<int>, notes: list<array{id: ?int, note: string, created: ?string}>}|null
     */
    public ?array $selected = null;

    public string $editTitle = '';

    /** @var list<int> */
    public array $editTags = [];

    public ?int $editCorrespondent = null;

    public ?int $editDocumentType = null;

    public string $editDate = '';

    public string $newNote = '';

    public ?string $message = null;

    public function mount(): void
    {
        $this->resolveHousehold();
        $this->instanceId = PaperlessInstance::defaultFor($this->currentUser())?->id;
    }

    private function instance(): ?PaperlessInstance
    {
        return PaperlessInstance::query()->availableTo($this->currentUser())->find($this->instanceId);
    }

    private function client(): PaperlessClient
    {
        return PaperlessClient::for($this->instance() ?? abort(404), $this->currentUser());
    }

    public function updated(string $property): void
    {
        // Any filter change starts again on the first page.
        if (in_array($property, ['query', 'tag', 'correspondent', 'documentType', 'from', 'to', 'ordering', 'instanceId'], true)) {
            $this->page = 1;
        }

        if ($property === 'instanceId') {
            $this->reset('selectedId', 'selected', 'tag', 'correspondent', 'documentType');
        }
    }

    public function resetFilters(): void
    {
        $this->reset('query', 'tag', 'correspondent', 'documentType', 'from', 'to', 'page');
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    /**
     * Opens a document in the detail panel and loads its current metadata.
     */
    public function open(int $id): void
    {
        $this->message = null;

        try {
            $document = $this->client()->document($id);
        } catch (PaperlessException $exception) {
            $this->message = $exception->getMessage();

            return;
        }

        $this->selectedId = $id;
        $this->selected = $document;
        $this->editTitle = $document['title'];
        $this->editTags = $document['tags'];
        $this->editCorrespondent = $document['correspondent'];
        $this->editDocumentType = $document['document_type'];
        $this->editDate = $document['created'] ?? '';

        // Bring the detail into view – on mobile it would otherwise open far below the grid.
        $this->dispatch('document-opened');
    }

    public function close(): void
    {
        $this->reset('selectedId', 'selected', 'editTitle', 'editTags', 'editCorrespondent', 'editDocumentType', 'editDate', 'newNote');
    }

    public function toggleEditTag(int $tagId): void
    {
        $this->editTags = in_array($tagId, $this->editTags, true)
            ? array_values(array_diff($this->editTags, [$tagId]))
            : [...$this->editTags, $tagId];
    }

    /**
     * Saves the edited metadata back to paperless.
     */
    public function saveMeta(): void
    {
        abort_if($this->selectedId === null, 404);

        $data = $this->validate([
            'editTitle' => ['required', 'string', 'max:255'],
            'editDate' => ['nullable', 'date'],
        ], attributes: ['editTitle' => __('Title'), 'editDate' => __('Date')]);

        try {
            $this->client()->update($this->selectedId, [
                'title' => trim($data['editTitle']),
                'tags' => array_values(array_unique(array_map('intval', $this->editTags))),
                'correspondent' => $this->editCorrespondent,
                'document_type' => $this->editDocumentType,
                'created' => $data['editDate'] !== '' ? $data['editDate'] : null,
            ]);
            $this->selected = $this->client()->document($this->selectedId);
            $this->message = __('Saved.');
        } catch (PaperlessException $exception) {
            $this->message = $exception->getMessage();
        }
    }

    public function addNote(): void
    {
        abort_if($this->selectedId === null, 404);
        $note = trim($this->newNote);

        if ($note === '') {
            return;
        }

        try {
            $this->client()->addNote($this->selectedId, $note);
            $this->selected = $this->client()->document($this->selectedId);
            $this->newNote = '';
            $this->message = __('Note added.');
        } catch (PaperlessException $exception) {
            $this->message = $exception->getMessage();
        }
    }

    /**
     * Permanently deletes the document in paperless.
     */
    public function deleteDocument(int $id): void
    {
        try {
            $this->client()->deleteDocument($id);
            $this->message = __('Document deleted.');
        } catch (PaperlessException $exception) {
            $this->message = $exception->getMessage();

            return;
        }

        if ($this->selectedId === $id) {
            $this->close();
        }
    }

    public function with(): array
    {
        $user = $this->currentUser();
        $instances = PaperlessInstance::query()->availableTo($user)->orderBy('name')->get();

        $tags = [];
        $correspondents = [];
        $documentTypes = [];
        $documents = ['results' => [], 'count' => 0, 'page' => 1, 'pages' => 1];
        $error = null;

        if ($this->instanceId !== null && $instances->contains('id', $this->instanceId)) {
            try {
                $client = $this->client();
                $tags = $client->tags();
                $correspondents = $client->correspondents();
                $documentTypes = $client->documentTypes();
                $documents = $client->documents([
                    'query' => $this->query,
                    'tag' => $this->tag,
                    'correspondent' => $this->correspondent,
                    'document_type' => $this->documentType,
                    'created_from' => $this->from ?: null,
                    'created_to' => $this->to ?: null,
                    'ordering' => $this->ordering,
                ], $this->page);
            } catch (PaperlessException $exception) {
                $error = $exception->getMessage();
            }
        }

        return [
            'instances' => $instances,
            'tags' => $tags,
            'correspondents' => $correspondents,
            'documentTypes' => $documentTypes,
            'documents' => $documents,
            'error' => $error,
        ];
    }
}; ?>

<div x-data x-on:document-opened.window="$nextTick(() => document.getElementById('doc-detail')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">🗂️ {{ __('Documents') }}</h2>
            <a href="{{ route('scan.index') }}" wire:navigate class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">📷 {{ __('Scan') }}</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            @if ($instances->isEmpty())
                <div class="rounded-2xl bg-white p-6 text-sm text-gray-600 shadow-xs dark:bg-gray-800 dark:text-gray-300">
                    {{ __('No paperless connection set up yet. Add one under Profile → paperless to see your documents.') }}
                    <a href="{{ route('profile') }}" wire:navigate class="text-indigo-600 underline dark:text-indigo-400">{{ __('To the settings') }}</a>
                </div>
            @else
                {{-- Filters --}}
                <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                    <div class="flex flex-wrap items-end gap-3">
                        @if ($instances->count() > 1)
                            <div>
                                <x-input-label for="doc-instance" :value="__('paperless instance')" />
                                <select id="doc-instance" wire:model.live="instanceId" class="mt-1 rounded-md border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                    @foreach ($instances as $paperlessInstance)
                                        <option value="{{ $paperlessInstance->id }}">{{ $paperlessInstance->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div class="min-w-48 flex-1">
                            <x-input-label for="doc-q" :value="__('Search')" />
                            <x-text-input id="doc-q" wire:model.live.debounce.400ms="query" class="mt-1 block w-full" placeholder="{{ __('Full-text search …') }}" />
                        </div>
                        <div>
                            <x-input-label for="doc-tag" :value="__('Tag')" />
                            <select id="doc-tag" wire:model.live="tag" class="mt-1 rounded-md border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                <option value="">{{ __('All') }}</option>
                                @foreach ($tags as $tId => $tName)
                                    <option value="{{ $tId }}">{{ $tName }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="doc-corr" :value="__('Correspondent')" />
                            <select id="doc-corr" wire:model.live="correspondent" class="mt-1 rounded-md border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                <option value="">{{ __('All') }}</option>
                                @foreach ($correspondents as $cId => $cName)
                                    <option value="{{ $cId }}">{{ $cName }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="doc-type" :value="__('Document type')" />
                            <select id="doc-type" wire:model.live="documentType" class="mt-1 rounded-md border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                <option value="">{{ __('All') }}</option>
                                @foreach ($documentTypes as $dId => $dName)
                                    <option value="{{ $dId }}">{{ $dName }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="doc-from" :value="__('From')" />
                            <x-text-input id="doc-from" type="date" wire:model.live="from" class="mt-1 block" />
                        </div>
                        <div>
                            <x-input-label for="doc-to" :value="__('To')" />
                            <x-text-input id="doc-to" type="date" wire:model.live="to" class="mt-1 block" />
                        </div>
                        <div>
                            <x-input-label for="doc-order" :value="__('Sort')" />
                            <select id="doc-order" wire:model.live="ordering" class="mt-1 rounded-md border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                <option value="-created">{{ __('Newest first') }}</option>
                                <option value="created">{{ __('Oldest first') }}</option>
                                <option value="title">{{ __('Title A–Z') }}</option>
                                <option value="-added">{{ __('Recently added') }}</option>
                            </select>
                        </div>
                        <button type="button" wire:click="resetFilters" class="pb-1.5 text-sm text-gray-500 underline">{{ __('Reset') }}</button>
                    </div>
                </div>

                @if ($message)
                    <p class="mt-3 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-gray-900/50 dark:text-gray-300" role="status">{{ $message }}</p>
                @endif
                @if ($error)
                    <p class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $error }}</p>
                @endif

                <div class="mt-4 grid gap-4 lg:grid-cols-3">
                    {{-- Document grid --}}
                    <div class="lg:col-span-2">
                        <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count document|:count documents', $documents['count'], ['count' => number_format($documents['count'], 0, ',', '.')]) }}</p>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            @forelse ($documents['results'] as $doc)
                                <button type="button" wire:key="doc-{{ $doc['id'] }}" wire:click="open({{ $doc['id'] }})" @class([
                                    'group rounded-lg border p-2 text-left transition',
                                    'border-indigo-500 ring-1 ring-indigo-500' => $selectedId === $doc['id'],
                                    'border-gray-200 hover:border-indigo-300 dark:border-gray-700' => $selectedId !== $doc['id'],
                                ])>
                                    <img src="{{ route('paperless.archive', [$instanceId, $doc['id'], 'thumb']) }}" alt="" loading="lazy" class="mb-2 h-36 w-full rounded-sm border border-gray-100 bg-gray-50 object-cover object-top dark:border-gray-700">
                                    <p class="line-clamp-2 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $doc['title'] ?: __('(untitled)') }}</p>
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $doc['created'] ? \Illuminate\Support\Carbon::parse($doc['created'])->format('d.m.Y') : '' }}
                                        @if ($doc['correspondent'] && isset($correspondents[$doc['correspondent']])) · {{ $correspondents[$doc['correspondent']] }} @endif
                                    </p>
                                    @if ($doc['tags'] !== [])
                                        <p class="mt-1 flex flex-wrap gap-1">
                                            @foreach ($doc['tags'] as $tagId)
                                                @if (isset($tags[$tagId]))
                                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ $tags[$tagId] }}</span>
                                                @endif
                                            @endforeach
                                        </p>
                                    @endif
                                </button>
                            @empty
                                @unless ($error)
                                    <p class="col-span-full rounded-lg bg-white p-8 text-center text-sm text-gray-500 shadow-xs dark:bg-gray-800 dark:text-gray-400">{{ __('No documents found.') }}</p>
                                @endunless
                            @endforelse
                        </div>

                        {{-- Pagination --}}
                        @if ($documents['pages'] > 1)
                            <div class="mt-4 flex items-center justify-center gap-3 text-sm">
                                <button type="button" wire:click="goToPage({{ $documents['page'] - 1 }})" @disabled($documents['page'] <= 1) class="rounded-md px-3 py-1 ring-1 ring-gray-300 disabled:opacity-40 dark:ring-gray-600">←</button>
                                <span class="text-gray-600 dark:text-gray-300">{{ __('Page :page of :pages', ['page' => $documents['page'], 'pages' => $documents['pages']]) }}</span>
                                <button type="button" wire:click="goToPage({{ $documents['page'] + 1 }})" @disabled($documents['page'] >= $documents['pages']) class="rounded-md px-3 py-1 ring-1 ring-gray-300 disabled:opacity-40 dark:ring-gray-600">→</button>
                            </div>
                        @endif
                    </div>

                    {{-- Detail panel: an overlay on phones (so it clearly opens over the grid), an in-column panel from lg up. --}}
                    <div class="lg:col-span-1">
                        @if ($selectedId && $selected)
                            <div id="doc-detail" wire:key="doc-detail-{{ $selectedId }}" wire:click.self="close" class="fixed inset-0 z-40 overflow-y-auto overscroll-contain bg-black/40 p-4 backdrop-blur-xs lg:static lg:z-auto lg:overflow-visible lg:bg-transparent lg:p-0 lg:backdrop-blur-none">
                            <div class="mx-auto max-w-lg space-y-3 rounded-2xl bg-white p-4 shadow-xl dark:bg-gray-800 lg:sticky lg:top-4 lg:max-w-none lg:shadow-xs">
                                <div class="flex items-start justify-between gap-2">
                                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Document') }}</h3>
                                    <button type="button" wire:click="close" class="text-gray-400 hover:text-gray-600" aria-label="{{ __('Close') }}">✕</button>
                                </div>

                                <a href="{{ route('paperless.archive', [$instanceId, $selectedId, 'preview']) }}" target="_blank" rel="noopener" class="block">
                                    <img src="{{ route('paperless.archive', [$instanceId, $selectedId, 'thumb']) }}" alt="" class="max-h-72 w-full rounded-md border border-gray-200 bg-gray-50 object-contain dark:border-gray-700">
                                </a>

                                <div class="flex flex-wrap gap-2 text-sm">
                                    <a href="{{ route('paperless.archive', [$instanceId, $selectedId, 'preview']) }}" target="_blank" rel="noopener" class="rounded-md px-2 py-1 ring-1 ring-gray-300 dark:ring-gray-600">👁 {{ __('Preview') }}</a>
                                    <a href="{{ route('paperless.archive', [$instanceId, $selectedId, 'download']) }}" class="rounded-md px-2 py-1 ring-1 ring-gray-300 dark:ring-gray-600">⬇ {{ __('Download') }}</a>
                                    <button type="button" wire:click="deleteDocument({{ $selectedId }})" wire:confirm="{{ __('Delete this document in paperless? This cannot be undone.') }}" class="ms-auto rounded-md px-2 py-1 text-red-600 ring-1 ring-red-300 dark:text-red-400 dark:ring-red-800">🗑 {{ __('Delete') }}</button>
                                </div>

                                <form wire:submit="saveMeta" class="space-y-3 border-t border-gray-100 pt-3 dark:border-gray-700">
                                    <div>
                                        <x-input-label for="edit-title" :value="__('Title')" />
                                        <x-text-input id="edit-title" wire:model="editTitle" class="mt-1 block w-full" />
                                        <x-input-error :messages="$errors->get('editTitle')" class="mt-1" />
                                    </div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <x-input-label for="edit-corr" :value="__('Correspondent')" />
                                            <select id="edit-corr" wire:model="editCorrespondent" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                                <option value="">—</option>
                                                @foreach ($correspondents as $cId => $cName)
                                                    <option value="{{ $cId }}">{{ $cName }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <x-input-label for="edit-type" :value="__('Document type')" />
                                            <select id="edit-type" wire:model="editDocumentType" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                                <option value="">—</option>
                                                @foreach ($documentTypes as $dId => $dName)
                                                    <option value="{{ $dId }}">{{ $dName }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    <div>
                                        <x-input-label for="edit-date" :value="__('Date')" />
                                        <x-text-input id="edit-date" type="date" wire:model="editDate" class="mt-1 block w-full" />
                                        <x-input-error :messages="$errors->get('editDate')" class="mt-1" />
                                    </div>
                                    @if ($tags !== [])
                                        <div>
                                            <x-input-label :value="__('Tags')" />
                                            <div class="mt-1 flex flex-wrap gap-1.5">
                                                @foreach ($tags as $tId => $tName)
                                                    <button type="button" wire:click="toggleEditTag({{ $tId }})" @class([
                                                        'rounded-full border px-2.5 py-1 text-xs',
                                                        'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200' => in_array($tId, $editTags, true),
                                                        'border-gray-300 text-gray-600 dark:border-gray-600 dark:text-gray-300' => ! in_array($tId, $editTags, true),
                                                    ])>{{ $tName }}</button>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                    <x-primary-button>{{ __('Save') }}</x-primary-button>
                                </form>

                                {{-- Notes --}}
                                <div class="border-t border-gray-100 pt-3 dark:border-gray-700">
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ __('Notes') }}</p>
                                    @forelse ($selected['notes'] as $note)
                                        <div wire:key="note-{{ $note['id'] ?? $loop->index }}" class="mt-2 rounded-md bg-gray-50 px-2 py-1 text-sm text-gray-700 dark:bg-gray-900/50 dark:text-gray-300">
                                            {{ $note['note'] }}
                                            @if ($note['created']) <span class="block text-xs text-gray-400">{{ \Illuminate\Support\Carbon::parse($note['created'])->format('d.m.Y') }}</span> @endif
                                        </div>
                                    @empty
                                        <p class="mt-1 text-xs text-gray-400">{{ __('No notes yet.') }}</p>
                                    @endforelse
                                    <form wire:submit="addNote" class="mt-2 flex gap-2">
                                        <x-text-input wire:model="newNote" class="block w-full text-sm" placeholder="{{ __('Add a note …') }}" />
                                        <x-secondary-button>+</x-secondary-button>
                                    </form>
                                </div>
                            </div>
                            </div>
                        @else
                            <div class="hidden rounded-2xl bg-white p-6 text-center text-sm text-gray-500 shadow-xs lg:block dark:bg-gray-800 dark:text-gray-400">
                                {{ __('Select a document to view and edit it.') }}
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
