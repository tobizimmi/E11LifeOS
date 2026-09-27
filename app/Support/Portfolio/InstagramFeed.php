<?php

namespace App\Support\Portfolio;

use App\Models\InstagramPost;
use App\Models\PortfolioSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches the latest posts of the configured Instagram profile via Apify, stores them and lets
 * the AI read them; users who want it get new stock ideas as push notification.
 */
class InstagramFeed
{
    private const APIFY_URL = 'https://api.apify.com/v2/acts/apify~instagram-scraper/run-sync-get-dataset-items';

    public function __construct(private InstagramAi $ai) {}

    public static function isConfigured(): bool
    {
        return filled(config('services.instagram.apify_key'));
    }

    /**
     * @return int new or updated posts
     */
    public function fetch(): int
    {
        if (! self::isConfigured()) {
            throw new RuntimeException(__('The Instagram analysis is not set up on this server (APIFY_API_KEY).'));
        }

        $profile = (string) config('services.instagram.profile');
        $response = Http::withToken((string) config('services.instagram.apify_key'))->timeout(320)
            ->post(self::APIFY_URL, [
                'directUrls' => ['https://www.instagram.com/'.$profile.'/'],
                'resultsType' => 'posts',
                'resultsLimit' => (int) config('services.instagram.limit', 20),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(__('Apify answered with :status.', ['status' => $response->status()]));
        }

        return $this->store((array) $response->json(), $profile);
    }

    /**
     * Stores scraped items (Apify format) and returns how many were new.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function store(array $items, string $profile): int
    {
        $count = 0;

        foreach ($items as $item) {
            $shortcode = (string) ($item['shortCode'] ?? $item['shortcode'] ?? '');

            if ($shortcode === '') {
                continue;
            }

            $post = InstagramPost::firstOrNew(['instagram_id' => (string) ($item['id'] ?? $shortcode)]);
            $isNew = ! $post->exists;
            $post->fill([
                'profile' => $profile,
                'shortcode' => $shortcode,
                'caption' => $item['caption'] ?? $post->caption,
                'thumbnail_url' => $item['displayUrl'] ?? $item['thumbnailUrl'] ?? $post->thumbnail_url,
                'video_url' => $item['videoUrl'] ?? $post->video_url,
                'post_url' => 'https://www.instagram.com/p/'.$shortcode.'/',
                'published_at' => isset($item['timestamp']) ? Carbon::parse($item['timestamp']) : $post->published_at,
                'duration_sec' => isset($item['videoDuration']) ? (int) $item['videoDuration'] : $post->duration_sec,
                'view_count' => isset($item['videoViewCount']) ? (int) $item['videoViewCount'] : $post->view_count,
                'transcript' => $item['transcript'] ?? $post->transcript,
            ])->save();

            $count += $isNew ? 1 : 0;
        }

        return $count;
    }

    /**
     * Lets the AI read posts that were not analysed yet.
     */
    public function analyzePending(int $limit = 20): int
    {
        if (! InstagramAi::isConfigured()) {
            return 0;
        }

        $count = 0;

        foreach (InstagramPost::whereNull('analyzed_at')->orderByDesc('published_at')->limit($limit)->get() as $post) {
            $post->update($this->ai->analyze($post));
            $count++;
        }

        return $count;
    }

    /**
     * Push new stock ideas (analysed posts with a ticker) to users who switched it on.
     */
    public function notify(Notifier $notifier): int
    {
        $posts = InstagramPost::whereNotNull('analyzed_at')->whereNull('notified_at')->where('published_at', '>=', now()->subDays(3))->orderBy('published_at')->get()
            ->filter(fn (InstagramPost $post) => $post->mainTicker() !== null);
        $sent = 0;

        if ($posts->isNotEmpty()) {
            foreach (PortfolioSetting::where('instagram_push', true)->with('user')->get() as $settings) {
                foreach ($posts as $post) {
                    $notifier->send(
                        $settings->user,
                        '📸 '.__('New idea from @:profile', ['profile' => $post->profile]).': '.($post->stock_name ?: $post->mainTicker()),
                        trim(($post->entry_price ? __(':type :price', ['type' => $post->entry_type ?: __('Entry'), 'price' => number_format($post->entry_price, 2, ',', '.').' '.$post->currency]).' · ' : '')
                            .($post->stop_loss ? __('Stop :price', ['price' => number_format($post->stop_loss, 2, ',', '.')]).' · ' : '').mb_strimwidth((string) $post->summary, 0, 160, '…'), ' ·'),
                        route('portfolio.instagram'),
                    );
                    $sent++;
                }
            }
        }

        InstagramPost::whereNotNull('analyzed_at')->whereNull('notified_at')->update(['notified_at' => now()]);

        return $sent;
    }
}
