<?php

namespace App\Support\Life;

use App\Models\ExpenseGroup;
use App\Models\ExternalLink;
use App\Models\Item;
use App\Models\Trip;
use App\Models\TripBooking;
use App\Models\TripParticipant;
use App\Models\TripStop;
use App\Support\NoteMarkdown;
use Illuminate\Support\Collection;

/**
 * Group trips and club outings: the texts for WhatsApp (general and per participant), the files,
 * day plan, ideas and pages of the trip folder and what each participant owes from the shared costs.
 */
class GroupTrip
{
    /**
     * Summary for everybody (WhatsApp group, e-mail), optionally ending with the folder link.
     */
    public static function summary(Trip $trip, ?string $link = null): string
    {
        $lines = ['*'.$trip->title.'*'];
        $lines[] = '📅 '.self::dates($trip).($trip->destination ? ' · 📍 '.$trip->destination : '');

        if ($trip->meeting_point) {
            $lines[] = '🚩 '.__('Meeting point').': '.$trip->meeting_point;
        }

        $program = self::program($trip);

        if ($program !== []) {
            $lines[] = '';
            $lines[] = '*'.__('Programme').'*';
            array_push($lines, ...$program);
        }

        if (trim((string) $trip->group_info) !== '') {
            $lines[] = '';
            $lines[] = trim((string) $trip->group_info);
        }

        $confirmed = $trip->participants->where('status', 'confirmed');

        if ($trip->participants->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '👥 '.trans_choice(':count participant confirmed|:count participants confirmed', $confirmed->count()).($confirmed->isNotEmpty() ? ': '.$confirmed->pluck('name')->implode(', ') : '');
        }

        if ($trip->organizer_contact) {
            $lines[] = '☎️ '.__('Questions to: :contact', ['contact' => $trip->organizer_contact]);
        }

        if ($link) {
            $lines[] = '';
            $lines[] = __('Trip folder: :link', ['link' => $link]);
        }

        return implode("\n", $lines);
    }

    /**
     * Personal message with the participant's own link (reply, personal details, own costs).
     */
    public static function personalMessage(Trip $trip, TripParticipant $participant, string $link): string
    {
        $lines = [__('Hello :name,', ['name' => $participant->name]), '', __('here is everything about “:trip” (:dates):', ['trip' => $trip->title, 'dates' => self::dates($trip)])];

        if (trim((string) $participant->personal_info) !== '') {
            $lines[] = '';
            $lines[] = trim((string) $participant->personal_info);
        }

        $lines[] = '';
        $lines[] = __('Your personal page – please let us know there whether you are coming: :link', ['link' => $link]);

        return implode("\n", $lines);
    }

    public static function whatsappUrl(string $text, ?string $number = null): string
    {
        return 'https://wa.me/'.($number ?? '').'?text='.rawurlencode($text);
    }

    public static function dates(Trip $trip): string
    {
        return $trip->starts_on->equalTo($trip->ends_on)
            ? $trip->starts_on->translatedFormat('D, d.m.Y')
            : $trip->starts_on->translatedFormat('D, d.m.').' – '.$trip->ends_on->translatedFormat('D, d.m.Y');
    }

    /**
     * Bookings with a time as programme lines ("Sa 08:00 – Bus to …").
     *
     * @return list<string>
     */
    public static function program(Trip $trip, bool $withReferences = false): array
    {
        $kinds = TripBooking::kinds();

        return $trip->bookings->map(function (TripBooking $booking) use ($kinds, $withReferences) {
            $when = $booking->starts_at ? $booking->starts_at->translatedFormat('D d.m. H:i').' – ' : '';
            $reference = $withReferences && $booking->reference ? ' ('.$booking->reference.')' : '';

            return '• '.$when.($kinds[$booking->kind]['icon'] ?? '').' '.$booking->title.($booking->provider ? ', '.$booking->provider : '').$reference;
        })->values()->all();
    }

    /**
     * Uploaded files of the trip that the folder offers for download.
     *
     * @return Collection<int, ExternalLink>
     */
    public static function files(Trip $trip): Collection
    {
        return ExternalLink::where('linkable_type', 'trip')->where('linkable_id', $trip->id)->where('kind', ExternalLink::FILE)->whereNotNull('path')->orderBy('title')->get();
    }

