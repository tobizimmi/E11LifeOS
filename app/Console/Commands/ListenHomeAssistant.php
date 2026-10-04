<?php

namespace App\Console\Commands;

use App\Models\SmartHomeConnection;
use App\Support\Automation\AutomationEngine;
use App\Support\SmartHome\HomeAssistantStream;
use App\Support\SmartHome\WebSocketClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('smart-home:listen {--once : Read the available messages once and stop (for tests)} {--hours=6 : Restart after this many hours (PM2 starts it again)}')]
#[Description('Keeps a live connection to every Home Assistant (WebSocket): state changes arrive within a second, automations react at once')]
class ListenHomeAssistant extends Command
{
    /** @var array<int, array{client: WebSocketClient, stream: HomeAssistantStream, route: string, pinged: int}> */
    private array $sessions = [];

    /** @var array<int, int> connection id => earliest next attempt (unix time) */
    private array $retryAt = [];

    /** @var array<int, int> connection id => failed attempts in a row */
    private array $failures = [];

    public function handle(AutomationEngine $engine): int
    {
        $stopAt = time() + (int) $this->option('hours') * 3600;
        $lastScan = 0;

        do {
            if (time() - $lastScan >= 60) {
                $this->connectAll($engine);
                $lastScan = time();
            }

            if ($this->sessions === []) {
                if ($this->option('once')) {
                    break;
                }

                sleep(15);

                continue;
            }

            $this->listen($this->option('once') ? 0 : 30);
        } while (! $this->option('once') && time() < $stopAt);

        foreach ($this->sessions as $session) {
            $session['client']->close();
        }

        return self::SUCCESS;
    }

    /**
     * Opens a session for every Home Assistant without one (new connections, after an error with
     * back-off up to five minutes) and closes those of deleted connections.
     */
    private function connectAll(AutomationEngine $engine): void
    {
        $connections = SmartHomeConnection::where('type', SmartHomeConnection::HOME_ASSISTANT)->whereNotNull('token')->get()->keyBy('id');

        foreach (array_diff_key($this->sessions, $connections->all()) as $id => $session) {
            $session['client']->close();
            unset($this->sessions[$id]);
        }

        foreach ($connections as $id => $connection) {
            if (isset($this->sessions[$id]) || ($this->retryAt[$id] ?? 0) > time()) {
                continue;
            }

            $this->open($connection, $engine);
        }
    }

    private function open(SmartHomeConnection $connection, AutomationEngine $engine): void
    {
        $errors = [];

        foreach (array_filter(['vpn' => $connection->url, 'external' => $connection->external_url]) as $route => $base) {
            try {
                $client = WebSocketClient::connect((string) $base);
                $stream = new HomeAssistantStream($connection, fn (array $message) => $client->sendJson($message), $engine);

                // Sign in right away (auth_required → auth → auth_ok → subscribe).
                for ($step = 0; $step < 3 && ! $stream->authenticated; $step++) {
                    if ($message = $client->receiveJson()) {
                        $stream->handle($message);
                    }
                }

                if (! $stream->authenticated) {
                    throw new \RuntimeException(__('No answer from Home Assistant.'));
                }

                $this->sessions[$connection->id] = ['client' => $client, 'stream' => $stream, 'route' => $route, 'pinged' => time()];
                $this->failures[$connection->id] = 0;
                $connection->forceFill(['last_contact_at' => now(), 'last_route' => 'websocket', 'last_error' => null])->save();
                $this->info("Connected to {$connection->name} ({$route}).");

                return;
            } catch (Throwable $exception) {
                $errors[] = $route.': '.mb_strimwidth($exception->getMessage(), 0, 150, '…');
            }
        }

        $failures = $this->failures[$connection->id] = ($this->failures[$connection->id] ?? 0) + 1;
        $this->retryAt[$connection->id] = time() + min(300, 15 * 2 ** min($failures, 5));
        $this->warn("{$connection->name}: ".implode(' · ', $errors));
    }

    /**
     * Waits for messages of all sessions; pings every 30 seconds and marks the contact.
     */
    private function listen(int $seconds): void
    {
        $read = array_map(fn (array $session) => $session['client']->stream(), $this->sessions);
        $write = $except = null;

        if (@stream_select($read, $write, $except, $seconds) === false) {
            return;
        }

        foreach ($this->sessions as $id => $session) {
            try {
                if (in_array($session['client']->stream(), $read, true)) {
                    while ($message = $session['client']->receiveJson()) {
                        $session['stream']->handle($message);

                        if (! $session['client']->hasPending() && ! $this->hasBufferedData($session['client'])) {
                            break;
                        }
                    }
                }

                if (time() - $session['pinged'] >= 30) {
                    $session['client']->ping();
                    $this->sessions[$id]['pinged'] = time();
                    SmartHomeConnection::whereKey($id)->update(['last_contact_at' => now(), 'last_route' => 'websocket']);
                }
            } catch (Throwable $exception) {
                $this->warn("Connection {$id} lost: ".$exception->getMessage());
                $session['client']->close();
                unset($this->sessions[$id]);
                $this->retryAt[$id] = time() + 15;
            }
        }
    }

    private function hasBufferedData(WebSocketClient $client): bool
    {
        $read = [$client->stream()];
        $write = $except = null;

        return (bool) @stream_select($read, $write, $except, 0);
    }
}
