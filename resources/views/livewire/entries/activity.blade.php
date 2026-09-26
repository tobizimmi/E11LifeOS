<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Comment;
use App\Models\Item;
use App\Models\User;
use App\Support\ActivityLog;
use App\Support\WebPush;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/*
 * Comments and history of one collection entry or task, shown in its detail view.
 */
new class extends Component
{
    use InteractsWithHousehold;

    private const PER_PAGE = 30;

    #[Locked]
    public int $itemId;

    public string $body = '';

    public int $limit = self::PER_PAGE;

    public bool $showHistory = false;

    private function item(): Item
    {
        return Item::query()->forHousehold((int) $this->householdId)->visibleTo($this->currentUser())
            ->whereNotNull('collection_id')->find($this->itemId) ?? abort(404);
    }

    public function addComment(WebPush $push): void
    {
        $this->validate(['body' => ['required', 'string', 'max:5000']], attributes: ['body' => __('Comment')]);
        $item = $this->item();

        $item->comments()->create(['user_id' => $this->currentUser()->id, 'body' => trim($this->body)]);
        $this->reset('body');

        if (! $item->isPrivate()) {
            $assignee = $item->collection?->systemProperty('assignee');
            $recipients = collect([$item->created_by, $assignee ? $item->propertyValue($assignee) : null])
                ->filter()->map(fn ($id) => (int) $id)->unique()->reject(fn (int $id) => $id === $this->currentUser()->id);

            User::whereIn('id', $recipients)->whereHas('households', fn ($query) => $query->whereKey($this->householdId))->get()
                ->each(fn (User $user) => $push->send($user, '💬 '.__(':name commented', ['name' => $this->currentUser()->name]), $item->title, route('items.show', $item)));
        }
    }

    public function deleteComment(int $commentId): void
    {
        $comment = $this->item()->comments()->find($commentId) ?? abort(404);
        abort_unless($comment->user_id === $this->currentUser()->id, 403);
        $comment->delete();
    }

    public function with(): array
    {
        $item = $this->item();
        $comments = $item->comments()->with('user:id,name')->latest()->limit($this->limit)->get()
            ->map(fn (Comment $comment) => ['type' => 'comment', 'at' => $comment->created_at, 'model' => $comment]);
        $activities = $this->showHistory
            ? $item->activities()->with('user:id,name')->latest('created_at')->limit($this->limit)->get()
                ->map(fn ($activity) => ['type' => 'activity', 'at' => $activity->created_at, 'model' => $activity])
            : collect();

        return [
            'timeline' => $comments->concat($activities)->sortByDesc('at')->take($this->limit)->values(),
            'commentCount' => $item->comments()->count(),
            'historyCount' => $item->activities()->count(),
        ];
    }
}; ?>

<section class="space-y-3" aria-label="{{ __('Comments & history') }}">
    <div class="flex items-center justify-between gap-2">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">💬 {{ __('Comments') }}@if ($commentCount) ({{ $commentCount }})@endif</h3>
        @if ($historyCount > 0)
            <label class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                <input type="checkbox" wire:model.live="showHistory" class="rounded border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">
                {{ __('Show history') }}
            </label>
        @endif
    </div>

    <form wire:submit="addComment" class="flex items-end gap-2">
        <textarea wire:model="body" rows="1" placeholder="{{ __('Write a comment …') }}" aria-label="{{ __('Comment') }}"
                  x-data x-on:keydown.enter="if ($event.metaKey || $event.ctrlKey) { $event.preventDefault(); $wire.addComment() }"
                  class="block min-h-[2.5rem] w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
        <x-primary-button wire:loading.attr="disabled" wire:target="addComment">{{ __('Send') }}</x-primary-button>
    </form>
    <x-input-error :messages="$errors->get('body')" />

    @if ($timeline->isNotEmpty())
        <ol class="space-y-2">
            @foreach ($timeline as $row)
                @if ($row['type'] === 'comment')
                    <li wire:key="comment-{{ $row['model']->id }}" class="group rounded-xl bg-gray-50 px-3 py-2 dark:bg-gray-900/40">
                        <p class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                            <strong class="text-gray-800 dark:text-gray-200">{{ $row['model']->user?->name ?? __('Former member') }}</strong>
                            <time datetime="{{ $row['at']->toIso8601String() }}" title="{{ $row['at']->translatedFormat('j. F Y, H:i') }}">{{ $row['at']->diffForHumans() }}</time>
                            @if ($row['model']->user_id === auth()->id())
                                <button type="button" wire:click="deleteComment({{ $row['model']->id }})" wire:confirm="{{ __('Delete this comment?') }}" class="ms-auto text-gray-400 opacity-0 hover:text-red-600 focus:opacity-100 group-hover:opacity-100" aria-label="{{ __('Delete') }}">✕</button>
                            @endif
                        </p>
                        <p class="mt-0.5 whitespace-pre-line break-words text-sm text-gray-800 dark:text-gray-100">{{ $row['model']->body }}</p>
                    </li>
                @else
                    <li wire:key="activity-{{ $row['model']->id }}" class="flex gap-2 px-3 text-xs text-gray-500 dark:text-gray-400">
                        <span aria-hidden="true">•</span>
                        <span><strong class="font-medium text-gray-700 dark:text-gray-300">{{ $row['model']->user?->name ?? __('Someone') }}</strong> {{ ActivityLog::describe($row['model']) }} · <time datetime="{{ $row['at']->toIso8601String() }}" title="{{ $row['at']->translatedFormat('j. F Y, H:i') }}">{{ $row['at']->diffForHumans() }}</time></span>
                    </li>
                @endif
            @endforeach
        </ol>
        @if ($timeline->count() >= $limit)
            <button type="button" wire:click="$set('limit', {{ $limit + 30 }})" class="text-xs text-indigo-600 hover:underline dark:text-indigo-300">{{ __('Show more') }}</button>
        @endif
    @endif
</section>
