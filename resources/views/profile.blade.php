<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @if (session('two-factor-required') || (config('security.require_two_factor') && ! auth()->user()->hasSecondFactor()))
                <p class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100" role="alert">🔐 {{ __('Please set up two-factor authentication below – it is required on this server.') }}</p>
            @endif
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-xs rounded-2xl">
                <div class="max-w-xl">
                    <livewire:profile.update-profile-information-form />
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-xs rounded-2xl">
                <div class="max-w-xl">
                    <livewire:profile.update-password-form />
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-xs rounded-2xl">
                <div class="max-w-xl">
                    <livewire:profile.two-factor-form />
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-xs rounded-2xl">
                <div class="max-w-xl">
                    <livewire:profile.passkeys-form />
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-xs rounded-2xl">
                <div class="max-w-xl">
                    <livewire:profile.security-form />
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-xs rounded-2xl">
                <div class="max-w-xl">
                    <livewire:profile.reminder-settings-form />
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-xs rounded-2xl">
                <div class="max-w-xl">
                    <livewire:profile.weekly-digest-form />
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-xs rounded-2xl">
                <div class="max-w-xl">
                    <livewire:profile.morning-briefing-form />
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-xs rounded-2xl">
                <div class="max-w-xl">
                    <livewire:profile.paperless-form />
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-xs rounded-2xl">
                <div class="max-w-xl">
                    <livewire:profile.push-notifications-form />
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-xs rounded-2xl">
                <div class="max-w-xl">
                    <livewire:profile.calendar-feed-form />
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-xs rounded-2xl">
                <livewire:profile.calendar-sync-form />
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-xs rounded-2xl">
                <div class="max-w-xl">
                    <livewire:profile.app-devices-form />
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-xs rounded-2xl">
                <livewire:profile.data-overview />
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-xs rounded-2xl">
                <section class="space-y-6 max-w-xl">
                    <header>
                        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                            {{ __('Export your data') }}
                        </h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            {{ __('Download a copy of your account and household data. The file is a ZIP archive encrypted with AES-256 – choose a password to open it (e.g. with 7-Zip, Keka or WinRAR).') }}
                        </p>
                    </header>

                    <form method="POST" action="{{ route('profile.export.encrypted') }}" class="grid gap-3 sm:grid-cols-2">
                        @csrf
                        <div class="sm:col-span-2">
                            <x-input-label for="export_current_password" :value="__('Your current password')" />
                            <x-text-input id="export_current_password" name="current_password" type="password" class="mt-1 block w-full sm:w-1/2" autocomplete="current-password" required />
                            <x-input-error :messages="$errors->get('current_password')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="export_password" :value="__('Password for the file')" />
                            <x-text-input id="export_password" name="export_password" type="password" class="mt-1 block w-full" autocomplete="new-password" required minlength="8" />
                        </div>
                        <div>
                            <x-input-label for="export_password_confirmation" :value="__('Repeat password')" />
                            <x-text-input id="export_password_confirmation" name="export_password_confirmation" type="password" class="mt-1 block w-full" autocomplete="new-password" required minlength="8" />
                        </div>
                        <x-input-error :messages="$errors->get('export_password')" class="sm:col-span-2" />
                        <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                            <x-primary-button>{{ __('Download encrypted') }}</x-primary-button>
                            <a href="{{ route('profile.export') }}" class="text-sm text-gray-600 underline dark:text-gray-400">{{ __('Unencrypted JSON') }}</a>
                        </div>
                    </form>
                </section>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-xs rounded-2xl">
                <div class="max-w-xl">
                    <livewire:profile.delete-user-form />
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
