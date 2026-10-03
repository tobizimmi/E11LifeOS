<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads the caption and photo of a public Instagram post or reel. Instagram shows normal page
 * requests only a login page, so the post is read from its embed page, then from the preview
 * data Instagram gives link previews, and – when the server has an Apify key – via Apify.
 */
class InstagramPostReader
{
    private const APIFY_URL = 'https://api.apify.com/v2/acts/apify~instagram-scraper/run-sync-get-dataset-items';

    /**
     * The post's shortcode for instagram.com/p/…, /reel/…, /reels/… and /tv/… links, else null.
     */
    public static function shortcode(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! preg_match('/(^|\.)(instagram\.com|instagr\.am)$/', $host)) {
            return null;
        }

        return preg_match('#/(?:p|reels?|tv)/([A-Za-z0-9_-]+)#', (string) parse_url($url, PHP_URL_PATH), $match) ? $match[1] : null;
    }

    /**
     * Caption and photo of the post; caption is null when Instagram gives nothing out.
     *
     * @return array{caption: ?string, imageUrl: ?string}
     */
    public function read(string $shortcode): array
    {
        foreach ([fn () => $this->fromEmbed($shortcode), fn () => $this->fromPreview($shortcode), fn () => $this->fromApify($shortcode)] as $source) {
            try {
                $post = $source();
            } catch (Throwable) {
                continue;
            }

            if (filled($post['caption'] ?? null)) {
                return $post;
            }
        }

        return ['caption' => null, 'imageUrl' => null];
    }

    /**
     * The public embed page ("/embed/captioned/") shows the caption without a login.
     *
     * @return array{caption: ?string, imageUrl: ?string}
     */
    private function fromEmbed(string $shortcode): array
    {
        $html = Http::timeout(15)->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 'Accept-Language' => 'de,en;q=0.5'])
            ->get('https://www.instagram.com/p/'.$shortcode.'/embed/captioned/')->throw()->body();

        $caption = null;

        if (preg_match('#<div class="Caption"[^>]*>(.*?)<div class="CaptionComments"#si', $html, $match)) {
            $inner = preg_replace('#<a class="CaptionUsername".*?</a>#si', '', $match[1]);
            $caption = $this->plain((string) $inner);
        }

        preg_match('#<img[^>]*class="[^"]*EmbeddedMediaImage[^"]*"[^>]*src="([^"]+)"#i', $html, $image);

        return ['caption' => $caption, 'imageUrl' => isset($image[1]) ? html_entity_decode($image[1]) : null];
    }

    /**
     * Link previews (as for chats) get the caption in og:description: "12 likes – name: "caption"".
     *
     * @return array{caption: ?string, imageUrl: ?string}
     */
    private function fromPreview(string $shortcode): array
    {
        $html = Http::timeout(15)->withHeaders(['User-Agent' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'])
            ->get('https://www.instagram.com/p/'.$shortcode.'/')->throw()->body();

        $meta = function (string $property) use ($html): ?string {
            return preg_match('#<meta[^>]*(?:property|name)="'.preg_quote($property, '#').'"[^>]*content="([^"]*)"#i', $html, $match)
                ? html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5) : null;
        };

        $description = (string) $meta('og:description');
        // The text after the account name and date is the caption itself.
        $caption = preg_match('/:\s*["“](.*)["”]\s*\.?\s*$/su', $description, $match) ? $match[1] : null;

        return ['caption' => $caption ? $this->plain($caption) : null, 'imageUrl' => $meta('og:image')];
    }

    /**
     * Apify's Instagram scraper (only with APIFY_API_KEY, also used by the Instagram analysis).
     *
     * @return array{caption: ?string, imageUrl: ?string}
     */
    private function fromApify(string $shortcode): array
    {
        if (blank(config('services.instagram.apify_key'))) {
            return ['caption' => null, 'imageUrl' => null];
        }

        $item = (array) (Http::withToken((string) config('services.instagram.apify_key'))->timeout(120)
            ->post(self::APIFY_URL, ['directUrls' => ['https://www.instagram.com/p/'.$shortcode.'/'], 'resultsType' => 'posts', 'resultsLimit' => 1])
            ->throw()->json()[0] ?? []);

        return ['caption' => isset($item['caption']) ? $this->plain((string) $item['caption']) : null, 'imageUrl' => $item['displayUrl'] ?? null];
    }

    private function plain(string $html): string
    {
        $text = preg_replace('#<br\s*/?>#i', "\n", $html);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace("/[ \t]+/u", ' ', $text));
    }
}
