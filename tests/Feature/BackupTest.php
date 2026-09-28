<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/e11-backup-test-'.uniqid();
        config(['backup.path' => $this->directory, 'backup.keep' => 2]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        File::delete(storage_path('app/private/backup-test.txt'));

        parent::tearDown();
    }

    public function test_a_backup_contains_the_database_and_the_uploaded_files_and_old_ones_are_removed(): void
    {
        [, $household] = $this->memberWithHousehold();
        Item::factory()->note()->for($household)->create(['title' => "Omas Rezept, mit 'Apostroph'"]);
        File::put(storage_path('app/private/backup-test.txt'), 'Anhang');

        File::ensureDirectoryExists($this->directory);
        File::put($this->directory.'/e11lifeos-2026-01-01-000000.zip', 'alt');
        File::put($this->directory.'/e11lifeos-2026-01-02-000000.zip', 'alt');

        $this->artisan('backup:run')->assertSuccessful();

        $backups = File::glob($this->directory.'/e11lifeos-*.zip');
        $this->assertCount(2, $backups);
        $this->assertFileDoesNotExist($this->directory.'/e11lifeos-2026-01-01-000000.zip');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(end($backups)));
        $sql = $zip->getFromName('database.sql');
        $this->assertMatchesRegularExpression('/CREATE TABLE [`"]items[`"]/', $sql);
        $this->assertStringContainsString("'Omas Rezept, mit ''Apostroph'''", $sql);
        $this->assertSame('Anhang', $zip->getFromName('files/private/backup-test.txt'));
        $zip->close();
    }
}
