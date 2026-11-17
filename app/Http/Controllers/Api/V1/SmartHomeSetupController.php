<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\HouseholdMember;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEntity;
use App\Support\Automation\AutomationCatalog;
use App\Support\Drives\WebDavClient;
use App\Support\SmartHome\EntityControl;
use App\Support\SmartHome\EvccClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Smart home setup and extras for the app: connections (Home Assistant, evcc), choosing the devices,
 * sliders and the charge mode. Only the household owner changes connections and devices.
 */
class SmartHomeSetupController extends ApiController
{
    public function setup(): JsonResponse
    {
        $householdId = $this->householdId();
        $connections = SmartHomeConnection::where('household_id', $householdId)->orderBy('id')->get();
        $evcc = null;
        $evccError = null;

        if ($connection = $connections->firstWhere('type', SmartHomeConnection::EVCC)) {
            try {
                $evcc = $connection->evcc()->state();
            } catch (Throwable $exception) {
                $evccError = $exception->getMessage();
            }
        }

        return $this->ok([
            'owner' => $this->isOwner(),
            'types' => collect(SmartHomeConnection::types())->map(fn (array $type, string $key): array => ['key' => $key, 'label' => $type['label'], 'icon' => $type['icon']])->values()->all(),
            'connections' => $connections->map(fn (SmartHomeConnection $connection): array => [
                'id' => $connection->id,
                'type' => $connection->type,
                'name' => $connection->name,
                'url' => $connection->url,
                'external_url' => $connection->external_url,
                'has_token' => filled($connection->token),
                'last_error' => $connection->last_error,
            ])->all(),
            'evcc' => $evcc,
            'evcc_error' => $evccError,
            'evcc_modes' => collect(AutomationCatalog::evccModes())->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values()->all(),
            'entities' => SmartHomeEntity::where('household_id', $householdId)->orderBy('position')->orderBy('name')->get()
                ->map(fn (SmartHomeEntity $entity): array => ['id' => $entity->id, 'entity_id' => $entity->entity_id, 'name' => $entity->name, 'area' => $entity->area, 'favorite' => (bool) $entity->favorite])->all(),
        ]);
    }

    public function storeConnection(Request $request): JsonResponse
    {
        $this->owner();
        $connection = SmartHomeConnection::create($this->connectionData($request, null) + ['household_id' => $this->householdId()]);

        return $this->ok(['id' => $connection->id] + $this->test($connection), 201);
    }

    public function updateConnection(Request $request, int $connection): JsonResponse
    {
        $this->owner();
        $model = $this->connection($connection);
        $model->update($this->connectionData($request, $model));

        return $this->ok(['id' => $model->id] + $this->test($model));
    }

    public function destroyConnection(int $connection): JsonResponse
    {
        $this->owner();
        $this->connection($connection)->delete();

        return $this->ok();
    }

    public function testConnection(int $connection): JsonResponse
    {
        return $this->ok($this->test($this->connection($connection)));
    }

    /**
     * Entities of Home Assistant to pick from (without the ones already added).
     */
    public function available(Request $request): JsonResponse
    {
        $this->owner();
        $connection = SmartHomeConnection::where('household_id', $this->householdId())->where('type', SmartHomeConnection::HOME_ASSISTANT)->orderBy('id')->first() ?? abort(404);
        $known = SmartHomeEntity::where('household_id', $this->householdId())->pluck('entity_id')->all();
        $search = mb_strtolower(trim((string) $request->query('q', '')));

        try {
            $states = $connection->homeAssistant()->states();
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 502);
        }

