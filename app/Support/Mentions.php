<?php

namespace App\Support;

use App\Models\Household;
use Illuminate\Database\Eloquent\Model;

/**
 * @-mentions of household members in wiki pages. A mention is stored as "@[id:Name]" in the
 * page Markdown and rendered as a chip by NoteMarkdown::mentions(); the id keeps it stable.
 */
class Mentions
{
    /**
     * Members of the household whose name matches the query, for the editor's "@" autocomplete.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function search(string $query, int $householdId, int $limit = 6): array
    {
        $household = Household::find($householdId);

        if ($household === null) {
            return [];
        }

        return $household->users()
            ->when($query !== '', fn ($builder) => $builder->where('name', 'like', '%'.$query.'%'))
            ->orderBy('name')
            ->limit($limit)
            ->get(['users.id', 'users.name'])
            ->map(fn (Model $user): array => ['id' => (int) $user->getKey(), 'name' => (string) $user->getAttribute('name')])
            ->all();
    }

    /**
     * Ids of members mentioned in a page body (@[id:Name]).
     *
     * @return array<int, int>
     */
    public static function idsInText(string $text): array
    {
        if (preg_match_all('/@\[(\d+):[^\]]+\]/', $text, $matches) === false) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $matches[1])));
    }
}
