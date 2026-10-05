<?php

namespace App\Models;

use App\Support\Modules\ModuleAccess;
use Database\Factories\ModuleEntitlementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Grants or blocks one module for a household or a single account. Today an administrator sets
 * them; later a purchase (source "license") or a trial creates them with a validity period.
 *
 * @property string $module
 * @property string $access
 * @property string $source
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
#[Fillable(['module', 'household_id', 'user_id', 'access', 'source', 'starts_at', 'ends_at', 'reference', 'note', 'granted_by'])]
class ModuleEntitlement extends Model
{
    /** @use HasFactory<ModuleEntitlementFactory> */
    use HasFactory;

    public const GRANTED = 'granted';

    public const BLOCKED = 'blocked';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_LICENSE = 'license';

    public const SOURCE_TRIAL = 'trial';

    protected static function booted(): void
    {
        static::saved(fn () => ModuleAccess::forget());
        static::deleted(fn () => ModuleAccess::forget());
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * Entries in effect right now (started and not yet expired).
     *
     * @param  Builder<ModuleEntitlement>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isActive(): bool
    {
        return ($this->starts_at === null || $this->starts_at->isPast()) && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
