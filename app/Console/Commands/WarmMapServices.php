<?php

namespace App\Console\Commands;

use App\Support\Life\MapHttp;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('maps:warm')]
#[Description('Looks up the addresses of the map services in advance (fast trip map on servers with slow DNS)')]
class WarmMapServices extends Command
{
    public function handle(): int
    {
        MapHttp::warm();
        $this->info('Map services looked up.');

        return self::SUCCESS;
    }
}
