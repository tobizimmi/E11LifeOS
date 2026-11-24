<?php

namespace App\Support;

use App\Enums\NotificationCategory;
use App\Models\User;

/**
 * What a notification is about, so every member can decide per topic how to be notified:
 * "all" (push and bell), "bell" (only the notification list in the app) or "off". The topic is read
 * from the area the notification links to; mails always form their own topic.
 */
class NotificationTopics
{
    public const ALL = 'all';

    public const BELL = 'bell';

    public const OFF = 'off';

    public const LEVELS = [self::ALL, self::BELL, self::OFF];

    public const OTHER = 'other';

    /** Topics that can be muted in the push but never silenced completely. */
    private const KEEP_IN_BELL = ['emergency'];

    /**
     * @return array<string, array{label: string, icon: string, segments: list<string>}>
     */
    public static function topics(): array
    {
        return [
            'mail' => ['label' => __('Mail'), 'icon' => '📬', 'segments' => ['mail']],
            'tasks' => ['label' => __('Tasks & calendar'), 'icon' => '✅', 'segments' => ['tasks', 'calendar', 'focus', 'inbox', 'notes', 'journal']],
            'shopping' => ['label' => __('Shopping & kitchen'), 'icon' => '🛒', 'segments' => ['shopping-list', 'pantry', 'recipes', 'meal-plan']],
            'finance' => ['label' => __('Finances'), 'icon' => '💶', 'segments' => ['finance', 'contracts', 'business', 'net-worth', 'splits', 'wishlist', 'cashflow', 'wealth-plan']],
            'portfolio' => ['label' => __('Portfolio & prices'), 'icon' => '📈', 'segments' => ['portfolio']],
            'home' => ['label' => __('Home & vehicles'), 'icon' => '🏠', 'segments' => ['house', 'smart-home', 'automations', 'vehicles', 'real-estate']],
            'health' => ['label' => __('Health'), 'icon' => '❤️', 'segments' => ['health']],
            'family' => ['label' => __('Family, people & trips'), 'icon' => '👪', 'segments' => ['people', 'kids', 'groups', 'trips', 'places', 'work', 'career']],
            'documents' => ['label' => __('Documents'), 'icon' => '📄', 'segments' => ['documents', 'drives']],
            'emergency' => ['label' => __('Emergency folder'), 'icon' => '🆘', 'segments' => ['emergency', 'provision']],
            self::OTHER => ['label' => __('Everything else'), 'icon' => '🔔', 'segments' => []],
        ];
    }

    /**
     * The topic of a notification: mails by their lane, everything else by the area of its link.
     */
    public static function fromUrl(?string $url, NotificationCategory $lane = NotificationCategory::General): string
    {
        if ($lane === NotificationCategory::Mail) {
            return 'mail';
        }

        $path = $url ? (string) parse_url($url, PHP_URL_PATH) : '';
        $base = rtrim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/');

        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $segment = explode('/', ltrim($path, '/'))[0] ?? '';

        foreach (self::topics() as $key => $topic) {
            if (in_array($segment, $topic['segments'], true)) {
                return $key;
            }
        }

        return self::OTHER;
    }

    public static function level(User $user, string $topic): string
    {
        $stored = $user->notification_prefs['topics'][$topic] ?? self::ALL;
        $level = in_array($stored, self::LEVELS, true) ? $stored : self::ALL;

        return $level === self::OFF && in_array($topic, self::KEEP_IN_BELL, true) ? self::BELL : $level;
    }

    /**
     * @param  array<string, string>  $levels  topic => level
     */
    public static function save(User $user, array $levels): void
    {
        $topics = $user->notification_prefs['topics'] ?? [];

        foreach ($levels as $topic => $level) {
            if (array_key_exists($topic, self::topics()) && in_array($level, self::LEVELS, true)) {
                $topics[$topic] = $level;
            }
        }

        $user->forceFill(['notification_prefs' => ['topics' => $topics]])->save();
    }
}
