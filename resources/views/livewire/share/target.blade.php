<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Support\Addons;
use App\Support\QuickCapture;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Share target of the installed app ("Share → E11LifeOS"): a link or text from another app
 * becomes a recipe, an inbox entry or a task.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    /** What the other app sent (query string of the share target). */
    #[Url]
    public string $title = '';

    #[Url]
    public string $text = '';

    #[Url]
    public string $url = '';

    #[Locked]
    public string $sharedTitle = '';

    #[Locked]
    public string $sharedText = '';

    #[Locked]
    public string $sharedUrl = '';

    public function mount(): void
    {
        $this->resolveHousehold();
        $this->sharedTitle = mb_substr(trim($this->title), 0, 255);
        $text = mb_substr(trim($this->text), 0, 2000);
        $url = trim($this->url);

        // Many apps put the link into the text.
        if ($url === '' && preg_match('~https?://\S+~u', $text, $match)) {
            $url = $match[0];
            $text = trim(str_replace($url, '', $text));
        }

        $this->sharedUrl = filter_var($url, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $url) ? mb_substr($url, 0, 2000) : '';
        $this->sharedText = $text;
    }

    private function line(): string
    {
        return trim(implode(' ', array_unique(array_filter([$this->sharedTitle ?: $this->sharedText, $this->sharedUrl]))));
    }

    public function toRecipe(): void
    {
        abort_unless($this->sharedUrl !== '' && Addons::allowed(Auth::user(), 'recipes'), 404);

        $this->redirectRoute('recipes.import', ['url' => $this->sharedUrl], navigate: true);
    }

    public function toInbox(): void
    {
        abort_if($this->line() === '', 422);
        QuickCapture::inbox(Auth::user(), (int) $this->householdId, $this->line());

        $this->redirectRoute('inbox.index', navigate: true);
    }

    public function toTask(): void
    {
        abort_if($this->line() === '', 422);
        QuickCapture::task(Auth::user(), (int) $this->householdId, $this->line(), ignored: [$this->line()]);

        $this->redirectRoute('todos.index', navigate: true);
    }

    public function with(): array
    {
        return ['recipesAllowed' => Addons::allowed(Auth::user(), 'recipes')];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Shared with E11LifeOS') }}</h2>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="mx-auto max-w-lg space-y-4 px-4">
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200/60 dark:bg-gray-800 dark:ring-gray-700">
                @if ($sharedTitle !== '' || $sharedText !== '' || $sharedUrl !== '')
                    @if ($sharedTitle !== '')
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $sharedTitle }}</p>
                    @endif
                    @if ($sharedText !== '')
                        <p class="mt-1 whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ \Illuminate\Support\Str::limit($sharedText, 400) }}</p>
                    @endif
                    @if ($sharedUrl !== '')
                        <p class="mt-1 truncate text-sm text-indigo-600 dark:text-indigo-400">{{ $sharedUrl }}</p>
                    @endif
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing was shared.') }}</p>
                @endif
            </div>

            @if ($sharedTitle !== '' || $sharedText !== '' || $sharedUrl !== '')
                <div class="grid gap-2">
                    @if ($sharedUrl !== '' && $recipesAllowed)
                        <button type="button" wire:click="toRecipe" class="rounded-xl bg-indigo-600 px-4 py-3 text-start font-medium text-white hover:bg-indigo-500">🍳 {{ __('Import as recipe') }}</button>
                    @endif
                    <button type="button" wire:click="toInbox" class="rounded-xl bg-white px-4 py-3 text-start font-medium text-gray-900 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-700 dark:hover:bg-gray-700">📥 {{ __('Save to inbox') }}</button>
                    <button type="button" wire:click="toTask" class="rounded-xl bg-white px-4 py-3 text-start font-medium text-gray-900 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-700 dark:hover:bg-gray-700">✅ {{ __('Create a task') }}</button>
                </div>
            @endif
        </div>
    </div>
</div>
