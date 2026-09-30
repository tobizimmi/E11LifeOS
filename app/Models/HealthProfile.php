<?php

namespace App\Models;

use Database\Factories\HealthProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Health data of one person the member looks after (themselves, a child, a parent): the emergency card
 * details are stored encrypted and only visible to the member.
 */
#[Fillable(['user_id', 'name', 'birth_date', 'sex', 'blood_type', 'allergies', 'conditions', 'insurance', 'emergency_contact', 'doctors'])]
class HealthProfile extends Model
{
    /** @use HasFactory<HealthProfileFactory> */
    use HasFactory;

    public const BLOOD_TYPES = ['0+', '0-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'blood_type' => 'encrypted',
            'allergies' => 'encrypted',
            'conditions' => 'encrypted',
            'insurance' => 'encrypted',
            'emergency_contact' => 'encrypted',
            'doctors' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(HealthEntry::class)->orderBy('next_due_on')->orderBy('id');
    }

    public function age(): ?int
    {
        return $this->birth_date ? (int) $this->birth_date->diffInYears(today()) : null;
    }

    /**
     * Age in whole months (for the children's check-ups).
     */
    public function ageInMonths(): ?int
    {
        return $this->birth_date ? (int) $this->birth_date->diffInMonths(today()) : null;
    }
}
