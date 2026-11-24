<?php

namespace App\Support\Portfolio;

use App\Models\User;
use App\Support\Pushover;
use App\Support\WebPush;

/**
 * Portfolio notifications (price alerts, stop loss, savings plans, news): web push in LifeOS
 * and, when the user saved a Pushover user key, additionally Pushover.
 */
class Notifier
{
    public function __construct(private WebPush $webPush, private Pushover $pushover) {}

    public function send(User $user, string $title, string $body, ?string $url = null, bool $important = false, ?string $category = null): void
    {
        $this->webPush->send($user, $title, $body, $url, pushover: false, category: $category);
        $this->pushover->send($user, $title, $body, $url, $important);
    }
}
