<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BusinessProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The business details that appear on every invoice (name, address, taxes, bank, numbering, dunning).
 */
class BusinessSettingsController extends ApiController
{
    private const TEXT = ['street', 'postal_code', 'city', 'phone', 'website', 'tax_number', 'vat_id', 'bank_name', 'iban', 'bic', 'invoice_footer'];

    public function show(): JsonResponse
    {
        return $this->ok($this->data(BusinessProfile::where('user_id', $this->user()->id)->first()));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->user();
        $household = $user->currentHousehold() ?? abort(404);

        $request->merge([
            'iban' => strtoupper(str_replace(' ', '', (string) $request->input('iban'))),
            'bic' => strtoupper(str_replace(' ', '', (string) $request->input('bic'))),
        ]);

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'city' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'website' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:32'],
            'vat_id' => ['nullable', 'string', 'max:32'],
            'small_business' => ['required', 'boolean'],
            'default_vat_rate' => ['required', 'numeric', 'min:0', 'max:99'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'iban' => ['nullable', 'string', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/'],
            'bic' => ['nullable', 'string', 'regex:/^[A-Z0-9]{8}([A-Z0-9]{3})?$/'],
            'invoice_prefix' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-_\/]*$/'],
            'quote_prefix' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-_\/]*$/', 'different:invoice_prefix'],
            'credit_note_prefix' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-_\/]*$/', 'different:invoice_prefix', 'different:quote_prefix'],
            'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'payment_days' => ['required', 'integer', 'min:0', 'max:365'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'reminder_days' => ['required', 'integer', 'min:1', 'max:90'],
            'reminder_fee_1' => ['required', 'numeric', 'min:0', 'max:1000'],
            'reminder_fee_2' => ['required', 'numeric', 'min:0', 'max:1000'],
            'reminder_fee_3' => ['required', 'numeric', 'min:0', 'max:1000'],
            'invoice_footer' => ['nullable', 'string', 'max:1000'],
        ]);

        foreach (['invoice_prefix', 'quote_prefix', 'credit_note_prefix'] as $prefix) {
            $data[$prefix] = (string) ($data[$prefix] ?? '');
        }

        $profile = BusinessProfile::updateOrCreate(['user_id' => $user->id], ['household_id' => $household->id] + $data);

        return $this->ok($this->data($profile));
    }

    public function logo(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:1024']]);
        $profile = BusinessProfile::where('user_id', $this->user()->id)->first();
        $profile ??= abort(422, __('Please save your business details first.'));

        if ($profile->logo_path) {
            Storage::disk(BusinessProfile::LOGO_DISK)->delete($profile->logo_path);
        }

        $file = $request->file('logo');
        $extension = $file->guessExtension() === 'png' ? 'png' : 'jpg';
        $profile->update(['logo_path' => $file->storeAs('business-logos', $this->user()->id.'-'.now()->timestamp.'.'.$extension, BusinessProfile::LOGO_DISK)]);

        return $this->ok($this->data($profile->refresh()));
    }

    public function removeLogo(): JsonResponse
    {
        $profile = BusinessProfile::where('user_id', $this->user()->id)->first() ?? abort(404);

        if ($profile->logo_path) {
            Storage::disk(BusinessProfile::LOGO_DISK)->delete($profile->logo_path);
            $profile->update(['logo_path' => null]);
        }

        return $this->ok($this->data($profile->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function data(?BusinessProfile $profile): array
    {
        $user = $this->user();
        $values = [];

        foreach (self::TEXT as $field) {
            $values[$field] = (string) ($profile->{$field} ?? '');
        }

        return $values + [
            'exists' => $profile !== null,
            'has_logo' => (bool) $profile?->logo_path,
            'company_name' => (string) ($profile->company_name ?? $user->name),
            'owner_name' => (string) ($profile->owner_name ?? $user->name),
            'email' => (string) ($profile->email ?? $user->email),
            'small_business' => (bool) ($profile->small_business ?? true),
            'default_vat_rate' => (float) ($profile->default_vat_rate ?? 19),
            'invoice_prefix' => (string) ($profile->invoice_prefix ?? 'RE-'),
            'quote_prefix' => (string) ($profile->quote_prefix ?? 'AN-'),
            'credit_note_prefix' => (string) ($profile->credit_note_prefix ?? 'GS-'),
            'accent_color' => (string) ($profile->accent_color ?? '#4338ca'),
            'payment_days' => (int) ($profile->payment_days ?? 14),
            'hourly_rate' => $profile?->hourly_rate !== null ? (float) $profile->hourly_rate : null,
            'reminder_days' => (int) ($profile->reminder_days ?? 7),
            'reminder_fee_1' => (float) ($profile->reminder_fee_1 ?? 0),
            'reminder_fee_2' => (float) ($profile->reminder_fee_2 ?? 5),
            'reminder_fee_3' => (float) ($profile->reminder_fee_3 ?? 10),
        ];
    }
}
