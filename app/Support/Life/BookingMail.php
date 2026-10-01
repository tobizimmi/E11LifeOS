<?php

namespace App\Support\Life;

use App\Support\Mail\MimeMessage;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads booking confirmations (a saved .eml mail or pasted text) into draft trip bookings:
 * first the schema.org reservation data many airlines, railways and booking portals embed,
 * otherwise booking number, dates, price and type found in the text. No AI involved.
 */
class BookingMail
{
    /** Labels in front of a booking number. */
    private const REFERENCE_LABELS = 'buchungsnummer|buchungscode|buchungs-nr\.?|bestätigungsnummer|bestätigungscode|reservierungsnummer|auftragsnummer|vorgangsnummer|booking (?:reference|number|code|id)|confirmation (?:number|code)|reservation (?:number|code)|pnr|filekey|ticketnummer';

    /** Words that tell the type of booking, checked in this order. */
    private const KIND_WORDS = [
        'flight' => ['flug', 'flight', 'boarding', 'airline', 'lufthansa', 'eurowings', 'ryanair', 'easyjet', 'condor', 'tuifly'],
        'train' => ['deutsche bahn', 'db fernverkehr', 'bahn.de', 'zugbindung', 'ice ', 'sitzplatzreservierung', 'öbb', 'sbb', 'trenitalia', 'sncf', 'train'],
        'bus' => ['flixbus', 'fernbus', 'fähre', 'ferry'],
        'car' => ['mietwagen', 'rental car', 'sixt', 'europcar', 'avis', 'hertz', 'enterprise', 'abholung des fahrzeugs'],
        'hotel' => ['hotel', 'unterkunft', 'booking.com', 'airbnb', 'ferienwohnung', 'check-in', 'anreise', 'übernachtung', 'accommodation'],
        'activity' => ['ticket', 'eintritt', 'tour', 'getyourguide', 'konzert', 'event'],
        'insurance' => ['reiseversicherung', 'reiserücktritt', 'auslandskrankenversicherung', 'travel insurance'],
    ];

    /**
     * Draft bookings found in the mail (at least one with the subject as title, unless it is empty).
     *
     * @return list<array{kind: string, title: string, provider: string, starts_at: string, ends_at: string, reference: string, amount: string, currency: string}>
     */
    public static function read(string $raw): array
    {
        $raw = mb_substr($raw, 0, 2_000_000);
        $mail = self::isMime($raw) ? self::parseMime($raw) : ['subject' => '', 'html' => '', 'text' => $raw];
        $bookings = self::fromSchema($mail['html'] !== '' ? $mail['html'] : $mail['text']);

        if ($bookings !== []) {
            return $bookings;
        }

        $text = $mail['text'] !== '' ? $mail['text'] : MimeMessage::htmlToText($mail['html']);
        $draft = self::fromText($mail['subject'], $text);

        return $draft ? [$draft] : [];
    }

    private static function isMime(string $raw): bool
    {
        return (bool) preg_match('/^(?:[\w-]+:.*\R(?:[ \t].*\R)*)*?(?:Subject|Content-Type|From):/mi', substr($raw, 0, 4000));
    }

    /**
     * Subject, HTML and text body of a MIME mail.
     *
     * @return array{subject: string, html: string, text: string}
     */
    public static function parseMime(string $raw): array
    {
        $message = MimeMessage::parse($raw);

        return ['subject' => $message->subject, 'html' => $message->html, 'text' => $message->text];
    }

    /**
     * Draft bookings from an already parsed mail (used by the mail intake).
     *
     * @return list<array{kind: string, title: string, provider: string, starts_at: string, ends_at: string, reference: string, amount: string, currency: string}>
     */
    public static function fromMessage(MimeMessage $message): array
    {
        return self::fromSchema($message->html !== '' ? $message->html : $message->text);
    }

    /**
     * Reservations from schema.org JSON-LD blocks.
     *
     * @return list<array{kind: string, title: string, provider: string, starts_at: string, ends_at: string, reference: string, amount: string, currency: string}>
     */
    public static function fromSchema(string $html): array
    {
        preg_match_all('~<script[^>]+application/ld\+json[^>]*>(.*?)</script>~is', $html, $blocks);
        $bookings = [];

        foreach ($blocks[1] as $json) {
            $data = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);

            foreach (self::reservations(is_array($data) ? $data : []) as $reservation) {
                if ($booking = self::fromReservation($reservation)) {
                    $bookings[] = $booking;
                }
            }
        }

