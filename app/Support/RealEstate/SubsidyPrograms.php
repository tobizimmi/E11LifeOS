<?php

namespace App\Support\RealEstate;

use App\Models\RealEstate;

/**
 * Common German subsidy programmes for buying and renovating, as a starting point. Conditions
 * change often – the page links to the programme and asks to check before applying.
 */
class SubsidyPrograms
{
    /**
     * @return array<string, array{title: string, kind: string, description: string, url: string, usage: string, energy: bool, before_start: bool}>
     */
    public static function all(): array
    {
        return [
            'kfw_124' => [
                'title' => 'KfW 124 – Wohneigentumsprogramm',
                'kind' => 'loan',
                'description' => __('Loan for buying or building a home you live in yourself; can be combined with a bank loan.'),
                'url' => 'https://www.kfw.de/124',
                'usage' => 'owner', 'energy' => false, 'before_start' => true,
            ],
            'kfw_300' => [
                'title' => 'KfW 300 – Wohneigentum für Familien',
                'kind' => 'loan',
                'description' => __('Low-interest loan for families with children and limited income for an energy-efficient new home.'),
                'url' => 'https://www.kfw.de/300',
                'usage' => 'owner', 'energy' => true, 'before_start' => true,
            ],
            'kfw_308' => [
                'title' => 'KfW 308 – Jung kauft Alt',
                'kind' => 'loan',
                'description' => __('Low-interest loan for families buying an older, energy-inefficient house and renovating it.'),
                'url' => 'https://www.kfw.de/308',
                'usage' => 'owner', 'energy' => true, 'before_start' => true,
            ],
            'kfw_261' => [
                'title' => 'KfW 261 – Wohngebäude Kredit (BEG)',
                'kind' => 'loan',
                'description' => __('Loan with repayment grant for renovating to an efficiency house standard; for owners and landlords.'),
                'url' => 'https://www.kfw.de/261',
                'usage' => 'both', 'energy' => true, 'before_start' => true,
            ],
            'kfw_458' => [
                'title' => 'KfW 458 – Heizungsförderung',
                'kind' => 'grant',
                'description' => __('Grant for a new climate-friendly heating system (e.g. heat pump), with bonuses depending on income and speed.'),
                'url' => 'https://www.kfw.de/458',
                'usage' => 'both', 'energy' => true, 'before_start' => true,
            ],
            'bafa_beg_em' => [
                'title' => 'BAFA BEG Einzelmaßnahmen',
                'kind' => 'grant',
                'description' => __('Grant for single measures on the building envelope (insulation, windows, doors) and system technology; bonus with an individual renovation roadmap (iSFP).'),
                'url' => 'https://www.bafa.de/beg',
                'usage' => 'both', 'energy' => true, 'before_start' => true,
            ],
            'energy_advice' => [
                'title' => 'Energieberatung (iSFP)',
                'kind' => 'grant',
                'description' => __('Grant for an energy consultation with an individual renovation roadmap – raises later subsidies.'),
                'url' => 'https://www.bafa.de/ebw',
                'usage' => 'both', 'energy' => true, 'before_start' => true,
            ],
            'tax_35c' => [
                'title' => '§ 35c EStG – Steuerermäßigung energetische Sanierung',
                'kind' => 'tax',
                'description' => __('Instead of a subsidy: 20 % of the costs of energy measures spread over three years (max. 40,000 €) for homes you live in that are older than ten years.'),
                'url' => 'https://www.gesetze-im-internet.de/estg/__35c.html',
                'usage' => 'owner', 'energy' => true, 'before_start' => false,
            ],
            'wohn_riester' => [
                'title' => 'Wohn-Riester',
                'kind' => 'grant',
                'description' => __('Use Riester savings and allowances for buying a home you live in or for paying off its loan.'),
                'url' => 'https://www.deutsche-rentenversicherung.de',
                'usage' => 'owner', 'energy' => false, 'before_start' => false,
            ],
            'regional' => [
                'title' => __('Regional programmes'),
                'kind' => 'grant',
                'description' => __('Many states, districts and towns have their own grants (e.g. for families, listed buildings or solar). Search the federal funding database.'),
                'url' => 'https://www.foerderdatenbank.de',
                'usage' => 'both', 'energy' => false, 'before_start' => true,
            ],
        ];
    }

    /**
     * Programmes that fit the property: its usage and whether energy measures are planned.
     *
     * @return array<string, array{title: string, kind: string, description: string, url: string, usage: string, energy: bool, before_start: bool}>
     */
    public static function suggestionsFor(RealEstate $realEstate): array
    {
        $hasEnergy = $realEstate->budgetItems->contains('energy_measure', true);
        $used = $realEstate->subsidies->pluck('program')->all();
        $livesIn = $realEstate->rentalShare() < 100;
        $lets = $realEstate->rentalShare() > 0;

        return array_filter(self::all(), fn (array $program, string $key) => ! in_array($key, $used, true)
            && ($program['usage'] === 'both' || ($program['usage'] === 'owner' && $livesIn) || ($program['usage'] === 'rental' && $lets))
            && (! $program['energy'] || $hasEnergy || in_array($key, ['energy_advice', 'kfw_308'], true)), ARRAY_FILTER_USE_BOTH);
    }
}
