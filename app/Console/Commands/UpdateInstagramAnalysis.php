<?php

namespace App\Console\Commands;

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
