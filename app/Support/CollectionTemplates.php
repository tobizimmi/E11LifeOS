<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\CollectionProperty;

/**
 * Starting points offered when a new collection is created – also the household
 * "modules" (contracts, budget, pantry, bookmarks, journal, habits), which are
 * ordinary collections with fitting properties.
 */
class CollectionTemplates
{
    /**
     * @return array<string, array{label: string, icon: string, description: string, private?: bool}>
     */
    public static function all(): array
    {
        return [
            'blank' => ['label' => __('Blank'), 'icon' => '🗂️', 'description' => __('Start without properties and add your own.')],
            'projects' => ['label' => __('Projects'), 'icon' => '🚀', 'description' => __('Status, due date and assignee – ideal as a board.')],
            'contracts' => ['label' => __('Contracts & documents'), 'icon' => '📑', 'description' => __('Provider, cost, customer number and cancellation deadline with e-mail reminder; attach the documents to each contract.')],
            'budget' => ['label' => __('Household budget'), 'icon' => '💶', 'description' => __('Expenses and income with category and date – the table adds up the amounts.')],
            'inventory' => ['label' => __('Household inventory'), 'icon' => '🧰', 'description' => __('Appliances and valuables with purchase date, price, warranty and maintenance reminders – attach the receipt and manual from paperless to each device.')],
            'pantry' => ['label' => __('Pantry'), 'icon' => '🥫', 'description' => __('Stock, minimum stock, storage place and best-before date with reminder.')],
            'bookmarks' => ['label' => __('Bookmarks / read later'), 'icon' => '🔖', 'description' => __('Links to read later with status – ideal as a board.')],
            'journal' => ['label' => __('Journal'), 'icon' => '📔', 'description' => __('One entry per day with mood and your notes – private by default.'), 'private' => true],
            'habits' => ['label' => __('Habits'), 'icon' => '✅', 'description' => __('One row per day, tick off your habits – the table counts how often.')],
            'reading' => ['label' => __('Reading list'), 'icon' => '📚', 'description' => __('Author, status and rating.')],
        ];
    }

