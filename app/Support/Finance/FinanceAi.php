<?php

namespace App\Support\Finance;

use App\Support\StructuredAi;

/**
 * The "financial analyst": a written assessment of the report and categories for bookings
 * the rules did not recognise. Only aggregated figures and payee names are sent.
 */
class FinanceAi extends StructuredAi
{
    private const CATEGORIZE_BATCH = 80;

    /**
     * @var array<string, mixed>
     */
    private const ADVICE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'summary' => ['type' => 'string', 'description' => 'two to four sentences on the overall financial situation'],
            'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
            'recommendations' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'text' => ['type' => 'string'],
                        'yearly_saving' => ['type' => 'number', 'description' => 'estimated yearly saving in euro, 0 if not quantifiable'],
                    ],
                    'required' => ['title', 'text', 'yearly_saving'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['summary', 'strengths', 'recommendations'],
        'additionalProperties' => false,
    ];

    /**
     * @param  array<string, mixed>  $report  See FinanceReport::build().
     * @return array{summary: string, strengths: array<int, string>, recommendations: array<int, array{title: string, text: string, yearly_saving: float}>}
     */
    public function advise(array $report): array
    {
        $facts = [
            'period' => $report['from']->toDateString().' – '.$report['to']->toDateString().' ('.$report['monthCount'].' months)',
            'income_total' => $report['income'],
            'consumption_expenses_total' => $report['expenses'],
            'transfers_to_savings_total' => $report['savingsTransfers'],
            'savings_rate_percent' => $report['savingsRate'],
            'average_expenses_per_month' => $report['avgExpenses'],
            'categories' => array_map(fn (array $category) => ['category' => $category['label'], 'total' => $category['amount'], 'per_month' => $category['perMonth'], 'share_percent' => $category['share']], $report['categories']),
            'recurring_payments' => array_map(fn (array $row) => ['payee' => $row['name'], 'frequency' => $row['frequency'], 'amount' => $row['amount'], 'per_month' => $row['monthly'], 'recent_increase' => $row['increase']], array_slice($report['recurring'], 0, 30)),
            'split_percent_of_income' => ['needs' => $report['split']['needs'], 'wants' => $report['split']['wants'], 'savings' => $report['split']['savings']],
            'months' => array_map(fn (array $month) => ['month' => $month['month'], 'income' => $month['income'], 'expenses' => $month['expenses']], $report['monthly']),
        ];

        $result = $this->ask([[
            'type' => 'text',
            'text' => "You are a friendly, precise personal finance analyst in Germany. Analyse these aggregated bank figures of a private household. \n"
                ."Write in German, use \"du\". Be concrete: name categories, payees and euro amounts from the data; no generic advice without a link to the data. \n"
                ."Give 3 to 6 recommendations sorted by impact with a realistic yearly saving estimate (0 if unknown). Mention strengths too. \n"
                ."Do not give investment advice for specific securities.\n\n".json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]], self::ADVICE_SCHEMA, 3000);

        return [
            'summary' => trim((string) ($result['summary'] ?? '')),
            'strengths' => array_values(array_filter(array_map(fn ($text) => trim((string) $text), (array) ($result['strengths'] ?? [])))),
            'recommendations' => array_values(array_map(fn ($row) => [
                'title' => trim((string) ($row['title'] ?? '')),
                'text' => trim((string) ($row['text'] ?? '')),
                'yearly_saving' => max(0.0, (float) ($row['yearly_saving'] ?? 0)),
            ], array_filter((array) ($result['recommendations'] ?? []), 'is_array'))),
        ];
    }

    /**
     * @param  array<int, array{id: int, counterparty: ?string, purpose: ?string, amount: float}>  $bookings
     * @return array<int, string> Category key by booking id (only valid categories).
     */
    public function categorize(array $bookings): array
    {
        $categories = collect(FinanceCategories::all())->map(fn (array $category, string $key) => $key.' = '.$category['label'])->implode("\n");
        $result = [];

        foreach (array_chunk($bookings, self::CATEGORIZE_BATCH) as $batch) {
            $answer = $this->ask([[
                'type' => 'text',
                'text' => "Assign each German bank booking one category key. Negative amounts are expenses, positive income. Use \"other\" / \"other_income\" when unsure.\n\nCategories:\n"
                    .$categories."\n\nBookings (JSON):\n".json_encode($batch, JSON_UNESCAPED_UNICODE),
            ]], [
                'type' => 'object',
                'properties' => ['items' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer'], 'category' => ['type' => 'string']],
                    'required' => ['id', 'category'],
                    'additionalProperties' => false,
                ]]],
                'required' => ['items'],
                'additionalProperties' => false,
            ], 4000);

            foreach ((array) ($answer['items'] ?? []) as $item) {
                if (is_array($item) && isset($item['id'], $item['category']) && FinanceCategories::exists((string) $item['category'])) {
                    $result[(int) $item['id']] = (string) $item['category'];
                }
            }
        }

        return $result;
    }
}
