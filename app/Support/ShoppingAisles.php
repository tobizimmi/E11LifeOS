<?php

namespace App\Support;

use App\Models\ShoppingListEntry;

/**
 * Supermarket sections in walking order, and a guess of the section from the item name. A
 * section the household chose for an item before wins over the guess.
 */
class ShoppingAisles
{
    public const OTHER = 'other';

    /**
     * Section => keywords (German and English, matched as part of the lower-case name).
     *
     * @var array<string, list<string>>
     */
    private const KEYWORDS = [
        'produce' => ['apfel', 'äpfel', 'banane', 'birne', 'orange', 'zitrone', 'limette', 'traube', 'beere', 'kiwi', 'mango', 'ananas', 'melone', 'obst', 'gemüse', 'salat', 'gurke', 'tomate', 'paprika', 'zwiebel', 'knoblauch', 'kartoffel', 'möhre', 'karotte', 'zucchini', 'brokkoli', 'blumenkohl', 'spinat', 'lauch', 'porree', 'pilz', 'champignon', 'avocado', 'ingwer', 'kräuter', 'petersilie', 'basilikum', 'schnittlauch', 'kohl', 'sellerie', 'radieschen', 'kürbis', 'aubergine', 'rucola', 'apple', 'banana', 'lemon', 'onion', 'garlic', 'potato', 'carrot', 'tomato', 'lettuce'],
        'bakery' => ['brot', 'brötchen', 'semmel', 'baguette', 'toast', 'croissant', 'brezel', 'laugen', 'kuchen', 'bread', 'bun'],
        'dairy' => ['milch', 'joghurt', 'jogurt', 'quark', 'käse', 'butter', 'sahne', 'schmand', 'frischkäse', 'mozzarella', 'feta', 'parmesan', 'ei', 'eier', 'margarine', 'kefir', 'skyr', 'pudding', 'milk', 'cheese', 'yogurt', 'cream', 'egg'],
        'meat' => ['fleisch', 'hack', 'hähnchen', 'huhn', 'pute', 'rind', 'schwein', 'wurst', 'schinken', 'salami', 'speck', 'bacon', 'steak', 'schnitzel', 'fisch', 'lachs', 'thunfisch', 'garnele', 'chicken', 'beef', 'pork', 'fish', 'salmon', 'ham'],
        'frozen' => ['tiefkühl', 'tk-', 'tk ', 'eis', 'pizza', 'pommes', 'frozen'],
        'pantry' => ['nudel', 'pasta', 'spaghetti', 'reis', 'mehl', 'zucker', 'haferflocken', 'müsli', 'cornflakes', 'linsen', 'bohnen', 'kichererbsen', 'dose', 'konserve', 'tomatenmark', 'passata', 'öl', 'olivenöl', 'rapsöl', 'sonnenblumenöl', 'essig', 'honig', 'marmelade', 'nutella', 'erdnussbutter', 'brühe', 'couscous', 'bulgur', 'rice', 'flour', 'sugar', 'oil', 'oats'],
        'spices' => ['salz', 'pfeffer', 'gewürz', 'paprikapulver', 'curry', 'zimt', 'vanille', 'backpulver', 'hefe', 'senf', 'ketchup', 'mayo', 'soße', 'sauce', 'sojasauce', 'salt', 'pepper', 'spice'],
        'snacks' => ['schokolade', 'chips', 'keks', 'gummibär', 'süßigkeit', 'bonbon', 'nüsse', 'nuss', 'cracker', 'riegel', 'popcorn', 'chocolate', 'cookie', 'snack'],
        'drinks' => ['wasser', 'saft', 'cola', 'limo', 'bier', 'wein', 'sekt', 'kaffee', 'tee', 'früchtetee', 'kräutertee', 'pfefferminztee', 'kakao', 'schorle', 'sprudel', 'water', 'juice', 'beer', 'wine', 'coffee', 'tea'],
        'household' => ['klopapier', 'toilettenpapier', 'küchenrolle', 'taschentuch', 'spülmittel', 'waschmittel', 'weichspüler', 'müllbeutel', 'putzmittel', 'reiniger', 'schwamm', 'zahnpasta', 'zahnbürste', 'shampoo', 'duschgel', 'seife', 'deo', 'creme', 'windel', 'batterie', 'alufolie', 'frischhaltefolie', 'backpapier', 'tabs', 'toilet paper', 'detergent', 'soap'],
    ];

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function all(): array
    {
        return [
            'produce' => ['label' => __('Fruit & vegetables'), 'icon' => '🥦'],
            'bakery' => ['label' => __('Bakery'), 'icon' => '🥖'],
            'dairy' => ['label' => __('Dairy & eggs'), 'icon' => '🧀'],
            'meat' => ['label' => __('Meat & fish'), 'icon' => '🥩'],
            'frozen' => ['label' => __('Frozen'), 'icon' => '🧊'],
            'pantry' => ['label' => __('Pantry'), 'icon' => '🥫'],
            'spices' => ['label' => __('Spices & baking'), 'icon' => '🧂'],
            'snacks' => ['label' => __('Sweets & snacks'), 'icon' => '🍫'],
            'drinks' => ['label' => __('Drinks'), 'icon' => '🥤'],
            'household' => ['label' => __('Household & drugstore'), 'icon' => '🧻'],
            self::OTHER => ['label' => __('Other'), 'icon' => '🛒'],
        ];
    }

    public static function guess(string $label, ?int $householdId = null): string
    {
        $name = mb_strtolower(trim($label));

        if ($householdId) {
            $known = ShoppingListEntry::where('household_id', $householdId)->whereRaw('LOWER(label) = ?', [$name])->whereNotNull('aisle')->latest('updated_at')->value('aisle');

            if ($known) {
                return $known;
            }
        }

        // Longest keyword wins, so "tomatenmark" (pantry) beats "tomate" (produce).
        $best = self::OTHER;
        $bestLength = 0;

        foreach (self::KEYWORDS as $aisle => $keywords) {
            foreach ($keywords as $keyword) {
                $length = mb_strlen($keyword);

                if ($length > $bestLength && self::matches($name, $keyword)) {
                    [$best, $bestLength] = [$aisle, $length];
                }
            }
        }

        return $best;
    }

    /**
     * Short keywords ("ei", "eis", "tee") only as whole words, longer ones anywhere.
     */
    private static function matches(string $name, string $keyword): bool
    {
        return mb_strlen($keyword) <= 3
            ? (bool) preg_match('/(^|[^\p{L}])'.preg_quote($keyword, '/').'($|[^\p{L}])/u', $name)
            : str_contains($name, $keyword);
    }
}