    public static function apply(Collection $collection, string $template): void
    {
        $properties = match ($template) {
            'projects' => [
                self::select(__('Status'), [__('Idea'), __('In progress'), __('Done')]),
                ['name' => __('Due'), 'type' => CollectionProperty::TYPE_DATE],
                ['name' => __('Assignee'), 'type' => CollectionProperty::TYPE_PERSON],
            ],
            'contracts' => [
                self::select(__('Category'), [__('Insurance'), __('Energy'), __('Internet & phone'), __('Subscriptions'), __('Mobility'), __('Other')]),
                ['name' => __('Provider'), 'type' => CollectionProperty::TYPE_TEXT],
                ['name' => __('Monthly cost (€)'), 'type' => CollectionProperty::TYPE_NUMBER],
                ['name' => __('Customer number'), 'type' => CollectionProperty::TYPE_TEXT],
                ['name' => __('Notice period'), 'type' => CollectionProperty::TYPE_TEXT],
                ['name' => __('Ends on'), 'type' => CollectionProperty::TYPE_DATE],
                ['name' => __('Cancel by'), 'type' => CollectionProperty::TYPE_DATE, 'options' => ['remind_days' => 30, 'create_task' => true]],
                ['name' => __('Website'), 'type' => CollectionProperty::TYPE_URL],
            ],
            'budget' => [
                ['name' => __('Amount (€)'), 'type' => CollectionProperty::TYPE_NUMBER],
                self::select(__('Type'), [__('Expense'), __('Income')]),
                self::select(__('Category'), [__('Groceries'), __('Home'), __('Mobility'), __('Leisure'), __('Health'), __('Other')]),
                ['name' => __('Date'), 'type' => CollectionProperty::TYPE_DATE],
                ['name' => __('Paid by'), 'type' => CollectionProperty::TYPE_PERSON],
            ],
            'inventory' => [
                self::select(__('Room'), [__('Kitchen'), __('Living room'), __('Bedroom'), __('Bathroom'), __('Office'), __('Cellar'), __('Garage'), __('Garden')]),
                ['name' => __('Manufacturer & model'), 'type' => CollectionProperty::TYPE_TEXT],
                ['name' => __('Serial number'), 'type' => CollectionProperty::TYPE_TEXT],
                ['name' => __('Purchased on'), 'type' => CollectionProperty::TYPE_DATE],
                ['name' => __('Price (€)'), 'type' => CollectionProperty::TYPE_NUMBER],
                ['name' => __('Warranty until'), 'type' => CollectionProperty::TYPE_DATE, 'options' => ['remind_days' => 30]],
                ['name' => __('Next maintenance'), 'type' => CollectionProperty::TYPE_DATE, 'options' => ['remind_days' => 7, 'create_task' => true]],
                ['name' => __('Manual'), 'type' => CollectionProperty::TYPE_URL],
            ],
            'pantry' => [
                ['name' => __('Quantity'), 'type' => CollectionProperty::TYPE_NUMBER],
                ['name' => __('Unit'), 'type' => CollectionProperty::TYPE_TEXT],
                ['name' => __('Minimum stock'), 'type' => CollectionProperty::TYPE_NUMBER],
                self::select(__('Place'), [__('Fridge'), __('Cupboard'), __('Freezer'), __('Cellar')]),
                ['name' => __('Best before'), 'type' => CollectionProperty::TYPE_DATE, 'options' => ['remind_days' => 3]],
            ],
            'bookmarks' => [
                self::select(__('Status'), [__('Read later'), __('Read'), __('Archived')]),
                ['name' => __('Link'), 'type' => CollectionProperty::TYPE_URL],
            ],
            'journal' => [
                ['name' => __('Date'), 'type' => CollectionProperty::TYPE_DATE],
                self::select(__('Mood'), ['😄', '🙂', '😐', '🙁', '😞']),
            ],
            'habits' => [
                ['name' => __('Date'), 'type' => CollectionProperty::TYPE_DATE],
                ['name' => __('Exercise'), 'type' => CollectionProperty::TYPE_CHECKBOX],
                ['name' => __('Reading'), 'type' => CollectionProperty::TYPE_CHECKBOX],
                ['name' => __('Meditation'), 'type' => CollectionProperty::TYPE_CHECKBOX],
                ['name' => __('Enough water'), 'type' => CollectionProperty::TYPE_CHECKBOX],
            ],
            'reading' => [
                ['name' => __('Author'), 'type' => CollectionProperty::TYPE_TEXT],
                self::select(__('Status'), [__('To read'), __('Reading'), __('Finished')]),
                self::select(__('Rating'), ['★', '★★', '★★★', '★★★★', '★★★★★']),
            ],
            default => [],
        };

        foreach ($properties as $position => $property) {
            $collection->properties()->create($property + ['position' => $position]);
        }

        // The first pantry of a household is linked with recipes and the shopping list.
        if ($template === 'pantry' && ! Collection::where('household_id', $collection->household_id)->where('system_key', Pantry::SYSTEM_KEY)->exists()) {
            Pantry::adopt($collection->load('properties'));
        }

        $defaultView = match ($template) {
            'contracts', 'reading', 'inventory' => 'cards',
            'pantry', 'journal' => 'list',
            'projects', 'bookmarks' => 'board',
            default => null,
        };

        if ($defaultView) {
            $collection->update(['default_view' => $defaultView]);
        }
    }

    /**
     * @param  array<int, string>  $names
     * @return array{name: string, type: string, options: array<int, array{id: string, name: string, color: string}>}
     */
    private static function select(string $name, array $names): array
    {
        return [
            'name' => $name,
            'type' => CollectionProperty::TYPE_SELECT,
            'options' => collect($names)->values()->map(fn (string $option, int $index) => [
                'id' => 'o'.($index + 1),
                'name' => $option,
                'color' => CollectionProperty::COLORS[$index % count(CollectionProperty::COLORS)],
            ])->all(),
        ];
    }
}
