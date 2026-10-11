<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\ExternalLink;
use App\Models\PaperlessInstance;
use App\Models\Transaction;
use App\Support\Finance\TransactionDocuments;
use App\Support\ExternalLinks;
use App\Support\Paperless\PaperlessClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * "Documents" section on entries, properties and invoices: documents from paperless-ngx (they
 * stay there – only the id is stored), links to e-mails and, where a link does not work, a copy
 * of the mail (PDF/.eml), which goes to paperless if connected.
 */
new class extends Component
{
    use InteractsWithHousehold;
    use WithFileUploads;

    #[Locked]
    public string $type = 'item';

    #[Locked]
    public int $targetId;

    /**
     * Open form: "", "paperless", "upload" or "mail".
     */
    public string $mode = '';

    public string $query = '';

    public string $mailUrl = '';

    public string $mailTitle = '';

    public $upload = null;

    public string $uploadTitle = '';

    public ?int $editingDocumentId = null;

    public string $editTitle = '';

    public string $newTag = '';

    public string $note = '';

    public ?string $message = null;

    /**
     * paperless instance used for searching and uploading (own or shared with the household).
     */
    public ?int $instanceId = null;

    public function mount(string $type, int $id): void
    {
        abort_unless(in_array($type, ExternalLinks::TYPES, true), 404);
        $this->resolveHousehold();
        $this->type = $type;
        $this->targetId = $id;
        $this->assertVisible();
        $this->instanceId = PaperlessInstance::defaultFor(Auth::user())?->id;
    }

    private function instance(?int $id = null): PaperlessInstance
    {
        $instance = PaperlessInstance::query()->availableTo(Auth::user())->find($id ?? $this->instanceId);
        abort_unless($instance, 404);

        return $instance;
    }

    private function client(?int $instanceId = null): PaperlessClient
    {
        return PaperlessClient::for($this->instance($instanceId), Auth::user());
    }

    private function assertVisible(): void
    {
        abort_unless(ExternalLinks::resolve(Auth::user(), (int) $this->householdId, $this->type, $this->targetId), 404);
    }

    private function links()
    {
        return ExternalLink::where('household_id', $this->householdId)->where('linkable_type', $this->type)->where('linkable_id', $this->targetId);
    }

    private function create(array $attributes): ExternalLink
    {
        return ExternalLink::create($attributes + [
            'household_id' => $this->householdId,
            'created_by' => Auth::id(),
            'linkable_type' => $this->type,
            'linkable_id' => $this->targetId,
        ]);
    }

    public function open(string $mode): void
    {
        abort_unless(in_array($mode, ['', 'paperless', 'upload', 'mail'], true), 422);
        $this->resetValidation();
        $this->reset('query', 'mailUrl', 'mailTitle', 'upload', 'uploadTitle', 'message', 'editingDocumentId');
        $this->mode = $this->mode === $mode ? '' : $mode;

        // For a booking the search starts with the payee, so the matching invoice is usually first.
        if ($this->mode === 'paperless' && ($transaction = $this->transaction())) {
            $this->query = mb_substr(trim((string) $transaction->counterparty), 0, 60);
        }
    }

    /**
     * The bank booking the panel belongs to (only for type "transaction").
     */
    private function transaction(): ?Transaction
    {
        $target = $this->type === 'transaction' ? ExternalLinks::resolve(Auth::user(), (int) $this->householdId, $this->type, $this->targetId) : null;

        return $target instanceof Transaction ? $target : null;
    }

    /**
     * Tags the linked invoice "Paid" in paperless and notes the booking there.
     */
    public function markPaid(int $linkId): void
    {
        $transaction = $this->transaction() ?? abort(404);
        $link = $this->links()->where('kind', ExternalLink::PAPERLESS)->whereNotNull('paperless_document_id')->findOrFail($linkId);
        abort_unless($link->paperless_instance_id && $link->paperlessInstance?->isAvailableTo(Auth::user()), 404);

        try {
            TransactionDocuments::markPaid($this->client($link->paperless_instance_id), (int) $link->paperless_document_id, $transaction);
        } catch (Throwable $exception) {
            report($exception);
            $this->message = __('paperless is not reachable.');

            return;
        }

        $this->message = __('Marked as paid in paperless.');
    }

    /**
     * No invoice: sends a receipt of the booking to paperless and links it.
     */
    public function sendBookingToPaperless(): void
    {
        $transaction = $this->transaction() ?? abort(404);
        abort_unless($this->instanceId, 422);

        try {
            $taskId = TransactionDocuments::sendReceipt($this->client(), $transaction);
        } catch (Throwable $exception) {
            report($exception);
            $this->message = __('paperless is not reachable.');

            return;
        }

        $this->create(['kind' => ExternalLink::PAPERLESS, 'paperless_instance_id' => $this->instanceId, 'paperless_task_id' => mb_substr($taskId, 0, 64), 'title' => TransactionDocuments::title($transaction)]);
        $this->message = __('Sent to paperless – it appears here as soon as paperless has processed it.');
    }

    public function linkPaperless(int $documentId, string $title): void
    {
        $this->assertVisible();

        $instance = $this->instance();

        // The household-scoping check below (ExternalLink/household_id) only protects documents
        // that are ALREADY linked; a document id that was never linked here would otherwise be
        // accepted and written with the caller's own household_id without ever checking it
        // actually exists on this Paperless instance.
        try {
            $exists = ($this->client($instance->id)->document($documentId)['id'] ?? null) === $documentId;
        } catch (Throwable $exception) {
            $exists = false;
        }

        abort_unless($exists, 404);

        if (! $this->links()->where('paperless_instance_id', $instance->id)->where('paperless_document_id', $documentId)->exists()) {
            $this->create(['kind' => ExternalLink::PAPERLESS, 'paperless_instance_id' => $instance->id, 'paperless_document_id' => $documentId, 'title' => mb_substr($title ?: '#'.$documentId, 0, 255)]);
        }

        $this->mode = '';
    }

    public function addMailLink(): void
    {
        $this->assertVisible();
        $this->validate([
            'mailUrl' => ['required', 'string', 'max:2000', function ($attribute, $value, $fail) {
                if (! ExternalLinks::isMailUrl($value)) {
                    $fail(__('Paste the link to the e-mail (e.g. message://, https://mail.google.com/…, Outlook link).'));
                }
            }],
            'mailTitle' => ['nullable', 'string', 'max:255'],
        ]);

        $this->create(['kind' => ExternalLink::MAIL, 'url' => trim($this->mailUrl), 'title' => trim($this->mailTitle) ?: __('E-mail (:app)', ['app' => ExternalLinks::mailApp($this->mailUrl)])]);
        $this->reset('mailUrl', 'mailTitle', 'mode');
    }

    /**
     * A copy of a mail or any document: to paperless when connected, otherwise kept in the app.
     */
    public function uploadDocument(): void
    {
        $this->assertVisible();
        $this->validate([
            'upload' => ['required', 'file', 'max:30720', 'mimes:pdf,eml,msg,jpg,jpeg,png,webp,txt,doc,docx,odt'],
            'uploadTitle' => ['nullable', 'string', 'max:255'],
        ]);

        $title = trim($this->uploadTitle) ?: pathinfo($this->upload->getClientOriginalName(), PATHINFO_FILENAME);

        if ($this->instanceId) {
            try {
                $taskId = $this->client()->upload($this->upload, $title);
            } catch (Throwable $exception) {
                report($exception);
                $this->addError('upload', __('paperless is not reachable.'));

                return;
            }

            $this->create(['kind' => ExternalLink::PAPERLESS, 'paperless_instance_id' => $this->instanceId, 'paperless_task_id' => mb_substr($taskId, 0, 64), 'title' => mb_substr($title, 0, 255)]);
            $this->message = __('Sent to paperless – it appears here as soon as paperless has processed it.');
        } else {
            $extension = strtolower($this->upload->getClientOriginalExtension() ?: $this->upload->guessExtension() ?: 'bin');
            $this->create([
                'kind' => ExternalLink::FILE,
                'title' => mb_substr($this->upload->getClientOriginalName(), 0, 255),
                'path' => $this->upload->storeAs('external/'.$this->householdId, Str::uuid().'.'.$extension, ExternalLink::DISK),
                'mime_type' => $this->upload->getMimeType(),
            ]);
        }

        $this->reset('upload', 'uploadTitle', 'mode');
    }

    public function unlink(int $id): void
    {
        $this->assertVisible();
        $this->links()->findOrFail($id)->delete();
    }

    // ── Editing paperless documents ──────────────────────────────────────

    public function editDocument(int $linkId): void
    {
        $link = $this->links()->where('kind', ExternalLink::PAPERLESS)->findOrFail($linkId);
        $this->reset('newTag', 'note', 'message');
        $this->editingDocumentId = $this->editingDocumentId === $link->id ? null : $link->id;
        $this->editTitle = $link->title;
    }

    private function editingLink(): ExternalLink
    {
        $this->assertVisible();

        $link = $this->links()->where('kind', ExternalLink::PAPERLESS)->whereNotNull('paperless_document_id')->findOrFail($this->editingDocumentId);
        abort_unless($link->paperless_instance_id && $link->paperlessInstance?->isAvailableTo(Auth::user()), 404);

        return $link;
    }

    public function saveDocument(): void
    {
        $link = $this->editingLink();
        $this->validate(['editTitle' => ['required', 'string', 'max:128'], 'newTag' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:5000']]);
        $client = $this->client($link->paperless_instance_id);

        try {
            $document = $client->document($link->paperless_document_id);
            $data = ['title' => trim($this->editTitle)];

            if ($this->newTag !== '' && array_key_exists((int) $this->newTag, $client->tags())) {
                $data['tags'] = array_values(array_unique([...$document['tags'], (int) $this->newTag]));
            }

            $client->update($link->paperless_document_id, $data);

            if (trim($this->note) !== '') {
                $client->addNote($link->paperless_document_id, trim($this->note));
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->message = __('paperless is not reachable.');

            return;
        }

        $link->update(['title' => trim($this->editTitle)]);
        $this->reset('editingDocumentId', 'newTag', 'note');
        $this->message = __('Saved in paperless.');
    }

    /**
     * Uploads that paperless has finished get their document id.
     */
    private function resolvePending(): void
    {
        foreach ($this->links()->where('kind', ExternalLink::PAPERLESS)->whereNull('paperless_document_id')->whereNotNull('paperless_task_id')->where('created_by', Auth::id())->with('paperlessInstance')->get() as $link) {
            if (! $link->paperlessInstance?->isAvailableTo(Auth::user())) {
                continue;
            }

            try {
                $result = PaperlessClient::for($link->paperlessInstance, Auth::user())->taskResult($link->paperless_task_id);
            } catch (Throwable) {
                return;
            }

            if (is_int($result)) {
                $link->update(['paperless_document_id' => $result, 'paperless_task_id' => null]);
            } elseif ($result === false) {
                $link->update(['paperless_task_id' => null, 'title' => mb_substr('⚠ '.$link->title.' – '.__('paperless could not process the file'), 0, 255)]);
            }
        }
    }

    public function with(): array
    {
        $user = Auth::user();
        $this->resolvePending();
        $results = [];
        $error = null;
        $tags = [];
        $document = null;

        $instances = PaperlessInstance::query()->availableTo($user)->orderBy('name')->get();

        if (! $instances->contains('id', $this->instanceId)) {
            $this->instanceId = PaperlessInstance::defaultFor($user)?->id;
        }

        if ($instances->isNotEmpty() && ($this->mode === 'paperless' || $this->editingDocumentId)) {
            try {
                if ($this->mode === 'paperless' && $this->instanceId) {
                    $results = $this->client()->search($this->query);
                }

                if ($this->editingDocumentId) {
                    $link = $this->editingLink();
                    $client = $this->client($link->paperless_instance_id);
                    $tags = $client->tags();
                    $document = $client->document($link->paperless_document_id);
                }
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        return [
            'documents' => $this->links()->with('paperlessInstance')->orderBy('id')->get(),
            'hasPaperless' => $instances->isNotEmpty(),
            'instances' => $instances,
            'availableInstanceIds' => $instances->pluck('id')->all(),
            'results' => $results,
            'error' => $error,
            'tags' => $tags,
            'paperlessDocument' => $document,
        ];
    }
}; ?>

<x-drop-zone model="upload" method="uploadDocument" :label="$instanceId ? __('Drop files here – they go to paperless') : __('Drop files here')">
<section class="space-y-2" aria-label="{{ __('Documents & e-mails') }}" @if ($documents->contains(fn ($document) => $document->isPending() && $document->paperless_task_id)) wire:poll.5s @endif>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">🗂 {{ __('Documents & e-mails') }}</h3>
        <div class="flex flex-wrap gap-3 text-sm">
            @if ($hasPaperless)
                <button type="button" wire:click="open('paperless')" class="text-indigo-600 hover:underline dark:text-indigo-400">+ {{ __('From paperless') }}</button>
            @endif
            <button type="button" wire:click="open('mail')" class="text-indigo-600 hover:underline dark:text-indigo-400">+ {{ __('Mail link') }}</button>
            <button type="button" wire:click="open('upload')" class="text-indigo-600 hover:underline dark:text-indigo-400">+ {{ $hasPaperless ? __('Upload to paperless') : __('Upload file') }}</button>
            @if ($type === 'transaction' && $hasPaperless && $instanceId)
                <button type="button" wire:click="sendBookingToPaperless" wire:loading.attr="disabled" class="text-indigo-600 hover:underline disabled:opacity-50 dark:text-indigo-400" title="{{ __('Creates a receipt of this booking in paperless – useful when there is no invoice.') }}">⇪ {{ __('Booking to paperless') }}</button>
            @endif
        </div>
    </div>

    @if ($message)
        <p class="text-xs text-gray-600 dark:text-gray-400" role="status">{{ $message }}</p>
    @endif

    @if (in_array($mode, ['paperless', 'upload'], true) && $instances->count() > 1)
        <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">{{ __('paperless instance') }}
            <select wire:model.live="instanceId" class="rounded-md border-gray-300 py-0.5 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                @foreach ($instances as $instance)
                    <option value="{{ $instance->id }}">{{ $instance->name }}{{ $instance->shared ? ' 👥' : ' 🔒' }}</option>
                @endforeach
            </select>
        </label>
    @endif

    @if ($mode === 'paperless')
        <div class="rounded-md bg-gray-50 p-2 dark:bg-gray-900/50">
            <x-text-input wire:model.live.debounce.300ms="query" type="search" class="block w-full text-sm" :placeholder="__('Search in paperless (full text) …')" :aria-label="__('Search in paperless')" autofocus />
            @if ($error)
                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $error }}</p>
            @endif
            <ul class="mt-1 max-h-64 overflow-y-auto">
                @forelse ($results as $result)
                    <li wire:key="paperless-result-{{ $result['id'] }}">
                        <button type="button" wire:click="linkPaperless({{ $result['id'] }}, @js($result['title']))" class="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-start text-sm hover:bg-white dark:hover:bg-gray-800">
                            <img src="{{ route('paperless.document', [$instanceId, $result['id'], 'thumb']) }}" alt="" loading="lazy" class="h-10 w-8 shrink-0 rounded-sm object-cover ring-1 ring-gray-200 dark:ring-gray-700">
                            <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100">{{ $result['title'] }}</span>
                            <span class="shrink-0 text-xs text-gray-500">{{ $result['created'] ? \Illuminate\Support\Carbon::parse($result['created'])->format('d.m.Y') : '' }}</span>
                        </button>
                    </li>
                @empty
                    @unless ($error)
                        <li class="px-2 py-1.5 text-sm text-gray-500">{{ __('Nothing found.') }}</li>
                    @endunless
                @endforelse
            </ul>
        </div>
    @elseif ($mode === 'mail')
        <form wire:submit="addMailLink" class="space-y-2 rounded-md bg-gray-50 p-2 dark:bg-gray-900/50">
            <x-text-input wire:model="mailUrl" type="text" class="block w-full text-sm" placeholder="message://… · https://mail.google.com/… · https://outlook.office.com/…" :aria-label="__('Link to the e-mail')" autofocus />
            <x-input-error :messages="$errors->get('mailUrl')" />
            <x-text-input wire:model="mailTitle" type="text" class="block w-full text-sm" :placeholder="__('Title, e.g. Quote from the roofer (optional)')" :aria-label="__('Title')" />
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Apple Mail: drag the mail into a text field or copy its link; Gmail/Outlook web: copy the address of the opened mail. If there is no link, save the mail as PDF or .eml and upload it instead.') }}</p>
            <x-primary-button>{{ __('Add') }}</x-primary-button>
        </form>
    @elseif ($mode === 'upload')
        <form wire:submit="uploadDocument" class="space-y-2 rounded-md bg-gray-50 p-2 dark:bg-gray-900/50">
            <input type="file" wire:model="upload" accept=".pdf,.eml,.msg,.jpg,.jpeg,.png,.webp,.txt,.doc,.docx,.odt" class="block w-full text-sm text-gray-700 dark:text-gray-300" aria-label="{{ __('File') }}">
            <p wire:loading wire:target="upload" class="text-xs text-gray-500">{{ __('Uploading …') }}</p>
            <x-input-error :messages="$errors->get('upload')" />
            @if ($instanceId)
                <x-text-input wire:model="uploadTitle" type="text" class="block w-full text-sm" :placeholder="__('Title in paperless (optional)')" :aria-label="__('Title')" />
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('The file is stored in paperless, not in this app.') }}</p>
            @else
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Connect paperless in your profile to keep documents there instead.') }}</p>
            @endif
            <x-primary-button wire:loading.attr="disabled" wire:target="upload,uploadDocument">{{ __('Upload') }}</x-primary-button>
        </form>
    @endif

    @if ($documents->isNotEmpty())
        <ul class="space-y-1.5">
            @foreach ($documents as $document)
                <li wire:key="external-{{ $document->id }}" class="rounded-md bg-gray-50 px-2 py-1.5 text-sm dark:bg-gray-900/50">
                    @php
                        $viewable = $document->kind === 'paperless' && $document->paperless_document_id && in_array($document->paperless_instance_id, $availableInstanceIds, true);
                    @endphp
                    <div class="flex items-center gap-2">
                        @if ($viewable)
                            <a href="{{ route('paperless.document', [$document->paperless_instance_id, $document->paperless_document_id, 'preview']) }}" target="_blank" class="shrink-0" title="{{ __('Preview') }}">
                                <img src="{{ route('paperless.document', [$document->paperless_instance_id, $document->paperless_document_id, 'thumb']) }}" alt="" loading="lazy" class="h-10 w-8 rounded-sm object-cover ring-1 ring-gray-200 dark:ring-gray-700">
                            </a>
                        @else
                            <span class="w-8 shrink-0 text-center text-lg" aria-hidden="true">{{ ['paperless' => '📄', 'mail' => '✉️', 'file' => '📎'][$document->kind] ?? '📄' }}</span>
                        @endif
                        <div class="min-w-0 flex-1">
                            @if ($document->kind === 'mail')
                                <a href="{{ $document->url }}" target="_blank" rel="noopener noreferrer" class="block truncate text-indigo-700 hover:underline dark:text-indigo-300">{{ $document->title }}</a>
                                <span class="text-xs text-gray-500">{{ \App\Support\ExternalLinks::mailApp($document->url) }}</span>
                            @elseif ($document->kind === 'file')
                                <a href="{{ route('external-links.file', $document) }}" class="block truncate text-indigo-700 hover:underline dark:text-indigo-300">{{ $document->title }}</a>
                                <span class="text-xs text-gray-500">{{ __('Copy in this app') }}</span>
                            @elseif ($document->isPending())
                                <span class="block truncate text-gray-700 dark:text-gray-300">{{ $document->title }}</span>
                                <span class="text-xs text-gray-500">{{ $document->paperless_task_id ? '⏳ '.__('paperless is processing …') : '' }}</span>
                            @else
                                @if ($viewable)
                                    <a href="{{ route('paperless.document', [$document->paperless_instance_id, $document->paperless_document_id, 'preview']) }}" target="_blank" class="block truncate text-indigo-700 hover:underline dark:text-indigo-300">{{ $document->title }}</a>
                                @else
                                    <span class="block truncate text-gray-800 dark:text-gray-200">{{ $document->title }}</span>
                                @endif
                                <span class="text-xs text-gray-500">{{ $document->paperlessInstance?->name ?? 'paperless' }} #{{ $document->paperless_document_id }}@if ($document->paperlessInstance) · <a href="{{ $document->paperlessInstance->baseUrl() }}/documents/{{ $document->paperless_document_id }}/details" target="_blank" rel="noopener" class="underline">{{ __('open in paperless') }}</a>@endif @unless ($viewable) · {{ __('no access') }}@endunless</span>
                            @endif
                        </div>
                        @if ($viewable && $type === 'transaction')
                            <button type="button" wire:click="markPaid({{ $document->id }})" class="shrink-0 rounded-md px-2 py-0.5 text-xs text-emerald-700 ring-1 ring-emerald-200 hover:bg-emerald-50 dark:text-emerald-300 dark:ring-emerald-800 dark:hover:bg-emerald-900/30" title="{{ __('Tags the document “Paid” in paperless and notes this booking.') }}">✓ {{ __('Mark paid') }}</button>
                        @endif
                        @if ($viewable)
                            <button type="button" wire:click="editDocument({{ $document->id }})" class="text-gray-400 hover:text-indigo-600" title="{{ __('Title, tag or note in paperless') }}" aria-label="{{ __('Edit in paperless') }}"><x-icon name="pencil" class="h-5 w-5" /></button>
                        @endif
                        <button type="button" wire:click="unlink({{ $document->id }})" wire:confirm="{{ __('Remove this link? The document itself stays where it is.') }}" class="text-gray-400 hover:text-red-600" aria-label="{{ __('Remove link') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                    </div>

                    @if ($editingDocumentId === $document->id)
                        <form wire:submit="saveDocument" class="mt-2 space-y-2 border-t border-gray-200 pt-2 dark:border-gray-700">
                            <label class="block text-xs text-gray-600 dark:text-gray-400">{{ __('Title') }}
                                <x-text-input wire:model="editTitle" type="text" class="mt-0.5 block w-full text-sm" />
                            </label>
                            @if ($paperlessDocument)
                                @if ($paperlessDocument['tags'] !== [])
                                    <p class="flex flex-wrap gap-1">
                                        @foreach ($paperlessDocument['tags'] as $tagId)
                                            <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">#{{ $tags[$tagId] ?? $tagId }}</span>
                                        @endforeach
                                    </p>
                                @endif
                                <label class="block text-xs text-gray-600 dark:text-gray-400">{{ __('Add tag') }}
                                    <select wire:model="newTag" class="mt-0.5 block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                        <option value="">–</option>
                                        @foreach ($tags as $tagId => $tagName)
                                            @continue(in_array($tagId, $paperlessDocument['tags'], true))
                                            <option value="{{ $tagId }}">{{ $tagName }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                @foreach ($paperlessDocument['notes'] as $existingNote)
                                    <p class="text-xs text-gray-600 dark:text-gray-400">💬 {{ $existingNote['note'] }} <span class="text-gray-400">{{ $existingNote['created'] }}</span></p>
                                @endforeach
                            @elseif ($error)
                                <p class="text-xs text-red-600 dark:text-red-400">{{ $error }}</p>
                            @endif
                            <label class="block text-xs text-gray-600 dark:text-gray-400">{{ __('Add note') }}
                                <textarea wire:model="note" rows="2" class="mt-0.5 block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"></textarea>
                            </label>
                            <x-primary-button>{{ __('Save in paperless') }}</x-primary-button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
</x-drop-zone>
