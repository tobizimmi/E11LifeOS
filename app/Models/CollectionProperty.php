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

    /**
     * Multi-line free text (shown as a textarea), as opposed to the single-line {@see self::TYPE_TEXT}.
     */
    public const TYPE_LONG_TEXT = 'long_text';

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

    /**
     * A column of attached files (any type); the value is a list of {@see Attachment} ids
     * belonging to the entry.
     */
    /**
     * A computed column: an arithmetic expression over other numeric columns, stored in
     * options['formula'] (e.g. "{Preis} * {Menge}"). The value is calculated on display, never stored.
     */
    public const TYPE_FORMULA = 'formula';

    /**
     * A 1–5 star rating.
     */
    public const TYPE_RATING = 'rating';

    /**
     * A 0–100 % progress value (shown as a bar).
     */
    public const TYPE_PROGRESS = 'progress';

    public const TYPE_FILE = 'file';

    /**
     * Like {@see self::TYPE_FILE} but meant for pictures, shown as thumbnails and embedded in reports.
     */
    public const TYPE_IMAGE = 'image';

    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_LONG_TEXT,
        self::TYPE_NUMBER,
        self::TYPE_DATE,
        self::TYPE_SELECT,
        self::TYPE_CHECKBOX,
        self::TYPE_PERSON,
        self::TYPE_URL,
        self::TYPE_MULTI_SELECT,
        self::TYPE_RELATION,
        self::TYPE_RATING,
        self::TYPE_PROGRESS,
        self::TYPE_FORMULA,
        self::TYPE_FILE,
        self::TYPE_IMAGE,
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

    /**
     * File and image columns store a list of attachment ids rather than a scalar value.
     */
    public function isFiles(): bool
    {
        return in_array($this->type, [self::TYPE_FILE, self::TYPE_IMAGE], true);
    }

    /**
     * Computed columns (formula, rollup) are calculated on display and never stored or edited.
     */
    public function isComputed(): bool
    {
        return in_array($this->type, [self::TYPE_FORMULA], true);
    }

    /**
     * The formula expression of a formula column (empty string when none set).
     */
    public function formulaExpression(): string
    {
        if ($this->type !== self::TYPE_FORMULA) {
            return '';
        }

        $formula = data_get($this->options, 'formula');

        return is_string($formula) ? $formula : '';
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
     * Date properties with a reminder can also create a task when the date comes close
     * (e.g. the cancellation deadline of a contract), see App\Support\ContractAssistant.
     */
    public function createsTask(): bool
    {
        return $this->remindDays() !== null && ($this->options['create_task'] ?? false) === true;
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

        if ($this->type === self::TYPE_CHECKBOX) {
            return $values->filter()->count().' / '.$values->count();
        }

        if ($this->type !== self::TYPE_NUMBER) {
            return null;
        }

        $numbers = $values->filter(fn ($value) => is_numeric($value))->map(fn ($value) => (float) $value);

        if ($numbers->isEmpty()) {
            return null;
        }

        $result = match ($this->summaryFunction()) {
            'avg' => $numbers->avg(),
            'min' => $numbers->min(),
            'max' => $numbers->max(),
            'count' => $numbers->count(),
            default => $numbers->sum(),
        };

        return rtrim(rtrim(number_format((float) $result, 2, ',', '.'), '0'), ',');
    }

    /**
     * How a number column is summarised under the table: sum (default), average, minimum, maximum
     * or how many entries have a value.
     *
     * @var array<int, string>
     */
    public const SUMMARY_FUNCTIONS = ['sum', 'avg', 'min', 'max', 'count'];

    public function summaryFunction(): string
    {
        $function = data_get($this->options, 'summary');

        return is_string($function) && in_array($function, self::SUMMARY_FUNCTIONS, true) ? $function : 'sum';
    }

    /**
     * Short prefix shown before the summary value (Σ for sum, Ø for average …).
     */
    public function summaryPrefix(): string
    {
        return match ($this->summaryFunction()) {
            'avg' => 'Ø ',
            'min' => '↓ ',
            'max' => '↑ ',
            'count' => '# ',
            default => 'Σ ',
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

        if ($this->isFiles()) {
            $ids = collect(is_array($value) ? $value : [$value])
                ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
                ->map(fn ($id) => (int) $id)->unique()->take(50)->values()->all();

            return $ids ?: null;
        }

        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        return match ($this->type) {
            self::TYPE_TEXT => mb_substr(trim((string) $value), 0, 1000) ?: null,
            self::TYPE_LONG_TEXT => mb_substr(trim((string) $value), 0, 20000) ?: null,
            self::TYPE_NUMBER => is_numeric($value) ? $value + 0 : null,
            self::TYPE_RATING => is_numeric($value) ? max(1, min(5, (int) round((float) $value))) : null,
            self::TYPE_PROGRESS => is_numeric($value) ? max(0, min(100, (int) round((float) $value))) : null,
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
