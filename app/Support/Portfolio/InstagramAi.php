<?php

namespace App\Support\Portfolio;

use App\Models\InstagramPost;
use App\Support\StructuredAi;

/**
 * Reads a finance Instagram post (caption and transcript): stock idea, sentiment, key points,
 * entry price, stop loss and buying tranches.
 */
class InstagramAi extends StructuredAi
{
    /**
     * @return array<string, mixed> attributes for InstagramPost
     */
    public function analyze(InstagramPost $post): array
    {
        $text = trim(mb_substr((string) $post->caption, 0, 8000)."\n\n".mb_substr((string) $post->transcript, 0, 12000));

        if ($text === '') {
            return ['summary' => '', 'tickers' => [], 'sentiment' => 'neutral', 'key_points' => [], 'tranches' => [], 'analyzed_at' => now()];
        }

        $result = $this->ask([['type' => 'text', 'text' => 'This is an Instagram post by the stock market expert @'.$post->profile.' from '.($post->published_at?->toDateString() ?? 'unknown date').".\n\n".$text."\n\n"
            .'Summarise it in 2–3 German sentences from an investor\'s point of view (highlight the concrete recommendation). '
            .'Tickers: only real Yahoo Finance symbols (e.g. IFX.DE, AAPL), empty if none. stock_name: full company name of the recommended stock or empty. '
            .'sentiment: bullish, bearish, neutral or mixed. key_points: at most 3 German bullet points. '
            .'entry_price: the price named as entry or current price (0 if none) and entry_type what it means in German (e.g. "Idee des Tages", "Kaufkurs"). '
            .'stop_loss: the named stop loss (0 if none). currency of the prices (usually EUR). '
            .'tranches: only if buying in parts is explicitly recommended, the prices, else empty. Never invent numbers.']], [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'tickers' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'stock_name' => ['type' => 'string'],
                    'sentiment' => ['type' => 'string', 'enum' => InstagramPost::SENTIMENTS],
                    'key_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'entry_price' => ['type' => 'number'],
                    'entry_type' => ['type' => 'string'],
                    'stop_loss' => ['type' => 'number'],
                    'currency' => ['type' => 'string'],
                    'tranches' => ['type' => 'array', 'items' => ['type' => 'number']],
                ],
                'required' => ['summary', 'tickers', 'stock_name', 'sentiment', 'key_points', 'entry_price', 'entry_type', 'stop_loss', 'currency', 'tranches'],
                'additionalProperties' => false,
            ], 1500);

        $tranches = array_values(array_filter(array_map('floatval', (array) ($result['tranches'] ?? [])), fn (float $price) => $price > 0));
        rsort($tranches);
        $entry = (float) ($result['entry_price'] ?? 0);
        $stop = (float) ($result['stop_loss'] ?? 0);

        return [
            'summary' => mb_substr(trim((string) ($result['summary'] ?? '')), 0, 2000),
            'tickers' => array_slice(array_values(array_filter(array_map(fn ($ticker) => strtoupper(trim((string) $ticker)), (array) ($result['tickers'] ?? [])), fn (string $ticker) => (bool) preg_match('/^[A-Z0-9.\-^=]{1,20}$/', $ticker))), 0, 10),
            'stock_name' => mb_substr(trim((string) ($result['stock_name'] ?? '')), 0, 255) ?: null,
            'sentiment' => in_array($result['sentiment'] ?? null, InstagramPost::SENTIMENTS, true) ? $result['sentiment'] : 'neutral',
            'key_points' => array_slice(array_values(array_filter(array_map(fn ($point) => trim((string) $point), (array) ($result['key_points'] ?? [])))), 0, 3),
            'entry_price' => $entry > 0 ? $entry : null,
            'entry_type' => $entry > 0 ? (mb_substr(trim((string) ($result['entry_type'] ?? '')), 0, 100) ?: null) : null,
            'stop_loss' => $stop > 0 ? $stop : null,
            'currency' => preg_match('/^[A-Z]{3}$/', strtoupper((string) ($result['currency'] ?? ''))) ? strtoupper($result['currency']) : 'EUR',
            'tranches' => array_slice($tranches, 0, 3),
            'analyzed_at' => now(),
        ];
    }
}
