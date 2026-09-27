<?php

namespace App\Console\Commands;

use App\Support\Finance\Budgets;
use App\Support\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:check-budgets')]
#[Description('Push a warning when a monthly budget reaches 80 % or 100 % (once per level and month)')]
class CheckFinanceBudgets extends Command
{
    public function handle(WebPush $webPush): int
    {
        $this->info('Budget warnings: '.Budgets::warn($webPush));

        return self::SUCCESS;
    }
}
