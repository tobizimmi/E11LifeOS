<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Security;
use App\Support\Portfolio\MarketData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketController extends ApiController
{
    public function __construct(private MarketData $market) {}

    /**
     * Quotes for up to 50 comma separated symbols, keyed by symbol.
     */
    public function quotes(Request $request): JsonResponse
    {
        $symbols = collect(explode(',', (string) $request->query('symbols')))
            ->map(fn (string $symbol) => strtoupper(trim($symbol)))
            ->filter(fn (string $symbol) => preg_match('/^[A-Z0-9.\-^=]{1,32}$/', $symbol))
            ->unique()->take(50)->values()->all();

        return $this->ok((object) collect($this->market->quotes($symbols))->map(fn (Security $security) => $this->present($security))->all());
    }

    public function quote(string $symbol): JsonResponse
    {
        $security = $this->market->quote(strtoupper($symbol));
        abort_unless($security, 404);

        return $this->ok($this->present($security));
    }

    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q'));

        return $this->ok(mb_strlen($query) < 2 ? [] : $this->market->search($query));
    }

    /**
     * @return array{symbol: string, name: ?string, price: ?float, change: ?float, change_pct: ?float, currency: ?string, market_cap: ?float, updated_at: ?string}
     */
    private function present(Security $security): array
    {
        return [
            'symbol' => $security->symbol,
            'name' => $security->name,
            'price' => $security->last_price,
            'change' => $security->last_price !== null && $security->previous_close ? round($security->last_price - $security->previous_close, 4) : null,
            'change_pct' => $security->changePercent(),
            'currency' => $security->currency,
            'market_cap' => $security->market_cap,
            'updated_at' => $security->price_updated_at?->toIso8601String(),
        ];
    }
}
