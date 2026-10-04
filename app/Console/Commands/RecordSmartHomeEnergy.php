<?php

namespace App\Console\Commands;

use App\Support\SmartHome\EnergyRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('smart-home:energy {--close : Close yesterday: daily energy, meter readings and charging entries}')]
#[Description('Record evcc and Home Assistant values (every five minutes) or close the previous day')]
class RecordSmartHomeEnergy extends Command
{
    public function handle(EnergyRecorder $recorder): int
    {
        $this->option('close') ? $recorder->closeDay(today()->subDay()) : $recorder->record(now());

        return self::SUCCESS;
    }
}
