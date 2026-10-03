<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Support\RecipeAi;
use App\Support\RecipeAiException;
use App\Support\RecipeImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class RecipeImportFromSourcesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.anthropic.key' => 'test-key']);
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    public function test_a_recipe_website_is_read_from_its_structured_data_and_opens_the_form_prefilled(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->mock(RecipeAi::class, fn (MockInterface $ai) => $ai->shouldNotReceive('extractFromText'));

        $jsonLd = json_encode(['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'WebPage', 'name' => 'Seite'],
            [
                '@type' => ['Recipe'],
                'name' => 'Käsespätzle &amp; Zwiebeln',
                'description' => 'Wie im Allgäu.',
                'recipeYield' => '4 Portionen',
                'prepTime' => 'PT20M',
                'cookTime' => 'PT1H5M',
                'image' => ['https://rezepte.example/bilder/spaetzle.jpg'],
                'recipeIngredient' => ['500 g Mehl', '1/2 TL Salz', '5 Eier', 'Muskat'],
                'recipeInstructions' => [
                    ['@type' => 'HowToSection', 'name' => 'Teig', 'itemListElement' => [['@type' => 'HowToStep', 'text' => 'Teig rühren.']]],
                    ['@type' => 'HowToStep', 'text' => 'Spätzle schaben.'],
                ],
            ],
        ]]);
        Http::fake([
            'rezepte.example/kaesespaetzle' => Http::response('<html><head><script type="application/ld+json">'.$jsonLd.'</script></head><body>…</body></html>'),
            'rezepte.example/bilder/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->actingAs($user);
        Volt::test('recipes.import')
            ->set('url', 'https://rezepte.example/kaesespaetzle')
            ->call('import')
            ->assertHasNoErrors()
            ->assertRedirect(route('recipes.create'));

        $form = Volt::test('recipes.form')
            ->assertSet('title', 'Käsespätzle & Zwiebeln')
            ->assertSet('servings', 4)
            ->assertSet('prepTime', 20)
            ->assertSet('cookTime', 65)
            ->assertSet('steps', ['Teig rühren.', 'Spätzle schaben.'])
            ->assertSet('sourceUrl', 'https://rezepte.example/kaesespaetzle');
        $this->assertSame(
            [['500', 'g', 'Mehl'], ['1/2', 'TL', 'Salz'], ['5', '', 'Eier'], ['', '', 'Muskat']],
            collect($form->get('ingredients'))->map(fn ($ingredient) => [$ingredient['amount'], $ingredient['unit'], $ingredient['name']])->all(),
        );
        $form->call('save');

        $recipe = Recipe::sole();
        $this->assertSame([$household->id, 'WEB', 4], [$recipe->household_id, $recipe->source_type, count($recipe->ingredients)]);
        $this->assertStringStartsWith('recipes/'.$household->id.'/', $recipe->image_path);
        Storage::disk('local')->assertExists($recipe->image_path);

        // The draft is used once only.
        Volt::test('recipes.form')->assertSet('title', '');
    }

    public function test_pages_without_recipe_data_are_read_by_the_ai(): void
    {
        [$user] = $this->memberWithHousehold();
        Http::fake(['rezepte.example/*' => Http::response('<html><head><meta property="og:image" content="/bild.png"></head><body><script>tracking()</script><h1>Omas Apfelkuchen</h1><p>3 Äpfel …</p></body></html>')]);
        $this->mock(RecipeAi::class, fn (MockInterface $ai) => $ai->shouldReceive('extractFromText')
            ->once()
            ->withArgs(fn (string $text) => str_contains($text, 'Omas Apfelkuchen') && ! str_contains($text, 'tracking'))
            ->andReturn(['found' => true, 'title' => 'Omas Apfelkuchen', 'description' => '', 'servings' => 0, 'prepTime' => 0, 'cookTime' => 45,
                'ingredients' => [['amount' => '', 'unit' => '', 'name' => 'Teig', 'isSection' => true], ['amount' => '3', 'unit' => '', 'name' => 'Äpfel', 'isSection' => false]],
                'steps' => ['Backen.']]));

        $this->actingAs($user);
        Volt::test('recipes.import')->set('url', 'https://rezepte.example/apfelkuchen')->call('import')->assertRedirect(route('recipes.create'));

        $draft = session('recipe_draft');
        $this->assertSame(['Omas Apfelkuchen', null, 45, 'https://rezepte.example/bild.png'], [$draft['title'], $draft['servings'], $draft['cookTime'], $draft['imageUrl']]);
        $this->assertTrue($draft['ingredients'][0]['isSection']);
        $this->assertNull($draft['imagePath']);
    }

    public function test_instagram_posts_and_reels_are_read_from_their_caption(): void
    {
        [$user] = $this->memberWithHousehold();
        $answer = ['found' => true, 'title' => 'One-Pot-Pasta', 'description' => '', 'servings' => 2, 'prepTime' => 0, 'cookTime' => 15, 'ingredients' => [['amount' => '250', 'unit' => 'g', 'name' => 'Spaghetti', 'isSection' => false]], 'steps' => ['Alles kochen.']];
        $this->mock(RecipeAi::class, function (MockInterface $ai) use ($answer) {
            // As in RezepteApp2: the post page read as an iPhone, the shortened caption without closing quote.
            $ai->shouldReceive('extractFromText')->once()->with('Linsen-Dal 🍛 200 g rote Linsen, 1 Dose Kokosmilch, 1 Zwiebel …')->andReturn(['title' => 'Linsen-Dal'] + $answer);
            $ai->shouldReceive('extractFromText')->once()->with("One-Pot-Pasta 🍝\nZutaten:\n250 g Spaghetti\nAlles 15 Min. kochen.")->andReturn($answer);
            $ai->shouldReceive('extractFromText')->once()->with('Mein kompletter Text mit 250 g Spaghetti und allen Mengen')->andReturn(['title' => 'Eingefügt'] + $answer);
        });
        Http::fake([
            'www.instagram.com/reel/CReel99/*' => Http::response('<meta property="og:image" content="https://scontent.cdninstagram.com/dal-og.jpg"><meta name="twitter:image" content="https://scontent.cdninstagram.com/dal.jpg?x=1&amp;oh=2"><meta property="og:description" content="1.204 likes, 33 comments - veggie.koch am 2. Oktober 2026: &quot;Linsen-Dal 🍛 200 g rote Linsen, 1 Dose Kokosmilch, 1 Zwiebel …">'),
            'scontent.cdninstagram.com/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            // The iPhone page gives nothing here, the embed page has the caption.
            'www.instagram.com/p/DAbc123/embed/captioned/' => Http::response('<div class="Caption"><a class="CaptionUsername" href="#">koch.profi</a><br/><br/>One-Pot-Pasta 🍝<br />Zutaten:<br/>250 g Spaghetti<br>Alles 15 Min. kochen.<div class="CaptionComments">12 Kommentare</div></div><img class="EmbeddedMediaImage" src="https://scontent.cdninstagram.com/pasta.jpg?a=1&amp;b=2">'),
            'www.instagram.com/p/DAbc123/*' => Http::response('<html>Login</html>'),
            'www.instagram.com/p/OnlyPic1/*' => Http::response('<meta property="og:image" content="https://scontent.cdninstagram.com/pic.jpg"><meta property="og:description" content="5 likes">'),
            'www.instagram.com/p/Nothing1/*' => Http::response('<html>Login</html>'),
        ]);
        $this->actingAs($user);

        Volt::test('recipes.import')->set('url', 'https://www.instagram.com/reel/CReel99/?igsh=abc')->call('import')->assertRedirect(route('recipes.create'));
        $draft = session('recipe_draft');
        $this->assertSame(['Linsen-Dal', 'INSTAGRAM', 'https://scontent.cdninstagram.com/dal.jpg?x=1&oh=2'], [$draft['title'], $draft['sourceType'], $draft['imageUrl']]);
        $this->assertNotNull($draft['imagePath']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'dal.jpg') && $request->header('Referer')[0] === 'https://www.instagram.com/');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/reel/CReel99/') && str_contains($request->header('User-Agent')[0], 'iPhone'));

        Volt::test('recipes.import')->set('url', 'https://instagram.com/p/DAbc123/')->call('import')->assertRedirect(route('recipes.create'));
        $this->assertSame(['One-Pot-Pasta', 'https://scontent.cdninstagram.com/pasta.jpg?a=1&b=2'], [session('recipe_draft')['title'], session('recipe_draft')['imageUrl']]);

        // A pasted post text wins over Instagram's shortened one.
        Volt::test('recipes.import')->set('url', 'https://www.instagram.com/reel/CReel99/')->set('caption', 'Mein kompletter Text mit 250 g Spaghetti und allen Mengen')
            ->call('import')->assertRedirect(route('recipes.create'));
        $this->assertSame('Eingefügt', session('recipe_draft')['title']);

        // Only the photo: the form opens with it and says what is missing.
        Volt::test('recipes.import')->set('url', 'https://www.instagram.com/p/OnlyPic1/')->call('import')->assertRedirect(route('recipes.create'));
        Volt::test('recipes.form')->assertSet('title', 'Instagram-Rezept')->assertSee('Instagram hat nur das Foto herausgegeben');

        // Nothing readable at all: a clear hint.
        Volt::test('recipes.import')->set('url', 'https://www.instagram.com/p/Nothing1/')->call('import')
            ->assertSee('Instagram gibt den Text dieses Beitrags nicht heraus')->assertNoRedirect();
    }

    public function test_photos_pdfs_and_text_are_sent_to_the_ai(): void
    {
        [$user] = $this->memberWithHousehold();
        $answer = ['found' => true, 'title' => 'Aus der KI', 'description' => 'x', 'servings' => 2, 'prepTime' => 5, 'cookTime' => 0, 'ingredients' => [], 'steps' => ['Fertig.']];
        $this->mock(RecipeAi::class, function (MockInterface $ai) use ($answer) {
            $ai->shouldReceive('extractFromImages')->once()->withArgs(fn (array $images) => count($images) === 2 && $images[0]['mediaType'] === 'image/jpeg')->andReturn($answer);
            $ai->shouldReceive('extractFromPdf')->once()->withArgs(fn (string $pdf) => str_starts_with($pdf, '%PDF'))->andReturn($answer);
            $ai->shouldReceive('extractFromText')->once()->with('Nudeln kochen, Soße drüber, fertig ist das Essen.')->andReturn($answer);
        });
        $this->actingAs($user);

        Volt::test('recipes.import')->set('source', 'photo')
            ->set('photos', [UploadedFile::fake()->image('seite1.jpg', 2000, 3000), UploadedFile::fake()->image('seite2.png')])
            ->call('import')->assertRedirect(route('recipes.create'));
        $this->assertSame(['Aus der KI', 'IMAGE'], [session('recipe_draft')['title'], session('recipe_draft')['sourceType']]);

        Volt::test('recipes.import')->set('source', 'pdf')
            ->set('pdf', UploadedFile::fake()->createWithContent('rezept.pdf', "%PDF-1.4\n…"))
            ->call('import')->assertRedirect(route('recipes.create'));

        Volt::test('recipes.import')->set('source', 'text')
            ->set('text', 'Nudeln kochen, Soße drüber, fertig ist das Essen.')
            ->call('import')->assertRedirect(route('recipes.create'));
        $this->assertSame('TEXT', session('recipe_draft')['sourceType']);
    }

    public function test_failures_are_shown_instead_of_opening_the_form(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->mock(RecipeAi::class, fn (MockInterface $ai) => $ai->shouldReceive('extractFromText')->andThrow(new RecipeAiException('Kein Rezept gefunden.')));
        Http::fake(['rezepte.example/*' => Http::response('nicht gefunden', 404)]);
        $this->actingAs($user);

        Volt::test('recipes.import')->set('source', 'text')->set('text', 'Das ist nur ein Einkaufszettel ohne Rezept.')
            ->call('import')->assertSet('failure', 'Kein Rezept gefunden.')->assertNoRedirect();

        Volt::test('recipes.import')->set('url', 'https://rezepte.example/weg')
            ->call('import')->assertSee('Die Seite konnte nicht geladen werden')->assertNoRedirect();

        Volt::test('recipes.import')->set('url', 'kein link')->call('import')->assertHasErrors('url');
    }

    public function test_addresses_in_the_local_network_are_never_fetched(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        foreach (['http://localhost/admin', 'http://127.0.0.1:8080/', 'http://10.0.0.5/rezept', 'http://[::1]/', 'http://192.168.1.1/', 'http://router.local/'] as $url) {
            Volt::test('recipes.import')->set('url', $url)->call('import')
                ->assertSet('failure', 'Diese Adresse kann nicht importiert werden.');
        }

        Http::assertNothingSent();
    }

    public function test_without_api_key_only_links_can_be_imported(): void
    {
        config(['services.anthropic.key' => null]);
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $this->assertFalse(RecipeAi::isConfigured());
        Volt::test('recipes.import')->set('source', 'photo')->assertSee('ANTHROPIC_API_KEY')->assertDontSee('Importieren</button>', false);
        $this->expectException(RecipeAiException::class);
        app(RecipeAi::class)->extractFromText('Nudeln mit Tomatensoße');
    }

    public function test_ingredient_lines_are_split_into_amount_unit_and_name(): void
    {
        $importer = app(RecipeImporter::class);

        $this->assertSame(['amount' => '200', 'unit' => 'g', 'name' => 'Mehl'], $importer->parseIngredient('200 g Mehl'));
        $this->assertSame(['amount' => '1,5', 'unit' => 'EL', 'name' => 'Öl'], $importer->parseIngredient('1,5 EL Öl'));
        $this->assertSame(['amount' => '2-3', 'unit' => 'Zehen', 'name' => 'Knoblauch'], $importer->parseIngredient('2-3 Zehen Knoblauch'));
        $this->assertSame(['amount' => '½', 'unit' => '', 'name' => 'Zitrone'], $importer->parseIngredient('½ Zitrone'));
        $this->assertSame(['amount' => '3', 'unit' => 'Gläser', 'name' => 'Wasser'], $importer->parseIngredient('3 Gläser Wasser'));
        $this->assertSame(['amount' => '', 'unit' => '', 'name' => 'Salz und Pfeffer'], $importer->parseIngredient('Salz und Pfeffer'));
        $this->assertSame(['amount' => '1', 'unit' => '', 'name' => 'Gurke'], $importer->parseIngredient('1 Gurke'));
    }
}