    /**
     * What each participant paid and owes in the trip's shared cost groups (matched by name).
     *
     * @return array<string, array{paid: float, owed: float, balance: float}> lower-case name => figures
     */
    public static function costs(Trip $trip): array
    {
        $costs = [];

        foreach (ExpenseGroup::where('trip_id', $trip->id)->where('visibility', Item::VISIBILITY_SHARED)->get() as $group) {
            foreach (ExpenseSplitter::balances($group) as $row) {
                $key = mb_strtolower(trim($row['member']->name));
                $costs[$key] = [
                    'paid' => round(($costs[$key]['paid'] ?? 0) + $row['paid'], 2),
                    'owed' => round(($costs[$key]['owed'] ?? 0) + $row['owed'], 2),
                    'balance' => round(($costs[$key]['balance'] ?? 0) + $row['balance'], 2),
                ];
            }
        }

        return $costs;
    }

    /**
     * What the trip folder shows besides information, programme and documents.
     *
     * @return array{plan: bool, ideas: bool, notes: bool, pages: list<int>, links: list<array{title: string, url: string}>}
     */
    public static function folderOptions(Trip $trip): array
    {
        $options = (array) ($trip->folder_options ?? []);

        return [
            'plan' => (bool) ($options['plan'] ?? true),
            'ideas' => (bool) ($options['ideas'] ?? true),
            'notes' => (bool) ($options['notes'] ?? false),
            'pages' => array_values(array_map('intval', (array) ($options['pages'] ?? []))),
            'links' => array_values(array_filter((array) ($options['links'] ?? []), fn ($link) => is_array($link) && isset($link['title'], $link['url']))),
        ];
    }

    /**
     * The day plan and the ideas as shown in the trip folder (notes only when switched on).
     *
     * @return array{days: list<array{label: string, entries: list<array{id: int, time: string, icon: string, title: string, location: string, maps: ?string, notes: string}>}>, ideas: list<array{id: int, time: string, icon: string, title: string, location: string, maps: ?string, notes: string}>}
     */
    public static function folderPlan(Trip $trip): array
    {
        $options = self::folderOptions($trip);
        $plan = TripItinerary::build($trip);
        $kinds = TripStop::kinds();
        $entry = fn (TripStop $stop) => [
            'id' => $stop->id,
            'time' => trim(($stop->time ? substr($stop->time, 0, 5) : '').($stop->end_time ? ' – '.substr($stop->end_time, 0, 5) : '')),
            'icon' => $kinds[$stop->kind]['icon'] ?? '📍',
            'title' => $stop->title,
            'location' => (string) $stop->location,
            'maps' => $stop->mapsUrl(),
            'notes' => $options['notes'] ? trim((string) $stop->notes) : '',
        ];
        $days = [];

        if ($options['plan']) {
            foreach ($plan['days'] as $day) {
                if ($day['stops']->isNotEmpty()) {
                    $days[] = [
                        'label' => __('Day :number', ['number' => $day['number']]).' · '.$day['date']->translatedFormat('D, j. M'),
                        'entries' => $day['stops']->map($entry)->values()->all(),
                    ];
                }
            }
        }

        return [
            'days' => $days,
            'ideas' => $options['ideas'] ? $plan['ideas']->map($entry)->values()->all() : [],
        ];
    }

    /**
     * Pages of the household chosen for the folder; private pages are never shown.
     *
     * @return Collection<int, Item>
     */
    public static function folderPages(Trip $trip): Collection
    {
        $ids = self::folderOptions($trip)['pages'];

        if ($ids === []) {
            return new Collection;
        }

        $pages = Item::query()->forHousehold($trip->household_id)->ofType(Item::TYPE_NOTE)
            ->where('items.visibility', Item::VISIBILITY_SHARED)->whereIn('items.id', $ids)->get();

        return $pages->sortBy(fn (Item $page) => array_search($page->id, $ids, true))->values();
    }

    /**
     * A page body as HTML for people outside the household: editor blocks and wiki links become plain text.
     */
    public static function pageHtml(Item $page): string
    {
        $body = preg_replace('/^\s*:::.*$/m', '', $page->body()) ?? '';
        $body = preg_replace_callback(Item::WIKI_LINK_PATTERN, fn (array $match) => trim(($match[2] ?? '') ?: $match[1]), $body) ?? $body;

        return NoteMarkdown::plainToHtml($body);
    }
}