        return $this->ok(collect($states)
            ->reject(fn (array $state): bool => in_array($state['entity_id'], $known, true))
            ->filter(fn (array $state): bool => $search === '' || str_contains(mb_strtolower($state['name'].' '.$state['entity_id']), $search))
            ->take(80)->map(fn (array $state): array => ['entity_id' => $state['entity_id'], 'name' => $state['name'], 'state' => $state['state'], 'unit' => $state['unit']])->values()->all());
    }

    public function addEntity(Request $request): JsonResponse
    {
        $this->owner();
        $data = $request->validate(['entity_id' => ['required', 'string', 'max:255']]);
        $connection = SmartHomeConnection::where('household_id', $this->householdId())->where('type', SmartHomeConnection::HOME_ASSISTANT)->orderBy('id')->first() ?? abort(404);

        try {
            $state = collect($connection->homeAssistant()->states())->firstWhere('entity_id', $data['entity_id']) ?? abort(404);
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 502);
        }

        $entity = SmartHomeEntity::firstOrCreate(
            ['smart_home_connection_id' => $connection->id, 'entity_id' => $data['entity_id']],
            ['household_id' => $this->householdId(), 'name' => mb_substr((string) $state['name'], 0, 150), 'unit' => $state['unit'], 'state' => $state['state'], 'state_changed_at' => now(),
                'position' => (int) SmartHomeEntity::where('household_id', $this->householdId())->max('position') + 1],
        );

        return $this->ok(['id' => $entity->id], 201);
    }

    public function updateEntity(Request $request, int $entity): JsonResponse
    {
        $this->owner();
        $model = SmartHomeEntity::where('household_id', $this->householdId())->findOrFail($entity);
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:150'], 'area' => ['sometimes', 'nullable', 'string', 'max:100'], 'favorite' => ['sometimes', 'boolean']]);

        if (array_key_exists('area', $data)) {
            $data['area'] = filled($data['area']) ? trim($data['area']) : null;
        }

        $model->update($data);

        return $this->ok();
    }

    public function destroyEntity(int $entity): JsonResponse
    {
        $this->owner();
        SmartHomeEntity::where('household_id', $this->householdId())->whereKey($entity)->delete();

        return $this->ok();
    }

    /**
     * Slider of a device: brightness, target temperature or cover position.
     */
    public function setValue(Request $request, int $entity): JsonResponse
    {
        $data = $request->validate(['value' => ['required', 'numeric']]);
        $device = SmartHomeEntity::where('household_id', $this->householdId())->with('connection')->findOrFail($entity);
        $error = EntityControl::attempt(fn () => EntityControl::setValue($this->user(), $device, (float) $data['value']));
        abort_if($error !== null, 502, $error);

        return $this->ok();
    }

    public function chargeMode(Request $request, int $loadpoint): JsonResponse
    {
        $data = $request->validate(['mode' => ['required', 'in:off,now,minpv,pv']]);
        $this->evcc($loadpoint)->setMode($loadpoint, $data['mode']);

        return $this->ok();
    }

    public function chargeLimit(Request $request, int $loadpoint): JsonResponse
    {
        $data = $request->validate(['soc' => ['required', 'integer', 'between:20,100']]);
        $this->evcc($loadpoint)->setLimitSoc($loadpoint, (int) $data['soc']);

        return $this->ok();
    }

    private function evcc(int $loadpoint): EvccClient
    {
        abort_unless(EntityControl::mayControl($this->user(), $this->householdId()), 403);
        $connection = SmartHomeConnection::where('household_id', $this->householdId())->where('type', SmartHomeConnection::EVCC)->orderBy('id')->first() ?? abort(404);

        try {
            return $connection->evcc();
        } catch (Throwable $exception) {
            abort(502, $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionData(Request $request, ?SmartHomeConnection $existing): array
    {
        $allowed = fn ($attribute, $value, $fail) => $value === '' || $value === null || WebDavClient::isAllowedUrl((string) $value) ? null : $fail(__('This address is not allowed: it is neither in a VPN network nor public. Enter your home network (e.g. 192.168.1.0/24) under Admin › Server settings › VPN › Networks through the VPN, or use a public address.'));
        $type = $existing !== null ? $existing->type : (string) $request->input('type', SmartHomeConnection::HOME_ASSISTANT);

        $data = $request->validate([
            'type' => [$existing ? 'sometimes' : 'required', 'in:'.implode(',', array_keys(SmartHomeConnection::types()))],
            'name' => ['required', 'string', 'max:100'],
            'url' => ['nullable', 'url:http,https', 'max:500', $allowed, 'required_without:external_url'],
            'external_url' => ['nullable', 'url:https', 'max:500', $allowed],
            'token' => [$existing || $type === SmartHomeConnection::EVCC ? 'nullable' : 'required', 'string', 'max:1000'],
        ]);

        $attributes = ['type' => $type, 'name' => $data['name'], 'url' => $data['url'] ?: null, 'external_url' => $data['external_url'] ?? null ?: null];

        if (filled($data['token'] ?? null)) {
            $attributes['token'] = $data['token'];
        }

        return $attributes;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function test(SmartHomeConnection $connection): array
    {
        try {
            $count = $connection->isHomeAssistant() ? count($connection->homeAssistant()->states()) : count($connection->evcc()->state()['loadpoints']);

            return ['ok' => true, 'message' => $connection->isHomeAssistant() ? __('Connected: :count entities.', ['count' => $count]) : __('Connected: :count charging points.', ['count' => $count])];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $connection->name.': '.$exception->getMessage()];
        }
    }

    private function connection(int $id): SmartHomeConnection
    {
        return SmartHomeConnection::where('household_id', $this->householdId())->find($id) ?? abort(404);
    }

    private function owner(): void
    {
        abort_unless($this->isOwner(), 403, __('Only the household owner can change this.'));
    }

    private function isOwner(): bool
    {
        return (bool) HouseholdMember::where('household_id', $this->householdId())->where('user_id', $this->user()->id)->first()?->isOwner();
    }

    private function householdId(): int
    {
        return ($this->user()->currentHousehold() ?? throw ValidationException::withMessages(['household' => __('You are not a member of a household yet.')]))->id;
    }
}
