<?php

namespace App\Console\Commands;

use App\Support\AppSettings;
use App\Support\Vpn;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

#[Signature('vpn:run {--once : Check and start only once (for tests)}')]
#[Description('Keep the WireGuard connection (wireproxy) running as configured on the admin page – runs as PM2 process')]
class RunVpn extends Command
{
    /** Without a handshake for this long the tunnel is reconnected (also picks up a new DynDNS address). */
    private const HANDSHAKE_TIMEOUT = 180;

    /** A fresh connection gets this long for its first handshake. */
    private const START_GRACE = 90;

    /** At most one reconnect in this time. */
    private const RECONNECT_PAUSE = 120;

    private ?Process $process = null;

    private string $runningHash = '';

    private int $port = 0;

    private ?string $notice = null;

    private int $startedAt = 0;

    private int $reconnectedAt = 0;

    /**
     * The VPN settings from .env, restored before each reload (removed values fall back to them).
     *
     * @var array<string, mixed>
     */
    private array $baseConfig = [];

    public function handle(): int
    {
        $this->baseConfig = config('vpn');

        do {
            $this->tick();

            if ($this->option('once')) {
                break;
            }

            sleep(10);
        } while (true);

        return self::SUCCESS;
    }

    /**
     * One round: reload the settings, (re)start or stop wireproxy and report the state.
     */
    private function tick(): void
    {
        // Settings saved on the admin page change while this process keeps running.
        config(['vpn' => $this->baseConfig]);
        AppSettings::apply();

        if (! Vpn::isConfigured()) {
            $this->stop();
            $this->report(config('vpn.enabled') ? 'error' : 'off', config('vpn.enabled') ? __('The WireGuard configuration is incomplete.') : null);

            return;
        }

        $binary = (string) config('vpn.binary');

        if (! is_file($binary) || ! is_executable($binary)) {
            $this->report('error', __('wireproxy is not installed. Run “php artisan vpn:install” on the server (the deploy does it automatically).'));

            return;
        }

        $hash = sha1(config('vpn.wireguard_config').'|'.config('vpn.socks_port'));

        if ($this->process?->isRunning() && $hash === $this->runningHash) {
            $this->watchHandshake();

            return;
        }

        $error = $this->process && ! $this->process->isRunning() ? trim($this->process->getErrorOutput().' '.$this->process->getOutput()) : '';
        $this->stop();

        if (! $this->choosePort()) {
            return;
        }

        $files = Vpn::writeFiles(storage_path('app/vpn'), $this->port);
        $this->start([$binary, '-c', $files['wireproxy'], '-i', '127.0.0.1:'.(int) config('vpn.info_port')]);

        // Older wireproxy versions know no status endpoint ("-i"): run without it.
        if (! $this->process->isRunning() && str_contains($this->process->getErrorOutput().$this->process->getOutput(), '-i')) {
            $this->start([$binary, '-c', $files['wireproxy']]);
        }

        $this->runningHash = $hash;
        $this->startedAt = time();

        if ($this->process->isRunning() && Vpn::portInUse($this->port)) {
            $this->info('wireproxy started on port '.$this->port);
            $this->report('running', $this->notice);
        } else {
            $this->report('error', mb_substr($this->startFailure($binary, $files['wireproxy']) ?: $error, 0, 300));
            $this->stop();
        }
    }

    /**
     * Why wireproxy did not come up: its own output, else its exit code and the result of its
     * configuration check ("-n"), so the admin page names the cause.
     */
    private function startFailure(string $binary, string $configFile): string
    {
        $output = trim($this->process->getErrorOutput().' '.$this->process->getOutput());

        if ($output !== '') {
            return $output;
        }

        if ($this->process->isRunning()) {
            return __('wireproxy is running but does not listen on port :port.', ['port' => $this->port]);
        }

        $check = new Process([$binary, '-c', $configFile, '-n']);
        $check->setTimeout(10);

        try {
            $check->run();
            $checkOutput = trim($check->getErrorOutput().' '.$check->getOutput());
        } catch (\Throwable) {
            $checkOutput = '';
        }

        return trim(__('wireproxy stopped right away (exit code :code).', ['code' => $this->process->getExitCode() ?? '?']).' '.$checkOutput);
    }

    /**
     * The configured SOCKS port, or the installation's own one when another program (usually the
     * VPN of the other installation on this server) already listens there.
     */
    private function choosePort(): bool
    {
        $configured = (int) config('vpn.socks_port');
        $this->notice = null;

        if (! Vpn::portInUse($configured)) {
            $this->port = $configured;

            return true;
        }

        $own = Vpn::defaultSocksPort();

        if ($own !== $configured && ! Vpn::portInUse($own)) {
            $this->port = $own;
            $this->notice = __('Port :configured is already used by another program (probably the VPN of the other installation on this server) – port :own is used instead. Leave “Local port” empty, then every installation gets its own.', ['configured' => $configured, 'own' => $own]);

            return true;
        }

        $this->report('error', __('Port :port is already in use. Choose another “Local port” or leave it empty.', ['port' => $configured]));

        return false;
    }

    /**
     * Reconnects when the router has not answered for a while: after a new public IP of the
     * router (DynDNS), a router restart or a lost UDP mapping wireproxy would otherwise wait forever.
     */
    private function watchHandshake(): void
    {
        $tunnel = Vpn::tunnelState();
        $now = time();
        $lastHandshake = $tunnel['handshake_at'] ?? null;
        $stale = $tunnel !== null && ($lastHandshake === null ? $now - $this->startedAt > self::START_GRACE : $now - $lastHandshake > self::HANDSHAKE_TIMEOUT);

        if ($stale && $now - $this->reconnectedAt > self::RECONNECT_PAUSE) {
            $this->reconnectedAt = $now;
            $this->stop();
            $this->report('error', __('No answer from the router – reconnecting.'));

            return;
        }

        $this->report($stale ? 'error' : 'running', $stale ? __('No handshake with the router – check the Endpoint and the router.') : $this->notice);
    }

    /**
     * @param  list<string>  $command
     */
    private function start(array $command): void
    {
        $this->process = new Process($command);
        $this->process->setTimeout(null);
        $this->process->start();

        // Give it up to 10 seconds to open its SOCKS port (resolving a DynDNS endpoint can take a moment).
        for ($waited = 0; $waited < 40 && $this->process->isRunning() && ! ($this->port && Vpn::portInUse($this->port)); $waited++) {
            usleep(250_000);
        }
    }

    private function stop(): void
    {
        if ($this->process?->isRunning()) {
            $this->process->stop(3);
        }

        $this->process = null;
        $this->runningHash = '';
    }

    private function report(string $state, ?string $message): void
    {
        Cache::put(Vpn::STATUS_KEY, ['state' => $state, 'message' => $message, 'port' => $this->port ?: null, 'at' => now()->toIso8601String()], now()->addMinutes(10));
    }
}
