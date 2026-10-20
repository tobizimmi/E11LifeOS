<?php

namespace App\Console\Commands;

use App\Support\Life\DeadlineTasks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('life:deadline-tasks')]
#[Description('Create a to-do for life-area deadlines (expiring documents/IDs, vehicle inspection/service) that come within their lead time')]
class CreateLifeDeadlineTasks extends Command
{
    public function handle(): int
    {
        $this->info('Life deadline tasks created: '.DeadlineTasks::create());

        return self::SUCCESS;
    }
}
