<?php

namespace App\Console\Commands;

use App\Support\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('webpush:vapid')]
#[Description('Generate the VAPID key pair for web push notifications (add the printed lines to .env)')]
class GenerateVapidKeys extends Command
{
    public function handle(): int
    {
        $keys = WebPush::generateKeys();

        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:you@example.com');
        $this->newLine();
        $this->info('Add these lines to .env. Changing the keys later means every device has to turn notifications on again.');

        return self::SUCCESS;
    }
}
