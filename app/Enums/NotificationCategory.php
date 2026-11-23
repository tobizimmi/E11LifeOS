<?php

namespace App\Enums;

/**
 * The lane a notification belongs to. Mails are kept apart from the rest so new-mail pushes do not
 * drown in – or get bundled together with – reminders, alerts and the other notifications.
 */
enum NotificationCategory: string
{
    case Mail = 'mail';
    case Important = 'important';
    case General = 'general';

    /**
     * Resolves a stored or caller-supplied value, falling back to {@see self::General}.
     */
    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::General;
    }

    public function label(): string
    {
        return match ($this) {
            self::Mail => __('Mails'),
            self::Important => __('Important'),
            self::General => __('General'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Mail => '📬',
            self::Important => '❗',
            self::General => '🔔',
        };
    }

    /**
     * The service-worker tag of this lane's bundle, so each lane shows as its own notification on the
     * device instead of replacing another lane's.
     */
    public function bundleTag(): string
    {
        return 'e11-bundle-'.$this->value;
    }

    /**
     * The title of a bundled summary for this lane (e.g. "3 new mails" vs "3 new notifications").
     */
    public function bundleTitle(int $count): string
    {
        return match ($this) {
            self::Mail => trans_choice(':count new mail|:count new mails', $count),
            self::Important => trans_choice(':count important notification|:count important notifications', $count),
            self::General => trans_choice(':count new notification|:count new notifications', $count),
        };
    }
}
