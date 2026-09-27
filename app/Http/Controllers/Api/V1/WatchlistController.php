<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Security;
use App\Models\WatchlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WatchlistController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->ok(WatchlistItem::where('user_id', $this->user()->id)->with('security')->orderBy('symbol')->get()->map(fn (WatchlistItem $item) => [
            'id' => $item->id,
            'symbol' => $item->symbol,
            'name' => $item->name ?: $item->security?->name ?: $item->symbol,
            'notes' => $item->notes,
            'added_at' => $item->created_at?->toIso8601String(),
            'current_price' => $item->security?->last_price,
            'change_pct' => $item->security?->changePercent(),
            'currency' => $item->security?->currency,
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['symbol' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9.\-^=]+$/'], 'name' => ['nullable', 'string', 'max:255']]);
        $symbol = strtoupper($data['symbol']);
        $item = WatchlistItem::firstOrCreate(
            ['user_id' => $this->user()->id, 'symbol' => $symbol],
            ['name' => ($data['name'] ?? null) ?: Security::where('symbol', $symbol)->value('name') ?? $symbol],
        );

        return $this->ok(['id' => $item->id, 'symbol' => $item->symbol], $item->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(string $symbol): JsonResponse
    {
        WatchlistItem::where('user_id', $this->user()->id)->where('symbol', strtoupper($symbol))->delete();

        return $this->ok();
    }
}
