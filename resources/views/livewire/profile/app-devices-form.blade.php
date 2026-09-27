<?php

use App\Models\ApiToken;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Signs a device out (its token stops working immediately).
     */
    public function revoke(int $tokenId): void
    {
        ApiToken::where('user_id', Auth::id())->whereKey($tokenId)->delete();
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
            <code class="rounded bg-gray-100 px-1 text-xs dark:bg-gray-900">{{ $apiUrl }}</code>
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
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('Signed in :date', ['date' => $token->created_at->format('d.m.Y')]) }} · {{ $token->last_used_at ? __('last used :time', ['time' => $token->last_used_at->diffForHumans()]) : __('not used yet') }}</span>
                    </span>
                    <button type="button" wire:click="revoke({{ $token->id }})" wire:confirm="{{ __('Sign this device out?') }}" class="shrink-0 text-red-600 underline dark:text-red-400">{{ __('Sign out') }}</button>
                </li>
            @endforeach
        </ul>
    @endif
</section>
