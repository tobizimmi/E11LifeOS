<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use PharData;
use Throwable;

#[Signature('vpn:install {--if-missing : Do nothing when wireproxy is already installed}')]
#[Description('Download wireproxy (WireGuard in user space, no root needed) for the VPN connection')]
class InstallVpn extends Command
{
    public function handle(): int
    {
        $binary = (string) config('vpn.binary');

        if ($this->option('if-missing') && is_file($binary) && is_executable($binary)) {
            $this->info('wireproxy is already installed.');

            return self::SUCCESS;
        }

        $arch = match (php_uname('m')) {
            'aarch64', 'arm64' => 'arm64',
            'armv7l' => 'arm',
            default => 'amd64',
        };
        $url = str_replace('{arch}', $arch, (string) config('vpn.download_url'));
        $directory = dirname($binary);
        $archive = $directory.'/wireproxy.tar.gz';

        try {
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }

            Http::timeout(120)->sink($archive)->get($url)->throw();
            (new PharData($archive))->extractTo($directory, 'wireproxy', true);
            unlink($archive);

            if ($directory.'/wireproxy' !== $binary) {
                rename($directory.'/wireproxy', $binary);
            }

            chmod($binary, 0700);
        } catch (Throwable $exception) {
            $this->error('wireproxy could not be installed from '.$url.': '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('wireproxy installed: '.$binary);

        return self::SUCCESS;
    }
}
