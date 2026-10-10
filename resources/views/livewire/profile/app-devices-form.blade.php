<?php

use App\Models\ApiToken;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    /** A freshly created token, shown once so it can be copied into the shortcut. */
    public ?string $newToken = null;

    /**
     * Signs a device out (its token stops working immediately).
     */
    public function revoke(int $tokenId): void
    {
        ApiToken::where('user_id', Auth::id())->whereKey($tokenId)->delete();
    }

    /**
     * Creates a token for a Siri shortcut / automation and shows it once.
     */
    public function createSiriToken(): void
    {
        $this->newToken = ApiToken::issue(Auth::user(), 'Siri / '.__('Shortcut'));
    }

    public function with(): array
    {
        return [
            'tokens' => ApiToken::where('user_id', Auth::id())->latest()->get(),
            'apiUrl' => url('api/v1'),
        ];
    }
}; ?>

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Mobile app') }}
        </h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Sign in to the portfolio app on your phone with your e-mail address and password. Server address for the app:') }}
            <code class="rounded-sm bg-gray-100 px-1 text-xs dark:bg-gray-900">{{ $apiUrl }}</code>
        </p>
    </header>

    @if ($tokens->isEmpty())
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No device signed in yet.') }}</p>
    @else
        <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($tokens as $token)
                <li wire:key="token-{{ $token->id }}" class="flex items-center justify-between gap-3 py-2 text-sm">
                    <span class="min-w-0">
                        <span class="block truncate font-medium text-gray-900 dark:text-gray-100">📱 {{ $token->name }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('Signed in :date', ['date' => $token->created_at->format('d.m.Y')]) }} · {{ $token->last_used_at ? __('last used :time', ['time' => $token->last_used_at->diffForHumans()]) : __('not used yet') }}@if ($token->expiresAt()) · {{ __('expires :date without use', ['date' => $token->expiresAt()->format('d.m.Y')]) }}@endif</span>
                    </span>
                    <button type="button" wire:click="revoke({{ $token->id }})" wire:confirm="{{ __('Sign this device out?') }}" class="shrink-0 text-red-600 underline dark:text-red-400">{{ __('Sign out') }}</button>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="mt-6 rounded-lg bg-gray-50 p-4 dark:bg-gray-900/40">
        <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">🗣️ {{ __('Add to the shopping list with Siri') }}</h3>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Create an iOS shortcut that dictates to the shopping list – also hands-free via Siri. Several items at once work too, e.g. “2 litres of milk, butter and 6 eggs”.') }}</p>

        @if ($newToken)
            <div class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm dark:border-amber-800 dark:bg-amber-900/30" x-data="{ copied: false }">
                <p class="font-medium text-amber-900 dark:text-amber-200">{{ __('Your token (shown only once – copy it now):') }}</p>
                <div class="mt-1 flex items-center gap-2">
                    <code class="min-w-0 flex-1 truncate rounded bg-white px-2 py-1 font-mono text-xs dark:bg-gray-800" x-ref="token">{{ $newToken }}</code>
                    <button type="button" class="shrink-0 rounded-md bg-amber-600 px-2 py-1 text-xs font-medium text-white" x-on:click="navigator.clipboard?.writeText($refs.token.textContent).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"><span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak>✓</span></button>
                </div>
            </div>
        @else
            <button type="button" wire:click="createSiriToken" class="mt-3 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">{{ __('Create token for the shortcut') }}</button>
        @endif

        <div class="mt-4 text-sm text-gray-600 dark:text-gray-300">
            <p class="font-medium text-gray-800 dark:text-gray-200">{{ __('Set up the shortcut (iPhone/iPad):') }}</p>
            <ol class="mt-1 list-decimal space-y-1 ps-5">
                <li>{{ __('Open the Shortcuts app → “+” → add the action “Get Contents of URL”.') }}</li>
                <li>{{ __('URL:') }} <code class="rounded bg-gray-100 px-1 text-xs dark:bg-gray-800">{{ $apiUrl }}/shopping/quick</code></li>
                <li>{{ __('Method: POST · Request body: JSON with one field “text”.') }}</li>
                <li>{{ __('Headers: “Authorization” = “Bearer <your token>” and “Accept” = “application/json”.') }}</li>
                <li>{{ __('For “text”, use the shortcut input or an “Ask for Text” / “Dictate Text” action.') }}</li>
                <li>{{ __('Name the shortcut e.g. “Add to shopping list” – then say “Hey Siri, Add to shopping list”.') }}</li>
            </ol>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('The items land in your household’s default shopping list. The token stays valid as long as it is used; an unused token expires on its own.') }}</p>
        </div>
    </div>
</section>
