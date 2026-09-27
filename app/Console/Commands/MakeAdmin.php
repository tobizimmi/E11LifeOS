<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:admin {email : Account that may open the server settings} {--revoke : Take the right away again}')]
#[Description('Make an account administrator (server settings on /admin/settings)')]
class MakeAdmin extends Command
{
    public function handle(): int
    {
        $user = User::firstWhere('email', $this->argument('email'));

        if (! $user) {
            $this->error('No account with this e-mail address.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();
        $this->info($user->is_admin ? "{$user->email} is now an administrator." : "{$user->email} is no longer an administrator.");

        return self::SUCCESS;
    }
}
