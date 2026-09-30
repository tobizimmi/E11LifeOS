<?php

namespace App\Support\Business;

use App\Support\AiFeatures;
use App\Support\StructuredAi;

/**
 * Reads a receipt (photo or PDF) and suggests date, vendor, amounts and a bookkeeping category.
 */
class ReceiptAi extends StructuredAi
{
    protected const FEATURE = AiFeatures::RECEIPTS;

    public const MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

    /**
     * @return array{booked_on: ?string, counterparty: string, description: string, amount: ?float, vat_amount: ?float, category: ?string}
     */
    public function read(string $data, string $mediaType, string $kind): array
    {
        $categories = collect(BookkeepingCategories::forKind($kind))->map(fn (array $category, string $key) => $key.' = '.$category['label'])->implode("\n");

        $source = $mediaType === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($data)]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mediaType, 'data' => base64_encode($data)]];

        $result = $this->ask([
            $source,
            ['type' => 'text', 'text' => 'This is a receipt of a German small business ('.($kind === 'income' ? 'income' : 'expense').'). '
                .'Read the date, the other party, a short German description of what was bought or sold, the gross total and the VAT contained (0 if none is shown). '
                .'Choose the best category key. Leave strings empty and numbers 0 if you cannot read them – never guess. Today is '.now()->toDateString().".\n\nCategories:\n".$categories],
        ], [
            'type' => 'object',
            'properties' => [
                'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD or empty'],
                'counterparty' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'gross_amount' => ['type' => 'number'],
                'vat_amount' => ['type' => 'number'],
                'category' => ['type' => 'string'],
            ],
            'required' => ['date', 'counterparty', 'description', 'gross_amount', 'vat_amount', 'category'],
            'additionalProperties' => false,
        ], 1500);

        $date = trim((string) ($result['date'] ?? ''));
        $amount = abs((float) ($result['gross_amount'] ?? 0));
        $vat = abs((float) ($result['vat_amount'] ?? 0));
        $category = trim((string) ($result['category'] ?? ''));

        return [
            'booked_on' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) ? $date : null,
            'counterparty' => mb_substr(trim((string) ($result['counterparty'] ?? '')), 0, 255),
            'description' => mb_substr(trim((string) ($result['description'] ?? '')), 0, 255),
            'amount' => $amount > 0 ? round($amount, 2) : null,
            'vat_amount' => $vat > 0 && $vat < $amount ? round($vat, 2) : null,
            'category' => BookkeepingCategories::exists($category, $kind) ? $category : null,
        ];
    }
}
