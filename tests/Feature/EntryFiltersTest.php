<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Support\EntryFilters;
use Illuminate\Support\Collection;
use Tests\TestCase;

class EntryFiltersTest extends TestCase
{
    public function test_filters_combine_with_and_or_or(): void
    {
        $entries = new Collection([
            new Item(['title' => 'Apfel']),
            new Item(['title' => 'Birne']),
            new Item(['title' => 'Apfelsaft']),
        ]);

        $filters = [
            ['property' => 'title', 'operator' => 'contains', 'value' => 'Apfel'],
            ['property' => 'title', 'operator' => 'contains', 'value' => 'Birne'],
        ];

        // AND: no title contains both "Apfel" and "Birne".
        $and = EntryFilters::apply($entries, $filters, new Collection, 1, 'and');
        $this->assertCount(0, $and);

        // OR: every title contains "Apfel" or "Birne".
        $or = EntryFilters::apply($entries, $filters, new Collection, 1, 'or')->pluck('title')->all();
        $this->assertSame(['Apfel', 'Birne', 'Apfelsaft'], $or);
    }
}
