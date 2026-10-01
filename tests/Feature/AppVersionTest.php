<?php

namespace Tests\Feature;

use App\Support\AppVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class AppVersionTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function tearDown(): void
    {
        @unlink(base_path('build.json'));

        parent::tearDown();
    }

    public function test_the_changelog_is_parsed_and_shown_with_version_and_build(): void
    {
        $releases = AppVersion::parse("# Changelog\n\n## [2.1.0] – 2026-10-02\n\n### Neu\n- Eins\n- Zwei mit `code`\n\n### Behoben\n- Drei\n\n## [2.0.0]\n- Alt\n");
        $this->assertSame(['2.1.0', '2.0.0'], array_column($releases, 'version'));
        $this->assertSame('2026-10-02', $releases[0]['date']);
        $this->assertSame([['title' => 'Neu', 'items' => ['Eins', 'Zwei mit `code`']], ['title' => 'Behoben', 'items' => ['Drei']]], $releases[0]['sections']);
        $this->assertSame([['title' => '', 'items' => ['Alt']]], $releases[1]['sections']);

        // The real changelog: its newest entry is the version of the app.
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', AppVersion::version());
        $this->assertSame(AppVersion::releases()[0]['version'], AppVersion::version());

        file_put_contents(base_path('build.json'), json_encode(['build' => 512, 'commit' => 'abc1234', 'environment' => 'staging', 'date' => '2026-10-01T10:00:00Z']));
        $this->assertSame('v'.AppVersion::version().' · Build 512 · Staging', AppVersion::label());

        [$user] = $this->memberWithHousehold();
        $this->actingAs($user)->get(route('changelog'))->assertOk()
            ->assertSee('Neuigkeiten')
            ->assertSee('v'.AppVersion::version())
            ->assertSee('Build 512')->assertSee('abc1234');
        $this->get(route('dashboard'))->assertSee('Build 512 · Staging');
    }
}
