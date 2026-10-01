<?php

namespace Tests\Feature;

use App\Models\CareerEntry;
use App\Models\ExternalLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class CareerTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-01 10:00'));
    }

    public function test_career_path_trainings_contracts_and_cv(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('career.index')
            ->call('add', 'job')->set('entry.title', 'Teamleiter IT')->set('entry.organization', 'Muster AG')->set('entry.location', 'Lindau')->set('entry.starts_on', '2020-03-01')->set('entry.description', 'Team mit 8 Personen')->call('save')->assertHasNoErrors()
            ->call('add', 'education')->set('entry.title', 'B.Sc. Informatik')->set('entry.organization', 'HS Kempten')->set('entry.starts_on', '2012-10-01')->set('entry.ends_on', '2016-09-30')->set('entry.grade', '1,7')->call('save')
            ->call('add', 'training')->set('entry.title', 'Scrum Master')->set('entry.starts_on', '2024-05-10')->set('entry.hours', '16')->set('entry.expires_on', '2026-11-15')->call('save')
            ->call('add', 'contract')->set('entry.title', 'Arbeitsvertrag Muster AG')->set('entry.organization', 'Muster AG')->set('entry.salary', '4.800 € brutto')->set('entry.notice_period', '3 Monate')->call('save')
            ->call('add', 'education')->set('entry.title', 'Falsch')->set('entry.starts_on', '2020-01-01')->set('entry.ends_on', '2019-01-01')->call('save')->assertHasErrors('entry.ends_on')
            ->call('closeSheet')
            ->assertSee('Teamleiter IT')->assertSee('03/2020 – heute')->assertSee('B.Sc. Informatik')
            ->assertSee('Das Zertifikat „Scrum Master“ läuft am 15.11.2026 ab')
            ->set('tab', 'contracts')->assertSee('Gehalt: 4.800 € brutto')->assertSee('Kündigung: 3 Monate')
            ->set('profile.headline', 'IT-Teamleiter')->set('profile.skills', 'Scrum, PHP; Führung')->set('tab', 'cv')->call('saveProfile')
            ->assertSee('IT-Teamleiter')->assertSee('Führung');

        $training = CareerEntry::where('type', 'training')->sole();
        $this->assertSame(['hours' => '16', 'expires_on' => '2026-11-15'], $training->details);
        $this->assertSame('1,7', CareerEntry::where('type', 'education')->sole()->detail('grade'));

        // Certificates and contracts take documents.
        Volt::test('documents-panel', ['type' => 'career_entry', 'id' => $training->id])->assertOk();

        $this->get(route('career.cv'))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Only the member sees their career.
        $partner = $this->addMemberTo($household);
        $this->actingAs($partner);
        Volt::test('career.index')->assertDontSee('Teamleiter IT')->call('edit', $training->id)->assertNotFound();
        Volt::test('documents-panel', ['type' => 'career_entry', 'id' => $training->id])->assertNotFound();
    }

    public function test_applications_with_job_suggestions(): void
    {
        Http::fake(['rest.arbeitsagentur.de/*' => Http::response(['stellenangebote' => [
            ['titel' => 'Projektleiter (m/w/d)', 'arbeitgeber' => 'Beispiel GmbH', 'arbeitsort' => ['ort' => 'Kempten'], 'refnr' => '10000-123', 'aktuelleVeroeffentlichungsdatum' => '2026-09-28'],
        ]])]);
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $page = Volt::test('career.index')->set('tab', 'applications')
            ->set('profile.desired_role', 'Projektleiter')->set('profile.desired_location', 'Kempten')->call('saveProfile')
            ->assertSee('StepStone')->set('loadJobs', true)
            ->assertSee('Projektleiter (m/w/d)')->assertSee('Beispiel GmbH');

        Http::assertSent(fn (Request $request) => $request->hasHeader('X-API-Key', 'jobboerse-jobsuche') && $request['was'] === 'Projektleiter' && $request['wo'] === 'Kempten');

        $job = ['title' => 'Projektleiter (m/w/d)', 'employer' => 'Beispiel GmbH', 'location' => 'Kempten', 'url' => 'https://www.arbeitsagentur.de/jobsuche/jobdetail/10000-123'];
        $page->call('rememberJob', $job)->call('rememberJob', $job);
        $application = CareerEntry::where('type', 'application')->sole();
        $this->assertSame('planned', $application->detail('status'));

        $page->call('setStatus', $application->id, 'interview')->assertSee('Vorstellungsgespräch');
        $this->assertSame('interview', $application->fresh()->detail('status'));
        $page->call('setStatus', $application->id, 'nonsense')->assertStatus(422);

        ExternalLink::create(['household_id' => $application->household_id, 'created_by' => $user->id, 'linkable_type' => 'career_entry', 'linkable_id' => $application->id, 'kind' => ExternalLink::MAIL, 'url' => 'message://abc', 'title' => 'Anschreiben']);
        Volt::test('career.index')->call('delete', $application->id);
        $this->assertSame(0, ExternalLink::count());
    }
}
