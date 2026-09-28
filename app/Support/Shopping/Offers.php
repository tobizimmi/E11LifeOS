<?php

namespace App\Support\Shopping;

use App\Models\ShoppingStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Current supermarket offers near a postcode (source: marktguru.de, which collects the weekly
 * flyers of the German chains). Results are cached for three hours.
 */
class Offers
{
    /**
     * Offers for a search term, newest first; optionally only from the given chains.
     *
     * @param  list<string>  $chains  keys of ShoppingStore::CHAINS
     * @return list<array{id: string, title: string, brand: ?string, store: string, price: ?float, old_price: ?float, unit: ?string, valid_to: ?string, image: ?string}>
     */
    public function search(string $term, string $zip, array $chains = [], int $limit = 24): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2 || ! preg_match('/^\d{5}$/', $zip)) {
            return [];
        }

        $offers = Cache::remember('offers:'.md5(mb_strtolower($term).'|'.$zip), now()->addHours(3), fn () => $this->fetch($term, $zip));

        if ($chains !== []) {
            $offers = array_values(array_filter($offers, fn (array $offer) => self::chainOf($offer['store']) !== null && in_array(self::chainOf($offer['store']), $chains, true)));
        }

        return array_slice($offers, 0, $limit);
    }

    /**
     * The chain key for a store name from the offers ("REWE Center" → rewe), or null.
     */
    public static function chainOf(string $store): ?string
    {
        $name = mb_strtolower($store);

        foreach (ShoppingStore::CHAINS as $key => $chain) {
            if ($key === 'other') {
                continue;
            }

            $needle = match ($key) {
                'aldi_sued' => 'aldi süd',
                'aldi_nord' => 'aldi nord',
                'netto' => 'netto',
                default => mb_strtolower($chain['name']),
            };

            if (str_contains($name, $needle)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, title: string, brand: ?string, store: string, price: ?float, old_price: ?float, unit: ?string, valid_to: ?string, image: ?string}>
     */
    private function fetch(string $term, string $zip): array
    {
        [$apiKey, $clientKey] = $this->keys();

        if ($apiKey === null) {
            throw new OffersUnavailable(__('The offers service cannot be reached right now.'));
        }

        try {
            $response = Http::withHeaders(['x-apikey' => $apiKey, 'x-clientkey' => $clientKey, 'Accept' => 'application/json'])
                ->timeout(12)
                ->get('https://api.marktguru.de/api/v1/offers/search', ['as' => 'web', 'q' => $term, 'zipCode' => $zip, 'limit' => 60, 'offset' => 0])
                ->throw();
        } catch (Throwable $exception) {
            Cache::forget('offers:keys');

            throw new OffersUnavailable(__('The offers service cannot be reached right now.'), previous: $exception);
        }

        return collect($response->json('results') ?? [])
            ->map(function (array $offer) {
                $validTo = data_get($offer, 'validityDates.0.to');

                return [
                    'id' => (string) ($offer['id'] ?? ''),
                    'title' => trim((string) (data_get($offer, 'product.name') ?: ($offer['description'] ?? ''))),
                    'brand' => data_get($offer, 'brand.name') ?: data_get($offer, 'product.brand.name'),
                    'store' => (string) (data_get($offer, 'advertisers.0.name') ?? ''),
                    'price' => isset($offer['price']) ? (float) $offer['price'] : null,
                    'old_price' => isset($offer['oldPrice']) && $offer['oldPrice'] > 0 ? (float) $offer['oldPrice'] : null,
                    'unit' => trim(implode(' ', array_filter([(string) ($offer['quantity'] ?? ''), (string) data_get($offer, 'unit.shortName', '')]))) ?: null,
                    'valid_to' => $validTo ? substr((string) $validTo, 0, 10) : null,
                    'image' => isset($offer['id']) ? 'https://mg2de.b-cdn.net/api/v1/offers/'.$offer['id'].'/images/default/0/small.jpg' : null,
                ];
            })
            ->filter(fn (array $offer) => $offer['title'] !== '' && $offer['store'] !== '' && ($offer['valid_to'] === null || $offer['valid_to'] >= today()->toDateString()))
            ->values()->all();
    }

    /**
     * API keys: configured, or read from the public website (cached for a day).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function keys(): array
    {
        if (filled(config('services.offers.api_key'))) {
            return [(string) config('services.offers.api_key'), (string) config('services.offers.client_key')];
        }

        if ($cached = Cache::get('offers:keys')) {
            return $cached;
        }

        try {
            $html = Http::timeout(10)->get('https://www.marktguru.de/')->throw()->body();
        } catch (Throwable) {
            return [null, null];
        }

        preg_match('/"apiKey"\s*:\s*"([^"]+)"/', $html, $api);
        preg_match('/"clientKey"\s*:\s*"([^"]+)"/', $html, $client);
        $keys = [$api[1] ?? null, $client[1] ?? null];

        if ($keys[0] !== null) {
            Cache::put('offers:keys', $keys, now()->addDay());
        }

        return $keys;
    }
}
