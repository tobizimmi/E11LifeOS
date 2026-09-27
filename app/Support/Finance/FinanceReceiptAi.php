<?php

namespace App\Support\Finance;

use App\Support\StructuredAi;

/**
 * Reads a private receipt (photo or PDF) for the finance overview: date, shop, total and category.
 */
class FinanceReceiptAi extends StructuredAi
{
    public const MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

    /**
     * @return array{booked_on: ?string, counterparty: string, purpose: string, amount: ?float, category: ?string}
     */
    public function read(string $data, string $mediaType): array
    {
        $categories = collect(FinanceCategories::all())
            ->reject(fn (array $category) => in_array($category['group'], ['income', 'transfer'], true))
            ->map(fn (array $category, string $key) => $key.' = '.$category['label'])->implode("\n");

        $source = $mediaType === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($data)]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mediaType, 'data' => base64_encode($data)]];

        $result = $this->ask([
            $source,
            ['type' => 'text', 'text' => 'This is a private purchase receipt. Read the date, the shop, a short German summary of what was bought (max. 8 words) and the total paid. '
                .'Choose the best category key. Leave strings empty and the total 0 if you cannot read them – never guess. Today is '.now()->toDateString().".\n\nCategories:\n".$categories],
        ], [
            'type' => 'object',
            'properties' => [
                'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD or empty'],
                'shop' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'total' => ['type' => 'number'],
                'category' => ['type' => 'string'],
            ],
            'required' => ['date', 'shop', 'summary', 'total', 'category'],
            'additionalProperties' => false,
        ], 1000);

        $date = trim((string) ($result['date'] ?? ''));
        $total = abs((float) ($result['total'] ?? 0));
        $category = trim((string) ($result['category'] ?? ''));

        return [
            'booked_on' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) ? $date : null,
            'counterparty' => mb_substr(trim((string) ($result['shop'] ?? '')), 0, 255),
            'purpose' => mb_substr(trim((string) ($result['summary'] ?? '')), 0, 255),
            'amount' => $total > 0 ? round($total, 2) : null,
            'category' => array_key_exists($category, FinanceCategories::all()) ? $category : null,
        ];
    }
}
