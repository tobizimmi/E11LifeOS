<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads the caption and photo of a public Instagram post or reel. Instagram shows desktop page
 * requests only a login page, so the post is read as on an iPhone (as in RezepteApp2: the page
 * then carries og:description and the photo), then from its embed page, the link preview data
 * and – when the server has an Apify key – via Apify.
 */
class InstagramPostReader
{
    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

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
    public function read(string $shortcode, ?string $url = null): array
    {
        $imageUrl = null;
        $sources = [
            fn () => $this->fromPage($url ?? 'https://www.instagram.com/p/'.$shortcode.'/', self::IPHONE),
            fn () => $this->fromEmbed($shortcode),
            fn () => $this->fromPage('https://www.instagram.com/p/'.$shortcode.'/', 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'),
            fn () => $this->fromApify($shortcode),
        ];

        foreach ($sources as $source) {
            try {
                $post = $source();
            } catch (Throwable) {
                continue;
            }

            $imageUrl ??= $post['imageUrl'] ?? null;

            if (filled($post['caption'] ?? null)) {
                return ['caption' => $post['caption'], 'imageUrl' => $post['imageUrl'] ?? $imageUrl];
            }
        }

        // No text, but perhaps the photo: the form still opens with it.
        return ['caption' => null, 'imageUrl' => $imageUrl];
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
     * The post page as an iPhone or a link preview sees it: og:description holds the caption
     * ("12 likes, 3 comments - name on date: "caption"", often shortened), twitter:image the photo.
     *
     * @return array{caption: ?string, imageUrl: ?string}
     */
    private function fromPage(string $url, string $userAgent): array
    {
        $html = Http::timeout(15)->withHeaders(['User-Agent' => $userAgent, 'Accept-Language' => 'de,en;q=0.5'])->get($url)->throw()->body();

        $meta = function (string $property) use ($html): ?string {
            foreach ([
                '#<meta[^>]*(?:property|name)=["\']'.preg_quote($property, '#').'["\'][^>]*content=["\']([^"\']*)["\']#i',
                '#<meta[^>]*content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']'.preg_quote($property, '#').'["\']#i',
            ] as $pattern) {
                if (preg_match($pattern, $html, $match)) {
                    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
                }
            }

            return null;
        };

        return [
            'caption' => self::captionFromDescription((string) $meta('og:description')),
            'imageUrl' => $meta('twitter:image') ?: $meta('twitter:image:src') ?: $meta('og:image'),
        ];
    }

    /**
     * The caption part of og:description; the whole description when it has no quoted part
     * (Instagram cuts long captions off, then the closing quote is missing). Null when too short.
     */
    public static function captionFromDescription(string $description): ?string
    {
        $caption = preg_match('/[：:]\s*["“„”](.+?)["”“]?\s*\.?\s*$/su', $description, $match) ? $match[1] : $description;
        $caption = trim(html_entity_decode($caption, ENT_QUOTES | ENT_HTML5));

        return mb_strlen($caption) > 20 ? $caption : null;
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
