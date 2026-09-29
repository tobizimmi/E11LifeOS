<?php

namespace App\Support\Life;

use App\Models\BankAccount;
use App\Models\EmergencyEntry;
use App\Models\NetWorthItem;
use App\Models\Portfolio;
use App\Models\RealEstate;
use App\Models\RealEstateLoan;
use App\Models\User;
use App\Support\Addons;
use App\Support\ContractOverview;
use App\Support\RealEstate\RealEstateLink;
use Illuminate\Support\Collection;

/**
 * The emergency folder: what relatives need if something happens – documents and IDs,
 * directives, contacts, digital life and wishes (entered by the member, with a checklist per
 * section) plus an overview of accounts, depots, properties, loans and contracts that is read
 * from the other modules.
 */
class EmergencyFolder
{
    /**
     * @return array<string, array{label: string, icon: string, hint: string, presets: array<string, array{label: string, hint: string, expires: bool}>}>
     */
    public static function sections(): array
    {
        return [
            'documents' => [
                'label' => __('Documents & IDs'), 'icon' => '🪪',
                'hint' => __('Where the originals are and until when they are valid – you get a reminder before they expire.'),
                'presets' => [
                    'id_card' => ['label' => __('ID card'), 'hint' => __('Number and place of issue'), 'expires' => true],
                    'passport' => ['label' => __('Passport'), 'hint' => __('Number and place of issue'), 'expires' => true],
                    'driving_licence' => ['label' => __('Driving licence'), 'hint' => __('Classes; new card format expires after 15 years'), 'expires' => true],
                    'health_card' => ['label' => __('Health insurance card'), 'hint' => __('Health insurer and insurance number'), 'expires' => true],
                    'birth_certificate' => ['label' => __('Birth certificate'), 'hint' => '', 'expires' => false],
                    'marriage_certificate' => ['label' => __('Marriage certificate / family register'), 'hint' => '', 'expires' => false],
                    'social_security' => ['label' => __('Social security & pension number'), 'hint' => __('Pension insurance number, statutory pension insurer'), 'expires' => false],
                    'tax_id' => ['label' => __('Tax ID & tax office'), 'hint' => __('Tax identification number, responsible tax office'), 'expires' => false],
                ],
            ],
            'directives' => [
                'label' => __('Directives & will'), 'icon' => '📜',
                'hint' => __('Without a power of attorney even your spouse may not decide for you. Note where the signed originals are.'),
                'presets' => [
                    'power_of_attorney' => ['label' => __('Lasting power of attorney'), 'hint' => __('Who is authorised, registered with the central register?'), 'expires' => false],
                    'patient_decree' => ['label' => __('Living will'), 'hint' => __('Medical wishes if you cannot decide yourself'), 'expires' => false],
                    'care_directive' => ['label' => __('Care directive'), 'hint' => __('Who should become your legal guardian'), 'expires' => false],
                    'will' => ['label' => __('Will / inheritance contract'), 'hint' => __('Handwritten or deposited with the probate court?'), 'expires' => false],
                    'bank_power_of_attorney' => ['label' => __('Bank powers of attorney'), 'hint' => __('Account powers valid beyond death'), 'expires' => false],
                    'organ_donor_card' => ['label' => __('Organ donor card'), 'hint' => __('Decision and where the card is'), 'expires' => false],
                ],
            ],
            'contacts' => [
                'label' => __('Important contacts'), 'icon' => '☎️',
                'hint' => __('Who should be called first and who knows your affairs.'),
                'presets' => [
                    'emergency_contact' => ['label' => __('Emergency contact'), 'hint' => __('Name, relation, phone'), 'expires' => false],
                    'family_doctor' => ['label' => __('Family doctor'), 'hint' => __('Practice, phone'), 'expires' => false],
                    'employer' => ['label' => __('Employer'), 'hint' => __('Contact in HR'), 'expires' => false],
                    'tax_advisor' => ['label' => __('Tax advisor'), 'hint' => '', 'expires' => false],
                    'notary_lawyer' => ['label' => __('Notary / lawyer'), 'hint' => '', 'expires' => false],
                    'insurance_broker' => ['label' => __('Insurance broker / agent'), 'hint' => '', 'expires' => false],
                ],
            ],
            'digital' => [
                'label' => __('Digital life'), 'icon' => '💻',
                'hint' => __('Never store passwords here – only where they can be found (e.g. the emergency kit of your password manager).'),
                'presets' => [
                    'password_manager' => ['label' => __('Password manager'), 'hint' => __('Which one and where the emergency kit is'), 'expires' => false],
                    'phone_unlock' => ['label' => __('Smartphone & computer'), 'hint' => __('Where the unlock code is kept'), 'expires' => false],
                    'email_accounts' => ['label' => __('E-mail accounts'), 'hint' => __('Main addresses – they reset every other account'), 'expires' => false],
                    'online_accounts' => ['label' => __('Social media & online accounts'), 'hint' => __('Delete, memorialise or hand over?'), 'expires' => false],
                ],
            ],
            'wishes' => [
                'label' => __('Wishes & care'), 'icon' => '🕯️',
                'hint' => __('What matters to you and who takes care of whom.'),
                'presets' => [
                    'children_guardian' => ['label' => __('Guardian for the children'), 'hint' => __('Wish, also recorded in the will?'), 'expires' => false],
                    'pets_care' => ['label' => __('Care for pets'), 'hint' => __('Who takes the animals, vet'), 'expires' => false],
                    'notify_people' => ['label' => __('People to inform'), 'hint' => __('Friends, clubs, colleagues'), 'expires' => false],
                    'funeral_wishes' => ['label' => __('Funeral wishes'), 'hint' => __('Type of funeral, funeral provision, music'), 'expires' => false],
                ],
            ],
        ];
    }

