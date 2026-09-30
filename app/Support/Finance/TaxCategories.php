<?php

namespace App\Support\Finance;

use App\Models\Transaction;

/**
 * Tax categories for private bookings (German income tax) and suggestions from the finance
 * category and typical payee words. Suggestions are never assigned automatically.
 */
class TaxCategories
{
    /**
     * @return array<string, array{label: string, icon: string, form: string, hint: string}>
     */
    public static function all(): array
    {
        return [
            'work' => ['label' => __('Work expenses'), 'icon' => '💼', 'form' => 'Anlage N', 'hint' => __('Work equipment, training, union fees, job applications – only the part above the flat rate of 1,230 € counts.')],
            'insurance' => ['label' => __('Insurance'), 'icon' => '🛡️', 'form' => 'Anlage Vorsorgeaufwand', 'hint' => __('Health, care, liability, disability and accident insurance – within the maximum amounts; basic health insurance counts in full.')],
            'pension' => ['label' => __('Pension contributions'), 'icon' => '🏦', 'form' => 'Anlage Vorsorgeaufwand / AV', 'hint' => __('Rürup/basic pension, voluntary statutory pension, Riester (with allowance check).')],
            'donations' => ['label' => __('Donations'), 'icon' => '🎗️', 'form' => 'Anlage Sonderausgaben', 'hint' => __('Donations and membership fees to charitable organisations (up to 300 € a simple bank statement is enough).')],
            'church_tax' => ['label' => __('Church tax'), 'icon' => '⛪', 'form' => 'Anlage Sonderausgaben', 'hint' => __('Church tax paid (e.g. on capital gains or back payments) is deductible in full.')],
            'childcare' => ['label' => __('Childcare'), 'icon' => '🧸', 'form' => 'Anlage Kind', 'hint' => __('Nursery, day care, childminder: 80 % of the costs, at most 4,800 € per child – pay by transfer.')],
            'medical' => ['label' => __('Medical costs'), 'icon' => '💊', 'form' => 'Außergewöhnliche Belastungen', 'hint' => __('Doctor, dentist, glasses, prescribed medicine – only what exceeds the reasonable burden (depends on income) reduces the tax.')],
            'household_services' => ['label' => __('Household services (§ 35a)'), 'icon' => '🧹', 'form' => '§ 35a Abs. 2', 'hint' => __('Cleaning, gardening, care at home, chimney sweep inspection: 20 % of the labour costs, max. 4,000 € off the tax.')],
            'craftsman' => ['label' => __('Craftsman services (§ 35a)'), 'icon' => '🔧', 'form' => '§ 35a Abs. 3', 'hint' => __('Repairs and renovation in your home: 20 % of the labour costs, max. 1,200 € off the tax.')],
        ];
    }

    public static function label(?string $key): string
    {
        return self::all()[$key]['label'] ?? '';
    }

    /**
     * Typical words in payee or purpose, by tax category.
     *
     * @return array<string, list<string>>
     */
    public static function keywords(): array
    {
        return [
            'church_tax' => ['kirchensteuer'],
            'childcare' => ['kita', 'kindergarten', 'kindertagesstätte', 'tagesmutter', 'tagespflege', 'hort', 'krippe'],
            'work' => ['gewerkschaft', 'ver.di', 'verdi', 'ig metall', 'fortbildung', 'weiterbildung', 'seminar', 'fachbuch', 'berufsverband'],
            'pension' => ['rürup', 'ruerup', 'basisrente', 'riester'],
            'donations' => ['spende', 'unicef', 'caritas', 'diakonie', 'misereor', 'ärzte ohne grenzen', 'greenpeace', 'wwf', 'rotes kreuz', 'drk'],
            'household_services' => ['reinigung', 'putzhilfe', 'gebäudereinigung', 'gärtner', 'gartenpflege', 'hausmeister', 'winterdienst', 'schornsteinfeger', 'pflegedienst'],
            'craftsman' => ['handwerk', 'sanitär', 'heizungsbau', 'elektro', 'malerbetrieb', 'malermeister', 'dachdecker', 'fliesen', 'schreinerei', 'tischlerei', 'installateur'],
            'medical' => ['zahnarzt', 'arztpraxis', 'praxis dr', 'dr. med', 'kieferorthop', 'physiotherap', 'optiker', 'fielmann', 'apollo optik', 'klinik', 'krankenhaus'],
        ];
    }

    /**
     * Suggested tax category for an expense, or null.
     */
    public static function suggest(Transaction $transaction): ?string
    {
        if ($transaction->amount >= 0) {
            return null;
        }

        $text = mb_strtolower($transaction->counterparty.' '.$transaction->purpose);

        foreach (self::keywords() as $category => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    return $category;
                }
            }
        }

        return match ($transaction->category) {
            'insurance' => 'insurance',
            'donations' => str_contains($text, 'e.v.') || str_contains($text, 'stiftung') ? 'donations' : null,
            default => null,
        };
    }
}
