<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\RealEstate;
use App\Models\RealEstateApplicant;
use App\Support\RealEstate\RentalPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Mobile API for the applicants of a let property: switch the public application form on and off,
 * and sort the applications (status, rating, notes) up to accepting one as tenant.
 */
class RealEstateApplicantsController extends ApiController
{
    public function index(int $estate): JsonResponse
    {
        return $this->ok($this->payload($this->find($estate)));
    }

    /**
     * Puts the application form online (or updates its text and the rent shown).
     */
    public function open(Request $request, int $estate): JsonResponse
    {
        $property = $this->find($estate);
        $data = $request->validate(['intro' => ['nullable', 'string', 'max:2000'], 'rent' => ['nullable', 'numeric', 'gt:0', 'max:100000']]);
        $property->update([
            'applicant_token' => $property->getAttribute('applicant_token') ?? Str::random(32),
            'applicant_intro' => trim((string) ($data['intro'] ?? '')) ?: null,
            'applicant_rent' => isset($data['rent']) ? round((float) $data['rent'], 2) : null,
        ]);

        return $this->ok($this->payload($property->refresh()));
    }

    public function close(int $estate): JsonResponse
    {
        $property = $this->find($estate);
        $property->update(['applicant_token' => null]);

        return $this->ok($this->payload($property->refresh()));
    }

    public function update(Request $request, int $estate, int $applicant): JsonResponse
    {
        $property = $this->find($estate);
        $model = $this->applicant($property, $applicant);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(RealEstateApplicant::STATUSES)],
            'rating' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        if (isset($data['status'])) {
            $model->status = $data['status'];
        }

        if (array_key_exists('rating', $data)) {
            $model->rating = $data['rating'] ?: null;
        }

        if (array_key_exists('notes', $data)) {
            $model->notes = mb_substr(trim((string) $data['notes']), 0, 5000) ?: null;
        }

        $model->save();

        return $this->ok($this->payload($property));
    }

    public function destroy(int $estate, int $applicant): JsonResponse
    {
        $property = $this->find($estate);
        $this->applicant($property, $applicant)->delete();

        return $this->ok($this->payload($property));
    }

    /**
     * Marks the applicant accepted and returns the data for a prefilled tenant.
     */
    public function accept(int $estate, int $applicant): JsonResponse
    {
        $property = $this->find($estate);
        $model = $this->applicant($property, $applicant);
        $model->update(['status' => 'accepted']);

        return $this->ok([
            'name' => $model->name,
            'email' => $model->email,
            'phone' => $model->phone,
            'persons' => max(1, (int) $model->persons),
            'move_in_on' => $model->desired_move_in !== null ? Carbon::parse((string) $model->desired_move_in)->toDateString() : null,
        ]);
    }

    public function pdf(int $estate, int $applicant): Response
    {
        $property = $this->find($estate);
        $model = $this->applicant($property, $applicant);

        return response(RentalPdf::selfDisclosure($property, $model), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="selbstauskunft-'.Str::slug($model->name).'.pdf"']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(RealEstate $property): array
    {
        $token = $property->getAttribute('applicant_token');
        $rent = $property->applicant_rent;
        $applicants = [];

        foreach (RealEstateApplicant::where('real_estate_id', $property->id)->orderByDesc('id')->get() as $applicant) {
            $applicants[] = [
                'id' => $applicant->id,
                'status' => $applicant->status,
                'rating' => $applicant->rating,
                'name' => $applicant->name,
                'email' => $applicant->email,
                'phone' => $applicant->phone,
                'current_address' => $applicant->current_address,
                'occupation' => $applicant->occupation,
                'employer' => $applicant->employer,
                'net_income' => $applicant->net_income,
                'persons' => $applicant->persons,
                'children' => $applicant->children,
                'pets' => $applicant->pets,
                'smoker' => (bool) $applicant->smoker,
                'rent_arrears' => (bool) $applicant->rent_arrears,
                'insolvency' => (bool) $applicant->insolvency,
                'eviction' => (bool) $applicant->eviction,
                'desired_move_in' => $applicant->desired_move_in !== null ? Carbon::parse((string) $applicant->desired_move_in)->toDateString() : null,
                'message' => $applicant->message,
                'notes' => $applicant->notes,
                'rent_to_income' => $rent !== null ? $applicant->rentToIncome((float) $rent) : null,
                'received_at' => $applicant->created_at?->toIso8601String(),
            ];
        }

        return [
            'open' => is_string($token) && $token !== '',
            'url' => is_string($token) && $token !== '' ? route('rental.apply', ['token' => $token]) : null,
            'intro' => $property->applicant_intro,
            'rent' => $rent !== null ? (float) $rent : null,
            'statuses' => RealEstateApplicant::statusLabels(),
            'applicants' => $applicants,
        ];
    }

    private function find(int $id): RealEstate
    {
        $household = $this->household();

        return RealEstate::query()->visibleTo($this->user(), $household->id)->find($id) ?? abort(404);
    }

    private function applicant(RealEstate $property, int $id): RealEstateApplicant
    {
        return RealEstateApplicant::where('real_estate_id', $property->id)->find($id) ?? abort(404);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
