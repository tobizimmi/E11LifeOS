<?php

namespace App\Models;

use App\Support\Links;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

#[Fillable(['collection_id', 'name', 'type', 'options', 'system_key', 'position', 'required', 'default_value', 'help'])]
class CollectionProperty extends Model
{
    public const TYPE_TEXT = 'text';

    public const TYPE_NUMBER = 'number';

    public const TYPE_DATE = 'date';

    public const TYPE_SELECT = 'select';

    public const TYPE_CHECKBOX = 'checkbox';

    public const TYPE_PERSON = 'person';

    public const TYPE_URL = 'url';

    public const TYPE_MULTI_SELECT = 'multi_select';

    /**
     * Links to other pages, tasks, entries or recipes ("item:12", "recipe:3"), see App\Support\Links.
     */
    public const TYPE_RELATION = 'relation';

    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_NUMBER,
        self::TYPE_DATE,
        self::TYPE_SELECT,
        self::TYPE_CHECKBOX,
        self::TYPE_PERSON,
        self::TYPE_URL,
        self::TYPE_MULTI_SELECT,
        self::TYPE_RELATION,
    ];

    /**
     * Colors available for select options (Tailwind classes in the views).
     */
    public const COLORS = ['gray', 'blue', 'green', 'yellow', 'orange', 'red', 'purple', 'pink'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
            'default_value' => 'json',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /**
     * Select and multi-select properties have a list of options.
     */
    public function hasOptions(): bool
    {
        return in_array($this->type, [self::TYPE_SELECT, self::TYPE_MULTI_SELECT], true);
    }

    public function isSystem(): bool
    {
        return $this->system_key !== null;
    }

    /**
     * @return array<int, array{id: string, name: string, color: string}>
     */
    public function selectOptions(): array
    {
        return $this->hasOptions() ? array_values(array_filter($this->options ?? [], 'is_array')) : [];
    }

    /**
     * @return array{id: string, name: string, color: string}|null
     */
    public function option(?string $id): ?array
    {
        return collect($this->selectOptions())->firstWhere('id', $id);
    }

    /**
     * Days before a date value on which a reminder e-mail goes out (0 = on the day),
     * null if this date property has no reminder.
     */
    public function remindDays(): ?int
    {
        $days = $this->type === self::TYPE_DATE ? ($this->options['remind_days'] ?? null) : null;

        return is_int($days) ? $days : null;
    }

    /**
     * Summary shown under a table column / on a board column: sum of numbers,
     * "ticked / total" for checkboxes; null for other types.
     *
     * @param  iterable<int, Item>  $entries
     */
    public function aggregate(iterable $entries): ?string
    {
        $values = collect($entries)->map(fn (Item $entry) => $entry->propertyValue($this));

        return match ($this->type) {
            self::TYPE_NUMBER => $values->filter(fn ($value) => is_numeric($value))->isEmpty()
                ? null
                : rtrim(rtrim(number_format((float) $values->filter(fn ($value) => is_numeric($value))->sum(), 2, ',', '.'), '0'), ','),
            self::TYPE_CHECKBOX => $values->filter()->count().' / '.$values->count(),
            default => null,
        };
    }

    /**
     * Adds a select option (new ids are random, so renaming never breaks values).
     *
     * @return array{id: string, name: string, color: string}
     */
    public function addOption(string $name): array
    {
        $options = $this->selectOptions();
        $option = [
            'id' => Str::lower(Str::random(8)),
            'name' => $name,
            'color' => self::COLORS[count($options) % count(self::COLORS)],
        ];

        $this->update(['options' => [...$options, $option]]);

        return $option;
    }

    /**
     * Turns raw input (from a form field) into the stored value, or null when
     * empty or invalid. Person values must be members of $memberIds.
     *
     * @param  array<int, int>  $memberIds
     */
    public function normalizeValue(mixed $value, array $memberIds = []): mixed
    {
        if ($this->type === self::TYPE_MULTI_SELECT) {
            $ids = collect(is_array($value) ? $value : [$value])->map(fn ($id) => (string) $id)
                ->filter(fn (string $id) => $this->option($id) !== null)->unique()->values()->all();

            return $ids ?: null;
        }

        if ($this->type === self::TYPE_RELATION) {
            $keys = collect(is_array($value) ? $value : [$value])
                ->filter(fn ($key) => Links::parseKey($key) !== null)->unique()->take(50)->values()->all();

            return $keys ?: null;
        }

        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        return match ($this->type) {
            self::TYPE_TEXT => mb_substr(trim((string) $value), 0, 1000) ?: null,
            self::TYPE_NUMBER => is_numeric($value) ? $value + 0 : null,
            self::TYPE_DATE => $this->normalizeDate((string) $value),
            self::TYPE_SELECT => $this->option((string) $value) ? (string) $value : null,
            self::TYPE_CHECKBOX => filter_var($value, FILTER_VALIDATE_BOOLEAN) ?: null,
            self::TYPE_PERSON => in_array((int) $value, $memberIds, true) ? (int) $value : null,
            self::TYPE_URL => filter_var(trim((string) $value), FILTER_VALIDATE_URL) && preg_match('#^https?://#i', trim((string) $value))
                ? mb_substr(trim((string) $value), 0, 2000)
                : null,
            default => null,
        };
    }

    /**
     * The value a new entry starts with. Dates may be relative ("today", "today+7"),
     * a person may be "me" (the member creating the entry).
     */
    public function resolvedDefault(?int $userId = null): mixed
    {
        $default = $this->default_value;

        if ($default === null || $default === '' || $default === []) {
            return null;
        }

        if ($this->type === self::TYPE_DATE && is_string($default) && preg_match('/^today(?:\+(\d{1,3}))?$/', $default, $match)) {
            return today()->addDays((int) ($match[1] ?? 0))->toDateString();
        }

        if ($this->type === self::TYPE_PERSON && $default === 'me') {
            return $userId;
        }

        return $default;
    }

    private function normalizeDate(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value)->format('Y-m-d') === $value ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
