<?php

use App\Models\PaperlessInstance;
use App\Support\Paperless\PaperlessClient;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $url = '';

    public string $token = '';

    public bool $shared = false;

    public bool $showForm = false;

    public ?string $status = null;

    public bool $ok = false;

    public function add(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'name', 'url', 'token', 'shared', 'status');
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $instance = $this->ownInstance($id);
        $this->resetValidation();
        $this->reset('token', 'status');
        $this->editingId = $instance->id;
        $this->name = $instance->name;
        $this->url = $instance->url;
        $this->shared = $instance->shared;
        $this->showForm = true;
    }

    private function ownInstance(int $id): PaperlessInstance
    {
        return PaperlessInstance::where('user_id', Auth::id())->find($id) ?? abort(404);
    }

    /**
     * Saves the instance after a test request; an empty token keeps the saved one.
     */
    public function save(): void
    {
        $this->validate(['name' => ['nullable', 'string', 'max:100'], 'url' => ['required', 'string', 'max:255'], 'token' => ['nullable', 'string', 'max:255'], 'shared' => ['boolean']]);
        $user = Auth::user();
        $household = $user->currentHousehold();
        abort_unless($household, 403);
        $instance = $this->editingId ? $this->ownInstance($this->editingId) : null;
        $url = PaperlessClient::normaliseUrl($this->url);

        if (! $url) {
            $this->addError('url', __('Enter the web address of your paperless-ngx, e.g. https://paperless.example.com.'));

            return;
        }

        $token = trim($this->token) !== '' ? trim($this->token) : (string) $instance?->token;

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

        $attributes = [
            'name' => trim($this->name) ?: (string) parse_url($url, PHP_URL_HOST),
            'url' => $url,
            'token' => $token,
            'shared' => $this->shared,
        ];

        $instance
            ? $instance->update($attributes)
            : PaperlessInstance::create($attributes + ['household_id' => $household->id, 'user_id' => $user->id]);

        $this->reset('editingId', 'name', 'url', 'token', 'shared', 'showForm');
        $this->ok = true;
        $this->status = trans_choice('Connected – :count document in paperless.|Connected – :count documents in paperless.', $count);
    }

    public function toggleShared(int $id): void
    {
        $instance = $this->ownInstance($id);
        $instance->update(['shared' => ! $instance->shared]);
    }

    public function remove(int $id): void
    {
        $this->ownInstance($id)->delete();
        $this->reset('editingId', 'showForm', 'status');
    }

    public function with(): array
    {
        return [
            'instances' => PaperlessInstance::query()->availableTo(Auth::user())->with('user')->orderBy('name')->get(),
        ];
    }
}; ?>

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">📄 paperless-ngx</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Link documents from paperless-ngx to entries, properties and invoices, preview them and upload new ones. The documents stay in paperless; the app only remembers which document belongs where.') }}</p>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('For each instance you decide: only for you, or for the whole household – then everyone in the household works with your token and sees what it may see in paperless.') }}</p>
    </header>

    @if ($instances->isNotEmpty())
        <ul class="mt-4 divide-y divide-gray-100 rounded-lg ring-1 ring-gray-200 dark:divide-gray-700 dark:ring-gray-700">
            @foreach ($instances as $instance)
                <li wire:key="paperless-instance-{{ $instance->id }}" class="flex flex-wrap items-center gap-3 px-3 py-2.5 text-sm">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $instance->name }}</p>
                        <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $instance->url }}@if ($instance->user_id !== auth()->id()) · {{ __('shared by :name', ['name' => $instance->user?->name]) }}@endif</p>
                    </div>
                    @if ($instance->user_id === auth()->id())
                        <div class="inline-flex rounded-lg bg-gray-100 p-0.5 text-xs dark:bg-gray-900" role="group" aria-label="{{ __('Who may use it') }}">
                            <button type="button" wire:click="toggleShared({{ $instance->id }})" @disabled(! $instance->shared) aria-pressed="{{ $instance->shared ? 'false' : 'true' }}" @class(['rounded-md px-2 py-1', 'bg-white font-medium shadow-sm dark:bg-gray-700 dark:text-gray-100' => ! $instance->shared, 'text-gray-600 dark:text-gray-400' => $instance->shared])>🔒 {{ __('Only me') }}</button>
                            <button type="button" wire:click="toggleShared({{ $instance->id }})" @disabled($instance->shared) aria-pressed="{{ $instance->shared ? 'true' : 'false' }}" @class(['rounded-md px-2 py-1', 'bg-white font-medium shadow-sm dark:bg-gray-700 dark:text-gray-100' => $instance->shared, 'text-gray-600 dark:text-gray-400' => ! $instance->shared])>👥 {{ __('Household') }}</button>
                        </div>
                        <button type="button" wire:click="edit({{ $instance->id }})" class="text-gray-400 hover:text-indigo-600" aria-label="{{ __('Edit') }}">✎</button>
                        <button type="button" wire:click="remove({{ $instance->id }})" wire:confirm="{{ __('Remove this paperless connection? Links to its documents are kept, but cannot be previewed any more.') }}" class="text-gray-400 hover:text-red-600" aria-label="{{ __('Remove') }}">✕</button>
                    @else
                        <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">👥 {{ __('Household') }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($status)
        <p @class(['mt-3 text-sm', 'text-green-700 dark:text-green-400' => $ok, 'text-red-700 dark:text-red-400' => ! $ok]) role="status">{{ $ok ? '✓ ' : '⚠ ' }}{{ $status }}</p>
    @endif

    @if ($showForm)
        <form wire:submit="save" class="mt-4 space-y-3 rounded-lg bg-gray-50 p-4 dark:bg-gray-900/50">
            <div>
                <x-input-label for="paperless-name" :value="__('Name')" />
                <x-text-input id="paperless-name" wire:model="name" type="text" class="mt-1 block w-full" :placeholder="__('e.g. Family archive')" />
            </div>
            <div>
                <x-input-label for="paperless-url" :value="__('Address')" />
                <x-text-input id="paperless-url" wire:model="url" type="url" class="mt-1 block w-full" placeholder="https://paperless.example.com" />
                <x-input-error :messages="$errors->get('url')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="paperless-token" :value="__('API token')" />
                <x-text-input id="paperless-token" wire:model="token" type="password" autocomplete="off" class="mt-1 block w-full" :placeholder="$editingId ? __('saved – enter a new one to replace it') : ''" />
                <x-input-error :messages="$errors->get('token')" class="mt-1" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('In paperless: click your name → "My Profile" → "API Auth Token". The server must be able to reach paperless over the internet (or VPN).') }}</p>
            </div>
            <div>
                <x-input-label for="paperless-shared" :value="__('Who may use it')" />
                <select id="paperless-shared" wire:model="shared" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option value="0">🔒 {{ __('Only me') }}</option>
                    <option value="1">👥 {{ __('Whole household (uses my token)') }}</option>
                </select>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <x-primary-button>{{ __('Save & test') }}</x-primary-button>
                <x-secondary-button type="button" wire:click="$set('showForm', false)">{{ __('Cancel') }}</x-secondary-button>
            </div>
        </form>
    @else
        <x-secondary-button type="button" wire:click="add" class="mt-4">+ {{ __('Connect paperless') }}</x-secondary-button>
    @endif
</section>
