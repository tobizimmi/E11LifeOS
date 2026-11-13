<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\LoyaltyCard;
use App\Support\Barcode;
use App\Support\CardCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class LoyaltyCardsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_a_card_can_be_added_by_hand(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('loyalty-cards.index')
            ->set('name', 'REWE')
            ->set('code', '4006381333931')
            ->set('codeFormat', 'EAN_13')
            ->set('color', '#2563eb')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('adding', false);

        $card = LoyaltyCard::sole();
        $this->assertSame('REWE', $card->name);
        $this->assertSame('4006381333931', $card->code);
        $this->assertSame('EAN_13', $card->code_format);
        $this->assertSame('#2563eb', $card->color);
        $this->assertSame(Item::VISIBILITY_SHARED, $card->visibility);
        $this->assertSame($household->id, $card->household_id);
    }

    public function test_name_and_code_are_required(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('loyalty-cards.index')->call('save')->assertHasErrors(['name', 'code']);
    }

    public function test_a_scanned_code_prefills_the_form_and_maps_the_format(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        // A supported format is kept as is.
        Volt::test('loyalty-cards.index')
            ->call('setScanned', '4006381333931', 'EAN_13')
            ->assertSet('code', '4006381333931')
            ->assertSet('codeFormat', 'EAN_13')
            ->assertSet('adding', true);

        // An unsupported 1D format falls back to Code 128 (decodes to the same value) …
        Volt::test('loyalty-cards.index')
            ->call('setScanned', 'ABC123', 'CODE_93')
            ->assertSet('codeFormat', 'CODE_128');

        // … and an unsupported 2D format to a QR code.
        Volt::test('loyalty-cards.index')
            ->call('setScanned', 'https://card', 'AZTEC')
            ->assertSet('codeFormat', 'QR_CODE');
    }

    public function test_private_cards_are_hidden_from_other_household_members(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $other = $this->addMemberTo($household);

        LoyaltyCard::factory()->for($household)->create(['name' => 'Shared card']);
        LoyaltyCard::factory()->for($household)->privateTo($owner)->create(['name' => 'Owner only']);

        $this->actingAs($other);
        Volt::test('loyalty-cards.index')
            ->assertSee('Shared card')
            ->assertDontSee('Owner only');
    }

    public function test_a_card_can_be_edited_and_deleted(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $card = LoyaltyCard::factory()->for($household)->create(['name' => 'DM', 'code' => '111']);

        $component = Volt::test('loyalty-cards.index')
            ->call('startEditing', $card->id)
            ->assertSet('name', 'DM')
            ->set('name', 'dm drogerie')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('dm drogerie', $card->fresh()->name);

        $component->call('delete', $card->id);
        $this->assertNull($card->fresh());
    }

    public function test_the_detail_view_renders_a_scannable_code(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $card = LoyaltyCard::factory()->for($household)->create(['code' => '4006381333931', 'code_format' => 'EAN_13']);

        Volt::test('loyalty-cards.index')
            ->call('showCard', $card->id)
            ->assertSet('showingId', $card->id)
            ->assertSee('<svg', false);
    }

    public function test_barcode_renderer_produces_the_expected_symbologies(): void
    {
        // EAN-13 is 95 modules + a 10-module quiet zone each side = 115.
        $ean = Barcode::svg('4006381333931', 'EAN_13');
        $this->assertStringContainsString('viewBox="0 0 115 90"', $ean);

        // Code 128 renders something for arbitrary text.
        $this->assertStringContainsString('<svg', Barcode::svg('ABC-123', 'CODE_128'));

        // The dispatcher draws QR codes for 2D formats and barcodes otherwise.
        $this->assertStringContainsString('<svg', CardCode::svg('https://example.com', 'QR_CODE'));
        $this->assertTrue(CardCode::isTwoDimensional('QR_CODE'));
        $this->assertFalse(CardCode::isTwoDimensional('EAN_13'));
    }
}
