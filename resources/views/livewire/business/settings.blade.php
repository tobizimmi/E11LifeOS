<?php

use App\Livewire\Concerns\InteractsWithBusiness;
use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\BusinessProfile;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Storage;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithBusiness;
    use InteractsWithHousehold;
    use WithFileUploads;

    public $logo = null;

    /**
     * @var array<string, mixed>
     */
    public array $form = [];

    public function mount(): void
    {
        $profile = $this->businessProfile();

        $this->form = [
            'company_name' => $profile->company_name ?? $this->currentUser()->name,
            'owner_name' => $profile->owner_name ?? $this->currentUser()->name,
            'street' => $profile->street ?? '',
            'postal_code' => $profile->postal_code ?? '',
            'city' => $profile->city ?? '',
            'email' => $profile->email ?? $this->currentUser()->email,
            'phone' => $profile->phone ?? '',
            'website' => $profile->website ?? '',
            'tax_number' => $profile->tax_number ?? '',
            'vat_id' => $profile->vat_id ?? '',
            'small_business' => $profile->small_business ?? true,
            'default_vat_rate' => (string) ($profile->default_vat_rate ?? 19),
            'bank_name' => $profile->bank_name ?? '',
            'iban' => $profile->iban ?? '',
            'bic' => $profile->bic ?? '',
            'invoice_prefix' => $profile->invoice_prefix ?? 'RE-',
            'quote_prefix' => $profile->quote_prefix ?? 'AN-',
            'credit_note_prefix' => $profile->credit_note_prefix ?? 'GS-',
            'accent_color' => $profile->accent_color ?? '#4338ca',
            'payment_days' => (string) ($profile->payment_days ?? 14),
            'hourly_rate' => $profile?->hourly_rate !== null ? (string) $profile->hourly_rate : '',
            'reminder_days' => (string) ($profile->reminder_days ?? 7),
            'reminder_fee_1' => (string) ($profile->reminder_fee_1 ?? 0),
            'reminder_fee_2' => (string) ($profile->reminder_fee_2 ?? 5),
            'reminder_fee_3' => (string) ($profile->reminder_fee_3 ?? 10),
            'invoice_footer' => $profile->invoice_footer ?? '',
        ];
    }

    public function save(): void
    {
        $this->form['iban'] = strtoupper(str_replace(' ', '', (string) $this->form['iban']));
        $this->form['bic'] = strtoupper(str_replace(' ', '', (string) $this->form['bic']));

        $data = $this->validate([
            'form.company_name' => ['required', 'string', 'max:255'],
            'form.owner_name' => ['nullable', 'string', 'max:255'],
            'form.street' => ['nullable', 'string', 'max:255'],
            'form.postal_code' => ['nullable', 'string', 'max:16'],
            'form.city' => ['nullable', 'string', 'max:255'],
            'form.email' => ['nullable', 'email', 'max:255'],
            'form.phone' => ['nullable', 'string', 'max:64'],
            'form.website' => ['nullable', 'string', 'max:255'],
            'form.tax_number' => ['nullable', 'string', 'max:32'],
            'form.vat_id' => ['nullable', 'string', 'max:32'],
            'form.small_business' => ['boolean'],
            'form.default_vat_rate' => ['required', 'numeric', 'min:0', 'max:99'],
            'form.bank_name' => ['nullable', 'string', 'max:255'],
            'form.iban' => ['nullable', 'string', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/'],
            'form.bic' => ['nullable', 'string', 'regex:/^[A-Z0-9]{8}([A-Z0-9]{3})?$/'],
            'form.invoice_prefix' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-_\/]*$/'],
            'form.quote_prefix' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-_\/]*$/', 'different:form.invoice_prefix'],
            'form.credit_note_prefix' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-_\/]*$/', 'different:form.invoice_prefix', 'different:form.quote_prefix'],
            'form.accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'form.payment_days' => ['required', 'integer', 'min:0', 'max:365'],
            'form.hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'form.reminder_days' => ['required', 'integer', 'min:1', 'max:90'],
            'form.reminder_fee_1' => ['required', 'numeric', 'min:0', 'max:1000'],
            'form.reminder_fee_2' => ['required', 'numeric', 'min:0', 'max:1000'],
            'form.reminder_fee_3' => ['required', 'numeric', 'min:0', 'max:1000'],
            'form.invoice_footer' => ['nullable', 'string', 'max:1000'],
        ], attributes: [
            'form.company_name' => __('Business name'),
            'form.iban' => 'IBAN',
            'form.bic' => 'BIC',
            'form.invoice_prefix' => __('Number prefix'),
            'form.quote_prefix' => __('Quote prefix'),
            'form.credit_note_prefix' => __('Credit note prefix'),
        ])['form'];

        foreach (['invoice_prefix', 'quote_prefix', 'credit_note_prefix'] as $prefix) {
            $data[$prefix] = (string) ($data[$prefix] ?? '');
        }

        $data['hourly_rate'] = ($data['hourly_rate'] ?? '') === '' ? null : $data['hourly_rate'];

        BusinessProfile::updateOrCreate(
            ['user_id' => $this->currentUser()->id],
            ['household_id' => $this->householdId] + $data,
        );

        $this->dispatch('toast', message: __('Saved.'));
    }

    /**
     * Logo for invoices and quotes (PNG or JPG, shown top right).
     */
    public function updatedLogo(): void
    {
        $this->validate(['logo' => ['image', 'mimes:png,jpg,jpeg', 'max:1024']], attributes: ['logo' => __('Logo')]);
        $profile = $this->businessProfile();

        if (! $profile) {
            $this->addError('logo', __('Please save your business details first.'));

            return;
        }

        if ($profile->logo_path) {
            Storage::disk(BusinessProfile::LOGO_DISK)->delete($profile->logo_path);
        }

        $extension = $this->logo->guessExtension() === 'png' ? 'png' : 'jpg';
        $profile->update(['logo_path' => $this->logo->storeAs('business-logos', $this->currentUser()->id.'-'.now()->timestamp.'.'.$extension, BusinessProfile::LOGO_DISK)]);
        $this->reset('logo');
    }

    public function removeLogo(): void
    {
        $profile = $this->businessProfile() ?? abort(404);

        if ($profile->logo_path) {
            Storage::disk(BusinessProfile::LOGO_DISK)->delete($profile->logo_path);
            $profile->update(['logo_path' => null]);
        }
    }

    public function with(): array
    {
        return ['profile' => $this->businessProfile()];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Small business') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your business data.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('business.partials.nav', ['active' => 'business.settings'])

            @if (! $profile)
                <p class="rounded-xl bg-indigo-50 px-4 py-3 text-sm text-indigo-900 dark:bg-indigo-900/30 dark:text-indigo-100">👋 {{ __('Enter your business details once – they appear on every invoice.') }}</p>
            @endif

            <form wire:submit="save" class="space-y-5">
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Business & address') }}</h3>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        @foreach ([
                            'company_name' => [__('Business name'), 'sm:col-span-2'],
                            'owner_name' => [__('Owner'), ''],
                            'email' => [__('Email'), ''],
                            'street' => [__('Street and number'), 'sm:col-span-2'],
                            'postal_code' => [__('Postal code'), ''],
                            'city' => [__('City'), ''],
                            'phone' => [__('Phone'), ''],
                            'website' => [__('Website'), ''],
                        ] as $field => [$label, $span])
                            <div class="{{ $span }}">
                                <x-input-label :for="'profile-'.$field" :value="$label" />
                                <x-text-input :id="'profile-'.$field" wire:model="form.{{ $field }}" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('form.'.$field)" class="mt-1" />
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Taxes') }}</h3>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="profile-tax_number" :value="__('Tax number')" />
                            <x-text-input id="profile-tax_number" wire:model="form.tax_number" class="mt-1 block w-full" placeholder="12/345/67890" />
                        </div>
                        <div>
                            <x-input-label for="profile-vat_id" :value="__('VAT ID (optional)')" />
                            <x-text-input id="profile-vat_id" wire:model="form.vat_id" class="mt-1 block w-full" placeholder="DE123456789" />
                        </div>
                        <label class="flex items-start gap-3 rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40 sm:col-span-2">
                            <input type="checkbox" wire:model.live="form.small_business" class="mt-0.5 rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">
                            <span class="text-sm">
                                <strong class="text-gray-900 dark:text-gray-100">{{ __('Small business rule (§ 19 UStG)') }}</strong><br>
                                <span class="text-gray-600 dark:text-gray-400">{{ __('Invoices without VAT with the note “According to § 19 UStG no VAT is charged.” Possible if revenue stayed below 25,000 € last year and stays below 100,000 € this year.') }}</span>
                            </span>
                        </label>
                        @unless ($form['small_business'])
                            <div>
                                <x-input-label for="profile-vat" :value="__('Default VAT rate (%)')" />
                                <select id="profile-vat" wire:model="form.default_vat_rate" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                    <option value="19">19 %</option>
                                    <option value="7">7 %</option>
                                    <option value="0">0 %</option>
                                </select>
                            </div>
                        @endunless
                    </div>
                    <x-input-error :messages="$errors->get('form.default_vat_rate')" class="mt-1" />
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Bank details & invoices') }}</h3>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div>
                            <x-input-label for="profile-bank_name" :value="__('Bank')" />
                            <x-text-input id="profile-bank_name" wire:model="form.bank_name" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <x-input-label for="profile-iban" value="IBAN" />
                            <x-text-input id="profile-iban" wire:model="form.iban" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('form.iban')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="profile-bic" value="BIC" />
                            <x-text-input id="profile-bic" wire:model="form.bic" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('form.bic')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="profile-prefix" :value="__('Number prefix')" />
                            <x-text-input id="profile-prefix" wire:model="form.invoice_prefix" class="mt-1 block w-full" />
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Numbers look like :example and continue without gaps.', ['example' => ($form['invoice_prefix'] ?? '').now()->year.'-001']) }}</p>
                            <x-input-error :messages="$errors->get('form.invoice_prefix')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="profile-days" :value="__('Payment term (days)')" />
                            <x-text-input id="profile-days" type="number" min="0" wire:model="form.payment_days" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('form.payment_days')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="profile-quote-prefix" :value="__('Quote prefix')" />
                            <x-text-input id="profile-quote-prefix" wire:model="form.quote_prefix" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('form.quote_prefix')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="profile-credit-prefix" :value="__('Credit note prefix')" />
                            <x-text-input id="profile-credit-prefix" wire:model="form.credit_note_prefix" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('form.credit_note_prefix')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-3">
                            <x-input-label for="profile-footer" :value="__('Closing text on invoices (optional)')" />
                            <textarea id="profile-footer" wire:model="form.invoice_footer" rows="2" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200" placeholder="{{ __('Thank you for your order!') }}"></textarea>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Dunning & time tracking') }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Deadline and fees for payment reminders and dunning letters, default hourly rate for tracked time.') }}</p>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div>
                            <x-input-label for="profile-hourly" :value="__('Hourly rate (€)')" />
                            <x-text-input id="profile-hourly" type="number" step="0.01" min="0" wire:model="form.hourly_rate" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('form.hourly_rate')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="profile-reminder-days" :value="__('Deadline per reminder (days)')" />
                            <x-text-input id="profile-reminder-days" type="number" min="1" wire:model="form.reminder_days" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('form.reminder_days')" class="mt-1" />
                        </div>
                        <div class="hidden sm:block"></div>
                        @foreach ([1 => __('Fee payment reminder (€)'), 2 => __('Fee 1st dunning letter (€)'), 3 => __('Fee 2nd dunning letter (€)')] as $level => $label)
                            <div>
                                <x-input-label :for="'profile-fee-'.$level" :value="$label" />
                                <x-text-input :id="'profile-fee-'.$level" type="number" step="0.01" min="0" wire:model="form.reminder_fee_{{ $level }}" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('form.reminder_fee_'.$level)" class="mt-1" />
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Look of your documents') }}</h3>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="profile-logo" :value="__('Logo (PNG or JPG, max. 1 MB)')" />
                            @if ($profile?->logo_path)
                                <div class="mt-1 flex items-center gap-3">
                                    <img src="{{ $profile->logoDataUri() }}" alt="{{ __('Logo') }}" class="h-12 max-w-[10rem] rounded-sm bg-white object-contain p-1 ring-1 ring-gray-200">
                                    <button type="button" wire:click="removeLogo" class="text-xs text-red-600 hover:underline dark:text-red-400">{{ __('Remove') }}</button>
                                </div>
                            @endif
                            <input id="profile-logo" type="file" wire:model="logo" accept="image/png,image/jpeg" class="mt-1 block w-full text-sm text-gray-700 file:me-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-indigo-700 dark:text-gray-300 dark:file:bg-gray-700 dark:file:text-gray-200">
                            <x-input-error :messages="$errors->get('logo')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="profile-color" :value="__('Accent colour')" />
                            <div class="mt-1 flex items-center gap-2">
                                <input id="profile-color" type="color" wire:model="form.accent_color" class="h-10 w-14 cursor-pointer rounded-sm border-gray-300 dark:border-gray-600">
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Used for headings and lines in the PDF.') }}</span>
                            </div>
                            <x-input-error :messages="$errors->get('form.accent_color')" class="mt-1" />
                        </div>
                    </div>
                </section>

                <div class="flex items-center gap-3">
                    <x-primary-button>{{ __('Save') }}</x-primary-button>
                    @if ($profile && ! $profile->isCompleteForInvoices())
                        <span class="text-sm text-amber-700 dark:text-amber-300">⚠ {{ __('For valid invoices you need name, address and tax number or VAT ID.') }}</span>
                    @endif
                </div>
            </form>
        </div>
    </div>
</div>
