<?php

use App\Models\RealEstate;
use App\Models\User;
use App\Notifications\NewRentalApplicant;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * Public application form with self-disclosure (Selbstauskunft) for a let property – reached
 * through the random link the landlord shares; no login.
 */
new #[Layout('layouts.guest')] class extends Component
{
    #[Locked]
    public string $token = '';

    /**
     * @var array<string, mixed>
     */
    public array $form = [
        'name' => '', 'email' => '', 'phone' => '', 'birth_date' => '', 'current_address' => '', 'occupation' => '', 'employer' => '', 'employed_since' => '',
        'net_income' => '', 'persons' => '1', 'children' => '0', 'pets' => '', 'smoker' => false, 'current_landlord' => '', 'rent_arrears' => false,
        'insolvency' => false, 'eviction' => false, 'credit_report' => false, 'desired_move_in' => '', 'message' => '', 'consent' => false,
    ];

    /**
     * Honeypot – stays empty for people.
     */
    public string $website = '';

    public bool $sent = false;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->realEstate();
    }

    private function realEstate(): RealEstate
    {
        $realEstate = strlen($this->token) >= 32 ? RealEstate::where('applicant_token', $this->token)->first() : null;

        abort_unless($realEstate, 404);

        return $realEstate;
    }

    public function submit(): void
    {
        $realEstate = $this->realEstate();
        $this->form['net_income'] = str_replace(['.', ' ', '€', ','], ['', '', '', '.'], trim((string) $this->form['net_income']));

        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.email' => ['required', 'email', 'max:255'],
            'form.phone' => ['nullable', 'string', 'max:50'],
            'form.birth_date' => ['nullable', 'date', 'before:-18 years', 'after:1900-01-01'],
            'form.current_address' => ['required', 'string', 'max:255'],
            'form.occupation' => ['nullable', 'string', 'max:255'],
            'form.employer' => ['nullable', 'string', 'max:255'],
            'form.employed_since' => ['nullable', 'date', 'before_or_equal:today'],
            'form.net_income' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'form.persons' => ['required', 'integer', 'min:1', 'max:20'],
            'form.children' => ['required', 'integer', 'min:0', 'max:20', 'lt:form.persons'],
            'form.pets' => ['nullable', 'string', 'max:255'],
            'form.smoker' => ['boolean'],
            'form.current_landlord' => ['nullable', 'string', 'max:255'],
            'form.rent_arrears' => ['boolean'],
            'form.insolvency' => ['boolean'],
            'form.eviction' => ['boolean'],
            'form.credit_report' => ['boolean'],
            'form.desired_move_in' => ['nullable', 'date', 'after_or_equal:today'],
            'form.message' => ['nullable', 'string', 'max:3000'],
            'form.consent' => ['accepted'],
        ], attributes: [
            'form.name' => __('Name'),
            'form.email' => __('Email'),
            'form.current_address' => __('Current address'),
            'form.birth_date' => __('Date of birth'),
            'form.children' => __('Children'),
            'form.consent' => __('Consent'),
        ])['form'];

        // Bots fill the hidden field; they get the same answer without anything being saved.
        if ($this->website !== '') {
            $this->sent = true;

            return;
        }

        $key = 'rental-apply:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('form.name', __('Too many applications from your connection. Please try again later.'));

            return;
        }

        RateLimiter::hit($key, 3600);
        unset($data['consent']);

        $applicant = $realEstate->applicants()->create(array_map(fn ($value) => $value === '' ? null : $value, $data) + [
            'status' => 'new',
            'consent_at' => now(),
            'persons' => (int) $data['persons'],
            'children' => (int) $data['children'],
        ]);

        if ($realEstate->created_by && ($owner = User::find($realEstate->created_by))) {
            $owner->notify(new NewRentalApplicant($applicant));
        }

        $this->sent = true;
    }

    public function with(): array
    {
        return ['realEstate' => $this->realEstate()];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $address = trim(implode(', ', array_filter([$realEstate->street, trim($realEstate->postal_code.' '.$realEstate->city)])));
@endphp

<div class="space-y-4">
    <div>
        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Application with self-disclosure') }}</h1>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $realEstate->name }}@if ($address) · {{ $address }}@endif</p>
        @if ($realEstate->applicant_intro)
            <p class="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $realEstate->applicant_intro }}</p>
        @endif
    </div>

    @if ($sent)
        <div class="rounded-md bg-green-50 p-4 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-200" role="status">
            {{ __('Thank you! Your application has been sent. The landlord will get in touch with you.') }}
        </div>
    @else
        <form wire:submit="submit" class="space-y-4">
            <div class="hidden" aria-hidden="true">
                <label for="apply-website">Website</label>
                <input id="apply-website" type="text" wire:model="website" tabindex="-1" autocomplete="off">
            </div>

            <fieldset class="space-y-3">
                <legend class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('About you') }}</legend>
                <div>
                    <x-input-label for="apply-name" :value="__('Name(s) of all adult tenants')" />
                    <input id="apply-name" wire:model="form.name" autocomplete="name" class="{{ $input }}" required>
                    <x-input-error :messages="$errors->get('form.name')" class="mt-1" />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <x-input-label for="apply-email" :value="__('Email')" />
                        <input id="apply-email" type="email" wire:model="form.email" autocomplete="email" class="{{ $input }}" required>
                        <x-input-error :messages="$errors->get('form.email')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="apply-phone" :value="__('Phone')" />
                        <input id="apply-phone" type="tel" wire:model="form.phone" autocomplete="tel" class="{{ $input }}">
                    </div>
                    <div>
                        <x-input-label for="apply-birth" :value="__('Date of birth')" />
                        <input id="apply-birth" type="date" wire:model="form.birth_date" class="{{ $input }}">
                        <x-input-error :messages="$errors->get('form.birth_date')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="apply-move-in" :value="__('Desired move-in')" />
                        <input id="apply-move-in" type="date" wire:model="form.desired_move_in" class="{{ $input }}">
                        <x-input-error :messages="$errors->get('form.desired_move_in')" class="mt-1" />
                    </div>
                </div>
                <div>
                    <x-input-label for="apply-address" :value="__('Current address')" />
                    <input id="apply-address" wire:model="form.current_address" autocomplete="street-address" class="{{ $input }}" required>
                    <x-input-error :messages="$errors->get('form.current_address')" class="mt-1" />
                </div>
            </fieldset>

            <fieldset class="space-y-3">
                <legend class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Work and income') }}</legend>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <x-input-label for="apply-occupation" :value="__('Occupation')" />
                        <input id="apply-occupation" wire:model="form.occupation" class="{{ $input }}">
                    </div>
                    <div>
                        <x-input-label for="apply-employer" :value="__('Employer')" />
                        <input id="apply-employer" wire:model="form.employer" class="{{ $input }}">
                    </div>
                    <div>
                        <x-input-label for="apply-employed-since" :value="__('Employed since')" />
                        <input id="apply-employed-since" type="date" wire:model="form.employed_since" class="{{ $input }}">
                        <x-input-error :messages="$errors->get('form.employed_since')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="apply-income" :value="__('Monthly net income of the household (€)')" />
                        <input id="apply-income" inputmode="decimal" wire:model="form.net_income" class="{{ $input }}">
                        <x-input-error :messages="$errors->get('form.net_income')" class="mt-1" />
                    </div>
                </div>
            </fieldset>

            <fieldset class="space-y-3">
                <legend class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Household') }}</legend>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <x-input-label for="apply-persons" :value="__('Persons moving in (total)')" />
                        <input id="apply-persons" type="number" min="1" max="20" wire:model="form.persons" class="{{ $input }}">
                        <x-input-error :messages="$errors->get('form.persons')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="apply-children" :value="__('of which children')" />
                        <input id="apply-children" type="number" min="0" max="20" wire:model="form.children" class="{{ $input }}">
                        <x-input-error :messages="$errors->get('form.children')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="apply-pets" :value="__('Pets (kind, number)')" />
                        <input id="apply-pets" wire:model="form.pets" class="{{ $input }}">
                    </div>
                    <div>
                        <x-input-label for="apply-landlord" :value="__('Current landlord (name, phone)')" />
                        <input id="apply-landlord" wire:model="form.current_landlord" class="{{ $input }}">
                    </div>
                </div>
            </fieldset>

            <fieldset class="space-y-2">
                <legend class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Please answer truthfully') }}</legend>
                @foreach ([
                    'smoker' => __('I smoke / someone moving in smokes'),
                    'rent_arrears' => __('There are rent arrears from previous tenancies'),
                    'insolvency' => __('Insolvency proceedings or a statement of assets in the last 5 years'),
                    'eviction' => __('An eviction lawsuit in the last 5 years'),
                    'credit_report' => __('I can provide a current credit report (e.g. SCHUFA)'),
                ] as $field => $label)
                    <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model="form.{{ $field }}" class="mt-0.5 rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </fieldset>

            <div>
                <x-input-label for="apply-message" :value="__('Message to the landlord (optional)')" />
                <textarea id="apply-message" rows="4" wire:model="form.message" class="{{ $input }}"></textarea>
                <x-input-error :messages="$errors->get('form.message')" class="mt-1" />
            </div>

            <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" wire:model="form.consent" class="mt-0.5 rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                <span>{{ __('I confirm that the information is true and consent to it being processed to select tenants. It is deleted at the latest six months after a rejection.') }}</span>
            </label>
            <x-input-error :messages="$errors->get('form.consent')" />

            <x-primary-button class="w-full justify-center" wire:loading.attr="disabled">{{ __('Send application') }}</x-primary-button>
        </form>
    @endif
</div>
