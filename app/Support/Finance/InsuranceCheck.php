<?php

namespace App\Support\Finance;

use App\Models\Collection;
use App\Models\Item;
use App\Models\RealEstate;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Addons;
use App\Support\ContractOverview;
use App\Support\RealEstate\RealEstateLink;

/**
 * Insurance check: which insurances the contracts contain (recognised by title, provider and
 * category), which important ones are missing for the member's situation (income, properties,
 * loans, vehicles), which exist twice and which are often unnecessary. The member can mark an
 * insurance as existing elsewhere or not needed.
 */
class InsuranceCheck
{
    public const HAVE = 'have';

    public const NOT_NEEDED = 'not_needed';

    /** Kind of a contract set by hand when it is no insurance at all. */
    public const NONE = 'none';

    /**
     * @return array<string, array{label: string, icon: string, words: list<string>, why: string}>
     */
    public static function types(): array
    {
        return [
            'liability' => ['label' => __('Personal liability insurance'), 'icon' => '🛡️', 'words' => ['haftpflicht', 'liability'], 'why' => __('Covers damage you cause to others – can reach millions. A must for everyone and costs little.')],
            'disability' => ['label' => __('Occupational disability insurance'), 'icon' => '🦺', 'words' => ['berufsunfähig', 'bu-versicherung', ' bu ', 'erwerbsunfähig', 'disability'], 'why' => __('Your ability to earn money is your biggest asset while you work. Take it out young and healthy.')],
            'term_life' => ['label' => __('Term life insurance'), 'icon' => '🕊️', 'words' => ['risikoleben', 'risiko-leben', 'term life'], 'why' => __('Protects your family and the loan if you die – important with a mortgage or children.')],
            'building' => ['label' => __('Buildings insurance'), 'icon' => '🏠', 'words' => ['wohngebäude', 'gebäudeversicherung', 'building'], 'why' => __('Fire, storm and water damage to your house can ruin you. Banks require it for a loan.')],
            'contents' => ['label' => __('Household contents insurance'), 'icon' => '🛋️', 'words' => ['hausrat', 'contents'], 'why' => __('Covers your belongings against fire, burglary and water – worth it if replacing them would hurt.')],
            'car' => ['label' => __('Motor insurance'), 'icon' => '🚗', 'words' => ['kfz', 'auto', 'motor', 'fahrzeugversicherung', 'teilkasko', 'vollkasko'], 'why' => __('Third-party motor insurance is compulsory for every registered vehicle.')],
            'legal' => ['label' => __('Legal expenses insurance'), 'icon' => '⚖️', 'words' => ['rechtsschutz', 'legal'], 'why' => __('Optional – useful for disputes with employer, landlord or in traffic.')],
            'accident' => ['label' => __('Accident insurance'), 'icon' => '🩹', 'words' => ['unfallversicherung', 'accident'], 'why' => __('Optional – occupational disability insurance usually protects better.')],
            'dental' => ['label' => __('Supplementary dental insurance'), 'icon' => '🦷', 'words' => ['zahnzusatz', 'zahn', 'dental'], 'why' => __('Optional – pays off with expensive dentures.')],
        ];
    }

    /**
     * Insurances that consumer advice centres often consider unnecessary.
     *
     * @return array<string, string>
     */
    public static function oftenUnnecessary(): array
    {
        return [
            'handy' => __('Mobile phone insurance'),
            'brille' => __('Glasses insurance'),
            'reisegepäck' => __('Luggage insurance'),
            'restschuld' => __('Payment protection insurance'),
            'sterbegeld' => __('Funeral expenses insurance'),
            'garantieverlängerung' => __('Extended warranty'),
        ];
    }

