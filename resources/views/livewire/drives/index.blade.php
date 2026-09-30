<?php

use App\Models\NetworkDrive;
use App\Support\Drives\GoogleDriveClient;
use App\Support\Drives\WebDavClient;
use App\Support\Security\VirusScanner;
use App\Support\Vpn;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * Network drives (Synology/QNAP NAS, Nextcloud, storage box) over WebDAV: browse, download and
 * upload – drives in the home network are reached through the WireGuard VPN.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    #[Url(except: '')]
    public string $drive = '';

    #[Url(except: '')]
    public string $path = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    /**
     * @var array{name: string, url: string, username: string, password: string}
     */
    public array $form = ['name' => '', 'url' => '', 'username' => '', 'password' => ''];

    public $upload = null;

    public ?string $error = null;

    private function findDrive(int $id): NetworkDrive
    {
        return NetworkDrive::where('user_id', Auth::id())->find($id) ?? abort(404);
    }

    public function create(): void
    {
        $this->reset(['editingId', 'form']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $drive = $this->findDrive($id);
        $this->editingId = $drive->id;
        // The password is never sent back to the browser; empty keeps the saved one.
        $this->form = ['name' => $drive->name, 'url' => $drive->url, 'username' => (string) $drive->username, 'password' => ''];
        $this->showForm = true;
    }

    public function save(): void
    {
        if ($this->editingId && $this->findDrive($this->editingId)->isGoogle()) {
            $name = $this->validate(['form.name' => ['required', 'string', 'max:100']])['form']['name'];
            $this->findDrive($this->editingId)->update(['name' => $name]);
            $this->open($this->editingId);
            $this->reset(['editingId', 'form', 'showForm']);

            return;
        }

        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:100'],
            'form.url' => ['required', 'url:http,https', 'max:500', fn ($attribute, $value, $fail) => WebDavClient::isAllowedUrl((string) $value) ? null : $fail(__('This address is not allowed. Use an address in the home network that goes through the VPN, or a public address.'))],
            'form.username' => ['nullable', 'string', 'max:150'],
            'form.password' => ['nullable', 'string', 'max:255'],
        ], attributes: ['form.url' => __('WebDAV address')])['form'];

        $attributes = ['name' => $data['name'], 'url' => $data['url'], 'username' => $data['username'] ?: null];

        if ($data['password'] !== '' && $data['password'] !== null) {
            $attributes['password'] = $data['password'];
        }

        $drive = $this->editingId ? tap($this->findDrive($this->editingId))->update($attributes) : NetworkDrive::create($attributes + ['user_id' => Auth::id()]);
        $this->reset(['editingId', 'form', 'showForm']);
        $this->open($drive->id);
    }

    public function delete(int $id): void
    {
        $this->findDrive($id)->delete();
        $this->reset(['drive', 'path', 'editingId', 'form', 'showForm']);
    }

    public function open(int $id, string $path = ''): void
    {
        $this->drive = (string) $this->findDrive($id)->id;
        $this->path = WebDavClient::cleanPath($path);
        $this->error = null;
    }

    public function uploadFile(): void
    {
        $drive = $this->findDrive((int) $this->drive);
        $this->validate(['upload' => ['required', 'file', 'max:102400']], attributes: ['upload' => __('File')]);

        try {
            if (VirusScanner::isEnabled() && app(VirusScanner::class)->scanFile($this->upload->getRealPath())['status'] === VirusScanner::INFECTED) {
                throw new RuntimeException(__('The file was rejected by the virus scanner.'));
            }

            $stream = fopen($this->upload->getRealPath(), 'rb');
            $drive->client()->upload($this->path, $this->upload->getClientOriginalName(), $stream);
            $this->dispatch('toast', message: __('Uploaded to :drive.', ['drive' => $drive->name]));
        } catch (Throwable $exception) {
            $this->addError('upload', $exception->getMessage());
        }

        $this->reset('upload');
    }

    public function with(): array
    {
        $drives = NetworkDrive::where('user_id', Auth::id())->orderBy('name')->get();
        $current = $this->drive !== '' ? $drives->firstWhere('id', (int) $this->drive) : null;
        $entries = [];

        if ($current) {
            try {
                $entries = $current->client()->list($this->path);
                $current->forceFill(['last_checked_at' => now(), 'last_error' => null])->save();
            } catch (Throwable $exception) {
                $this->error = $exception->getMessage();
                $current->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 500)])->save();
            }
        }

        return [
            'drives' => $drives,
            'current' => $current,
            'entries' => $entries,
            'crumbs' => collect(explode('/', $this->path))->filter()->values(),
            'vpnReady' => Vpn::isConfigured(),
            'googleReady' => GoogleDriveClient::isConfigured(),
            'editingGoogle' => $this->editingId !== null && (bool) $drives->firstWhere('id', $this->editingId)?->isGoogle(),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $size = fn (?int $bytes) => $bytes === null ? '' : ($bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.').' MB' : number_format(max(1, $bytes / 1024), 0, ',', '.').' KB');
@endphp

<div x-on:page-primary.window="$wire.create()">
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Network drives') }}</h2>
            <x-primary-button type="button" x-on:click="$dispatch('page-primary')" class="shrink-0">+ {{ __('Add drive') }}</x-primary-button>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center gap-2">
                @foreach ($drives as $item)
                    <button type="button" wire:click="open({{ $item->id }})" wire:key="drive-{{ $item->id }}" @class(['rounded-full px-3 py-1.5 text-sm', 'bg-indigo-600 text-white' => $current?->id === $item->id, 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700' => $current?->id !== $item->id])>🗄️ {{ $item->name }}@if ($item->last_error) <span class="text-red-500" title="{{ $item->last_error }}">●</span>@endif</button>
                @endforeach
                @if ($googleReady && ! $drives->contains(fn ($item) => $item->isGoogle()))
                    <a href="{{ route('drives.google.connect') }}" class="ms-auto inline-flex items-center rounded-full bg-white px-3 py-1.5 text-sm text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600">+ {{ __('Connect Google Drive') }}</a>
                @endif
            </div>

            @if (session('error'))
                <p class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200" role="alert">⚠️ {{ session('error') }}</p>
            @endif

            @if ($showForm)
                <form wire:submit="save" class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-6 dark:bg-gray-800">
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="form.name" class="{{ $input }}" placeholder="{{ __('e.g. Synology at home') }}"><x-input-error :messages="$errors->get('form.name')" /></label>
                    @if ($editingGoogle)
                    <p class="text-sm text-gray-600 sm:col-span-4 dark:text-gray-300">{{ __('Connected via the Google sign-in.') }} <a href="{{ route('drives.google.connect') }}" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Connect again') }}</a></p>
                    @else
                    <label class="text-sm text-gray-700 sm:col-span-4 dark:text-gray-300">{{ __('WebDAV address') }}<input type="url" wire:model="form.url" class="{{ $input }}" placeholder="https://192.168.1.10:5006/home/"><x-input-error :messages="$errors->get('form.url')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('User name') }}<input type="text" wire:model="form.username" class="{{ $input }}" autocomplete="off"></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Password') }}<input type="password" wire:model="form.password" class="{{ $input }}" autocomplete="new-password" placeholder="{{ $editingId ? __('unchanged') : '' }}"></label>
                    <p class="text-xs text-gray-500 sm:col-span-6 dark:text-gray-400">{{ __('Synology: enable “WebDAV Server” in the Package Center (HTTPS port 5006). QNAP: Control Panel → Network services → WebDAV. Nextcloud: address from Files → Settings → WebDAV. Use a separate user with access to the shared folders you need only.') }}</p>
                    @endif
                    <div class="flex flex-wrap gap-2 sm:col-span-6">
                        <x-primary-button>{{ __('Save and connect') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="$set('showForm', false)">{{ __('Cancel') }}</x-secondary-button>
                        @if ($editingId)
                            <button type="button" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('Remove this drive? The files on it stay untouched.') }}" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Remove') }}</button>
                        @endif
                    </div>
                </form>
            @endif

            <details class="rounded-xl bg-white px-4 py-3 text-sm text-gray-600 shadow-sm dark:bg-gray-800 dark:text-gray-300">
                <summary class="cursor-pointer font-medium text-gray-800 dark:text-gray-100">{{ __('Google Drive and iCloud?') }}</summary>
                <p class="mt-2">{{ $googleReady ? __('Google Drive is connected with your Google sign-in. You can revoke the access at any time in your Google account under Security → Third-party apps.') : __('Google Drive can be connected as soon as the admin has entered a Google client under Admin → Settings → Google Drive.') }}</p>
                <p class="mt-2">{{ __('iCloud Drive has no interface for other apps, so it cannot be connected directly. Ways that work: sync the iCloud Drive folder on a Mac to your NAS (e.g. with Synology Drive Client) and connect the NAS here, or save files from the Files app on the iPhone to the NAS or Google Drive.') }}</p>
            </details>

            @if (! $vpnReady)
                <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">🔐 {{ __('For a NAS at home the VPN must be set up (Admin → Settings → VPN, WireGuard configuration from your router, e.g. FRITZ!Box). Public addresses such as a Nextcloud or storage box work without it.') }}</p>
            @endif

            @if ($current)
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <nav class="flex flex-wrap items-center gap-1 text-sm" aria-label="{{ __('Folder') }}">
                            <button type="button" wire:click="open({{ $current->id }})" class="font-semibold text-indigo-600 hover:underline dark:text-indigo-400">🗄️ {{ $current->name }}</button>
                            @foreach ($crumbs as $index => $crumb)
                                <span class="text-gray-400">/</span>
                                <button type="button" wire:click="open({{ $current->id }}, @js($crumbs->take($index + 1)->implode('/')))" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $crumb }}</button>
                            @endforeach
                        </nav>
                        <button type="button" wire:click="edit({{ $current->id }})" class="text-sm text-gray-500 underline dark:text-gray-400">{{ __('Settings') }}</button>
                    </div>

                    @if ($error)
                        <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200" role="alert">⚠️ {{ $error }}</p>
                    @else
                        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                            @if ($crumbs->isNotEmpty())
                                <li><button type="button" wire:click="open({{ $current->id }}, @js($crumbs->slice(0, -1)->implode('/')))" class="flex w-full items-center gap-2 py-2 text-sm text-gray-600 hover:underline dark:text-gray-300">↩ {{ __('Up') }}</button></li>
                            @endif
                            @forelse ($entries as $entry)
                                <li wire:key="entry-{{ md5($entry['path']) }}" class="flex items-center gap-3 py-2 text-sm">
                                    @if ($entry['is_dir'])
                                        <button type="button" wire:click="open({{ $current->id }}, @js($entry['path']))" class="min-w-0 flex-1 truncate text-left text-gray-900 hover:underline dark:text-gray-100">📁 {{ $entry['name'] }}</button>
                                    @else
                                        <a href="{{ route('drives.download', ['drive' => $current->id, 'path' => $entry['path']]) }}" class="min-w-0 flex-1 truncate text-gray-900 hover:underline dark:text-gray-100">📄 {{ $entry['name'] }}</a>
                                        <span class="shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $size($entry['size']) }}</span>
                                    @endif
                                    <span class="hidden w-28 shrink-0 text-right text-xs text-gray-500 sm:inline dark:text-gray-400">{{ $entry['modified']?->format('d.m.Y H:i') }}</span>
                                </li>
                            @empty
                                <li class="py-3 text-sm text-gray-500 dark:text-gray-400">{{ __('This folder is empty.') }}</li>
                            @endforelse
                        </ul>

                        <form wire:submit="uploadFile" class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4 dark:border-gray-700">
                            <input type="file" wire:model="upload" class="min-w-0 flex-1 text-sm text-gray-700 dark:text-gray-300" aria-label="{{ __('File to upload') }}">
                            <x-primary-button wire:loading.attr="disabled" wire:target="upload,uploadFile">⬆ {{ __('Upload here') }}</x-primary-button>
                        </form>
                        <x-input-error :messages="$errors->get('upload')" class="mt-1" />
                    @endif
                </section>
            @elseif ($drives->isEmpty() && ! $showForm)
                <section class="rounded-2xl bg-white p-6 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">🗄️</p>
                    <p class="mt-2 text-gray-700 dark:text-gray-200">{{ __('No network drive yet.') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Connect your NAS (Synology, QNAP), a Nextcloud or a storage box via WebDAV to browse, download and upload documents – at home securely through the VPN.') }}</p>
                </section>
            @endif
        </div>
    </div>
</div>
