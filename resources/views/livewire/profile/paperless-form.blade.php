<?php

use App\Support\Paperless\PaperlessClient;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $url = '';

    public string $token = '';

    public ?string $status = null;

    public bool $ok = false;

    public function mount(): void
    {
        $this->url = (string) Auth::user()->paperless_url;
    }

    /**
     * Saves address and token after a test request; an empty token keeps the saved one.
     */
    public function save(): void
    {
        $this->validate(['url' => ['required', 'string', 'max:255'], 'token' => ['nullable', 'string', 'max:255']]);
        $user = Auth::user();
        $url = PaperlessClient::normaliseUrl($this->url);

        if (! $url) {
            $this->addError('url', __('Enter the web address of your paperless-ngx, e.g. https://paperless.example.com.'));

            return;
        }

        $token = trim($this->token) !== '' ? trim($this->token) : (string) $user->paperless_token;

        if ($token === '') {
            $this->addError('token', __('Create a token in paperless under your profile ("API Auth Token").'));

            return;
        }

        try {
            $count = (new PaperlessClient($url, $token))->count();
        } catch (Throwable $exception) {
            $this->ok = false;
            $this->status = __('Connection failed – check address and token. (:message)', ['message' => str($exception->getMessage())->limit(120)]);

            return;
        }

        $user->update(['paperless_url' => $url, 'paperless_token' => $token]);
        $this->url = $url;
        $this->token = '';
        $this->ok = true;
        $this->status = trans_choice('Connected – :count document in paperless.|Connected – :count documents in paperless.', $count);
    }

    public function disconnect(): void
    {
        Auth::user()->update(['paperless_url' => null, 'paperless_token' => null]);
        $this->reset('url', 'token', 'status', 'ok');
    }
}; ?>

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">📄 paperless-ngx</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Link documents from your paperless-ngx to entries, properties and invoices, preview them and upload new ones. The documents stay in paperless; the app only remembers which document belongs where. Everyone uses their own access.') }}</p>
    </header>

    <form wire:submit="save" class="mt-4 space-y-3">
        <div>
            <x-input-label for="paperless-url" :value="__('Address')" />
            <x-text-input id="paperless-url" wire:model="url" type="url" class="mt-1 block w-full" placeholder="https://paperless.example.com" />
            <x-input-error :messages="$errors->get('url')" class="mt-1" />
        </div>
        <div>
            <x-input-label for="paperless-token" :value="__('API token')" />
            <x-text-input id="paperless-token" wire:model="token" type="password" autocomplete="off" class="mt-1 block w-full" :placeholder="auth()->user()->hasPaperless() ? __('saved – enter a new one to replace it') : ''" />
            <x-input-error :messages="$errors->get('token')" class="mt-1" />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('In paperless: click your name → "My Profile" → "API Auth Token". The server must be able to reach paperless over the internet (or VPN).') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <x-primary-button>{{ __('Save & test') }}</x-primary-button>
            @if (auth()->user()->hasPaperless())
                <button type="button" wire:click="disconnect" wire:confirm="{{ __('Disconnect paperless? Links to documents are kept.') }}" class="text-sm text-red-600 underline dark:text-red-400">{{ __('Disconnect') }}</button>
            @endif
        </div>
        @if ($status)
            <p @class(['text-sm', 'text-green-700 dark:text-green-400' => $ok, 'text-red-700 dark:text-red-400' => ! $ok]) role="status">{{ $ok ? '✓ ' : '⚠ ' }}{{ $status }}</p>
        @endif
    </form>
</section>
