<?php

namespace App\Support\Automation;

use App\Models\HouseholdMode;
use App\Models\SmartHomeEntity;
use App\Support\AiFeatures;
use App\Support\StructuredAi;

/**
 * Drafts an automation from a sentence ("When the last one leaves, switch off all lights"). The
 * draft only fills the editor; nothing is saved or run before the member checks it.
 */
class AutomationAi extends StructuredAi
{
    protected const FEATURE = AiFeatures::AUTOMATIONS;

    /**
     * @var array<string, mixed>
     */
    private const BLOCK = [
        'type' => 'object',
        'properties' => [
            'type' => ['type' => 'string'],
            'settings' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => ['key' => ['type' => 'string'], 'value' => ['type' => 'string']],
                    'required' => ['key', 'value'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['type', 'settings'],
        'additionalProperties' => false,
    ];

    /**
     * @return array{name: string, trigger: array<string, mixed>, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>}
     */
    public function draft(string $text, int $householdId, bool $smartHome): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Short name in the language of the request'],
                'trigger' => self::BLOCK,
                'conditions' => ['type' => 'array', 'items' => self::BLOCK],
                'actions' => ['type' => 'array', 'items' => self::BLOCK],
            ],
            'required' => ['name', 'trigger', 'conditions', 'actions'],
            'additionalProperties' => false,
        ];

        $data = $this->ask([['type' => 'text', 'text' => mb_substr($text, 0, 1000)]], $schema, 2000, $this->system($householdId, $smartHome));

        return self::toForm($data, $smartHome);
    }

    /**
     * The answer as blocks the editor understands: unknown types are dropped, every block gets
     * exactly the fields of its type.
     *
     * @param  array<string, mixed>  $data
     * @return array{name: string, trigger: array<string, mixed>, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>}
     */
    public static function toForm(array $data, bool $smartHome): array
    {
        $block = function (mixed $raw, string $kind) use ($smartHome): ?array {
            if (! is_array($raw) || (! $smartHome && in_array($raw['type'] ?? '', AutomationCatalog::SMART_HOME_ONLY, true))) {
                return null;
            }

            $fields = ['type' => $raw['type'] ?? ''];

            foreach ((array) ($raw['settings'] ?? []) as $setting) {
                if (is_array($setting) && is_string($setting['key'] ?? null)) {
                    $value = (string) ($setting['value'] ?? '');
                    $decoded = json_decode($value, true);
                    $fields[$setting['key']] = is_array($decoded) ? $decoded : $value;
                }
            }

            return AutomationCatalog::clean($fields, $kind);
        };

        $list = fn (mixed $items, string $kind) => array_slice(array_values(array_filter(array_map(fn ($item) => $block($item, $kind), is_array($items) ? $items : []))), 0, 20);

        return [
            'name' => mb_substr(trim((string) ($data['name'] ?? '')), 0, 150),
            'trigger' => $block($data['trigger'] ?? null, 'trigger') ?? ['type' => 'manual'],
            'conditions' => $list($data['conditions'] ?? [], 'condition'),
            'actions' => $list($data['actions'] ?? [], 'action'),
        ];
    }

    private function system(int $householdId, bool $smartHome): string
    {
        $describe = fn (array $catalog) => collect($catalog)
            ->reject(fn ($block, string $type) => ! $smartHome && in_array($type, AutomationCatalog::SMART_HOME_ONLY, true))
            ->map(fn (array $block, string $type) => '- '.$type.' ('.$block['label'].'): '.json_encode($block['defaults'], JSON_UNESCAPED_UNICODE))
            ->implode("\n");
        $modes = HouseholdMode::forHousehold($householdId)->map(fn (HouseholdMode $mode) => $mode->key.' = '.$mode->name)->implode(', ');
        $entities = $smartHome
            ? SmartHomeEntity::where('household_id', $householdId)->orderBy('name')->limit(150)->get()->map(fn (SmartHomeEntity $entity) => $entity->entity_id.' = '.$entity->name.($entity->area ? ' ('.$entity->area.')' : ''))->implode("\n")
            : '';
        $triggers = $describe(AutomationCatalog::triggers());
        $conditions = $describe(AutomationCatalog::conditions());
        $actions = $describe(AutomationCatalog::actions());

        return <<<PROMPT
        You turn a request into an automation of a household app: one trigger, optional conditions (all must hold; use type "any" with a "conditions" setting holding a JSON list of conditions for OR) and a list of actions run in order.
        Every block has a type and settings (key/value pairs, values as strings; lists and booleans as JSON, e.g. "[1,2,3]" or "true"). Use only these types and setting keys:

        Triggers:
        {$triggers}

        Conditions:
        {$conditions}

        Actions:
        {$actions}

        Times are HH:MM, weekdays 1 = Monday … 7 = Sunday. Texts may use placeholders: {reason}, {automation}, {time}, {date}, {weekday}, {weather}, {state:entity_id}.
        Modes: {$modes}
        Devices (entity_id = name):
        {$entities}

        Write names and texts in the language of the request. Leave an entity_id empty if no device fits.
        PROMPT;
    }
}
