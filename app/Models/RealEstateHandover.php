<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Handover protocol at move-in or move-out: meter readings, keys, the state of the rooms and photos.
 */
#[Fillable(['real_estate_id', 'tenant_id', 'created_by', 'kind', 'handed_over_on', 'meters', 'keys', 'rooms', 'photos', 'notes'])]
class RealEstateHandover extends Model
{
    public const KINDS = ['move_in', 'move_out'];

    /**
     * Where the photos are kept (private disk).
     */
    public const DISK = 'secure';

    protected function casts(): array
    {
        return [
            'handed_over_on' => 'date',
            'meters' => 'array',
            'keys' => 'array',
            'rooms' => 'array',
            'photos' => 'array',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function kindLabels(): array
    {
        return ['move_in' => __('Move-in'), 'move_out' => __('Move-out')];
    }

    public function realEstate(): BelongsTo
    {
        return $this->belongsTo(RealEstate::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(RealEstateTenant::class, 'tenant_id');
    }

    /**
     * Photos as data URIs for the PDF (remote loading is off there).
     *
     * @return list<string>
     */
    public function photoDataUris(): array
    {
        return collect($this->photos ?? [])
            ->filter(fn (string $path) => Storage::disk(self::DISK)->exists($path))
            ->map(fn (string $path) => 'data:'.(Storage::disk(self::DISK)->mimeType($path) ?: 'image/jpeg').';base64,'.base64_encode(Storage::disk(self::DISK)->get($path)))
            ->values()->all();
    }

    protected static function booted(): void
    {
        static::deleting(fn (RealEstateHandover $handover) => Storage::disk(self::DISK)->delete($handover->photos ?? []));
    }
}
