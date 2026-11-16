<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Support\Portfolio\Positions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Mobile API to keep the portfolios (depots) and to record, edit and delete their transactions
 * (buy, sell, dividend, split, fees, transfers); the positions are recalculated from them.
 */
class PortfolioWriteController extends ApiController
{
    public function storePortfolio(Request $request): JsonResponse
    {
        $portfolio = Portfolio::create(['user_id' => $this->user()->id] + $this->portfolioFields($request));

        return $this->ok($this->portfolioData($portfolio), 201);
    }

    public function updatePortfolio(Request $request, int $portfolio): JsonResponse
    {
        $model = $this->portfolio($portfolio);
        $model->update($this->portfolioFields($request));

        return $this->ok($this->portfolioData($model));
    }

    public function destroyPortfolio(int $portfolio): JsonResponse
    {
        $this->portfolio($portfolio)->delete();

        return $this->ok();
    }

    public function storeTransaction(Request $request, int $portfolio): JsonResponse
    {
        $model = $this->portfolio($portfolio);
        $attributes = $this->transactionFields($request, $model, null);
        $transaction = PortfolioTransaction::create($attributes);
        Positions::recalculate($model);

        return $this->ok($this->transactionData($transaction), 201);
    }

    public function updateTransaction(Request $request, int $transaction): JsonResponse
    {
        $existing = $this->transaction($transaction);
        $old = Portfolio::findOrFail($existing->portfolio_id);
        $target = $request->filled('portfolio_id') ? $this->portfolio((int) $request->input('portfolio_id')) : $old;
        $existing->update($this->transactionFields($request, $target, $existing));

        if ($old->isNot($target)) {
            Positions::recalculate($old);
        }

        Positions::recalculate($target);

        return $this->ok($this->transactionData($existing->fresh() ?? $existing));
    }

    public function destroyTransaction(int $transaction): JsonResponse
    {
        $existing = $this->transaction($transaction);
        $portfolio = Portfolio::findOrFail($existing->portfolio_id);
        $existing->delete();
        Positions::recalculate($portfolio);

        return $this->ok();
    }

    private function portfolio(int $id): Portfolio
    {
        return Portfolio::where('user_id', $this->user()->id)->findOrFail($id);
    }

    private function transaction(int $id): PortfolioTransaction
    {
        return PortfolioTransaction::whereIn('portfolio_id', Portfolio::where('user_id', $this->user()->id)->select('id'))->findOrFail($id);
    }

    /**
     * @return array{name: string, broker: ?string, description: ?string, color: string}
     */
    private function portfolioFields(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'broker' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        return ['name' => $data['name'], 'broker' => $data['broker'] ?? null, 'description' => $data['description'] ?? null, 'color' => $data['color'] ?? '#6366f1'];
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionFields(Request $request, Portfolio $portfolio, ?PortfolioTransaction $previous): array
    {
        $type = (string) $request->input('type');
        $needsQuantity = in_array($type, ['buy', 'sell', 'transfer_in', 'transfer_out'], true);
        $data = $request->validate([
            'type' => ['required', Rule::in(PortfolioTransaction::TYPES)],
            'symbol' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:255'],
            'isin' => ['nullable', 'string', 'size:12'],
            'executed_on' => ['required', 'date'],
            'quantity' => [$needsQuantity ? 'required' : 'nullable', 'numeric', $needsQuantity ? 'gt:0' : 'min:0'],
            'price' => [$type === 'fee' ? 'nullable' : 'required', 'numeric', 'min:0'],
            'fees' => ['nullable', 'numeric', 'min:0'],
            'taxes' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $symbol = strtoupper(trim($data['symbol']));
        $quantity = (float) ($data['quantity'] ?? 0);

        if ($type === 'sell') {
            $held = (float) $portfolio->positions()->where('symbol', $symbol)->value('quantity');
            $available = $held + ($previous !== null && $previous->type === 'sell' && $previous->symbol === $symbol && $previous->portfolio_id === $portfolio->id ? (float) $previous->quantity : 0);

            if ($quantity > $available + 0.000001) {
                throw ValidationException::withMessages(['quantity' => __('You only hold :quantity of this security.', ['quantity' => rtrim(rtrim(number_format($available, 6, ',', '.'), '0'), ',')])]);
            }
        }

        return [
            'portfolio_id' => $portfolio->id,
            'type' => $type,
            'symbol' => $symbol,
            'name' => ($data['name'] ?? null) ?: ($portfolio->positions()->where('symbol', $symbol)->value('name') ?? $symbol),
            'isin' => ! empty($data['isin']) ? strtoupper($data['isin']) : null,
            'executed_on' => $data['executed_on'],
            'quantity' => $quantity,
            'price' => (float) ($data['price'] ?? 0),
            'fees' => (float) ($data['fees'] ?? 0),
            'taxes' => (float) ($data['taxes'] ?? 0),
            'currency' => strtoupper($data['currency'] ?? 'EUR'),
            'exchange_rate' => (float) ($data['exchange_rate'] ?? 1) ?: 1.0,
            'notes' => ($data['notes'] ?? null) ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function portfolioData(Portfolio $portfolio): array
    {
        return ['id' => $portfolio->id, 'name' => $portfolio->name, 'color' => $portfolio->color, 'broker' => $portfolio->broker, 'description' => $portfolio->description];
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionData(PortfolioTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'portfolio_id' => $transaction->portfolio_id,
            'symbol' => $transaction->symbol,
            'name' => $transaction->name,
            'type' => $transaction->type,
            'quantity' => (float) $transaction->quantity,
            'price' => (float) $transaction->price,
            'fees' => (float) $transaction->fees,
            'taxes' => (float) $transaction->taxes,
            'currency' => $transaction->currency,
            'exchange_rate' => (float) $transaction->exchange_rate,
            'notes' => $transaction->notes,
            'transaction_date' => Carbon::parse((string) $transaction->executed_on)->toDateString(),
        ];
    }
}
