<?php

namespace App\Support\Paperless;

use App\Models\RealEstate;
use App\Models\User;
use App\Support\Addons;
use Illuminate\Support\Collection;

/**
 * Suggests where a document from the paperless inbox belongs, based on its tags and title:
 * a property (name or street mentioned), the small business, a private expense or a task.
 */
class PaperlessInbox
{
    public const TARGETS = ['finance', 'real_estate', 'business', 'task'];

    private const BUSINESS_WORDS = ['gewerbe', 'business', 'firma', 'betrieb', 'kleingewerbe', 'geschäft'];

    private const EXPENSE_WORDS = ['rechnung', 'beleg', 'quittung', 'kassenbon', 'kassenzettel', 'invoice', 'receipt', 'steuer', 'ausgabe'];

    /**
     * The targets the member may use.
     *
     * @return list<string>
     */
    public static function targetsFor(User $user): array
    {
        return array_values(array_filter(self::TARGETS, fn (string $target) => match ($target) {
            'finance' => $user->canUseAddon(Addons::FINANCE),
            'real_estate' => $user->canUseAddon(Addons::REAL_ESTATE),
            'business' => $user->canUseAddon(Addons::BUSINESS),
            default => true,
        }));
    }

    /**
     * @param  list<string>  $tagNames
     * @param  Collection<int, RealEstate>  $realEstates
     * @param  list<string>  $targets
     * @return array{target: string, real_estate_id: ?int}
     */
    public static function suggest(string $title, array $tagNames, Collection $realEstates, array $targets): array
    {
        $haystack = mb_strtolower($title.' '.implode(' ', $tagNames));

        if (in_array('real_estate', $targets, true)) {
            $realEstate = $realEstates->first(fn (RealEstate $realEstate) => collect([$realEstate->name, $realEstate->street])
                ->filter(fn (?string $word) => mb_strlen(trim((string) $word)) >= 4)
                ->contains(fn (string $word) => str_contains($haystack, mb_strtolower(trim($word)))));

            if ($realEstate) {
                return ['target' => 'real_estate', 'real_estate_id' => $realEstate->id];
            }
        }

        if (in_array('business', $targets, true) && self::mentions($haystack, self::BUSINESS_WORDS)) {
            return ['target' => 'business', 'real_estate_id' => null];
        }

        if (in_array('finance', $targets, true) && self::mentions($haystack, self::EXPENSE_WORDS)) {
            return ['target' => 'finance', 'real_estate_id' => null];
        }

        return ['target' => 'task', 'real_estate_id' => null];
    }

    /**
     * @param  list<string>  $words
     */
    private static function mentions(string $haystack, array $words): bool
    {
        foreach ($words as $word) {
            if (str_contains($haystack, $word)) {
                return true;
            }
        }

        return false;
    }
}
