<?php

namespace Tests\Feature;

use App\Models\PdfTemplate;
use App\Models\Recipe;
use App\Support\PdfTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PdfDesignTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_the_editor_saves_only_allowed_settings_per_household(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();

        Volt::actingAs($user)->test('pdf.editor')
            ->set('general.accent', '#30A46C')
            ->set('general.font', 'serif')
            ->set('general.footer', '<b>Familie Muster</b>')
            ->set('recipe.layout', 'stacked')
            ->set('recipe.paper', 'A3')
            ->set('recipe.notes', true)
            ->call('save')
            ->assertHasNoErrors();

        $general = PdfTheme::for($household->id, 'general');
        $recipe = PdfTheme::for($household->id, 'recipe');
        $this->assertSame(['accent' => '#30a46c', 'font' => 'serif', 'footer' => 'Familie Muster', 'date' => true], $general);
        $this->assertSame('stacked', $recipe['layout']);
        $this->assertSame('A4', $recipe['paper']);
        $this->assertTrue($recipe['notes']);
        $this->assertSame(PdfTheme::defaults('general'), PdfTheme::for($otherHousehold->id, 'general'));
        $this->assertSame(2, PdfTemplate::where('household_id', $household->id)->count());
    }

    public function test_the_theme_adds_font_accent_and_footer_and_falls_back_to_unicode_fonts(): void
    {
        $theme = ['accent' => '#e5484d', 'font' => 'helvetica', 'footer' => 'Familie Muster', 'date' => false];

        $html = PdfTheme::document('<html><head></head><body><p>Grüße</p></body></html>', null, true, $theme);
        $this->assertStringContainsString('Helvetica, sans-serif', $html);
        $this->assertStringContainsString('.accent{color:#e5484d;}', $html);
        $this->assertStringContainsString('<div class="pdf-footer">Familie Muster</div>', $html);

        $this->assertStringContainsString("'DejaVu Sans'", PdfTheme::document('<body>Łódź</body>', null, true, $theme));
        $this->assertStringNotContainsString('pdf-footer">', PdfTheme::document('<body>Brief</body>', null, false, $theme));
    }

    public function test_the_recipe_pdf_uses_saved_or_draft_settings(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $recipe = Recipe::factory()->for($household)->create(['title' => 'Pfannkuchen']);
        PdfTheme::save($household->id, 'general', ['accent' => '#0a84ff']);

        $saved = $this->actingAs($user)->get(route('recipes.pdf', ['recipe' => $recipe, 'format' => 'html']))->assertOk();
        $saved->assertSee('Pfannkuchen')->assertSee('#0a84ff', false);

        $draft = json_encode(['general' => ['accent' => '#8e4ec6'], 'recipe' => ['paper' => 'A5']]);
        $this->actingAs($user)->get(route('recipes.pdf', ['recipe' => $recipe, 'format' => 'html', 'draft' => $draft]))
            ->assertOk()->assertSee('#8e4ec6', false)->assertSee('148mm', false);

        $pdf = $this->actingAs($user)->get(route('recipes.pdf', ['recipe' => $recipe, 'inline' => 1, 'draft' => $draft]))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $pdf->headers->get('Content-Disposition'));

        $this->actingAs($user)->get(route('recipes.pdf', ['recipe' => $recipe, 'draft' => str_repeat('x', 2001)]))->assertStatus(422);
    }
}
