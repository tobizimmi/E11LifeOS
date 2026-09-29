<?php

namespace App\Console\Commands;

use App\Support\Life\PocketMoney;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kids:allowance')]
#[Description('Book the weekly pocket money of the children whose weekday it is')]
class PayPocketMoney extends Command
{
    public function handle(): int
    {
        $this->info('Pocket money booked: '.PocketMoney::payAllowances());

        return self::SUCCESS;
    }
}
