<?php

namespace App\Models;

use App\Support\Life\Journal;
use Database\Factories\JournalEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The journal of a member for one day: mood (1–5), activities, the questions of the morning
 * (grateful for, what would make the day great) and the evening (highlights, what to do better),
 * notes and photos. The texts are encrypted.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $date
 * @property ?int $mood
 * @property ?array<int, string> $activities
 * @property ?string $grateful
 * @property ?string $great_day
 * @property ?string $highlights
 * @property ?string $better
 * @property ?string $notes
 * @property ?array<int, string> $photos
 */
#[Fillable(['user_id', 'date', 'mood', 'activities', 'grateful', 'great_day', 'highlights', 'better', 'notes', 'photos'])]
class JournalEntry extends Model
{
    /** @use HasFactory<JournalEntryFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleted(fn (JournalEntry $entry) => collect($entry->photos ?? [])->each(fn (string $path) => Journal::deletePhoto($path)));
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'mood' => 'integer',
            'activities' => 'array',
            'photos' => 'array',
            'grateful' => 'encrypted',
            'great_day' => 'encrypted',
            'highlights' => 'encrypted',
            'better' => 'encrypted',
            'notes' => 'encrypted',
        ];
    }

    /**
     * Whether anything was written or chosen.
     */
    public function isEmpty(): bool
    {
        if ($this->mood !== null || ($this->activities ?? []) !== [] || ($this->photos ?? []) !== []) {
            return false;
        }

        foreach ([$this->grateful, $this->great_day, $this->highlights, $this->better, $this->notes] as $text) {
            if (trim((string) $text) !== '') {
                return false;
            }
        }

        return true;
    }
}
