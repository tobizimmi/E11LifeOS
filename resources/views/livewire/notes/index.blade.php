<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\Space;
use App\Support\NoteMarkdown;
use App\Support\PageMover;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * Quick notes: page-like jottings kept in the household's hidden "Notes" inbox space. Each note is
 * a real page (Item of type note), so it can be filed into the page tree with one tap.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Locked]
    public int $spaceId;

    /** New note text; the first line becomes the title, the rest the body. */
    public string $draft = '';

    public ?int $editingId = null;

    public string $editText = '';

    public function mount(): void
    {
        $this->resolveHousehold();

        // Legacy deep links (/notes?note=ID) point at a page.
        $legacy = request()->query('note');
        if (is_string($legacy) && ctype_digit($legacy)) {
            $this->redirectRoute('pages.index', ['item' => (int) $legacy], navigate: true);

            return;
        }

        $this->spaceId = Space::notesInboxFor((int) $this->householdId)->id;
    }

    public function addNote(): void
    {
        [$title, $body] = $this->split($this->draft);

        if ($title === '') {
            return;
        }

        Item::create([
            'household_id' => $this->householdId,
            'space_id' => $this->spaceId,
            'type' => Item::TYPE_NOTE,
            'visibility' => Item::VISIBILITY_SHARED,
            'title' => $title,
            'content' => ['body' => $body],
            'created_by' => Auth::id(),
            'position' => PageMover::positionForNewChild((int) $this->householdId, null),
        ]);

        $this->draft = '';
    }

    public function edit(int $id): void
    {
        $note = $this->findNote($id);
        $this->editingId = $note->id;
        $this->editText = trim($note->title."\n".(string) ($note->content['body'] ?? ''));
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editText = '';
    }

    public function saveEdit(): void
    {
        if (! $this->editingId) {
            return;
        }

        [$title, $body] = $this->split($this->editText);

        if ($title === '') {
            return;
        }

        $this->findNote($this->editingId)->update(['title' => $title, 'content' => ['body' => $body]]);
        $this->cancelEdit();
    }

    /**
     * Files the note into the page tree: it leaves the inbox and becomes a top-level page.
     */
    public function fileIntoPages(int $id): void
    {
        $note = $this->findNote($id);
        $note->update(['space_id' => null, 'position' => PageMover::positionForNewChild((int) $this->householdId, null)]);

        $this->redirectRoute('pages.index', ['item' => $note->id], navigate: true);
    }

    public function delete(int $id): void
    {
        $this->findNote($id)->delete();
        $this->cancelEdit();
    }

    private function findNote(int $id): Item
    {
        return Item::query()->ofType(Item::TYPE_NOTE)->forHousehold((int) $this->householdId)->visibleTo(Auth::user())
            ->where('space_id', $this->spaceId)->findOrFail($id);
    }

    /**
     * Splits a note into a title (first non-empty line, max 255) and the remaining body.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return ['', ''];
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [$text];
        $title = Str::limit(trim((string) array_shift($lines)), 252);

        return [$title, trim(implode("\n", $lines))];
    }

    public function with(): array
    {
        $notes = Item::query()->ofType(Item::TYPE_NOTE)->forHousehold((int) $this->householdId)->visibleTo(Auth::user())
            ->where('space_id', $this->spaceId)->latest()->get();

        return [
            'notes' => $notes->map(fn (Item $note) => [
                'id' => $note->id,
                'title' => $note->title,
                'html' => trim((string) ($note->content['body'] ?? '')) !== '' ? NoteMarkdown::toHtml($note, Auth::user()) : '',
                'updated' => $note->updated_at,
            ]),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">🗒️ {{ __('Notes') }}</h2>
            <a href="{{ route('pages.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">{{ __('To the pages') }} →</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-3xl space-y-5 px-4 sm:px-6 lg:px-8">
            {{-- Quick capture --}}
            <form wire:submit="addNote" class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                <label class="sr-only" for="note-draft">{{ __('New note') }}</label>
                <textarea id="note-draft" wire:model="draft" rows="3" class="block w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="{{ __('Jot something down … the first line becomes the title.') }}"></textarea>
                <div class="mt-2 flex items-center justify-between">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Notes are built like pages and can be filed into the page tree.') }}</p>
                    <x-primary-button>+ {{ __('Add note') }}</x-primary-button>
                </div>
            </form>

            {{-- Notes list --}}
            @forelse ($notes as $note)
                <section wire:key="note-{{ $note['id'] }}" class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                    @if ($editingId === $note['id'])
                        <form wire:submit="saveEdit">
                            <textarea wire:model="editText" rows="5" class="block w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"></textarea>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <x-primary-button>{{ __('Save') }}</x-primary-button>
                                <x-secondary-button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-secondary-button>
                                <button type="button" wire:click="delete({{ $note['id'] }})" wire:confirm="{{ __('Delete this note?') }}" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
                            </div>
                        </form>
                    @else
                        <div class="flex items-start justify-between gap-3">
                            <h3 class="min-w-0 font-semibold text-gray-900 dark:text-gray-100">{{ $note['title'] }}</h3>
                            <div class="flex shrink-0 gap-1">
                                <button type="button" wire:click="edit({{ $note['id'] }})" class="text-gray-400 hover:text-indigo-600" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" class="h-5 w-5" /></button>
                                <button type="button" wire:click="fileIntoPages({{ $note['id'] }})" wire:confirm="{{ __('File this note into the pages?') }}" class="text-gray-400 hover:text-indigo-600" title="{{ __('File into pages') }}" aria-label="{{ __('File into pages') }}">📄→</button>
                            </div>
                        </div>
                        @if ($note['html'] !== '')
                            <div class="prose prose-sm mt-2 max-w-none dark:prose-invert">{!! $note['html'] !!}</div>
                        @endif
                        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">{{ $note['updated']?->diffForHumans() }}</p>
                    @endif
                </section>
            @empty
                <p class="rounded-2xl bg-white p-8 text-center text-sm text-gray-500 shadow-xs dark:bg-gray-800 dark:text-gray-400">{{ __('No notes yet – jot your first one above.') }}</p>
            @endforelse
        </div>
    </div>
</div>
