<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An illustrating image attached to a roadmap milestone (mockup, screenshot, sketch). Stored on
 * RoadmapMilestoneImage::DISK and served to admins through the roadmap image route.
 */
#[Fillable(['roadmap_milestone_id', 'path', 'caption', 'position'])]
class RoadmapMilestoneImage extends Model
{
    public const DISK = 'local';

    /**
     * @return BelongsTo<RoadmapMilestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(RoadmapMilestone::class, 'roadmap_milestone_id');
    }
}
