<?php

namespace App\Support\Portfolio;

use App\Support\AiFeatures;
use App\Support\StructuredAi;

/**
 * Reads transactions or holdings from a broker statement, trade confirmation or screenshot
 * (PDF or image) for the import preview.
 */
class StatementAi extends StructuredAi
{
    protected const FEATURE = AiFeatures::STATEMENTS;

    public const MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

    /**
     * @return list<array<string, mixed>> rows in the parser format (transaction_date, symbol, isin …)
     */
    public function read(string $data, string $mediaType): array
    {
        $source = $mediaType === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($data)]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mediaType, 'data' => base64_encode($data)]];

        $result = $this->ask([
            $source,
            ['type' => 'text', 'text' => 'Extract every securities transaction (or, for a holdings overview, every position as a purchase) from this broker document. '
                .'Always fill ISIN and WKN when they are printed. Use the ticker symbol only if it is printed or certain (Yahoo Finance format, e.g. SAP.DE, AAPL). '
                .'Dates as YYYY-MM-DD (the purchase date; for holdings without a date use today, '.now()->toDateString().'). '
                .'Type is buy, sell, dividend or fee. Price per unit in the original currency; for dividends the total amount as price and quantity 0. '
                .'Never invent values: use empty strings and 0 when something is not shown.'],
        ], [
            'type' => 'object',
            'properties' => [
                'transactions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => ['buy', 'sell', 'dividend', 'fee']],
                            'name' => ['type' => 'string'],
                            'symbol' => ['type' => 'string'],
                            'isin' => ['type' => 'string'],
                            'wkn' => ['type' => 'string'],
                            'quantity' => ['type' => 'number'],
                            'price' => ['type' => 'number'],
                            'fees' => ['type' => 'number'],
                            'taxes' => ['type' => 'number'],
                            'currency' => ['type' => 'string'],
                        ],
                        'required' => ['date', 'type', 'name', 'symbol', 'isin', 'wkn', 'quantity', 'price', 'fees', 'taxes', 'currency'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['transactions'],
            'additionalProperties' => false,
        ], 8000);

        return array_map(fn (array $row) => ['transaction_date' => $row['date'] ?? ''] + $row + ['broker' => null], array_values(array_filter((array) ($result['transactions'] ?? []), 'is_array')));
    }
}
