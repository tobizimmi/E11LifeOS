<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\HouseholdMode;
use App\Models\SmartHomeDashboard;
use App\Models\SmartHomeEntity;
use App\Support\Automation\AutomationEngine;
use App\Support\SmartHome\EnergyRecorder;
use App\Support\SmartHome\EntityControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile API for the smart home: devices with their state (favourites first), household modes,
 * the dashboards and the energy flow; switching devices and modes.
 */
class SmartHomeController extends ApiController
{
    public function index(): JsonResponse
    {
        $user = $this->user();
        $household = $this->household();

        return $this->ok([
            'may_control' => EntityControl::mayControl($user, $household->id),
            'devices' => SmartHomeEntity::where('household_id', $household->id)->orderByDesc('favorite')->orderBy('position')->orderBy('name')->get()
                ->map(fn (SmartHomeEntity $entity) => $this->device($entity))->values(),
            'modes' => HouseholdMode::forHousehold($household->id)->map(fn (HouseholdMode $mode) => ['key' => $mode->key, 'name' => $mode->name, 'icon' => $mode->icon, 'active' => (bool) $mode->active])->values(),
            'dashboards' => SmartHomeDashboard::query()->visibleTo($user, $household->id)->with('cards')->orderBy('position')->get()
                ->map(fn (SmartHomeDashboard $dashboard) => [
                    'id' => $dashboard->id,
                    'name' => $dashboard->name,
                    'icon' => $dashboard->icon,
                    'cards' => $dashboard->cards->map(fn ($card) => $card->only(['kind', 'smart_home_entity_id', 'display', 'size', 'title', 'options']))->values(),
                ])->values(),
            'energy' => EnergyRecorder::latest($household->id) ?: null,
        ]);
    }

    /**
     * Tap on a device: switch, open/close, start a scene. Critical devices need "confirmed": true.
     */
    public function press(Request $request, int $entity): JsonResponse
    {
        $device = SmartHomeEntity::where('household_id', $this->household()->id)->with('connection')->findOrFail($entity);
        $error = EntityControl::attempt(fn () => EntityControl::press($this->user(), $device, $request->boolean('confirmed')));
        abort_if($error !== null, 502, $error);

        return $this->ok($this->device($device->fresh()));
    }

    public function mode(Request $request, string $key, AutomationEngine $engine): JsonResponse
    {
        $household = $this->household();
        $mode = HouseholdMode::forHousehold($household->id)->firstWhere('key', $key) ?? abort(404);
        $active = $request->has('active') ? $request->boolean('active') : ! $mode->active;
        $engine->setMode($household->id, $key, $active);

        return $this->ok(['key' => $key, 'active' => $active]);
    }

    /**
     * @return array<string, mixed>
     */
    private function device(SmartHomeEntity $entity): array
    {
        return [
            'id' => $entity->id,
            'entity_id' => $entity->entity_id,
            'name' => $entity->name,
            'area' => $entity->area,
            'icon' => $entity->icon(),
            'state' => $entity->state,
            'display_state' => $entity->displayState(),
            'unit' => $entity->unit,
            'on' => $entity->isOn(),
            'favorite' => (bool) $entity->favorite,
            'switchable' => $entity->isSwitchable() || $entity->isRunnable(),
            'critical' => EntityControl::isCritical($entity),
        ];
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
