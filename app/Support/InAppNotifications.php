<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * The bell in the app: every reminder, assignment and alert also lands here, whether or not
 * the member has push or e-mail switched on. Stored in Laravel's notifications table.
 */
class InAppNotifications
{
    /**
     * Entries older than this are removed when new ones arrive.
     */
    private const KEEP_DAYS = 60;

    public const TYPE = 'app';

    public static function record(User $user, string $title, string $body = '', ?string $url = null): DatabaseNotification
    {
        $user->notifications()->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();

        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => self::TYPE,
            'data' => ['title' => mb_substr($title, 0, 255), 'body' => mb_substr($body, 0, 2000), 'url' => $url],
        ]);
    }

    public static function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }
}
