<?php

namespace App\Support\Life;

use App\Models\EmergencyEntry;
use App\Models\Trip;
use App\Models\TripBooking;
use App\Models\TripPackingItem;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Trip planning: packing list templates, costs against the budget and checks before leaving
 * (ID card and passport from the emergency folder, travel insurance).
 */
class TripPlanner
{
    /** Many countries require a passport valid this long after the return. */
    public const PASSPORT_BUFFER_MONTHS = 6;

    /**
     * German amounts ("1.500", "12,50", "1.234,56 €") as a number string for validation.
     */
    public static function number(string $value): string
    {
        $value = trim(str_replace(['€', ' '], '', $value));

        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $value)) {
            return str_replace('.', '', $value);
        }

        return str_contains($value, ',') ? str_replace(',', '.', str_replace('.', '', $value)) : $value;
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function categories(): array
    {
        return [
            'documents' => ['label' => __('Documents & money'), 'icon' => '📄'],
            'clothes' => ['label' => __('Clothes'), 'icon' => '👕'],
            'toiletries' => ['label' => __('Toiletries'), 'icon' => '🧴'],
            'health' => ['label' => __('Health'), 'icon' => '💊'],
            'electronics' => ['label' => __('Electronics'), 'icon' => '🔌'],
            'kids' => ['label' => __('Kids'), 'icon' => '🧸'],
            'other' => ['label' => __('Other'), 'icon' => '🎒'],
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string, items: list<array{0: string, 1: string}>}>
     */
    public static function templates(): array
    {
        $items = fn (array $list) => array_map(fn (array $item) => [__($item[0]), $item[1]], $list);

        return [
            'basics' => ['label' => __('Basics'), 'icon' => '🧳', 'items' => $items([
                ['ID card or passport', 'documents'], ['Health insurance card', 'documents'], ['Driving licence', 'documents'], ['Tickets & booking confirmations', 'documents'], ['Cash & cards', 'documents'], ['House keys', 'documents'],
                ['Phone & charger', 'electronics'], ['Power bank', 'electronics'], ['Headphones', 'electronics'],
                ['Toothbrush & toothpaste', 'toiletries'], ['Deodorant', 'toiletries'], ['Shower gel & shampoo', 'toiletries'],
                ['Personal medication', 'health'], ['First-aid kit', 'health'],
                ['Underwear', 'clothes'], ['Socks', 'clothes'], ['T-shirts', 'clothes'], ['Trousers', 'clothes'], ['Pyjamas', 'clothes'], ['Jacket', 'clothes'], ['Comfortable shoes', 'clothes'],
            ])],
            'beach' => ['label' => __('Beach'), 'icon' => '🏖️', 'items' => $items([
                ['Swimwear', 'clothes'], ['Sun hat', 'clothes'], ['Flip-flops', 'clothes'], ['Sun cream', 'toiletries'], ['After-sun lotion', 'toiletries'], ['Insect repellent', 'health'], ['Sunglasses', 'other'], ['Beach towel', 'other'],
            ])],
            'winter' => ['label' => __('Winter & ski'), 'icon' => '⛷️', 'items' => $items([
                ['Ski jacket & trousers', 'clothes'], ['Thermal underwear', 'clothes'], ['Gloves', 'clothes'], ['Hat & scarf', 'clothes'], ['Warm socks', 'clothes'], ['Ski goggles', 'other'], ['Ski pass', 'documents'], ['Lip balm with SPF', 'toiletries'],
            ])],
            'city' => ['label' => __('City trip'), 'icon' => '🏙️', 'items' => $items([
                ['Day backpack', 'other'], ['Umbrella', 'other'], ['Reusable water bottle', 'other'], ['Offline map', 'electronics'], ['Comfortable walking shoes', 'clothes'],
            ])],
            'flight' => ['label' => __('Flight'), 'icon' => '✈️', 'items' => $items([
                ['Liquids bag (100 ml)', 'toiletries'], ['Neck pillow', 'other'], ['Travel adapter', 'electronics'], ['Snacks for the journey', 'other'], ['Pen for entry forms', 'documents'],
            ])],
            'business' => ['label' => __('Business travel'), 'icon' => '💼', 'items' => $items([
                ['Laptop & charger', 'electronics'], ['Business clothes', 'clothes'], ['Business cards', 'documents'], ['Presentation & documents', 'documents'], ['Travel adapter', 'electronics'],
            ])],
            'kids' => ['label' => __('With kids'), 'icon' => '🧸', 'items' => $items([
                ['Child ID or passport', 'documents'], ['Nappies & wipes', 'kids'], ['Baby food & snacks', 'kids'], ['Favourite cuddly toy', 'kids'], ['Toys & books for the journey', 'kids'], ['Buggy or baby carrier', 'kids'], ['Spare clothes', 'kids'], ['Children\'s medicine & thermometer', 'health'],
            ])],
            'camping' => ['label' => __('Camping'), 'icon' => '⛺', 'items' => $items([
                ['Tent', 'other'], ['Sleeping bag', 'other'], ['Sleeping mat', 'other'], ['Camping stove & gas', 'other'], ['Headlamp', 'electronics'], ['Pocket knife', 'other'], ['Dishes & cutlery', 'other'], ['Rope & pegs', 'other'],
            ])],
        ];
    }

    /**
     * Adds the items of a template that are not on the list yet; returns how many were added.
     */
    public static function applyTemplate(Trip $trip, string $template, ?string $person = null): int
    {
        $person = $person !== null && trim($person) !== '' ? trim($person) : null;
        $existing = $trip->packingItems()->get()->map(fn (TripPackingItem $item) => mb_strtolower($item->label).'|'.mb_strtolower((string) $item->person))->all();
        $position = (int) $trip->packingItems()->max('position');
        $added = 0;

        foreach (self::templates()[$template]['items'] ?? [] as [$label, $category]) {
            if (in_array(mb_strtolower($label).'|'.mb_strtolower((string) $person), $existing, true)) {
                continue;
            }

            $trip->packingItems()->create(['label' => $label, 'category' => $category, 'person' => $person, 'position' => ++$position]);
            $added++;
        }

        $trip->update(['packing_templates' => array_values(array_unique([...$trip->packing_templates ?? [], $template]))]);

        return $added;
    }

    /**
     * @return array{
     *     days_until: int, nights: int, costs: float, paid: float, open: float, budget_left: ?float, budget_percent: ?float,
     *     by_kind: array<string, float>, packed: int, packing_total: int, warnings: list<array{level: string, text: string, url: ?string}>
     * }
     */
    public static function overview(Trip $trip, User $user): array
    {
        $bookings = $trip->bookings;
        $costs = round((float) $bookings->sum('cost'), 2);
        $paid = round((float) $bookings->where('paid', true)->sum('cost'), 2);
        $items = $trip->packingItems;

        return [
            'days_until' => (int) today()->diffInDays($trip->starts_on, false),
            'nights' => $trip->nights(),
            'costs' => $costs,
            'paid' => $paid,
            'open' => round($costs - $paid, 2),
            'budget_left' => $trip->budget !== null ? round($trip->budget - $costs, 2) : null,
            'budget_percent' => $trip->budget ? round($costs / $trip->budget * 100, 1) : null,
            'by_kind' => $bookings->groupBy('kind')->map(fn ($group) => round((float) $group->sum('cost'), 2))->filter()->sortDesc()->all(),
            'packed' => $items->where('packed', true)->count(),
            'packing_total' => $items->count(),
            'warnings' => $trip->isPast() ? [] : self::warnings($trip, $user),
        ];
    }

    /**
     * @return list<array{level: string, text: string, url: ?string}>
     */
    private static function warnings(Trip $trip, User $user): array
    {
        $warnings = [];
        $documents = EmergencyEntry::where('user_id', $user->id)->whereIn('kind', ['id_card', 'passport'])->whereNotNull('expires_on')->get();

        foreach ($documents as $document) {
            $expires = Carbon::parse($document->expires_on);

            if ($expires->lt($trip->ends_on)) {
                $warnings[] = ['level' => 'critical', 'text' => __(':document expires on :date – before the end of the trip.', ['document' => $document->title, 'date' => $expires->format('d.m.Y')]), 'url' => route('emergency.index')];
            } elseif ($document->kind === 'passport' && $expires->lt($trip->ends_on->copy()->addMonths(self::PASSPORT_BUFFER_MONTHS))) {
                $warnings[] = ['level' => 'warning', 'text' => __(':document is valid for less than six months after the return (:date) – many countries require that.', ['document' => $document->title, 'date' => $expires->format('d.m.Y')]), 'url' => route('emergency.index')];
            }
        }

        if ($documents->isEmpty()) {
            $warnings[] = ['level' => 'hint', 'text' => __('Add ID card and passport with their expiry date to the emergency folder – the trip then checks whether they are still valid.'), 'url' => route('emergency.index')];
        }

        if (! $trip->bookings->contains(fn (TripBooking $booking) => $booking->kind === 'insurance')) {
            $warnings[] = ['level' => 'hint', 'text' => __('Travelling abroad? Check your travel health insurance and consider trip cancellation insurance.'), 'url' => route('provision.index')];
        }

        return $warnings;
    }
}
