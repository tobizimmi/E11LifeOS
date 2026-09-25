<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\CollectionProperty;

/**
 * Starting points offered when a new collection is created.
 */
class CollectionTemplates
{
    /**
     * @return array<string, array{label: string, icon: string, description: string}>
     */
    public static function all(): array
    {
        return [
            'blank' => ['label' => __('Blank'), 'icon' => '🗂️', 'description' => __('Start without properties and add your own.')],
            'projects' => ['label' => __('Projects'), 'icon' => '🚀', 'description' => __('Status, due date and assignee – ideal as a board.')],
            'contracts' => ['label' => __('Contracts'), 'icon' => '📑', 'description' => __('Provider, monthly cost, notice period and renewal date.')],
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
                ['name' => __('Provider'), 'type' => CollectionProperty::TYPE_TEXT],
                ['name' => __('Monthly cost (€)'), 'type' => CollectionProperty::TYPE_NUMBER],
                ['name' => __('Notice period'), 'type' => CollectionProperty::TYPE_TEXT],
                ['name' => __('Renews on'), 'type' => CollectionProperty::TYPE_DATE],
                ['name' => __('Website'), 'type' => CollectionProperty::TYPE_URL],
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
