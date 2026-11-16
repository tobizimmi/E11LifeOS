<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Automation;
use App\Models\Household;
use App\Models\HouseholdMode;
use App\Support\Addons;
use App\Support\Automation\AutomationCatalog;
use App\Support\Automation\AutomationEngine;
use App\Support\Automation\Placeholders;
use App\Support\Drives\WebDavClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile API for the block editor of automations: the catalogue of triggers, conditions and actions
 * with form fields, and saving, editing and test-running one automation.
 */
class AutomationEditorController extends ApiController
{
    private const TIME_KEYS = ['at', 'from', 'until', 'time'];

    public function catalog(): JsonResponse
    {
        $household = $this->household();
        $smartHome = $this->user()->canUseAddon(Addons::SMART_HOME);
        $modes = HouseholdMode::forHousehold($household->id)->map(fn (HouseholdMode $mode) => ['value' => $mode->key, 'label' => $mode->name])->values()->all();

        return $this->ok([
            'triggers' => $this->blocks(AutomationCatalog::triggers(), 'trigger', $modes, $smartHome),
            'conditions' => $this->blocks(AutomationCatalog::conditions(), 'condition', $modes, $smartHome),
            'actions' => $this->blocks(AutomationCatalog::actions(), 'action', $modes, $smartHome),
            'placeholders' => Placeholders::help(),
        ]);
    }

    public function show(int $automation): JsonResponse
    {
        $model = $this->find($automation);

        return $this->ok($this->definition($model));
    }

    public function store(Request $request): JsonResponse
    {
        $household = $this->household();
        $model = Automation::create($this->form($request) + ['household_id' => $household->id, 'user_id' => $this->user()->id, 'active' => true]);

        return $this->ok($this->definition($model), 201);
    }

    public function update(Request $request, int $automation): JsonResponse
    {
        $model = $this->find($automation);
        $attributes = $this->form($request);
        // A changed trigger starts from scratch, so a state that already holds fires again.
        $model->update($attributes + ($model->trigger != $attributes['trigger'] ? ['trigger_state' => false] : []));

        return $this->ok($this->definition($model));
    }

    /**
     * Shows what would happen now, without doing anything.
     */
    public function test(Request $request, AutomationEngine $engine): JsonResponse
    {
        $household = $this->household();
        $model = new Automation($this->form($request) + ['household_id' => $household->id, 'user_id' => $this->user()->id]);
        $model->setRelation('user', $this->user());

        return $this->ok(['log' => $engine->dryRun($model)]);
    }

