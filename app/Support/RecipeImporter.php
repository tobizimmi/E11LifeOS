<?php

namespace App\Support;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Turns a web page, photos/screenshots, a PDF or pasted text into a recipe draft for the form.
 * Web pages are read from their schema.org data first; Claude is only asked when there is none.
 *
 * Draft shape: title, description, servings, prepTime, cookTime (int|null),
 * ingredients [{amount, unit, name, isSection}], steps [string], imageUrl, sourceUrl, sourceType.
 */
class RecipeImporter
{
    /**
     * Enough page text for any recipe; navigation and comments beyond that are noise.
     */
    private const MAX_PAGE_TEXT = 50000;

    /**
     * Units recognised when splitting "200 g Mehl" into amount, unit and name.
     */
    private const UNITS = 'kg|g|mg|l|ml|cl|dl|EL|TL|Tl|El|Msp|Prise|Prisen|Pck|Päckchen|Packung|Dose|Dosen|Becher|Bund|Stück|Stk|Scheibe|Scheiben|Zehe|Zehen|Tasse|Tassen|Glas|Gläser|Handvoll|Tbsp|tbsp|Tsp|tsp|cup|cups|oz|lb';

    public function __construct(private RecipeAi $ai, private InstagramPostReader $instagram) {}

    /**
     * @return array<string, mixed>
     */
    public function fromUrl(string $url): array
    {
        if ($shortcode = InstagramPostReader::shortcode($url)) {
            return $this->fromInstagram($url, $shortcode);
        }

        $html = $this->fetch($url)->body();

        $schema = $this->jsonLdRecipe($html) ?? $this->microdataRecipe($html);
        $draft = $schema ? $this->fromSchema($schema) : $this->normalize($this->ai->extractFromText($this->pageText($html)));

        $draft['imageUrl'] ??= $this->metaContent($html, 'og:image');
        $draft['imageUrl'] = $draft['imageUrl'] ? $this->absoluteUrl($draft['imageUrl'], $url) : null;

        return ['sourceUrl' => $url, 'sourceType' => 'WEB'] + $draft;
    }

    /**
     * Instagram posts and reels: the caption holds the recipe, the AI turns it into the draft.
     *
     * @return array<string, mixed>
     */
    private function fromInstagram(string $url, string $shortcode): array
    {
        $post = $this->instagram->read($shortcode);

        if (blank($post['caption'])) {
            throw new RecipeAiException(__('Instagram does not give out the text of this post. Copy the description into “Text” or import a screenshot as photo.'));
        }

        $draft = $this->normalize($this->ai->extractFromText($post['caption']));
        $draft['imageUrl'] = $post['imageUrl'];

        return ['sourceUrl' => $url, 'sourceType' => 'INSTAGRAM'] + $draft;
    }

    /**
     * @return array<string, mixed>
     */
    public function fromText(string $text): array
    {
        return ['sourceType' => 'TEXT'] + $this->normalize($this->ai->extractFromText($text));
    }

    /**
     * @param  array<int, UploadedFile>  $images
     * @return array<string, mixed>
     */
    public function fromImages(array $images): array
    {
        $prepared = array_map(fn (UploadedFile $image) => RecipeImages::forAi($image), $images);

        return ['sourceType' => 'IMAGE'] + $this->normalize($this->ai->extractFromImages($prepared));
    }

    /**
     * @return array<string, mixed>
     */
    public function fromPdf(UploadedFile $pdf): array
    {
        return ['sourceType' => 'PDF'] + $this->normalize($this->ai->extractFromPdf((string) file_get_contents($pdf->getRealPath())));
    }

