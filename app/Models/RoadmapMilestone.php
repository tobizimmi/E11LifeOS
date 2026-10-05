<?php

namespace App\Models;

use Database\Factories\RoadmapMilestoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A step of the further development (e.g. "1.39 – Shop"): bugs and feature requests from the
 * support are planned into it.
 *
 * @property Carbon|null $due_on
 */
#[Fillable(['title', 'version', 'description', 'status', 'due_on', 'position'])]
class RoadmapMilestone extends Model
{
    /** @use HasFactory<RoadmapMilestoneFactory> */
    use HasFactory;

    public const STATUSES = ['planned', 'in_progress', 'done'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return ['planned' => __('Planned'), 'in_progress' => __('In progress'), 'done' => __('Done')];
    }

    /**
     * @return HasMany<SupportTicket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }
}
