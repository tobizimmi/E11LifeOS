<?php

namespace App\Support\SmartHome;

use Illuminate\Http\Client\PendingRequest;

/**
 * evcc REST API: energy flow, charging points and vehicles; switching the charge mode and limit.
 * With a password ("token") evcc wants a login first, its session cookie then goes along.
 */
class EvccClient
{
    use ConnectionRoutes;

    public const MODES = ['off', 'now', 'minpv', 'pv'];

    private ?string $cookie = null;

    protected function prepare(PendingRequest $request): PendingRequest
    {
        return $this->cookie ? $request->withHeaders(['Cookie' => $this->cookie]) : $request;
    }

    /**
     * The energy flow and charging points, reduced to what the tool shows and automates.
     *
     * @return array{pv: ?float, home: ?float, grid: ?float, battery: ?float, batterySoc: ?float, tariff: ?float, loadpoints: list<array{id: int, title: string, mode: string, charging: bool, connected: bool, power: float, vehicle: ?string, vehicleSoc: ?float, limitSoc: ?float, chargedEnergy: ?float}>}
     */
    public function state(): array
    {
        $json = (array) $this->send(fn (PendingRequest $request, string $base) => $request->get($base.'/api/state'))->json();
        $state = (array) ($json['result'] ?? $json);
        $grid = $state['grid'] ?? null;

        return [
            'pv' => self::number($state['pvPower'] ?? null),
            'home' => self::number($state['homePower'] ?? null),
            'grid' => self::number(is_array($grid) ? ($grid['power'] ?? null) : ($state['gridPower'] ?? null)),
            'battery' => self::number($state['batteryPower'] ?? null),
            'batterySoc' => self::number($state['batterySoc'] ?? null),
            'tariff' => self::number($state['tariffGrid'] ?? null),
            'loadpoints' => collect($state['loadpoints'] ?? [])->values()->map(fn (array $point, int $index) => [
                'id' => $index + 1,
                'title' => (string) ($point['title'] ?? __('Charging point :number', ['number' => $index + 1])),
                'mode' => (string) ($point['mode'] ?? 'off'),
                'charging' => (bool) ($point['charging'] ?? false),
                'connected' => (bool) ($point['connected'] ?? false),
                'power' => (float) ($point['chargePower'] ?? 0),
                'vehicle' => isset($point['vehicleTitle']) ? (string) $point['vehicleTitle'] : null,
                'vehicleSoc' => self::number($point['vehicleSoc'] ?? null),
                'limitSoc' => self::number($point['limitSoc'] ?? $point['effectiveLimitSoc'] ?? null),
                'chargedEnergy' => self::number($point['chargedEnergy'] ?? null),
            ])->all(),
        ];
    }

    public function setMode(int $loadpoint, string $mode): void
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new SmartHomeException(__('Unknown charge mode.'));
        }

        $this->post('/api/loadpoints/'.$loadpoint.'/mode/'.$mode);
    }

    public function setLimitSoc(int $loadpoint, int $soc): void
    {
        $this->post('/api/loadpoints/'.$loadpoint.'/limitsoc/'.max(20, min(100, $soc)));
    }

    private function post(string $path): void
    {
        $this->login();
        $this->send(fn (PendingRequest $request, string $base) => $request->post($base.$path));
    }

    private function login(): void
    {
        if ($this->cookie !== null || blank($this->connection->token)) {
            return;
        }

        $response = $this->send(fn (PendingRequest $request, string $base) => $request->post($base.'/api/auth/login', ['password' => (string) $this->connection->token]));
        $this->cookie = collect($response->cookies()->toArray())->map(fn (array $cookie) => $cookie['Name'].'='.$cookie['Value'])->implode('; ') ?: null;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
