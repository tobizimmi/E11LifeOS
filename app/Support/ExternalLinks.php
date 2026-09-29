<?php

namespace App\Support;

use App\Models\BookkeepingEntry;
use App\Models\ExternalLink;
use App\Models\Item;
use App\Models\Quote;
use App\Models\QuoteComparison;
use App\Models\RealEstate;
use App\Models\RealEstateInvoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What external documents (paperless, mail links, mail copies) can be attached to, and who may
 * see them: exactly those who can see the thing they are attached to.
 */
class ExternalLinks
{
    public const TYPES = ['item', 'real_estate', 'real_estate_invoice', 'transaction', 'bookkeeping_entry', 'quote'];

    /**
     * Mail links from the common mail apps; web mail uses https.
     */
    public const MAIL_SCHEMES = ['https', 'http', 'message', 'mid', 'mailto', 'thunderlink', 'ms-outlook', 'readdle-spark', 'googlegmail'];

    /**
     * The thing a link belongs to, if the user may see it.
     */
    public static function resolve(User $user, int $householdId, string $type, int $id): ?Model
    {
        return match ($type) {
            'item' => Item::query()->forHousehold($householdId)->visibleTo($user)->find($id),
            'real_estate' => RealEstate::query()->visibleTo($user, $householdId)->find($id),
            'real_estate_invoice' => RealEstateInvoice::query()->whereIn('real_estate_id', RealEstate::query()->visibleTo($user, $householdId)->select('id'))->find($id),
            'transaction' => Transaction::where('user_id', $user->id)->where('household_id', $householdId)->find($id),
            'bookkeeping_entry' => BookkeepingEntry::where('user_id', $user->id)->where('household_id', $householdId)->find($id),
            'quote' => Quote::query()->whereIn('quote_comparison_id', QuoteComparison::query()->visibleTo($user, $householdId)->select('id'))->find($id),
            default => null,
        };
    }

    public static function canSee(User $user, ExternalLink $link): bool
    {
        return $link->household_id === $user->currentHousehold()?->id
            && self::resolve($user, $link->household_id, $link->linkable_type, $link->linkable_id) !== null;
    }

    public static function isMailUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url(trim($url), PHP_URL_SCHEME));

        return in_array($scheme, self::MAIL_SCHEMES, true) && mb_strlen($url) <= 2000;
    }

    /**
     * Label of the mail app a link opens.
     */
    public static function mailApp(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return match (true) {
            $scheme === 'message' => 'Apple Mail',
            $scheme === 'thunderlink' => 'Thunderbird',
            $scheme === 'ms-outlook', str_contains($host, 'outlook.'), str_contains($host, 'office.com') => 'Outlook',
            $scheme === 'googlegmail', str_contains($host, 'mail.google.') => 'Gmail',
            $scheme === 'mailto' => __('E-mail'),
            default => $host !== '' ? $host : __('E-mail'),
        };
    }
}
