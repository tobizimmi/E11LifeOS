<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Household;
use App\Models\HouseholdMode;
use App\Support\Addons;
use App\Support\AiException;
use App\Support\Automation\AutomationAi;
use App\Support\Automation\AutomationCatalog;
use App\Support\Automation\AutomationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Mobile API for automations ("when … only if … then …"): switch on and off, run, read the log, start
 * from a template or package, let the AI draft one from a sentence. Editing the blocks themselves stays
 * in the web app.
 */
class AutomationsController extends ApiController
{
    public function index(): JsonResponse
    {
        $household = $this->household();
        $automations = Automation::where('household_id', $household->id)->orderBy('name')->get();
        $templates = [];

        foreach (AutomationCatalog::templates() as $key => $template) {
            $templates[] = ['key' => $key, 'name' => $template['name'], 'icon' => $template['icon'], 'description' => $template['description']];
        }

        $packages = [];

        foreach (AutomationCatalog::packages() as $key => $package) {
            $packages[] = ['key' => $key, 'name' => $package['name'], 'icon' => $package['icon'], 'description' => $package['description'], 'count' => count($package['automations'])];
        }

        return $this->ok([
            'automations' => array_map(fn (Automation $automation) => $this->data($automation), $automations->all()),
            'modes' => HouseholdMode::forHousehold($household->id)->map(fn (HouseholdMode $mode) => ['key' => $mode->key, 'name' => $mode->name, 'icon' => $mode->icon, 'active' => (bool) $mode->active])->values()->all(),
            'templates' => $templates,
            'packages' => $packages,
            'ai' => AutomationAi::isConfigured(),
        ]);
    }

    public function toggle(int $automation): JsonResponse
    {
        $item = $this->find($automation);
        $item->update(['active' => ! $item->active]);

        return $this->ok($this->data($item));
    }

    public function destroy(int $automation): JsonResponse
    {
        $this->find($automation)->delete();

        return $this->ok();
    }

    public function run(int $automation, AutomationEngine $engine): JsonResponse
    {
        $run = $engine->run($this->find($automation), __('Started by hand by :name', ['name' => $this->user()->name]), manual: true);

        return $this->ok(['status' => $run->status, 'log' => $this->logLines($run)]);
    }

    public function log(int $automation): JsonResponse
    {
        $runs = AutomationRun::where('automation_id', $this->find($automation)->id)->latest()->limit(15)->get();

        return $this->ok(array_map(fn (AutomationRun $run) => ['id' => $run->id, 'reason' => $run->reason, 'status' => $run->status, 'at' => $run->created_at?->toIso8601String(), 'log' => $this->logLines($run)], $runs->all()));
    }

    public function mode(Request $request, string $key, AutomationEngine $engine): JsonResponse
    {
        $household = $this->household();
        $data = $request->validate(['active' => ['required', 'boolean']]);
        abort_unless(HouseholdMode::forHousehold($household->id)->contains('key', $key), 404);
        $engine->setMode($household->id, $key, (bool) $data['active']);

        return $this->ok();
    }

    /**
     * Creates an automation from a ready-made template (paused when it still needs a device).
     */
    public function fromTemplate(string $template): JsonResponse
    {
        $household = $this->household();
        $preset = AutomationCatalog::templates()[$template] ?? abort(404);
        $item = $this->createFrom($household, $preset);

        return $this->ok($this->data($item), 201);
    }

    /**
     * Installs all automations of a package; ones that still need a device start paused.
     */
    public function installPackage(string $package): JsonResponse
    {
        $household = $this->household();
        $set = AutomationCatalog::packages()[$package] ?? abort(404);
        $existing = Automation::where('household_id', $household->id)->pluck('name')->all();
        $installed = 0;

        foreach ($set['automations'] as $automation) {
            if (in_array($automation['name'], $existing, true) || $this->needsSmartHome($automation)) {
                continue;
            }

            $this->createFrom($household, $automation);
            $installed++;
        }

        return $this->ok(['installed' => $installed], 201);
    }

