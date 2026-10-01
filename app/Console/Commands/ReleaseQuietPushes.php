<?php

namespace App\Console\Commands;

use App\Support\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('push:release-quiet')]
#[Description('Delivers the pushes collected during the quiet hours, bundled, once the quiet hours are over')]
class ReleaseQuietPushes extends Command
{
    public function handle(WebPush $webPush): int
    {
        if (! WebPush::isConfigured()) {
            return self::SUCCESS;
        }

        $this->info('Released: '.$webPush->releaseQuiet());

        return self::SUCCESS;
    }
}