    /**
     * @return array{found: array<string, list<array{item_id: int, title: string, provider: ?string, monthly: float, url: string, assigned: bool, payments: list<string>}>>, rows: list<array{key: string, label: string, icon: string, why: string, level: string, status: string, contracts: list<array{item_id: int, title: string, provider: ?string, monthly: float, url: string, assigned: bool, payments: list<string>}>}>, unnecessary: list<array{title: string, label: string, monthly: float, url: string}>, candidates: list<array{item_id: int, title: string, provider: ?string, monthly: float, url: string}>, monthly: float, gaps: int}
     */
    public static function for(User $user): array
    {
        $household = $user->currentHousehold();
        $contracts = $household ? ContractOverview::for($user, $household->id)['contracts'] : [];
        $answers = is_array($user->insurance_answers) ? $user->insurance_answers : [];

        $found = [];
        $unnecessary = [];
        $monthly = 0.0;

        $candidates = [];

        foreach ($contracts as $contract) {
            $text = ' '.mb_strtolower($contract['item']->title.' '.$contract['provider'].' '.$contract['category'].' '.$contract['collection']->name).' ';
            $assigned = $contract['item']->content['insurance_type'] ?? null;
            $row = ['item_id' => $contract['item']->id, 'title' => $contract['item']->title, 'provider' => $contract['provider'], 'monthly' => $contract['monthly'], 'url' => $contract['url'], 'assigned' => is_string($assigned), 'payments' => SubscriptionMatcher::linkedKeys($contract['item'])];
            $isInsurance = str_contains($text, 'versicher') || str_contains($text, 'insurance');

            // Set by hand: "no insurance" (a wrong guess) or the kind of insurance.
            if ($assigned === self::NONE) {
                continue;
            }

            if (is_string($assigned) && isset(self::types()[$assigned])) {
                $found[$assigned][] = $row;
                $monthly += $contract['monthly'];

                continue;
            }

            foreach (self::types() as $key => $type) {
                foreach ($type['words'] as $word) {
                    if (str_contains($text, $word) && ($isInsurance || $key !== 'car')) {
                        $found[$key][] = $row;
                        $monthly += $contract['monthly'];

                        continue 3;
                    }
                }
            }

            $isUnnecessary = false;

            foreach (self::oftenUnnecessary() as $word => $label) {
                if (str_contains($text, $word) && $isInsurance) {
                    $unnecessary[] = ['title' => $contract['item']->title, 'label' => $label, 'monthly' => $contract['monthly'], 'url' => $contract['url']];
                    $isUnnecessary = true;
                }
            }

            // An insurance the check could not place: the member can say which kind it is.
            if ($isInsurance && ! $isUnnecessary) {
                $candidates[] = ['item_id' => $row['item_id'], 'title' => $row['title'], 'provider' => $row['provider'], 'monthly' => $row['monthly'], 'url' => $row['url']];
            }
        }

        $situation = self::situation($user, $household?->id);
        $rows = [];

        foreach (self::types() as $key => $type) {
            $level = match ($key) {
                'liability' => 'essential',
                'disability' => $situation['earns'] ? 'essential' : 'recommended',
                'term_life' => $situation['loans'] ? 'essential' : 'optional',
                'building' => $situation['owns_home'] ? 'essential' : 'none',
                'contents' => 'recommended',
                'car' => $situation['vehicles'] ? 'essential' : 'none',
                default => 'optional',
            };

            if ($level === 'none' && ! isset($found[$key])) {
                continue;
            }

            $covered = isset($found[$key]) || ($key === 'car' && $situation['vehicle_insured']);
            $status = match (true) {
                $covered && count($found[$key] ?? []) > 1 => 'duplicate',
                $covered => 'ok',
                ($answers[$key] ?? null) === self::HAVE => 'ok',
                ($answers[$key] ?? null) === self::NOT_NEEDED => 'dismissed',
                default => 'missing',
            };

            $rows[] = ['key' => $key, 'label' => $type['label'], 'icon' => $type['icon'], 'why' => $type['why'], 'level' => $level, 'status' => $status, 'contracts' => $found[$key] ?? []];
        }

        $order = ['essential' => 0, 'recommended' => 1, 'optional' => 2, 'none' => 3];
        usort($rows, fn (array $a, array $b) => [$order[$a['level']], $a['label']] <=> [$order[$b['level']], $b['label']]);

        return [
            'found' => $found,
            'rows' => $rows,
            'unnecessary' => $unnecessary,
            'candidates' => $candidates,
            'monthly' => round($monthly, 2),
            'gaps' => count(array_filter($rows, fn (array $row) => $row['status'] === 'missing' && $row['level'] === 'essential')),
        ];
    }

    /**
     * Adds an insurance to the contracts (e.g. an occupational disability insurance), optionally
     * linked to its recurring payment from the bank bookings.
     */
    public static function create(User $user, int $householdId, string $type, string $provider, ?float $monthly, ?string $paymentKey = null): Item
    {
        $label = self::types()[$type]['label'];
        $item = SubscriptionMatcher::adopt($user, $householdId, [
            'name' => trim($label.($provider !== '' ? ' – '.$provider : '')),
            'monthly' => (float) ($monthly ?? 0),
            'category' => 'insurance',
        ]);

        $collection = Collection::with('properties')->find($item->collection_id);

        if ($collection && ($providerField = ContractOverview::property($collection, ['Provider']))) {
            $item->setPropertyValue($providerField, $provider !== '' ? mb_substr($provider, 0, 255) : null);
        }

        $item->content = ['insurance_type' => $type, 'payment_keys' => $paymentKey !== null ? [$paymentKey] : []] + ($item->content ?? []);
        $item->save();

        return $item;
    }

    /**
     * Sets the kind of insurance of a contract by hand (NONE: it is no insurance; null: guess again).
     */
    public static function assign(Item $contract, ?string $type): void
    {
        $content = $contract->content ?? [];

        if ($type === null) {
            unset($content['insurance_type']);
        } else {
            $content['insurance_type'] = $type;
        }

        $contract->update(['content' => $content]);
    }

    /**
     * @return array{earns: bool, owns_home: bool, loans: bool, vehicles: bool, vehicle_insured: bool}
     */
    private static function situation(User $user, ?int $householdId): array
    {
        $properties = $householdId ? RealEstateLink::properties($user, $householdId)->load('loans') : collect();
        $vehicles = $householdId ? Vehicle::query()->visibleTo($user, $householdId)->get() : collect();

        return [
            'earns' => $householdId && $user->canUseAddon(Addons::FINANCE)
                && Transaction::where('user_id', $user->id)->where('category', 'salary')->where('booked_on', '>=', today()->subMonths(6))->exists(),
            'owns_home' => $properties->isNotEmpty(),
            'loans' => $properties->contains(fn (RealEstate $realEstate) => $realEstate->loans->isNotEmpty()),
            'vehicles' => $vehicles->isNotEmpty(),
            'vehicle_insured' => $vehicles->isNotEmpty() && $vehicles->every(fn (Vehicle $vehicle) => $vehicle->insurance_yearly > 0),
        ];
    }
}
