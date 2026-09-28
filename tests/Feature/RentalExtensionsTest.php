<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\RealEstate;
use App\Models\RealEstateHandover;
use App\Models\RealEstateTenant;
use App\Models\RealEstateUtilityStatement;
use App\Models\User;
use App\Support\RealEstate\RentalAccounting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class RentalExtensionsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    /**
     * @return array{0: User, 1: Household, 2: RealEstate, 3: RealEstateTenant}
     */
    private function tenancy(array $tenant = []): array
    {
        [$user, $household] = $this->memberWithHousehold();
        $realEstate = RealEstate::factory()->rental(800)->for($household)->create(['created_by' => $user->id, 'name' => 'Lindenhaus', 'street' => 'Lindenstraße 5', 'postal_code' => '80331', 'city' => 'München', 'landlord_name' => 'Tobi Vermieter']);
        $tenancy = RealEstateTenant::factory()->create($tenant + ['real_estate_id' => $realEstate->id, 'name' => 'Maria Muster', 'move_in_on' => '2025-01-01', 'cold_rent' => 800, 'utilities_advance' => 200, 'rent_adjustment' => 'index', 'index_base' => 110.0, 'index_base_month' => '2024-11']);

        return [$user, $household, $realEstate, $tenancy];
    }

    public function test_an_index_rent_is_calculated_applied_at_most_once_a_year_and_written_as_letter(): void
    {
        [$user, , $realEstate, $tenant] = $this->tenancy();
        $this->actingAs($user);

        $calculation = RentalAccounting::indexRent($tenant, 121.0);
        $this->assertSame(880.0, $calculation['new']);
        $this->assertSame(10.0, $calculation['percent']);
        $this->assertSame('2026-11-01', $calculation['effective_on']->toDateString());
        $this->assertTrue($calculation['allowed']);

        $this->get(route('real-estate.index-rent', ['tenant' => $tenant->id, 'index' => '121', 'month' => '2026-08']))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        Volt::test('real-estate.rental', ['realEstateId' => $realEstate->id])
            ->set('indexCurrent.'.$tenant->id, '121')
            ->set('indexMonth.'.$tenant->id, '2026-08')
            ->assertSee('880,00 €')
            ->call('applyIndexRent', $tenant->id)
            ->assertHasNoErrors()
            ->set('indexCurrent.'.$tenant->id, '125')
            ->call('applyIndexRent', $tenant->id)
            ->assertHasErrors('indexCurrent.'.$tenant->id);

        $tenant->refresh();
        $this->assertSame([880.0, 121.0, '2026-08', '2026-11-01'], [$tenant->cold_rent, $tenant->index_base, $tenant->index_base_month, $tenant->last_rent_change_on->toDateString()]);
    }

    public function test_a_payment_reminder_lists_the_open_months(): void
    {
        [$user, , , $tenant] = $this->tenancy();
        $this->actingAs($user);
        // Everything up to August paid, September (due on the 3rd) is open.
        foreach (range(1, 8) as $month) {
            $tenant->payments()->create(['month' => Carbon::create(2026, $month, 1)->toDateString(), 'amount' => 1000, 'paid_on' => Carbon::create(2026, $month, 3)->toDateString()]);
        }

        $this->assertSame([['month' => 9, 'expected' => 1000.0, 'paid' => 0.0, 'open' => 1000.0]], RentalAccounting::openMonths($tenant, 2026));
        $this->get(route('real-estate.dunning', ['tenant' => $tenant->id, 'year' => 2026, 'level' => 2]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('real-estate.dunning', ['tenant' => $tenant->id, 'year' => 2024]))->assertNotFound();
    }

    public function test_a_handover_protocol_with_meters_and_photos_is_saved_printed_and_deleted(): void
    {
        Storage::fake(RealEstateHandover::DISK);
        [$user, , $realEstate, $tenant] = $this->tenancy();
        $this->actingAs($user);

        Volt::test('real-estate.rental', ['realEstateId' => $realEstate->id])
            ->call('newHandover', $tenant->id, 'move_in')
            ->assertSet('handover.handed_over_on', '2025-01-01')
            ->set('handover.meters.0.number', 'Z-4711')
            ->set('handover.meters.0.reading', '12345,6')
            ->set('handover.keys.0.count', '2')
            ->set('handover.rooms.1.ok', false)
            ->set('handover.rooms.1.notes', 'Kratzer im Parkett')
            ->call('removeHandoverRow', 'meters', 4)
            ->set('handoverPhotos', [UploadedFile::fake()->image('flur.jpg', 400, 300)])
            ->call('saveHandover')
            ->assertHasNoErrors()
            ->assertSee('mit Mängeln');

        $handover = RealEstateHandover::sole();
        $this->assertSame('Z-4711', $handover->meters[0]['number']);
        $this->assertCount(4, $handover->meters);
        $this->assertFalse($handover->rooms[1]['ok']);
        Storage::disk(RealEstateHandover::DISK)->assertExists($handover->photos[0]);

        $this->get(route('real-estate.handover-pdf', $handover))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('real-estate.handover-photo', [$handover, 0]))->assertOk();

        Volt::test('real-estate.rental', ['realEstateId' => $realEstate->id])->call('deleteHandover', $handover->id);
        Storage::disk(RealEstateHandover::DISK)->assertMissing($handover->photos[0]);
    }

    public function test_the_tenant_portal_shows_only_the_tenants_documents_until_the_link_is_turned_off(): void
    {
        [$user, , $realEstate, $tenant] = $this->tenancy();
        $sent = RealEstateUtilityStatement::create(['real_estate_id' => $realEstate->id, 'year' => 2025, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'costs' => [['type' => 'water', 'label' => 'Wasser', 'amount' => 600, 'key' => 'area']], 'sent_on' => '2026-03-01']);
        $draft = RealEstateUtilityStatement::create(['real_estate_id' => $realEstate->id, 'year' => 2026, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'costs' => []]);
        $this->actingAs($user);

        Volt::test('real-estate.rental', ['realEstateId' => $realEstate->id])->call('createPortalLink', $tenant->id);
        $token = $tenant->fresh()->portal_token;
        $this->assertSame(48, strlen($token));

        auth()->logout();
        $this->get(route('rental.portal', $token))->assertOk()
            ->assertSee('Deine Wohnung')->assertSee('Nebenkostenabrechnung 2025')->assertDontSee('Nebenkostenabrechnung 2026');
        $this->get(route('rental.portal.lease', $token))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('rental.portal.statement', [$token, $sent->id]))->assertOk();
        $this->get(route('rental.portal.statement', [$token, $draft->id]))->assertNotFound();
        $this->get(route('rental.portal', 'x'.str_repeat('a', 40)))->assertNotFound();

        $this->actingAs($user);
        Volt::test('real-estate.rental', ['realEstateId' => $realEstate->id])->call('revokePortalLink', $tenant->id);
        auth()->logout();
        $this->get(route('rental.portal', $token))->assertNotFound();
    }

    public function test_protocols_of_other_households_are_not_reachable(): void
    {
        [, , $realEstate, $tenant] = $this->tenancy();
        $handover = $realEstate->handovers()->create(['tenant_id' => $tenant->id, 'kind' => 'move_in', 'handed_over_on' => '2025-01-01']);
        [$stranger] = $this->memberWithHousehold();
        $this->actingAs($stranger);

        $this->get(route('real-estate.handover-pdf', $handover))->assertNotFound();
        $this->get(route('real-estate.index-rent', ['tenant' => $tenant->id, 'index' => '121']))->assertNotFound();
    }
}
