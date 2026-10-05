<?php

namespace App\Console\Commands;

use App\Support\Operations\AdminAlert;
use App\Support\Portfolio\InstagramAccessDenied;
use App\Support\Portfolio\InstagramFeed;
use App\Support\Portfolio\Notifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('portfolio:instagram {--no-fetch : Only analyse stored posts}')]
#[Description('Fetch the latest posts of the Instagram profile, let the AI read them and push new stock ideas (runs every 6 hours via the scheduler)')]
class UpdateInstagramAnalysis extends Command
{
    public function handle(InstagramFeed $feed, Notifier $notifier): int
    {
        $new = 0;

        if (! $this->option('no-fetch')) {
            if (! InstagramFeed::isConfigured()) {
                $this->warn('APIFY_API_KEY is not set – only stored posts are analysed.');
            } else {
                try {
                    $new = $feed->fetch();
                } catch (InstagramAccessDenied $exception) {
                    // A setup problem: tell the admins once a day instead of an error every six hours.
                    AdminAlert::send('instagram:access-denied', __('Instagram analysis paused'), $exception->getMessage(), 60 * 24, mail: false);
                    $this->warn($exception->getMessage());
                } catch (Throwable $exception) {
                    report($exception);
                    $this->error($exception->getMessage());
                }
            }
        }

        $analyzed = $feed->analyzePending();
        $pushed = $feed->notify($notifier);

        $this->info("New posts: {$new}, analysed: {$analyzed}, notifications: {$pushed}");

        return self::SUCCESS;
    }
}
