<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\CollectionTemplate;
use App\Models\Comment;
use App\Models\HouseholdInvite;
use App\Models\Item;
use App\Models\User;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;
use ZipArchive;

class FormBuilderCommentsAndImportTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00'));
    }

    private function collectionWith(array $properties, string $name = 'Projekte'): Collection
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $collection = Collection::create(['household_id' => $household->id, 'name' => $name, 'visibility' => 'shared', 'created_by' => $user->id]);

        foreach ($properties as $position => $attributes) {
            $collection->properties()->create($attributes + ['position' => $position + 1]);
        }

        return $collection->load('properties');
    }

    public function test_fields_can_be_reordered_required_and_prefilled(): void
    {
        $collection = $this->collectionWith([
            ['name' => 'Frist', 'type' => CollectionProperty::TYPE_DATE],
            ['name' => 'Status', 'type' => CollectionProperty::TYPE_SELECT, 'options' => [['id' => 'open', 'name' => 'Offen', 'color' => 'gray'], ['id' => 'done', 'name' => 'Fertig', 'color' => 'green']]],
            ['name' => 'Zuständig', 'type' => CollectionProperty::TYPE_PERSON],
            ['name' => 'Kunde', 'type' => CollectionProperty::TYPE_TEXT],
        ]);
        [$deadline, $status, $owner, $customer] = $collection->properties->all();

        $component = Volt::test('collections.show', ['collection' => $collection->id])
            ->call('moveProperty', $customer->id, -1)
            ->call('setPropertyRequired', $customer->id, true)
            ->call('setPropertyHelp', $customer->id, 'Firmenname laut Vertrag')
            ->call('setPropertyDefault', $deadline->id, 'today+7')
            ->call('setPropertyDefault', $status->id, 'open')
            ->call('setPropertyDefault', $owner->id, 'me');

        $this->assertSame(['Frist', 'Status', 'Kunde', 'Zuständig'], $collection->properties()->pluck('name')->all());

        $component->call('newEntry')
            ->assertSet('form.values.'.$deadline->id, '2026-10-02')
            ->assertSet('form.values.'.$status->id, 'open')
            ->assertSet('form.values.'.$owner->id, (string) auth()->id())
            ->assertSee('Firmenname laut Vertrag')
            ->set('form.title', 'Website')
            ->call('saveEntryForm')
            ->assertHasErrors('form.values.'.$customer->id);

        $this->assertSame(0, Item::where('collection_id', $collection->id)->count());

        $component->set('form.values.'.$customer->id, 'Café Sonne')->call('saveEntryForm')->assertHasNoErrors();
        $this->assertSame('Café Sonne', Item::where('collection_id', $collection->id)->sole()->propertyValue($customer));

        // Quick add uses the defaults as well.
        $component->set('newTitle', 'Flyer')->call('addEntry');
        $this->assertSame('2026-10-02', Item::where('title', 'Flyer')->sole()->propertyValue($deadline));
    }

    public function test_a_collection_is_saved_as_template_and_reused(): void
    {
        $collection = $this->collectionWith([
            ['name' => 'Kunde', 'type' => CollectionProperty::TYPE_TEXT, 'required' => true, 'help' => 'Firmenname'],
            ['name' => 'Budget (€)', 'type' => CollectionProperty::TYPE_NUMBER],
        ]);
        $collection->update(['icon' => '🚀', 'default_view' => 'list']);
        Item::create(['household_id' => $collection->household_id, 'collection_id' => $collection->id, 'type' => Item::TYPE_ENTRY, 'visibility' => 'shared', 'title' => 'Geheimprojekt']);

        Volt::test('collections.show', ['collection' => $collection->id])->call('saveAsTemplate', 'Kundenprojekt');
        $template = CollectionTemplate::sole();

        Volt::test('collections.index')
            ->set('showForm', true)
            ->assertSee('Kundenprojekt')
            ->set('template', 'custom:'.$template->id)
            ->assertSet('name', 'Kundenprojekt')
            ->set('name', 'Projekte 2027')
            ->call('create');

        $copy = Collection::where('name', 'Projekte 2027')->sole();
        $this->assertSame(['🚀', 'list'], [$copy->icon, $copy->default_view]);
        $this->assertSame(['Kunde', 'Budget (€)'], $copy->properties->pluck('name')->all());
        $this->assertTrue($copy->properties->first()->required);
        $this->assertSame('Firmenname', $copy->properties->first()->help);
        $this->assertSame(0, $copy->items()->count());

        // Templates belong to the household.
        [$stranger] = $this->memberWithHousehold();
        $this->actingAs($stranger);
        Volt::test('collections.index')->set('name', 'X')->set('template', 'custom:'.$template->id)->call('create')->assertNotFound();
    }

    public function test_members_comment_on_entries_and_changes_are_logged(): void
    {
        $collection = $this->collectionWith([
            ['name' => 'Status', 'type' => CollectionProperty::TYPE_SELECT, 'options' => [['id' => 'open', 'name' => 'Offen', 'color' => 'gray'], ['id' => 'done', 'name' => 'Fertig', 'color' => 'green']]],
        ]);
        $author = auth()->user();
        $status = $collection->properties->first();

        Volt::test('collections.show', ['collection' => $collection->id])->set('newTitle', 'Keller aufräumen')->call('addEntry');
        $entry = Item::where('title', 'Keller aufräumen')->sole();
        Volt::test('collections.show', ['collection' => $collection->id])->call('updateProperty', $entry->id, $status->id, 'done')->call('updateTitle', $entry->id, 'Keller & Garage aufräumen');

        $partner = $this->addMemberTo($collection->household);
        $this->actingAs($partner);
        $this->mock(WebPush::class, fn (MockInterface $push) => $push->shouldReceive('send')->once()->withArgs(fn (User $user, string $title) => $user->is($author) && str_contains($title, $partner->name)));

        Volt::test('entries.activity', ['itemId' => $entry->id])
            ->set('body', 'Ich übernehme die Garage.')
            ->call('addComment')
            ->assertHasNoErrors()
            ->assertSee('Ich übernehme die Garage.')
            ->set('showHistory', true)
            ->assertSee('hat den Eintrag erstellt')
            ->assertSee('Status geändert: — → Fertig', false)
            ->assertSee('umbenannt in „Keller &amp; Garage aufräumen“', false);

        $comment = Comment::sole();
        $this->actingAs($author);
        Volt::test('entries.activity', ['itemId' => $entry->id])->call('deleteComment', $comment->id)->assertForbidden();

        [$stranger] = $this->memberWithHousehold();
        $this->actingAs($stranger);
        Volt::test('entries.activity', ['itemId' => $entry->id])->assertNotFound();
    }

    public function test_a_csv_file_is_imported_with_column_mapping(): void
    {
        $collection = $this->collectionWith([
            ['name' => 'Kategorie', 'type' => CollectionProperty::TYPE_SELECT, 'options' => [['id' => 'book', 'name' => 'Buch', 'color' => 'blue']]],
        ], 'Leseliste');
        $category = $collection->properties->first();
        $csv = mb_convert_encoding("Titel;Kategorie;Gelesen am;Preis;Gelesen;Notiz\nDer Hobbit;Buch;24.12.2025;12,99;ja;Klassiker\nDune;Hörbuch;03.01.2026;1.234,50;nein;\n;;;;;\n", 'Windows-1252', 'UTF-8');

        Volt::test('collections.import', ['collectionId' => $collection->id])
            ->set('file', UploadedFile::fake()->createWithContent('liste.csv', $csv))
            ->assertSet('headers', ['Titel', 'Kategorie', 'Gelesen am', 'Preis', 'Gelesen', 'Notiz'])
            ->assertSet('mapping', ['title', 'property:'.$category->id, 'new', 'new', 'new', 'new'])
            ->set('mapping.5', 'skip')
            ->call('import')
            ->assertDispatched('collection-imported');

        $collection->refresh()->load('properties');
        $this->assertSame(['Kategorie', 'Gelesen am', 'Preis', 'Gelesen'], $collection->properties->pluck('name')->all());
        $this->assertSame([CollectionProperty::TYPE_DATE, CollectionProperty::TYPE_NUMBER, CollectionProperty::TYPE_CHECKBOX], $collection->properties->slice(1)->pluck('type')->values()->all());

        [$readOn, $price, $read] = $collection->properties->slice(1)->values()->all();
        $dune = Item::where('title', 'Dune')->sole();
        $this->assertSame(['2026-01-03', 1234.5, null], [$dune->propertyValue($readOn), $dune->propertyValue($price), $dune->propertyValue($read)]);
        $this->assertSame('Hörbuch', $collection->properties->first()->option($dune->propertyValue($category))['name']);
        $this->assertTrue(Item::where('title', 'Der Hobbit')->sole()->propertyValue($read));
        $this->assertSame(2, Item::where('collection_id', $collection->id)->count());
    }

    #[RequiresPhpExtension('zip')]
    public function test_excel_files_are_read(): void
    {
        $collection = $this->collectionWith([], 'Inventar');
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Tabelle1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Gerät</t></si><si><t>Gekauft</t></si><si><t>Bohrmaschine</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>46023</v></c></row></sheetData></worksheet>');
        $zip->close();

        Volt::test('collections.import', ['collectionId' => $collection->id])
            ->set('file', UploadedFile::fake()->createWithContent('inventar.xlsx', (string) file_get_contents($path)))
            ->assertSet('headers', ['Gerät', 'Gekauft'])
            ->call('import');

        $bought = $collection->properties()->where('name', 'Gekauft')->sole();
        $this->assertSame(CollectionProperty::TYPE_DATE, $bought->type);
        $this->assertSame('2026-01-01', Item::where('title', 'Bohrmaschine')->sole()->propertyValue($bought));
        unlink($path);
    }

    public function test_invite_links_fill_in_the_code(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        Volt::test('household.show')->call('generateInvite')->assertSee(route('register', ['code' => HouseholdInvite::sole()->code]));

        auth()->logout();
        Volt::test('pages.auth.register')->assertSet('invite_code', '');
        $this->get(route('register', ['code' => 'abc123']))->assertOk()->assertSee('ABC123');
    }
}