    /**
     * @return array{name: string, trigger: array<string, mixed>, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, cooldown_minutes: int}
     */
    private function form(Request $request): array
    {
        $time = ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'trigger' => ['required', 'array'],
            'trigger.type' => ['required', 'in:'.implode(',', array_keys(AutomationCatalog::triggers()))],
            'trigger.at' => $time,
            'conditions' => ['nullable', 'array', 'max:20'],
            'conditions.*.type' => ['required', 'in:'.implode(',', array_keys(AutomationCatalog::conditions()))],
            'conditions.*.from' => $time,
            'conditions.*.until' => $time,
            'conditions.*.conditions' => ['nullable', 'array', 'max:10'],
            'conditions.*.conditions.*.type' => ['required', 'in:'.implode(',', array_keys(AutomationCatalog::groupConditions()))],
            'conditions.*.conditions.*.from' => $time,
            'conditions.*.conditions.*.until' => $time,
            'actions' => ['required', 'array', 'min:1', 'max:20'],
            'actions.*.type' => ['required', 'in:'.implode(',', array_keys(AutomationCatalog::actions()))],
            'actions.*.from' => $time,
            'actions.*.until' => $time,
            'actions.*.time' => $time,
            'actions.*.minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'actions.*.url' => ['nullable', 'string', 'max:500', fn ($attribute, $value, $fail) => blank($value) || WebDavClient::isAllowedUrl((string) $value) ? null : $fail(__('This address is not allowed. Use an address in the home network that goes through the VPN, or a public address.'))],
            'actions.*.data' => ['nullable', 'string', 'max:2000', fn ($attribute, $value, $fail) => blank($value) || is_array(json_decode((string) $value, true)) ? null : $fail(__('Enter valid JSON, e.g. {"brightness_pct": 40}.'))],
            'cooldown_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        return [
            'name' => $data['name'],
            'trigger' => AutomationCatalog::clean($data['trigger'], 'trigger') ?? ['type' => 'manual'],
            'conditions' => array_values(array_filter(array_map(fn (array $block) => AutomationCatalog::clean($block, 'condition'), $data['conditions'] ?? []))),
            'actions' => array_values(array_filter(array_map(fn (array $block) => AutomationCatalog::clean($block, 'action'), $data['actions']))),
            'cooldown_minutes' => (int) ($data['cooldown_minutes'] ?? 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(Automation $automation): array
    {
        return [
            'id' => $automation->id,
            'name' => $automation->name,
            'active' => (bool) $automation->active,
            'trigger' => $automation->trigger,
            'conditions' => $automation->conditions ?? [],
            'actions' => $automation->actions ?? [],
            'cooldown_minutes' => $automation->cooldown_minutes,
        ];
    }

    /**
     * @param  array<string, array{label: string, icon: string, defaults: array<string, mixed>}>  $catalog
     * @param  list<array{value: string, label: string}>  $modes
     * @return list<array<string, mixed>>
     */
    private function blocks(array $catalog, string $kind, array $modes, bool $smartHome): array
    {
        $rows = [];

        foreach ($catalog as $type => $block) {
            if (! $smartHome && in_array($type, AutomationCatalog::SMART_HOME_ONLY, true)) {
                continue;
            }

            $fields = [];

            foreach ($block['defaults'] as $key => $default) {
                $fields[] = $this->field($kind, $type, (string) $key, $default, $modes);
            }

            $rows[] = ['type' => $type, 'label' => $block['label'], 'icon' => $block['icon'], 'defaults' => $block['defaults'], 'fields' => $fields];
        }

        return $rows;
    }

    /**
     * @param  list<array{value: string, label: string}>  $modes
     * @return array<string, mixed>
     */
    private function field(string $kind, string $type, string $key, mixed $default, array $modes): array
    {
        $options = $this->options($kind, $type, $key, $modes);

        $fieldKind = match (true) {
            $key === 'conditions' => 'group',
            $options !== null && is_array($default) => 'multi',
            $options !== null => 'select',
            $key === 'days' && is_array($default) => 'weekdays',
            is_array($default) => 'list',
            is_bool($default) => 'bool',
            is_int($default) => 'number',
            in_array($key, self::TIME_KEYS, true) && $type !== 'event' => 'time',
            $type === 'event' && $key === 'time' => 'time',
            in_array($key, AutomationCatalog::TEXT_FIELDS, true) => 'text',
            default => 'string',
        };

        return ['key' => $key, 'kind' => $fieldKind] + ($options !== null ? ['options' => $options] : []);
    }

    /**
     * @param  list<array{value: string, label: string}>  $modes
     * @return list<array{value: string, label: string}>|null
     */
    private function options(string $kind, string $type, string $key, array $modes): ?array
    {
        $map = fn (array $items) => array_map(fn ($value, $label) => ['value' => (string) $value, 'label' => (string) $label], array_keys($items), array_values($items));

        return match (true) {
            $key === 'edge' && $type === 'calendar' => $map(['start' => __('starts'), 'end' => __('ends')]),
            $key === 'edge' && $type === 'mode' => $map(['on' => __('switched on'), 'off' => __('switched off')]),
            $key === 'operator' => $map(AutomationCatalog::OPERATORS),
            $key === 'event' && $type === 'sun' => $map(['sunrise' => __('Sunrise'), 'sunset' => __('Sunset')]),
            $key === 'state' && $type === 'presence' => $map(AutomationCatalog::presenceStates()),
            $key === 'state' && $type === 'sun' => $map(['dark' => __('dark'), 'light' => __('light')]),
            $key === 'metric' && $type === 'weather' => $map(AutomationCatalog::weatherMetrics()),
            $key === 'metric' && $type === 'evcc' => $map(AutomationCatalog::evccMetrics()),
            $key === 'mode' && $type === 'evcc_mode' => $map(AutomationCatalog::evccModes()),
            $key === 'mode' && $type === 'scene_snapshot' => $map(['save' => __('Remember'), 'restore' => __('Restore')]),
            $key === 'key' && $type === 'mode' => $modes,
            $key === 'sources' => $map(AutomationCatalog::calendarSources()),
            $key === 'to' => $map(['me' => __('Me'), 'household' => __('Household')]),
            $key === 'due' && $type === 'task' => $map(['today' => __('Today'), 'tomorrow' => __('Tomorrow'), '' => __('No date')]),
            $key === 'day' && $type === 'event' => $map(['today' => __('Today'), 'tomorrow' => __('Tomorrow'), 'week' => __('In a week')]),
            default => null,
        };
    }

    private function find(int $id): Automation
    {
        return Automation::where('household_id', $this->household()->id)->find($id) ?? abort(404);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
