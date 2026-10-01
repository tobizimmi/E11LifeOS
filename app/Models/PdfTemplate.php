<?php

namespace App\Models;

use Database\Factories\PdfTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How the PDFs of a household look, edited in the PDF editor (see App\Support\PdfTheme).
 *
 * @property int $id
 * @property int $household_id
 * @property string $type general or recipe
 * @property array<string, mixed> $settings
 */
#[Fillable(['household_id', 'type', 'settings'])]
class PdfTemplate extends Model
{
    /** @use HasFactory<PdfTemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
