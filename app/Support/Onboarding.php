<?php

namespace App\Support;

use App\Models\Item;
use App\Models\User;

/**
 * "First steps" for new members: what is already set up and where to go next.
 */
class Onboarding
{
    /**
     * @return list<array{key: string, label: string, hint: string, done: bool, url: string}>
     */
    public static function steps(User $user): array
    {
        $household = $user->currentHousehold();

        return [
            ['key' => 'menu', 'label' => __('Choose your modules'), 'hint' => __('Keep the menu to what you use – everything else stays one search away.'), 'done' => $user->nav_preferences !== null, 'url' => route('navigation.settings')],
            ['key' => 'two_factor', 'label' => __('Protect your account with two-factor authentication'), 'hint' => __('Recommended – finances and documents are in here.'), 'done' => $user->hasSecondFactor(), 'url' => route('profile')],
            ['key' => 'household', 'label' => __('Invite your household'), 'hint' => __('Tasks, shopping list and collections are shared automatically.'), 'done' => $household && $household->members()->count() > 1, 'url' => route('household.show')],
            ['key' => 'task', 'label' => __('Create your first task'), 'hint' => __('e.g. "Take out the rubbish every Tuesday" – dates and repetitions are recognised.'), 'done' => $household && Item::where('household_id', $household->id)->where('type', Item::TYPE_TODO)->where('created_by', $user->id)->exists(), 'url' => route('tasks.index')],
            ['key' => 'push', 'label' => __('Switch on notifications'), 'hint' => __('Reminders and the morning briefing on your phone.'), 'done' => $user->pushSubscriptions()->exists(), 'url' => route('profile')],
            ['key' => 'briefing', 'label' => __('Choose your summaries'), 'hint' => __('Morning briefing and weekly summary by e-mail or push.'), 'done' => $user->morning_briefing !== 'off' || $user->weekly_digest !== 'off', 'url' => route('profile')],
        ];
    }

    public static function isVisible(User $user): bool
    {
        return $user->onboarding_dismissed_at === null && collect(self::steps($user))->contains('done', false);
    }
}