    /**
     * Downloads the draft's photo into the household's recipe photos; null if that fails.
     */
    public function downloadImage(string $url, int $householdId): ?string
    {
        try {
            $response = $this->fetch($url);

            return str_starts_with((string) $response->header('Content-Type'), 'image/')
                ? RecipeImages::store($response->body(), $householdId)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Cleans up whatever the source delivered into the draft shape.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalize(array $raw): array
    {
        $positive = fn ($value) => is_numeric($value) && (int) $value > 0 ? (int) $value : null;

        return [
            'title' => Str::limit(trim(strip_tags(html_entity_decode((string) ($raw['title'] ?? '')))), 500, '') ?: __('Imported recipe'),
            'description' => trim(strip_tags(html_entity_decode((string) ($raw['description'] ?? '')))),
            'servings' => $positive($raw['servings'] ?? null),
            'prepTime' => $positive($raw['prepTime'] ?? null),
            'cookTime' => $positive($raw['cookTime'] ?? null),
            'ingredients' => collect($raw['ingredients'] ?? [])
                ->map(fn ($ingredient) => is_string($ingredient) ? $this->parseIngredient($ingredient) : $ingredient)
                ->filter(fn ($ingredient) => is_array($ingredient) && trim((string) ($ingredient['name'] ?? '')) !== '')
                ->map(fn (array $ingredient) => [
                    'amount' => mb_substr(trim((string) ($ingredient['amount'] ?? '')), 0, 50),
                    'unit' => mb_substr(trim((string) ($ingredient['unit'] ?? '')), 0, 50),
                    'name' => mb_substr(trim(html_entity_decode((string) $ingredient['name'])), 0, 255),
                    'isSection' => (bool) ($ingredient['isSection'] ?? false),
                ])
                ->take(200)
                ->values()
                ->all(),
            'steps' => collect($raw['steps'] ?? [])
                ->map(fn ($step) => trim(strip_tags(html_entity_decode((string) $step))))
                ->filter()
                ->take(100)
                ->values()
                ->all(),
            'imageUrl' => is_string($raw['imageUrl'] ?? null) ? $raw['imageUrl'] : null,
        ];
    }

    /**
     * "200 g Mehl", "1/2 TL Salz", "3 Eier", "Salz" → amount, unit, name.
     *
     * @return array{amount: string, unit: string, name: string}
     */
    public function parseIngredient(string $line): array
    {
        $line = trim(preg_replace('/\s+/u', ' ', html_entity_decode($line)));

        if (preg_match('/^((?:\d+\s*\/\s*\d+|\d+(?:[.,]\d+)?|[½⅓⅔¼¾⅛])(?:\s*[-–]\s*\d+(?:[.,]\d+)?)?(?:\s+\d+\s*\/\s*\d+)?)\s*(.*)$/u', $line, $amount)) {
            $rest = trim($amount[2]);

            if (preg_match('/^('.self::UNITS.')(?![\p{L}])\.?\s+(.+)$/iu', $rest, $unit)) {
                return ['amount' => trim($amount[1]), 'unit' => $unit[1], 'name' => trim($unit[2])];
            }

            return ['amount' => trim($amount[1]), 'unit' => '', 'name' => $rest];
        }

        return ['amount' => '', 'unit' => '', 'name' => $line];
    }

    private function fetch(string $url): Response
    {
        $this->assertPublicUrl($url);

        return Http::timeout(20)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; E11LifeOS recipe import)',
                'Accept-Language' => 'de,en;q=0.5',
            ])
            ->withOptions(['allow_redirects' => [
                'max' => 5,
                'on_redirect' => fn ($request, $response, UriInterface $uri) => $this->assertPublicUrl((string) $uri),
            ]])
            ->get($url)
            ->throw();
    }

    /**
     * Only public http(s) addresses: the server must not be used to reach the local network.
     */
    private function assertPublicUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        $invalid = ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || $host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal');

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        foreach ($addresses as $address) {
            $invalid = $invalid || ! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        if ($invalid) {
            throw new RecipeAiException(__('This address cannot be imported.'));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonLdRecipe(string $html): ?array
    {
        preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si', $html, $matches);

        foreach ($matches[1] as $json) {
            $recipe = $this->findRecipeNode(json_decode(trim($json), true));

            if ($recipe) {
                return $recipe;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRecipeNode(mixed $node): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        if (in_array('Recipe', (array) ($node['@type'] ?? []), true)) {
            return $node;
        }

        foreach (array_merge((array) ($node['@graph'] ?? []), array_is_list($node) ? $node : []) as $child) {
            if ($found = $this->findRecipeNode($child)) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function microdataRecipe(string $html): ?array
    {
        $dom = new DOMDocument;

        if (trim($html) === '' || ! @$dom->loadHTML('<?xml encoding="UTF-8">'.$html)) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $root = $xpath->query('//*[contains(@itemtype, "schema.org/Recipe")]')->item(0);

        if (! $root) {
            return null;
        }

        $recipe = [];

        foreach ($xpath->query('.//*[@itemprop]', $root) as $property) {
            $name = $property->getAttribute('itemprop');
            $value = trim($property->getAttribute('content') ?: $property->getAttribute('src') ?: $property->textContent);

            if (in_array($name, ['recipeIngredient', 'ingredients', 'recipeInstructions'], true)) {
                $recipe[$name][] = $value;
            } else {
                $recipe[$name] ??= $value;
            }
        }

        return $recipe;
    }

    /**
     * @param  array<string, mixed>  $schema  schema.org Recipe (JSON-LD or microdata)
     * @return array<string, mixed>
     */
    private function fromSchema(array $schema): array
    {
        $steps = [];
        $collectSteps = function (mixed $instruction) use (&$collectSteps, &$steps) {
            if (is_string($instruction)) {
                array_push($steps, ...preg_split('/\R+/u', $instruction));
            } elseif (is_array($instruction) && isset($instruction['itemListElement'])) {
                array_map($collectSteps, (array) $instruction['itemListElement']);
            } elseif (is_array($instruction) && array_is_list($instruction)) {
                array_map($collectSteps, $instruction);
            } elseif (is_array($instruction)) {
                $steps[] = (string) ($instruction['text'] ?? $instruction['name'] ?? '');
            }
        };
        $collectSteps($schema['recipeInstructions'] ?? []);

        $image = $schema['image'] ?? null;
        $image = is_array($image) ? ($image['url'] ?? $image['contentUrl'] ?? $image[0] ?? null) : $image;
        $image = is_array($image) ? ($image['url'] ?? null) : $image;

        return $this->normalize([
            'title' => $schema['name'] ?? '',
            'description' => $schema['description'] ?? '',
            'servings' => $this->servings($schema['recipeYield'] ?? null),
            'prepTime' => $this->minutes($schema['prepTime'] ?? null),
            'cookTime' => $this->minutes($schema['cookTime'] ?? null) ?? $this->minutes($schema['totalTime'] ?? null),
            'ingredients' => array_values(array_filter((array) ($schema['recipeIngredient'] ?? $schema['ingredients'] ?? []), 'is_string')),
            'steps' => $steps,
            'imageUrl' => is_string($image) ? $image : null,
        ]);
    }

    /**
     * ISO 8601 durations ("PT1H30M") or plain minutes.
     */
    private function minutes(mixed $duration): ?int
    {
        if (is_string($duration) && preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?)?/i', trim($duration), $match) && $match[0] !== 'P') {
            $minutes = (int) ($match[1] ?? 0) * 1440 + (int) ($match[2] ?? 0) * 60 + (int) ($match[3] ?? 0);

            return $minutes > 0 ? $minutes : null;
        }

        return is_numeric($duration) && (int) $duration > 0 ? (int) $duration : null;
    }

    private function servings(mixed $yield): ?int
    {
        $yield = is_array($yield) ? ($yield[0] ?? null) : $yield;

        return is_scalar($yield) && preg_match('/\d+/', (string) $yield, $match) ? (int) $match[0] : null;
    }

    private function pageText(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|svg|nav|footer|header)\b[^>]*>.*?</\1>#si', ' ', $html);
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html))));

        return mb_substr($text, 0, self::MAX_PAGE_TEXT);
    }

    private function metaContent(string $html, string $property): ?string
    {
        $quoted = preg_quote($property, '#');

        foreach ([
            '#<meta[^>]*(?:name|property)=["\']'.$quoted.'["\'][^>]*content=["\']([^"\']+)["\']#i',
            '#<meta[^>]*content=["\']([^"\']+)["\'][^>]*(?:name|property)=["\']'.$quoted.'["\']#i',
        ] as $pattern) {
            if (preg_match($pattern, $html, $match)) {
                return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
            }
        }

        return null;
    }

    private function absoluteUrl(string $url, string $base): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        return str_starts_with($url, '//') ? ($parts['scheme'] ?? 'https').':'.$url : $origin.'/'.ltrim($url, '/');
    }
}
