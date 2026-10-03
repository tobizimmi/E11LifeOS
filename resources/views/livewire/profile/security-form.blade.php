<?php

use App\Models\SecurityEvent;
use App\Support\Security\SecurityLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    public string $password = '';

    public int $limit = 15;

    private function usesDatabaseSessions(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * Signs out every other browser (their sessions and "remember me" become invalid).
     */
    public function logoutOtherDevices(): void
    {
        $this->validate(['password' => ['required', 'string']]);

        if (! Auth::guard('web')->validate(['email' => Auth::user()->email, 'password' => $this->password])) {
            SecurityLog::record(Auth::user(), 'login_failed', details: 'confirm');

            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }

        Auth::guard('web')->logoutOtherDevices($this->password);

        if ($this->usesDatabaseSessions()) {
            DB::table(config('session.table', 'sessions'))->where('user_id', Auth::id())->where('id', '!=', Session::getId())->delete();
        }

        SecurityLog::record(Auth::user(), 'logout_other_devices');
        $this->reset('password');
        $this->dispatch('toast', message: __('All other devices were signed out.'));
    }

    /**
     * Signs out one other browser session.
     */
    public function revokeSession(string $id): void
    {
        abort_unless($this->usesDatabaseSessions() && $id !== Session::getId(), 404);
        $deleted = DB::table(config('session.table', 'sessions'))->where('user_id', Auth::id())->where('id', $id)->delete();
        abort_unless($deleted, 404);
        SecurityLog::record(Auth::user(), 'session_revoked');
    }

    public function with(): array
    {
        $sessions = $this->usesDatabaseSessions()
            ? DB::table(config('session.table', 'sessions'))->where('user_id', Auth::id())->orderByDesc('last_activity')->limit(20)->get()
                ->map(fn (object $session) => [
                    'id' => $session->id,
                    'device' => SecurityLog::device((string) $session->user_agent),
                    'ip' => (string) $session->ip_address,
                    'active' => Carbon::createFromTimestamp($session->last_activity),
                    'current' => $session->id === Session::getId(),
                ])
            : collect();

        return [
            'sessions' => $sessions,
            'databaseSessions' => $this->usesDatabaseSessions(),
            'events' => SecurityEvent::where('user_id', Auth::id())->latest('created_at')->latest('id')->limit($this->limit)->get(),
            'labels' => SecurityLog::events(),
            'idleMinutes' => (int) config('security.idle_minutes'),
        ];
    }
}; ?>

@php
    $levels = ['info' => 'text-gray-600 dark:text-gray-300', 'warning' => 'text-amber-700 dark:text-amber-400', 'critical' => 'text-red-700 dark:text-red-400'];
    $areas = ['health' => __('Health'), 'emergency' => __('Emergency folder'), 'banking' => __('Bank sync'), 'confirm' => __('Password confirmation')];
@endphp

<section id="security">
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">🔐 {{ __('Account security') }}</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Where you are signed in and what happened on your account. Sign-ins from new devices and repeated failed attempts are reported to you by e-mail and push.') }}
            @if ($idleMinutes > 0)
                {{ __('Browsers are signed out after :minutes minutes without activity.', ['minutes' => $idleMinutes]) }}
            @endif
        </p>
    </header>

    <h3 class="mt-5 text-sm font-medium text-gray-800 dark:text-gray-200">{{ __('Signed-in browsers') }}</h3>
    @if ($databaseSessions)
        <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-700">
            @foreach ($sessions as $session)
                <li wire:key="session-{{ $session['id'] }}" class="flex items-center gap-3 py-2">
                    <span class="min-w-0 flex-1">
                        <span class="block text-gray-900 dark:text-gray-100">{{ $session['device'] }} @if ($session['current'])<span class="ms-1 rounded-full bg-emerald-50 px-2 text-xs text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">{{ __('this browser') }}</span>@endif</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $session['ip'] }} · {{ __('active :time', ['time' => $session['active']->diffForHumans()]) }}</span>
                    </span>
                    @unless ($session['current'])
                        <button type="button" wire:click="revokeSession('{{ $session['id'] }}')" class="text-xs text-red-600 hover:underline dark:text-red-400">{{ __('Sign out') }}</button>
                    @endunless
                </li>
            @endforeach
        </ul>
    @else
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('The list of browsers needs SESSION_DRIVER=database on the server.') }}</p>
    @endif

    <form wire:submit="logoutOtherDevices" class="mt-3 flex flex-wrap items-end gap-2">
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Password') }}
            <input type="password" wire:model="password" autocomplete="current-password" class="mt-1 block w-56 rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
        </label>
        <x-danger-button>{{ __('Sign out all other devices') }}</x-danger-button>
        <x-input-error :messages="$errors->get('password')" class="w-full" />
    </form>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Signed-in phone apps are managed under “Mobile app”.') }}</p>

    <h3 class="mt-6 text-sm font-medium text-gray-800 dark:text-gray-200">{{ __('Security log') }}</h3>
    @if ($events->isEmpty())
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('No entries yet.') }}</p>
    @else
        <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-700">
            @foreach ($events as $event)
                @php($meta = $labels[$event->event] ?? ['label' => $event->event, 'icon' => '•', 'level' => 'info'])
                <li wire:key="security-event-{{ $event->id }}" class="flex items-start gap-3 py-2">
                    <span aria-hidden="true">{{ $meta['icon'] }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block {{ $levels[$meta['level']] }}">{{ $meta['label'] }}@if ($event->details) · {{ $areas[$event->details] ?? $event->details }}@endif</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $event->created_at->translatedFormat('D, j. M Y H:i') }} · {{ $event->device }} · {{ $event->ip_address }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
        @if ($events->count() >= $limit)
            <button type="button" wire:click="$set('limit', {{ $limit + 30 }})" class="mt-2 text-xs text-indigo-600 hover:underline dark:text-indigo-400">{{ __('Show more') }}</button>
        @endif
    @endif
</section>
