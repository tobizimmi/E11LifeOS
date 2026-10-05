<?php

namespace App\Support\Modules;

use App\Models\HouseholdMember;
use App\Models\ModuleEntitlement;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Helpdesk\Tickets;
use App\Support\Security\SecurityLog;
use Illuminate\Support\Carbon;

/**
 * The member's view of licensed modules: what the household has, trials to start and license
 * requests to the team (handled in the support; a shop with online payment can create the same
 * entitlements later).
 */
class Licenses
{
    /**
     * Every licensed module with its state for the member's household.
     *
     * @return list<array{key: string, label: string, icon: string, allowed: bool, reason: string, source: ?string, until: ?Carbon, trial_days: int, price: string, can_trial: bool, requested: bool}>
     */
    public static function overview(User $user): array
    {
        $household = $user->currentHousehold();
        $entries = ModuleEntitlement::query()
            ->where(fn ($query) => $query->where('household_id', $household?->id)->orWhere('user_id', $user->id))
            ->get();
        $openRequests = SupportTicket::where('user_id', $user->id)->where('type', 'account')->unsolved()->whereNotNull('area')->pluck('area')->all();
        $rows = [];

        foreach (ModuleCatalog::all() as $key => $module) {
            if (ModuleAccess::plan($key) !== ModuleAccess::LICENSED) {
                continue;
            }

            $state = ModuleAccess::explain($user, $key);
            $active = $entries->where('module', $key)->where('access', ModuleEntitlement::GRANTED)->filter->isActive()->sortByDesc('ends_at')->first();
            $offer = ModuleAccess::offer($key);

            $rows[] = [
                'key' => $key,
                'label' => $module['label'],
                'icon' => $module['icon'],
                'allowed' => $state['allowed'],
                'reason' => $state['reason'],
                'source' => $active?->source,
                'until' => $active?->ends_at,
                'trial_days' => $offer['trial_days'],
                'price' => $offer['price'],
                'can_trial' => self::canStartTrial($user, $key),
                'requested' => in_array($key, $openRequests, true),
            ];
        }

        return $rows;
    }

    /**
     * Owners start one trial per module and household, when the team offers one.
     */
    public static function canStartTrial(User $user, string $module): bool
    {
        $household = $user->currentHousehold();

        return $household !== null
            && ModuleAccess::plan($module) === ModuleAccess::LICENSED
            && ModuleAccess::offer($module)['trial_days'] > 0
            && ! ModuleAccess::allows($user, $module)
            && (bool) HouseholdMember::where('household_id', $household->id)->where('user_id', $user->id)->first()?->isOwner()
            && ! ModuleEntitlement::where('household_id', $household->id)->where('module', $module)->where('source', ModuleEntitlement::SOURCE_TRIAL)->exists()
            && ! ModuleEntitlement::where('household_id', $household->id)->where('module', $module)->where('access', ModuleEntitlement::BLOCKED)->active()->exists();
    }

    public static function startTrial(User $user, string $module): ModuleEntitlement
    {
        abort_unless(ModuleCatalog::exists($module) && self::canStartTrial($user, $module), 403);
        $household = $user->currentHousehold();

        $entry = ModuleEntitlement::create([
            'module' => $module,
            'household_id' => $household->id,
            'access' => ModuleEntitlement::GRANTED,
            'source' => ModuleEntitlement::SOURCE_TRIAL,
            'starts_at' => now(),
            'ends_at' => now()->addDays(ModuleAccess::offer($module)['trial_days'])->endOfDay(),
        ]);
        SecurityLog::record($user, 'module_trial', details: $module);

        return $entry;
    }

    /**
     * A license request lands in the support as a ticket for the team.
     */
    public static function request(User $user, string $module, string $note = ''): SupportTicket
    {
        abort_unless(ModuleCatalog::exists($module) && ModuleAccess::plan($module) === ModuleAccess::LICENSED, 422);
        abort_if(SupportTicket::where('user_id', $user->id)->where('type', 'account')->where('area', $module)->unsolved()->exists(), 422, __('You already asked for this module.'));

        $label = ModuleCatalog::all()[$module]['label'];
        $price = ModuleAccess::offer($module)['price'];

        return Tickets::open($user, [
            'subject' => __('License request: :module', ['module' => $label]),
            'body' => trim(__('I would like to use the module “:module” for our household.', ['module' => $label]).($price !== '' ? ' ('.$price.')' : '')."\n\n".$note),
            'type' => 'account',
            'area' => $module,
        ]);
    }
}
