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
    private ?Process $process = null;

    private string $runningHash = '';

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
            $this->report('running', null);

            return;
        }

        $error = $this->process && ! $this->process->isRunning() ? trim($this->process->getErrorOutput().' '.$this->process->getOutput()) : '';
        $this->stop();

        $files = Vpn::writeFiles(storage_path('app/vpn'));
        $this->process = new Process([$binary, '-c', $files['wireproxy']]);
        $this->process->setTimeout(null);
        $this->process->start();
        $this->runningHash = $hash;
        usleep(1_500_000);

        if ($this->process->isRunning()) {
            $this->info('wireproxy started on port '.config('vpn.socks_port'));
            $this->report('running', null);
        } else {
            $this->report('error', mb_substr(trim($this->process->getErrorOutput().' '.$this->process->getOutput()) ?: $error ?: __('wireproxy stopped right away.'), 0, 300));
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
        Cache::put(Vpn::STATUS_KEY, ['state' => $state, 'message' => $message, 'at' => now()->toIso8601String()], now()->addMinutes(10));
    }
}
