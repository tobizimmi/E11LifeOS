<?php

namespace App\Support\SmartHome;

use App\Models\SmartHomeConnection;
use App\Support\Vpn;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Sends a request to an installation in the home network: first through the VPN (local address),
 * then through the external address (e.g. Nabu Casa) when the VPN is down. Notes which way worked.
 */
trait ConnectionRoutes
{
    public function __construct(protected SmartHomeConnection $connection) {}

    /**
     * @return array<string, string> route name => base address
     */
    protected function routes(): array
    {
        return array_filter([
            'vpn' => $this->connection->url ? rtrim($this->connection->url, '/') : null,
            'external' => $this->connection->external_url ? rtrim($this->connection->external_url, '/') : null,
        ]);
    }

    /**
     * @param  callable(PendingRequest, string): Response  $send  gets the prepared request and the base address
     */
    protected function send(callable $send): Response
    {
        $errors = [];

        foreach ($this->routes() as $route => $base) {
            try {
                $response = $send($this->prepare(Http::timeout(10)->acceptJson()->withOptions(Vpn::httpOptions($base))), $base);
            } catch (ConnectionException $exception) {
                $errors[] = ($route === 'vpn' ? __('Home network (VPN)') : __('External address')).': '.mb_strimwidth($exception->getMessage(), 0, 150, '…');

                continue;
            }

            if ($response->status() === 401 || $response->status() === 403) {
                $this->remember(null, __('Access denied – check the token.'));

                throw new SmartHomeException(__('Access denied – check the token.'));
            }

            if ($response->failed()) {
                $errors[] = 'HTTP '.$response->status();

                continue;
            }

            $this->remember($route, null);

            return $response;
        }

        $message = $errors === [] ? __('No address is set up.') : implode(' · ', $errors);
        $this->remember(null, $message);

        throw new SmartHomeException($message);
    }

    protected function prepare(PendingRequest $request): PendingRequest
    {
        return $request;
    }

    private function remember(?string $route, ?string $error): void
    {
        $this->connection->forceFill($route
            ? ['last_contact_at' => now(), 'last_route' => $route, 'last_error' => null]
            : ['last_error' => mb_substr((string) $error, 0, 500)])->save();
    }
}