        return array_slice($bookings, 0, 10);
    }

    /**
     * @param  array<mixed>  $data
     * @return list<array<string, mixed>>
     */
    private static function reservations(array $data): array
    {
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            return self::reservations($data['@graph']);
        }

        if (array_is_list($data)) {
            return array_merge(...array_map(fn ($entry) => is_array($entry) ? self::reservations($entry) : [], $data ?: [[]]));
        }

        return str_ends_with((string) ($data['@type'] ?? ''), 'Reservation') ? [$data] : [];
    }

    /**
     * @param  array<string, mixed>  $reservation
     * @return ?array{kind: string, title: string, provider: string, starts_at: string, ends_at: string, reference: string, amount: string, currency: string}
     */
    private static function fromReservation(array $reservation): ?array
    {
        $for = is_array($reservation['reservationFor'] ?? null) ? $reservation['reservationFor'] : [];
        $name = fn ($value) => is_array($value) ? (string) ($value['name'] ?? $value['iataCode'] ?? '') : (string) $value;
        $type = (string) $reservation['@type'];

        [$kind, $title, $provider, $start, $end] = match ($type) {
            'FlightReservation' => [
                'flight',
                trim(($for['airline']['iataCode'] ?? '').($for['flightNumber'] ?? '').' '.($for['departureAirport']['iataCode'] ?? $name($for['departureAirport'] ?? '')).' → '.($for['arrivalAirport']['iataCode'] ?? $name($for['arrivalAirport'] ?? ''))),
                $name($for['airline'] ?? ($for['provider'] ?? '')), $for['departureTime'] ?? null, $for['arrivalTime'] ?? null,
            ],
            'TrainReservation', 'BusReservation' => [
                $type === 'TrainReservation' ? 'train' : 'bus',
                trim(($for['trainNumber'] ?? $for['busNumber'] ?? '').' '.$name($for['departureStation'] ?? $for['departureBusStop'] ?? '').' → '.$name($for['arrivalStation'] ?? $for['arrivalBusStop'] ?? '')),
                $name($for['provider'] ?? ($reservation['provider'] ?? '')), $for['departureTime'] ?? null, $for['arrivalTime'] ?? null,
            ],
            'LodgingReservation' => ['hotel', $name($for), $name($reservation['provider'] ?? ($reservation['broker'] ?? '')), $reservation['checkinTime'] ?? $reservation['checkinDate'] ?? null, $reservation['checkoutTime'] ?? $reservation['checkoutDate'] ?? null],
            'RentalCarReservation' => ['car', trim(__('Rental car').' '.$name($for['brand'] ?? '').' '.($for['model'] ?? '')), $name($for['rentalCompany'] ?? ($reservation['provider'] ?? '')), $reservation['pickupTime'] ?? null, $reservation['dropoffTime'] ?? null],
            'EventReservation' => ['activity', $name($for), $name($reservation['provider'] ?? ''), $for['startDate'] ?? null, $for['endDate'] ?? null],
            default => ['other', $name($for), $name($reservation['provider'] ?? ''), $reservation['startTime'] ?? null, $reservation['endTime'] ?? null],
        };

        $title = trim(str_replace(' →  ', ' ', $title), ' →');

        if ($title === '') {
            return null;
        }

        $price = $reservation['totalPrice'] ?? $reservation['price'] ?? null;

        return [
            'kind' => $kind,
            'title' => mb_substr($title, 0, 150),
            'provider' => mb_substr($provider, 0, 150),
            'starts_at' => self::dateTime($start),
            'ends_at' => self::dateTime($end),
            'reference' => mb_substr((string) ($reservation['reservationNumber'] ?? ''), 0, 100),
            'amount' => is_numeric(is_array($price) ? ($price['price'] ?? null) : $price) ? number_format((float) (is_array($price) ? $price['price'] : $price), 2, ',', '') : '',
            'currency' => strtoupper((string) ($reservation['priceCurrency'] ?? (is_array($price) ? ($price['priceCurrency'] ?? '') : ''))) ?: 'EUR',
        ];
    }

    private static function dateTime(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        try {
            $date = Carbon::parse($value);

            return strlen(trim($value)) <= 10 ? $date->format('Y-m-d').'T00:00' : $date->format('Y-m-d\TH:i');
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * A draft from the plain text: type by keywords, booking number, the first two dates and the total price.
     *
     * @return ?array{kind: string, title: string, provider: string, starts_at: string, ends_at: string, reference: string, amount: string, currency: string}
     */
    public static function fromText(string $subject, string $text): ?array
    {
        $text = mb_substr($text, 0, 60_000);
        $subject = trim((string) preg_replace('/^((re|fwd?|wg|aw):\s*)+/i', '', $subject));
        $haystack = mb_strtolower($subject."\n".$text);

        if (trim($haystack) === '') {
            return null;
        }

        $kind = 'other';

        foreach (self::KIND_WORDS as $candidate => $words) {
            foreach ($words as $word) {
                if (str_contains($haystack, $word)) {
                    $kind = $candidate;
                    break 2;
                }
            }
        }

        preg_match('/(?:'.self::REFERENCE_LABELS.')\s*[:#.]?\s*([A-Z0-9][A-Z0-9\-]{4,19})\b/iu', $text, $reference);
        $dates = self::dates($text);
        $amount = '';
        $currency = 'EUR';

        if (preg_match('/(?:gesamtpreis|gesamtbetrag|gesamtsumme|endbetrag|total(?: price| amount)?|summe|preis)\s*:?\s*(?:(EUR|USD|GBP|CHF|€|\$|£)\s*)?(\d{1,3}(?:[.\s]\d{3})*(?:,\d{2})|\d+(?:[.,]\d{2})?)\s*(EUR|USD|GBP|CHF|€|\$|£)?/iu', $text, $price)) {
            $symbol = strtoupper($price[1] !== '' ? $price[1] : ($price[3] ?? '€'));
            $currency = ['€' => 'EUR', '$' => 'USD', '£' => 'GBP'][$symbol] ?? $symbol;
            $value = str_replace([' ', '.'], '', $price[2]);
            $value = str_contains($price[2], ',') ? $value : str_replace(',', '', $price[2]);
            $amount = number_format((float) str_replace(',', '.', $value), 2, ',', '');
        }

        $title = $subject !== '' ? $subject : mb_strimwidth(trim(strtok($text, "\n") ?: ''), 0, 120, '…');

        return [
            'kind' => $kind,
            'title' => mb_substr($title !== '' ? $title : __('Booking'), 0, 150),
            'provider' => '',
            'starts_at' => $dates[0] ?? '',
            'ends_at' => $dates[1] ?? '',
            'reference' => strtoupper($reference[1] ?? ''),
            'amount' => $amount,
            'currency' => $currency,
        ];
    }

    /**
     * The first two travel dates of the text ("12.05.2027 14:30", "2027-05-12", "12. Mai 2027"), sorted;
     * dates in the past (e.g. the booking date) are skipped when later ones exist.
     *
     * @return list<string>
     */
    private static function dates(string $text): array
    {
        $months = ['januar' => 1, 'jan' => 1, 'februar' => 2, 'feb' => 2, 'märz' => 3, 'mär' => 3, 'march' => 3, 'april' => 4, 'apr' => 4, 'mai' => 5, 'may' => 5, 'juni' => 6, 'june' => 6, 'jun' => 6, 'juli' => 7, 'july' => 7, 'jul' => 7, 'august' => 8, 'aug' => 8, 'september' => 9, 'sep' => 9, 'oktober' => 10, 'october' => 10, 'okt' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11, 'dezember' => 12, 'december' => 12, 'dez' => 12, 'dec' => 12, 'january' => 1, 'february' => 2];
        preg_match_all('/\b(?:(\d{1,2})\.(\d{1,2})\.(\d{2,4})|(\d{4})-(\d{2})-(\d{2})|(\d{1,2})\.?\s+('.implode('|', array_keys($months)).')\.?\s+(\d{4}))(?:\D{1,12}?(\d{1,2}):(\d{2}))?/iu', $text, $matches, PREG_SET_ORDER);
        $found = [];

        foreach ($matches as $match) {
            [$day, $month, $year] = match (true) {
                ($match[1] ?? '') !== '' => [(int) $match[1], (int) $match[2], (int) $match[3]],
                ($match[4] ?? '') !== '' => [(int) $match[6], (int) $match[5], (int) $match[4]],
                default => [(int) $match[7], $months[mb_strtolower($match[8])] ?? 0, (int) $match[9]],
            };
            $year = $year < 100 ? 2000 + $year : $year;

            if (! checkdate($month, $day, $year) || $year < 2000 || $year > 2100) {
                continue;
            }

            $hour = ($match[10] ?? '') !== '' ? min(23, (int) $match[10]) : 0;
            $minute = ($match[11] ?? '') !== '' ? min(59, (int) $match[11]) : 0;
            $value = sprintf('%04d-%02d-%02dT%02d:%02d', $year, $month, $day, $hour, $minute);

            if (! in_array($value, $found, true)) {
                $found[] = $value;
            }

            if (count($found) === 8) {
                break;
            }
        }

        $upcoming = array_values(array_filter($found, fn (string $date) => $date >= today()->format('Y-m-d\\TH:i')));
        $found = array_slice($upcoming !== [] ? $upcoming : $found, 0, 2);
        sort($found);

        return $found;
    }
}