    /**
     * @return array{done: int, total: int, percent: int, sections: array<string, array{done: int, total: int}>}
     */
    public static function progress(Collection $entries): array
    {
        $sections = [];

        foreach (self::sections() as $key => $section) {
            $kinds = $entries->where('section', $key)->pluck('kind')->unique();
            $sections[$key] = ['done' => $kinds->intersect(array_keys($section['presets']))->count(), 'total' => count($section['presets'])];
        }

        $done = array_sum(array_column($sections, 'done'));
        $total = array_sum(array_column($sections, 'total'));

        return ['done' => $done, 'total' => $total, 'percent' => $total > 0 ? (int) round($done / $total * 100) : 0, 'sections' => $sections];
    }

    /**
     * Accounts, depots, properties, loans, contracts and other items from the modules.
     *
     * @return list<array{key: string, label: string, icon: string, rows: list<array{label: string, detail: ?string}>}>
     */
    public static function overview(User $user): array
    {
        $household = $user->currentHousehold();
        $groups = [];

        if ($household && $user->canUseAddon(Addons::FINANCE)) {
            $groups[] = self::group('accounts', __('Bank accounts'), '🏦', BankAccount::where('user_id', $user->id)->where('household_id', $household->id)->orderBy('name')->get()
                ->map(fn (BankAccount $account) => ['label' => $account->name, 'detail' => $account->iban])->all());
        }

        if ($user->canUseAddon(Addons::PORTFOLIO)) {
            $groups[] = self::group('portfolios', __('Securities accounts'), '📈', Portfolio::where('user_id', $user->id)->orderBy('name')->get()
                ->map(fn (Portfolio $portfolio) => ['label' => $portfolio->name, 'detail' => $portfolio->broker])->all());
        }

        if ($household) {
            $properties = RealEstateLink::properties($user, $household->id)->load('loans');
            $groups[] = self::group('properties', __('Real estate'), '🏠', $properties->map(fn (RealEstate $realEstate) => ['label' => $realEstate->name, 'detail' => $realEstate->address() ?: null])->all());
            $groups[] = self::group('loans', __('Loans'), '📉', $properties->flatMap(fn (RealEstate $realEstate) => $realEstate->loans->map(fn (RealEstateLoan $loan) => [
                'label' => trim(($loan->lender ?: __('Loan')).' · '.$realEstate->name),
                'detail' => number_format((float) $loan->amount, 2, ',', '.').' €',
            ]))->all());
            $groups[] = self::group('contracts', __('Contracts & insurance'), '📑', collect(ContractOverview::for($user, $household->id)['contracts'])
                ->map(fn (array $contract) => ['label' => $contract['item']->title, 'detail' => implode(' · ', array_filter([$contract['provider'], $contract['customer_number'] ? __('No. :number', ['number' => $contract['customer_number']]) : null]))])->all());
        }

        $groups[] = self::group('other', __('Other assets and debts'), '💎', NetWorthItem::where('user_id', $user->id)->orderBy('position')->get()
            ->map(fn (NetWorthItem $item) => ['label' => $item->name, 'detail' => ($item->isLiability() ? __('Debt') : __('Asset')).' · '.number_format((float) $item->value, 2, ',', '.').' €'])->all());

        return array_values(array_filter($groups, fn (array $group) => $group['rows'] !== []));
    }

    /**
     * Documents that expired or expire within their reminder window.
     *
     * @return Collection<int, EmergencyEntry>
     */
    public static function expiring(User $user): Collection
    {
        return EmergencyEntry::where('user_id', $user->id)->whereNotNull('expires_on')->orderBy('expires_on')->get()
            ->filter(fn (EmergencyEntry $entry) => $entry->isExpired() || $entry->expiresSoon())->values();
    }

    /**
     * @param  list<array{label: string, detail: ?string}>  $rows
     * @return array{key: string, label: string, icon: string, rows: list<array{label: string, detail: ?string}>}
     */
    private static function group(string $key, string $label, string $icon, array $rows): array
    {
        return ['key' => $key, 'label' => $label, 'icon' => $icon, 'rows' => array_values($rows)];
    }
}
