<?php

namespace App\Support\Admin;

use App\Models\Household;
use App\Models\ModuleEntitlement;
use App\Models\User;
use App\Support\Modules\ModuleCatalog;
use App\Support\Security\SecurityLog;
use Illuminate\Support\Carbon;

/**
 * An administrator releases or locks a module for a household or a single account. Entries from
 * purchases or trials stay untouched – only the administrator's own entry is replaced.
 */
class ModuleRights
{
    public const DEFAULT = 'default';

    /**
     * @param  string  $subjectType  household or user
     * @param  string  $access  default, granted or blocked (from the form, checked here)
     */
    public static function set(User $admin, string $subjectType, int $subjectId, string $module, string $access, ?Carbon $until = null): ?ModuleEntitlement
    {
        abort_unless(ModuleCatalog::exists($module) && in_array($access, [self::DEFAULT, ModuleEntitlement::GRANTED, ModuleEntitlement::BLOCKED], true), 422);
        $column = match ($subjectType) {
            'household' => 'household_id',
            'user' => 'user_id',
            default => abort(404),
        };
        $subject = $subjectType === 'household' ? Household::findOrFail($subjectId) : User::findOrFail($subjectId);

        ModuleEntitlement::where($column, $subject->id)->where('module', $module)->where('source', ModuleEntitlement::SOURCE_ADMIN)->get()->each->delete();

        $entry = $access === self::DEFAULT ? null : ModuleEntitlement::create([
            'module' => $module,
            $column => $subject->id,
            'access' => $access,
            'source' => ModuleEntitlement::SOURCE_ADMIN,
            'ends_at' => $until?->endOfDay(),
            'granted_by' => $admin->id,
        ]);

        SecurityLog::record($admin, 'admin_action', details: 'module_'.$access.' '.$module.' '.$subjectType.' #'.$subject->id.($until ? ' until '.$until->toDateString() : ''));

        return $entry;
    }

    /**
     * The administrator's entries and other active entries (license, trial) of a subject, by module.
     *
     * @param  'household'|'user'  $subjectType
     * @return array<string, array{admin: ?ModuleEntitlement, others: list<ModuleEntitlement>}>
     */
    public static function entries(string $subjectType, int $subjectId): array
    {
        $column = $subjectType === 'household' ? 'household_id' : 'user_id';
        $rows = ModuleEntitlement::where($column, $subjectId)->get()->groupBy('module');

        return collect(ModuleCatalog::all())->map(fn (array $module, string $key) => [
            'admin' => $rows->get($key)?->firstWhere('source', ModuleEntitlement::SOURCE_ADMIN),
            'others' => $rows->get($key)?->where('source', '!=', ModuleEntitlement::SOURCE_ADMIN)->filter->isActive()->values()->all() ?? [],
        ])->all();
    }
}
