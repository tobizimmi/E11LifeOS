<?php

use App\Support\Security\SecurityLog;
use App\Models\EmergencyEntry;
use App\Models\TrustedPerson;
use App\Notifications\EmergencyAccessUpdate;
use App\Support\Life\EmergencyFolder;
use App\Support\WebPush;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * Emergency access for a trusted person (personal link, no login): request access with the own
 * e-mail address, wait for the waiting period, then read the folder.
 */
new #[Layout('layouts.guest')] class extends Component
{
    #[Locked]
    public string $token = '';

    public string $email = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $person = $this->person();

        if ($person->hasAccess() && ($person->last_viewed_at === null || $person->last_viewed_at->lt(now()->subDay()))) {
            $person->update(['last_viewed_at' => now()]);
            SecurityLog::record($person->user, 'trusted_access', details: $person->name);
            app(WebPush::class)->send($person->user, '👀 '.__(':name opened your emergency folder', ['name' => $person->name]), '', route('emergency.index', ['section' => 'access']));
        }
    }

    private function person(): TrustedPerson
    {
        $person = TrustedPerson::findByToken($this->token);
        abort_unless($person && $person->user, 404);

        return $person;
    }

    public function requestAccess(): void
    {
        $person = $this->person();
        $this->validate(['email' => ['required', 'email']], attributes: ['email' => __('Email')]);

        if (RateLimiter::tooManyAttempts('emergency-access:'.$person->id, 5)) {
            $this->addError('email', __('Too many attempts. Please try again later.'));

            return;
        }

        RateLimiter::hit('emergency-access:'.$person->id, 3600);

        if (mb_strtolower(trim($this->email)) !== mb_strtolower($person->email)) {
            $this->addError('email', __('This is not the e-mail address the invitation was sent to.'));

            return;
        }

        if ($person->requested_at === null) {
            $person->update(['requested_at' => now()]);
            $url = route('emergency.index', ['section' => 'access']);
            $person->user->notify(new EmergencyAccessUpdate(EmergencyAccessUpdate::REQUESTED, $person->user->name, $person->name, $url, $person->grantsOn()->format('d.m.Y H:i')));
            app(WebPush::class)->send($person->user, '⚠ '.__(':name requested access to your emergency folder', ['name' => $person->name]), __('It opens on :date unless you decline.', ['date' => $person->grantsOn()->format('d.m.Y H:i')]), $url);
        }
    }

    public function with(): array
    {
        $person = $this->person();

        return [
            'person' => $person,
            'owner' => $person->user,
            'sections' => EmergencyFolder::sections(),
            'entries' => $person->hasAccess() ? EmergencyEntry::where('user_id', $person->user_id)->orderBy('position')->orderBy('id')->get() : collect(),
            'overview' => $person->hasAccess() ? EmergencyFolder::overview($person->user) : [],
        ];
    }
}; ?>

<div class="space-y-5">
    <div>
        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">🗂️ {{ __('Emergency folder of :name', ['name' => $owner->name]) }}</h1>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Hello :name, you are a trusted person.', ['name' => $person->name]) }}</p>
    </div>

    @if ($person->hasAccess())
        <a href="{{ route('emergency.shared-pdf', ['token' => $token]) }}" class="inline-block rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">🖨️ {{ __('PDF to print') }}</a>
        @include('emergency.folder', ['entries' => $entries, 'sections' => $sections, 'overview' => $overview])
    @elseif ($person->isWaiting())
        <p class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">⏳ {{ __('Access requested. :name has been informed; the folder opens on :date unless the request is declined. You will get an e-mail.', ['name' => $owner->name, 'date' => $person->grantsOn()->format('d.m.Y H:i')]) }}</p>
    @else
        <p class="text-sm text-gray-700 dark:text-gray-200">{{ __('Only request access in an emergency – for example if :name is ill or has had an accident. :name is informed immediately and can decline.', ['name' => $owner->name]) }}</p>
        <form wire:submit="requestAccess" class="space-y-3">
            <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Your e-mail address (for confirmation)') }}
                <input type="email" wire:model="email" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" autocomplete="email">
                <x-input-error :messages="$errors->get('email')" />
            </label>
            <x-danger-button>{{ __('Request emergency access') }}</x-danger-button>
        </form>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('The folder opens :count day after the request.|The folder opens :count days after the request.', $person->wait_days) }}</p>
    @endif
</div>
