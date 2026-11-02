<?php

namespace App\Support;

/**
 * Generates a weekly meal plan with Claude from the user's preferences (budget, store, diet,
 * allergens, disliked ingredients, cuisines, kitchen appliances, people, days and meals). It
 * returns invented recipes that can be added to the cookbook, a single chosen supermarket for the
 * week and a consolidated shopping list with estimated prices – optionally steered by the current
 * offers the user pasted or photographed.
 */
class MealPlanAi extends StructuredAi
{
    protected const FEATURE = AiFeatures::RECIPES;

    public const MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

    /**
     * @param  array<string, mixed>  $prefs  normalised preferences (see {@see self::describe()})
     * @param  array{data: string, mediaType: string}|null  $offerImage  a photo/PDF of the current offers
     * @return array<string, mixed>
     */
    public function generate(array $prefs, ?string $offersText = null, ?array $offerImage = null): array
    {
        $content = [];

        if ($offerImage !== null) {
            $isPdf = $offerImage['mediaType'] === 'application/pdf';
            $content[] = $isPdf
                ? ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($offerImage['data'])]]
                : ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $offerImage['mediaType'], 'data' => base64_encode($offerImage['data'])]];
            $content[] = ['type' => 'text', 'text' => 'Das ist der aktuelle Angebotsprospekt des Supermarkts. Lies die reduzierten Artikel heraus und bevorzuge sie im Plan.'];
        }

        if ($offersText !== null && trim($offersText) !== '') {
            $content[] = ['type' => 'text', 'text' => "Aktuelle Angebote des Supermarkts (bevorzugt verwenden):\n".trim($offersText)];
        }

        $content[] = ['type' => 'text', 'text' => $this->describe($prefs)];

        return $this->ask($content, $this->schema(), maxTokens: 20000, system: $this->system());
    }

    private function system(): string
    {
        return 'Du bist ein deutscher Ernährungs- und Haushaltsplaner. Du erstellst realistische, alltagstaugliche Wochenpläne '
            .'mit vollständigen Rezepten und einer konsolidierten Einkaufsliste mit realistischen deutschen Supermarktpreisen in Euro. '
            .'Halte dich strikt an Allergien und abgelehnte Zutaten. Antworte ausschließlich im geforderten JSON-Schema.';
    }

    /**
     * @param  array<string, mixed>  $prefs
     */
    private function describe(array $prefs): string
    {
        $list = function (string $key) use ($prefs): ?string {
            $value = $prefs[$key] ?? null;

            return is_array($value) && $value !== [] ? implode(', ', array_map('strval', $value)) : null;
        };

        $people = (int) ($prefs['people'] ?? 2);
        $days = (int) ($prefs['days_per_week'] ?? 5);
        $meals = $list('meals') ?? 'dinner';

        $lines = [
            "Erstelle einen Wochenplan für $people Person(en) über $days Tage.",
            "Mahlzeiten pro Tag: $meals (meal_type je Eintrag entsprechend setzen: breakfast, lunch, dinner, snack).",
            'Skaliere die Zutatenmengen jedes Rezepts auf '.$people.' Portion(en).',
        ];

        $budget = isset($prefs['weekly_budget']) ? (float) $prefs['weekly_budget'] : 0.0;
        if ($budget > 0) {
            $budgetText = number_format($budget, 2, ',', '.');
            $lines[] = "Wochenbudget: maximal $budgetText € für den gesamten Einkauf. Bleibe darunter und setze within_budget entsprechend.";
        }

        if ($list('supermarkets')) {
            $lines[] = 'Bevorzugte Supermärkte: '.$list('supermarkets').'. Wähle für die ganze Woche GENAU EINEN Markt (Feld supermarket) und plane den Einkauf dort.';
        } else {
            $lines[] = 'Wähle einen gängigen deutschen Supermarkt (Feld supermarket) und plane den Einkauf für die ganze Woche dort.';
        }

        $plz = (string) ($prefs['postal_code'] ?? '');
        if ($plz !== '') {
            $lines[] = "Region/PLZ: $plz (für Verfügbarkeit und Preisniveau berücksichtigen).";
        }

        $diet = (string) ($prefs['diet'] ?? '');
        if ($diet !== '') {
            $lines[] = "Ernährungsweise: $diet – alle Rezepte müssen dazu passen.";
        }

        if ($list('allergens')) {
            $lines[] = 'STRIKT vermeiden (Allergien): '.$list('allergens').'. Keine Zutat darf diese enthalten.';
        }

        if ($list('disliked_ingredients')) {
            $lines[] = 'Nicht verwenden (Abneigungen): '.$list('disliked_ingredients').'.';
        }

        if ($list('dish_types')) {
            $lines[] = 'Gewünschte Art der Gerichte: '.$list('dish_types').'.';
        }

        if ($list('cuisines')) {
            $lines[] = 'Bevorzugte Küchen: '.$list('cuisines').'.';
        }

        if ($list('appliances')) {
            $lines[] = 'Verfügbare Küchengeräte: '.$list('appliances').'. Plane nur Rezepte, die damit zubereitbar sind.';
        }

        if (! empty($prefs['meal_prep'])) {
            $lines[] = 'MEAL-PREP-Modus: bevorzuge vorkochbare, gut lagerbare Gerichte, die sich für mehrere Portionen und Aufbewahrung eignen; setze meal_prep=true bei passenden Rezepten.';
        }

        $lines[] = 'Erzeuge für jedes geplante Mahlzeiten-Slot ein vollständiges Rezept (Zutaten mit Mengen, Schritt-für-Schritt-Anleitung, Zubereitungs-/Kochzeit, Schwierigkeit, Kalorien pro Portion).';
        $lines[] = 'Fasse am Ende ALLE Zutaten aller Rezepte zu EINER Einkaufsliste zusammen (Mengen addieren, nach Kategorie gruppieren, geschätzter Preis in Euro, on_offer=true wenn ein Angebot genutzt wurde).';

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supermarket' => ['type' => 'string'],
                'currency' => ['type' => 'string'],
                'estimated_total' => ['type' => 'number'],
                'within_budget' => ['type' => 'boolean'],
                'notes' => ['type' => 'string'],
                'recipes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'day' => ['type' => 'integer'],
                            'meal_type' => ['type' => 'string', 'enum' => ['breakfast', 'lunch', 'dinner', 'snack']],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'servings' => ['type' => 'integer'],
                            'prep_time' => ['type' => 'integer'],
                            'cook_time' => ['type' => 'integer'],
                            'difficulty' => ['type' => 'string', 'enum' => ['easy', 'medium', 'hard']],
                            'calories' => ['type' => 'integer'],
                            'meal_prep' => ['type' => 'boolean'],
                            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'ingredients' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => ['name' => ['type' => 'string'], 'amount' => ['type' => 'string']],
                                    'required' => ['name'],
                                ],
                            ],
                            'instructions' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['day', 'meal_type', 'title', 'ingredients', 'instructions'],
                    ],
                ],
                'shopping_list' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'amount' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'estimated_price' => ['type' => 'number'],
                            'on_offer' => ['type' => 'boolean'],
                        ],
                        'required' => ['name'],
                    ],
                ],
            ],
            'required' => ['supermarket', 'recipes', 'shopping_list'],
        ];
    }
}
