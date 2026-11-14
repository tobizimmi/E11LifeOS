<?php

namespace App\Models;

use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A person that matters to the household: birthday and anniversary with reminder, contact details,
 * notes and gift ideas.
 *
 * @property int $id
 * @property int $household_id
 * @property ?int $created_by
 * @property string $visibility
 * @property string $name
 * @property ?string $relation
 * @property ?Carbon $birthday
 * @property bool $birth_year_known
 * @property ?Carbon $anniversary
 * @property ?string $anniversary_label
 * @property ?string $phone
 * @property ?string $email
 * @property ?string $address
 * @property ?string $notes
 * @property int $remind_days
 * @property ?Carbon $last_contact_on
 */
#[Fillable([
    'household_id', 'created_by', 'visibility', 'name', 'relation', 'birthday', 'birth_year_known', 'anniversary', 'anniversary_label',
    'phone', 'email', 'address', 'notes', 'remind_days', 'last_contact_on',
])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'birth_year_known' => 'boolean',
            'anniversary' => 'date',
            'remind_days' => 'integer',
            'last_contact_on' => 'date',
        ];
    }

    /**
     * @return HasMany<GiftIdea, $this>
     */
    public function giftIdeas(): HasMany
    {
        return $this->hasMany(GiftIdea::class)->orderByRaw("case status when 'idea' then 0 when 'bought' then 1 else 2 end")->orderByDesc('id');
    }

    /**
     * Shared people of the household and the member's private ones.
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('household_id', $householdId)
            ->where(fn (Builder $query) => $query->where('visibility', Item::VISIBILITY_SHARED)->orWhere('created_by', $user->id));
    }

    /**
     * The next time a yearly date comes round (today counts); 29 February falls on 28 February in other years.
     */
    public static function nextOccurrence(Carbon $date, ?Carbon $from = null): Carbon
    {
        $from = ($from ?? today())->copy()->startOfDay();
        $next = self::inYear($date, $from->year);

        return $next->lt($from) ? self::inYear($date, $from->year + 1) : $next;
    }

    private static function inYear(Carbon $date, int $year): Carbon
    {
        return Carbon::create($year, $date->month, min($date->day, Carbon::create($year, $date->month, 1)->daysInMonth));
    }

    public function nextBirthday(): ?Carbon
    {
        return $this->birthday ? self::nextOccurrence($this->birthday) : null;
    }

    /**
     * The age the person turns on their next birthday (null without a known year).
     */
    public function turns(): ?int
    {
        return $this->birthday && $this->birth_year_known ? $this->nextBirthday()->year - $this->birthday->year : null;
    }
}
