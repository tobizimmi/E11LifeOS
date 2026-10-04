<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The energy of one day: PV yield, consumption, grid import and feed-in, charged into the car.
 *
 * @property Carbon $date
 */
#[Fillable(['household_id', 'date', 'pv_kwh', 'home_kwh', 'import_kwh', 'export_kwh', 'charged_kwh'])]
class SmartHomeDay extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'pv_kwh' => 'float',
            'home_kwh' => 'float',
            'import_kwh' => 'float',
            'export_kwh' => 'float',
            'charged_kwh' => 'float',
        ];
    }

    /**
     * Share of the consumption covered by the own PV system and battery (0–100), null without data.
     */
    public function autarky(): ?int
    {
        if (! $this->home_kwh || $this->import_kwh === null) {
            return null;
        }

        return (int) round(max(0, min(1, 1 - $this->import_kwh / ($this->home_kwh + (float) $this->charged_kwh))) * 100);
    }
}
