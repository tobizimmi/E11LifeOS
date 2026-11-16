<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\RealEstate;
use App\Models\RealEstateHandover;
use App\Models\RealEstateTenant;
use App\Support\RealEstate\RentalPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mobile API for the handover protocols at move-in and move-out: meter readings, keys, the state of
 * the rooms and photos, plus the PDF.
 */
class RealEstateHandoverController extends ApiController
{
    private const MAX_PHOTOS = 12;

    public function index(int $estate): JsonResponse
    {
        return $this->ok($this->payload($this->find($estate)));
    }

    public function store(Request $request, int $estate): JsonResponse
    {
        $property = $this->find($estate);
        $handover = RealEstateHandover::create($this->fields($request, $property) + ['real_estate_id' => $property->id, 'created_by' => $this->user()->id, 'photos' => []]);

        return $this->ok($this->payload($property) + ['id' => $handover->id], 201);
    }

    public function update(Request $request, int $estate, int $handover): JsonResponse
    {
        $property = $this->find($estate);
        $this->handover($property, $handover)->update($this->fields($request, $property));

        return $this->ok($this->payload($property));
    }

    public function destroy(int $estate, int $handover): JsonResponse
    {
        $property = $this->find($estate);
        $this->handover($property, $handover)->delete();

        return $this->ok($this->payload($property));
    }

    public function addPhoto(Request $request, int $estate, int $handover): JsonResponse
    {
        $property = $this->find($estate);
        $model = $this->handover($property, $handover);
        $request->validate(['photo' => ['required', 'image', 'max:8192']]);
        $photos = $this->photos($model);
        abort_if(count($photos) >= self::MAX_PHOTOS, 422, __('At most :count photos per protocol.', ['count' => self::MAX_PHOTOS]));
        $file = $request->file('photo');
        abort_if($file === null, 422);
        $photos[] = $file->store('handovers/'.$model->id, RealEstateHandover::DISK);
        $model->update(['photos' => $photos]);

        return $this->ok($this->payload($property), 201);
    }

    public function removePhoto(int $estate, int $handover, int $index): JsonResponse
    {
        $property = $this->find($estate);
        $model = $this->handover($property, $handover);
        $photos = $this->photos($model);
        abort_unless(isset($photos[$index]), 404);
        Storage::disk(RealEstateHandover::DISK)->delete($photos[$index]);
        unset($photos[$index]);
        $model->update(['photos' => array_values($photos)]);

        return $this->ok($this->payload($property));
    }

    public function photo(int $estate, int $handover, int $index): StreamedResponse
    {
        $model = $this->handover($this->find($estate), $handover);
        $path = $this->photos($model)[$index] ?? abort(404);
        abort_unless(Storage::disk(RealEstateHandover::DISK)->exists($path), 404);

        return Storage::disk(RealEstateHandover::DISK)->response($path, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=86400'], 'inline');
    }

    public function pdf(int $estate, int $handover): Response
    {
        $model = $this->handover($this->find($estate), $handover);

        return response(RentalPdf::handover($model), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="uebergabeprotokoll-'.Str::slug($model->kind).'.pdf"']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(Request $request, RealEstate $property): array
    {
        $data = $request->validate([
            'tenant_id' => ['nullable', 'integer', Rule::exists('real_estate_tenants', 'id')->where('real_estate_id', $property->id)],
            'kind' => ['required', Rule::in(RealEstateHandover::KINDS)],
            'handed_over_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'meters' => ['present', 'array', 'max:30'],
            'meters.*.label' => ['nullable', 'string', 'max:100'],
            'meters.*.number' => ['nullable', 'string', 'max:100'],
            'meters.*.reading' => ['nullable', 'string', 'max:50'],
            'keys' => ['present', 'array', 'max:30'],
            'keys.*.label' => ['nullable', 'string', 'max:100'],
            'keys.*.count' => ['nullable', 'integer', 'min:0', 'max:99'],
            'rooms' => ['present', 'array', 'max:40'],
            'rooms.*.label' => ['nullable', 'string', 'max:100'],
            'rooms.*.ok' => ['boolean'],
            'rooms.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $keep = fn (array $rows) => array_values(array_filter($rows, fn (array $row) => trim((string) ($row['label'] ?? '')) !== ''));

        return [
            'tenant_id' => $data['tenant_id'] ?? null,
            'kind' => $data['kind'],
            'handed_over_on' => $data['handed_over_on'],
            'notes' => ($data['notes'] ?? '') ?: null,
            'meters' => array_map(fn (array $row) => ['label' => trim((string) $row['label']), 'number' => (string) ($row['number'] ?? ''), 'reading' => (string) ($row['reading'] ?? '')], $keep($data['meters'])),
            'keys' => array_map(fn (array $row) => ['label' => trim((string) $row['label']), 'count' => $row['count'] ?? ''], $keep($data['keys'])),
            'rooms' => array_map(fn (array $room) => ['label' => trim((string) $room['label']), 'ok' => (bool) ($room['ok'] ?? true), 'notes' => (string) ($room['notes'] ?? '')], $keep($data['rooms'])),
        ];
    }

    /**
     * @return list<string>
     */
    private function photos(RealEstateHandover $handover): array
    {
        $photos = $handover->getAttribute('photos');

        return is_array($photos) ? array_values(array_map('strval', $photos)) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(RealEstate $property): array
    {
        $rows = [];

        foreach (RealEstateHandover::where('real_estate_id', $property->id)->orderByDesc('handed_over_on')->orderByDesc('id')->get() as $handover) {
            $tenant = $handover->tenant_id !== null ? RealEstateTenant::find($handover->tenant_id) : null;
            $rows[] = [
                'id' => $handover->id,
                'tenant_id' => $handover->tenant_id,
                'tenant' => $tenant?->name,
                'kind' => $handover->kind,
                'handed_over_on' => Carbon::parse((string) $handover->handed_over_on)->toDateString(),
                'notes' => $handover->notes,
                'meters' => $handover->getAttribute('meters') ?? [],
                'keys' => $handover->getAttribute('keys') ?? [],
                'rooms' => $handover->getAttribute('rooms') ?? [],
                'photos' => count($this->photos($handover)),
            ];
        }

        return ['handovers' => $rows, 'max_photos' => self::MAX_PHOTOS];
    }

    private function find(int $id): RealEstate
    {
        $household = $this->household();

        return RealEstate::query()->visibleTo($this->user(), $household->id)->find($id) ?? abort(404);
    }

    private function handover(RealEstate $property, int $id): RealEstateHandover
    {
        return RealEstateHandover::where('real_estate_id', $property->id)->find($id) ?? abort(404);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
