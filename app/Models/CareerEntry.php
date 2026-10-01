<?php

namespace App\Models;

use Database\Factories\CareerEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of a member's career: a job, school or studies, a training (with certificate), a
 * milestone, a work contract or an application. Only the member sees their entries.
 *
 * @property int $id
 * @property int $user_id
 * @property int $household_id
 * @property string $type
 * @property string $title
 * @property ?string $organization
 * @property ?string $location
 * @property ?Carbon $starts_on
 * @property ?Carbon $ends_on
 * @property ?string $description
 * @property ?array<string, mixed> $details
 */
#[Fillable(['user_id', 'household_id', 'type', 'title', 'organization', 'location', 'starts_on', 'ends_on', 'description', 'details'])]
class CareerEntry extends Model
{
    /** @use HasFactory<CareerEntryFactory> */
    use HasFactory;

    public const TYPES = ['job', 'education', 'training', 'milestone', 'contract', 'application'];

    public const APPLICATION_STATUSES = ['planned', 'applied', 'interview', 'offer', 'accepted', 'rejected'];

    protected static function booted(): void
    {
        static::deleted(fn (CareerEntry $entry) => ExternalLink::where('linkable_type', 'career_entry')->where('linkable_id', $entry->id)->get()->each->delete());
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'description' => 'encrypted',
            'details' => 'encrypted:array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function types(): array
    {
        return [
            'job' => ['label' => __('Work experience'), 'icon' => '💼'],
            'education' => ['label' => __('School & studies'), 'icon' => '🎓'],
            'training' => ['label' => __('Training & certificate'), 'icon' => '📜'],
            'milestone' => ['label' => __('Milestone'), 'icon' => '🏁'],
            'contract' => ['label' => __('Work contract'), 'icon' => '📄'],
            'application' => ['label' => __('Application'), 'icon' => '📨'],
        ];
    }

    /**
     * @return array<string, array{label: string, color: string}>
     */
    public static function applicationStatuses(): array
    {
        return [
            'planned' => ['label' => __('Planned'), 'color' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200'],
            'applied' => ['label' => __('Applied'), 'color' => 'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-200'],
            'interview' => ['label' => __('Interview'), 'color' => 'bg-violet-100 text-violet-800 dark:bg-violet-900/50 dark:text-violet-200'],
            'offer' => ['label' => __('Offer'), 'color' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200'],
            'accepted' => ['label' => __('Accepted'), 'color' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200'],
            'rejected' => ['label' => __('Rejected'), 'color' => 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200'],
        ];
    }

    public function detail(string $key): ?string
    {
        $value = $this->details[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * Until when the certificate of a training is valid.
     */
    public function expiresOn(): ?Carbon
    {
        $value = $this->detail('expires_on');

        return $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? Carbon::parse($value) : null;
    }

    public function isCurrent(): bool
    {
        return $this->starts_on !== null && $this->ends_on === null && in_array($this->type, ['job', 'education', 'contract'], true);
    }

    /**
     * "03/2019 – today", "2015 – 2018" or a single date.
     */
    public function period(): string
    {
        $format = fn (Carbon $date) => $date->format('m/Y');

        if (! $this->starts_on) {
            return $this->ends_on ? $format($this->ends_on) : '';
        }

        if (in_array($this->type, ['training', 'milestone', 'application'], true) && ! $this->ends_on) {
            return $this->starts_on->format('d.m.Y');
        }

        return $format($this->starts_on).' – '.($this->ends_on ? $format($this->ends_on) : __('today'));
    }
}
