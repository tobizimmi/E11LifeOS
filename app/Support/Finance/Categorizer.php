<?php

namespace App\Support\Finance;

use App\Models\FinanceRule;

/**
 * Assigns a category to a booking: the user's own rules first, then a catalogue of
 * common German merchants and booking texts; the rest becomes "other".
 */
class Categorizer
{
    /**
     * Keywords (lower case, matched as substrings of counterparty + purpose), checked in this order.
     *
     * @var array<string, array<int, string>>
     */
    private const KEYWORDS = [
        'transfer' => ['umbuchung', 'übertrag', 'uebertrag', 'eigenes konto', 'kreditkartenabrechnung', 'kreditkarte ausgleich'],
        'salary' => ['gehalt', 'lohn', 'bezüge', 'bezuege', 'salary', 'besoldung', 'rente ', 'kindergeld', 'elterngeld'],
        'refunds' => ['erstattung', 'rückerstattung', 'rueckerstattung', 'gutschrift', 'refund'],
        'savings' => ['sparplan', 'depot', 'trade republic', 'scalable', 'comdirect depot', 'wertpapier', 'bausparkasse', 'tagesgeld', ' etf ', ' etf-'],
        'housing' => ['miete', 'hausgeld', 'nebenkosten', 'wohnungsbau', 'vermietung', 'grundsteuer', 'rundfunk', 'ard zdf', 'beitragsservice'],
        'energy' => ['stadtwerke', 'e.on', 'eon energie', 'vattenfall', 'enbw', 'rwe', 'strom', 'gas ', 'fernwärme', 'yello', 'lichtblick', 'tibber', 'octopus energy', 'naturstrom'],
        'telecom' => ['telekom', 'vodafone', 'o2 ', 'telefonica', '1&1', '1und1', 'congstar', 'drillisch', 'freenet', 'unitymedia', 'pyur', 'aldi talk', 'fraenk'],
        'insurance' => ['versicherung', 'allianz', 'huk', 'ergo', 'axa', 'devk', 'generali', 'r+v', 'debeka', 'signal iduna', 'lvm', 'provinzial', 'haftpflicht', 'krankenkasse', 'tk techniker', 'aok', 'barmer'],
        'subscriptions' => ['netflix', 'spotify', 'disney', 'amazon prime', 'prime video', 'apple.com/bill', 'itunes', 'youtube', 'dazn', 'sky deutschland', 'wow tv', 'audible', 'google storage', 'google one', 'icloud', 'microsoft 365', 'adobe', 'chatgpt', 'openai', 'patreon', 'fitx', 'mcfit', 'urban sports', 'zeit online', 'spiegel'],
        'groceries' => ['rewe', 'edeka', 'aldi', 'lidl', 'kaufland', 'netto', 'penny', 'norma', 'tegut', 'globus', 'real ', 'hit markt', 'marktkauf', 'denns', 'alnatura', 'bäckerei', 'baeckerei', 'metzgerei', 'dm drogerie', 'dm-drogerie', 'rossmann', 'müller drogerie', 'flink', 'getir', 'picnic'],
        'restaurants' => ['lieferando', 'wolt', 'uber eats', 'deliveroo', 'mcdonald', 'burger king', 'kfc', 'subway', 'starbucks', 'restaurant', 'pizzeria', 'ristorante', 'imbiss', 'café', 'cafe ', 'dean&david', 'vapiano', 'block house'],
        'mobility' => ['db vertrieb', 'deutsche bahn', 'bahn.de', 'shell', 'aral', 'esso', 'total', 'jet tankstelle', 'tankstelle', 'avia', 'bvg', 'mvg', 'hvv', 'kvb', 'rmv', 'vrs', 'deutschlandticket', 'uber ', 'bolt', 'free now', 'sixt', 'share now', 'flixbus', 'parkhaus', 'parken', 'adac', 'kfz-steuer', 'tier ', 'lime'],
        'health' => ['apotheke', 'arzt', 'praxis', 'zahnarzt', 'klinik', 'krankenhaus', 'physio', 'optiker', 'fielmann', 'docmorris', 'shop apotheke'],
        'shopping' => ['amazon', 'zalando', 'otto ', 'ebay', 'ikea', 'mediamarkt', 'media markt', 'saturn', 'h&m', 'primark', 'decathlon', 'about you', 'shein', 'temu', 'galaxus', 'kleinanzeigen', 'paypal', 'klarna', 'hornbach', 'obi ', 'bauhaus', 'toom'],
        'travel' => ['booking.com', 'airbnb', 'lufthansa', 'eurowings', 'ryanair', 'easyjet', 'condor', 'hotel', 'expedia', 'check24 reise', 'tui '],
        'leisure' => ['kino', 'cinemaxx', 'theater', 'eventim', 'ticketmaster', 'museum', 'schwimmbad', 'fitnessstudio', 'steam', 'playstation', 'nintendo', 'thalia', 'hugendubel'],
        'children' => ['kita', 'kindergarten', 'schule', 'hort', 'mytoys', 'babymarkt'],
        'pets' => ['fressnapf', 'zooplus', 'tierarzt', 'futterhaus'],
        'donations' => ['spende', 'unicef', 'greenpeace', 'caritas', 'diakonie', 'wwf', 'ärzte ohne grenzen'],
        'cash' => ['bargeld', 'geldautomat', 'bargeldauszahlung', 'atm ', 'cash '],
        'taxes' => ['finanzamt', 'steuer', 'bußgeld', 'bussgeld', 'gebühr stadt', 'stadtkasse', 'bürgeramt'],
        'bank_fees' => ['kontoführung', 'kontofuehrung', 'entgelt', 'zinsen', 'sollzins', 'dispo', 'kartengebühr', 'auslandseinsatz'],
    ];

    /**
     * @var array<string, string>|null
     */
    private ?array $rules = null;

    public function __construct(private int $userId) {}

    public function categorize(?string $counterparty, ?string $purpose, float $amount): string
    {
        $text = ' '.mb_strtolower(trim(($counterparty ?? '').' '.($purpose ?? ''))).' ';

        foreach ($this->rules() as $pattern => $category) {
            if (str_contains($text, $pattern)) {
                return $category;
            }
        }

        foreach (self::KEYWORDS as $category => $keywords) {
            // Income categories only for money coming in, spending categories only for money going out.
            $group = FinanceCategories::group($category);

            if (($group === 'income' && $amount < 0) || ($group !== 'income' && $group !== 'transfer' && $amount > 0 && $category !== 'savings')) {
                continue;
            }

            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $category;
                }
            }
        }

        return $amount >= 0 ? FinanceCategories::OTHER_INCOME : FinanceCategories::OTHER;
    }

    /**
     * @return array<string, string> Category by pattern, longest pattern first.
     */
    private function rules(): array
    {
        if ($this->rules === null) {
            $this->rules = FinanceRule::where('user_id', $this->userId)->pluck('category', 'pattern')->all();
            uksort($this->rules, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        }

        return $this->rules;
    }
}
