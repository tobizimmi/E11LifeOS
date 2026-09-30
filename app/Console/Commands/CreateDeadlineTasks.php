<?php

namespace App\Console\Commands;

use App\Support\ContractAssistant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('collections:deadline-tasks')]
#[Description('Create a task for collection dates (e.g. contract cancellation deadlines) that come within their reminder window')]
class CreateDeadlineTasks extends Command
{
    public function handle(): int
    {
        $this->info('Deadline tasks created: '.ContractAssistant::createDeadlineTasks());

        return self::SUCCESS;
    }
}
