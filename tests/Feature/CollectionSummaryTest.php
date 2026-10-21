<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class CollectionSummaryTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_number_columns_summarise_with_the_chosen_function(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $collection = Collection::factory()->for($household)->create(['name' => 'Ausgaben']);
        $amount = $collection->properties()->create(['name' => 'Betrag', 'type' => CollectionProperty::TYPE_NUMBER, 'position' => 0]);

        $entries = collect([10, 20, 30])->map(function (int $value) use ($collection, $household, $amount) {
            $entry = Item::factory()->for($collection)->for($household)->create(['type' => Item::TYPE_ENTRY, 'title' => 'Posten']);
            $entry->setPropertyValue($amount, $value);

            return $entry;
        });

        $this->assertSame('60', $amount->aggregate($entries)); // default sum

        $amount->update(['options' => ['summary' => 'avg']]);
        $this->assertSame('20', $amount->fresh()->aggregate($entries));
        $this->assertSame('Ø ', $amount->fresh()->summaryPrefix());

        $amount->update(['options' => ['summary' => 'max']]);
        $this->assertSame('30', $amount->fresh()->aggregate($entries));

        $amount->update(['options' => ['summary' => 'min']]);
        $this->assertSame('10', $amount->fresh()->aggregate($entries));

        $amount->update(['options' => ['summary' => 'count']]);
        $this->assertSame('3', $amount->fresh()->aggregate($entries));
    }
}
