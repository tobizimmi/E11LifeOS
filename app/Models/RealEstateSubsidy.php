<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subsidy for a property: grant, cheap loan or tax reduction, with application status.
 */
#[Fillable(['real_estate_id', 'budget_item_id', 'program', 'title', 'kind', 'amount', 'status', 'deadline_on', 'applied_on', 'approved_on', 'paid_on', 'notes'])]
class RealEstateSubsidy extends Model
{
    public const KINDS = ['grant', 'loan', 'tax'];

    public const STATUSES = ['idea', 'applied', 'approved', 'paid', 'rejected'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'deadline_on' => 'date',
            'applied_on' => 'date',
            'approved_on' => 'date',
            'paid_on' => 'date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function kindLabels(): array
    {
        return ['grant' => __('Grant'), 'loan' => __('Subsidised loan'), 'tax' => __('Tax reduction')];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return ['idea' => __('Idea'), 'applied' => __('Applied for'), 'approved' => __('Approved'), 'paid' => __('Paid out'), 'rejected' => __('Rejected')];
    }

    public function realEstate(): BelongsTo
    {
        return $this->belongsTo(RealEstate::class);
    }

    public function budgetItem(): BelongsTo
    {
        return $this->belongsTo(RealEstateBudgetItem::class, 'budget_item_id');
    }

    /**
     * Grants that are approved or paid count as secured.
     */
    public function isSecured(): bool
    {
        return in_array($this->status, ['approved', 'paid'], true);
    }
}