    /**
     * A draft from a sentence; it is saved paused so it can be checked in the web editor first.
     */
    public function draft(Request $request, AutomationAi $ai): JsonResponse
    {
        $household = $this->household();
        $data = $request->validate(['text' => ['required', 'string', 'min:8', 'max:1000']]);
        abort_unless(AutomationAi::isConfigured(), 422, __('AI is not set up.'));
        abort_if(RateLimiter::tooManyAttempts('automation-draft:'.$this->user()->id, 20), 429, __('Too many drafts – please try again in an hour.'));
        RateLimiter::hit('automation-draft:'.$this->user()->id, 3600);

        try {
            $draft = $ai->draft($data['text'], $household->id, $this->user()->canUseAddon(Addons::SMART_HOME));
        } catch (AiException $exception) {
            abort(422, $exception->getMessage());
        }

        $item = $this->createFrom($household, ['name' => $draft['name'] ?: mb_strimwidth($data['text'], 0, 60, '…'), 'trigger' => $draft['trigger'], 'conditions' => $draft['conditions'], 'actions' => $draft['actions']], false);

        return $this->ok($this->data($item), 201);
    }

    /**
     * @param  array{name: string, trigger: array<string, mixed>, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>}  $preset
     */
    private function createFrom(Household $household, array $preset, bool $activeWhenComplete = true): Automation
    {
        $blocks = [$preset['trigger'], ...$preset['conditions'], ...$preset['actions']];
        $complete = ! collect($blocks)->contains(fn (array $block) => AutomationCatalog::needsDevice($block));

        return Automation::create([
            'household_id' => $household->id,
            'user_id' => $this->user()->id,
            'name' => $preset['name'],
            'active' => $activeWhenComplete && $complete,
            'trigger' => AutomationCatalog::clean($preset['trigger'], 'trigger') ?? ['type' => 'manual'],
            'conditions' => array_values(array_filter(array_map(fn (array $block) => AutomationCatalog::clean($block, 'condition'), $preset['conditions']))),
            'actions' => array_values(array_filter(array_map(fn (array $block) => AutomationCatalog::clean($block, 'action'), $preset['actions']))),
            'cooldown_minutes' => 1,
        ]);
    }

    /**
     * @param  array{name: string, trigger: array<string, mixed>, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>}  $automation
     */
    private function needsSmartHome(array $automation): bool
    {
        if ($this->user()->canUseAddon(Addons::SMART_HOME)) {
            return false;
        }

        return collect([$automation['trigger'], ...$automation['conditions'], ...$automation['actions']])->contains(fn (array $block) => in_array($block['type'], AutomationCatalog::SMART_HOME_ONLY, true));
    }

    /**
     * @return list<array{ok: bool, message: string}>
     */
    private function logLines(AutomationRun $run): array
    {
        $lines = [];

        $entries = $run->getAttribute('log');

        foreach (is_array($entries) ? $entries : [] as $line) {
            if (is_array($line)) {
                $lines[] = ['ok' => (bool) ($line['ok'] ?? true), 'message' => (string) ($line['message'] ?? '')];
            }
        }

        return $lines;
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    private function find(int $id): Automation
    {
        return Automation::where('household_id', $this->household()->id)->find($id) ?? abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Automation $automation): array
    {
        $lastRun = AutomationRun::where('automation_id', $automation->id)->latest()->first();

        return [
            'id' => $automation->id,
            'name' => $automation->name,
            'active' => (bool) $automation->active,
            'when' => AutomationCatalog::describe($automation->trigger),
            'only_if' => array_map(fn (array $block) => AutomationCatalog::describe($block), $automation->conditions),
            'then' => array_map(fn (array $block) => AutomationCatalog::describe($block), $automation->actions),
            'last_run' => $automation->last_run_at?->toIso8601String(),
            'last_status' => $lastRun?->status,
        ];
    }
}
