<?php

namespace App\Support\Modules;

use App\Models\AppSetting;
use App\Models\Household;
use App\Models\ModuleEntitlement;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Whether a module may be used, from the plan of the module and the entitlements of the household
 * and the account. A block always wins. A module with the plan "licensed" needs an active grant
 * (by an administrator today, by a purchase or a trial later) for the household or the account.
 */
class ModuleAccess
{
    public const INCLUDED = 'included';

    public const LICENSED = 'licensed';

    /**
     * Drops the remembered entitlements and plans (after a change).
     */
    public static function forget(): void
    {
        app()->forgetInstance('module-access.memo');
    }

    /**
     * Remembered queries of this request or job; long-running processes look again after a minute.
     *
     * @return \ArrayObject<string, mixed>
     */
    private static function memo(): \ArrayObject
    {
        if (! app()->bound('module-access.memo')) {
            app()->scoped('module-access.memo', fn () => new \ArrayObject(['at' => now()->getTimestamp()]));
        }

        if (app('module-access.memo')['at'] < now()->getTimestamp() - 60) {
            self::forget();
        }

        return app('module-access.memo');
    }

    /**
     * @return array<string, string> module => plan (only modules that differ from "included")
     */
    public static function plans(): array
    {
        $memo = self::memo();

        return $memo['plans'] ??= (function (): array {
            $stored = json_decode((string) AppSetting::where('key', 'module_plans')->first()?->value, true);

            return is_array($stored) ? array_filter($stored, fn ($plan, $module) => $plan === self::LICENSED && ModuleCatalog::exists((string) $module), ARRAY_FILTER_USE_BOTH) : [];
        })();
    }

    public static function plan(string $module): string
    {
        return self::plans()[$module] ?? self::INCLUDED;
    }

    /**
     * @param  array<string, string>  $plans
     */
    public static function savePlans(array $plans): void
    {
        $licensed = array_filter($plans, fn ($plan, $module) => $plan === self::LICENSED && ModuleCatalog::exists((string) $module), ARRAY_FILTER_USE_BOTH);

        if ($licensed === []) {
            AppSetting::where('key', 'module_plans')->delete();
        } else {
            AppSetting::updateOrCreate(['key' => 'module_plans'], ['value' => json_encode($licensed)]);
        }

        self::forget();
    }

    /**
     * What a licensed module offers: trial days (0 = no trial) and a price text for members.
     *
     * @return array<string, array{trial_days: int, price: string}>
     */
    public static function offers(): array
    {
        $memo = self::memo();

        return $memo['offers'] ??= (function (): array {
            $stored = json_decode((string) AppSetting::where('key', 'module_offers')->first()?->value, true);

            return is_array($stored) ? collect($stored)->filter(fn ($offer, $module) => ModuleCatalog::exists((string) $module) && is_array($offer))
                ->map(fn (array $offer) => ['trial_days' => max(0, min(365, (int) ($offer['trial_days'] ?? 0))), 'price' => mb_substr((string) ($offer['price'] ?? ''), 0, 60)])->all() : [];
        })();
    }

    /**
     * @return array{trial_days: int, price: string}
     */
    public static function offer(string $module): array
    {
        return self::offers()[$module] ?? ['trial_days' => 0, 'price' => ''];
    }

    public static function saveOffer(string $module, int $trialDays, string $price): void
    {
        abort_unless(ModuleCatalog::exists($module), 422);
        $offers = array_merge(self::offers(), [$module => ['trial_days' => max(0, min(365, $trialDays)), 'price' => mb_substr(trim($price), 0, 60)]]);
        AppSetting::updateOrCreate(['key' => 'module_offers'], ['value' => json_encode($offers)]);
        self::forget();
    }

    /**
     * Whether the household as a whole may use the module (single accounts may still have their own grant).
     */
    public static function availableForHousehold(?Household $household, string $module): bool
    {
        if (! ModuleCatalog::exists($module)) {
            return true;
        }

        $entries = self::entries($household?->id, null)->where('module', $module);

        if ($entries->contains('access', ModuleEntitlement::BLOCKED)) {
            return false;
        }

        return self::plan($module) === self::INCLUDED || $entries->contains('access', ModuleEntitlement::GRANTED);
    }

    /**
     * Whether a link of the app leads into an area the user may use (search hits, calendar
     * entries, widgets); links outside the app and core pages are always allowed.
     */
    public static function allowsUrl(?User $user, string $url): bool
    {
        $module = ModuleCatalog::forUrl($url);

        return $module === null || self::allows($user, $module);
    }

    public static function allows(?User $user, string $module): bool
    {
        return self::explain($user, $module)['allowed'];
    }

    /**
     * The decision with its reason (for the admin pages).
     *
     * @return array{allowed: bool, reason: string}
     */
    public static function explain(?User $user, string $module, ?Household $household = null): array
    {
        if (! ModuleCatalog::exists($module)) {
            return ['allowed' => true, 'reason' => 'core'];
        }

        if (! $user) {
            return ['allowed' => false, 'reason' => 'guest'];
        }

        $household ??= $user->currentHousehold();
        $entries = self::entries($household?->id, $user->id)->where('module', $module);
        $blocked = $entries->where('access', ModuleEntitlement::BLOCKED);

        if ($blocked->isNotEmpty()) {
            return ['allowed' => false, 'reason' => $blocked->contains('user_id', $user->id) ? 'blocked_user' : 'blocked_household'];
        }

        if (self::plan($module) === self::INCLUDED) {
            return ['allowed' => true, 'reason' => 'included'];
        }

        $granted = $entries->where('access', ModuleEntitlement::GRANTED);

        return $granted->isEmpty()
            ? ['allowed' => false, 'reason' => 'needs_license']
            : ['allowed' => true, 'reason' => $granted->contains('user_id', $user->id) ? 'granted_user' : 'granted_household'];
    }

    /**
     * Active entitlements of a household and/or an account.
     *
     * @return Collection<int, ModuleEntitlement>
     */
    private static function entries(?int $householdId, ?int $userId): Collection
    {
        if ($householdId === null && $userId === null) {
            return collect();
        }

        $memo = self::memo();

        return $memo['entries:'.$householdId.':'.$userId] ??= ModuleEntitlement::query()
            ->active()
            ->where(fn ($query) => $query->when($householdId, fn ($query) => $query->orWhere('household_id', $householdId))->when($userId, fn ($query) => $query->orWhere('user_id', $userId)))
            ->get(['id', 'module', 'household_id', 'user_id', 'access']);
    }
}
