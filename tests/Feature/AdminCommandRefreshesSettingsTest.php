<?php

namespace Tests\Feature;

use App\Jobs\RunAdminCommand;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCommandRefreshesSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_command_job_reapplies_stored_settings_before_running(): void
    {
        // Credentials entered on the admin page are stored in the database.
        AppSettings::save([
            'rezepteapp_db_username' => 'rezepteapp',
            'rezepteapp_db_password' => 'geheim',
        ]);

        // Simulate a long-running worker whose boot-time config still holds the defaults.
        config([
            'database.connections.rezepteapp.username' => 'rezepteapp2',
            'database.connections.rezepteapp.password' => '',
        ]);

        // Running an admin command (here a harmless one) must refresh the config from the settings.
        (new RunAdminCommand('recipes', 'list'))->handle();

        $this->assertSame('rezepteapp', config('database.connections.rezepteapp.username'));
        $this->assertSame('geheim', config('database.connections.rezepteapp.password'));
    }
}
