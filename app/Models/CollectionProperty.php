<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

#[Fillable(['collection_id', 'name', 'type', 'options', 'system_key', 'position'])]
class CollectionProperty extends Model
{
    public const TYPE_TEXT = 'text';

    public const TYPE_NUMBER = 'number';

    public const TYPE_DATE = 'date';

    public const TYPE_SELECT = 'select';

    public const TYPE_CHECKBOX = 'checkbox';

    public const TYPE_PERSON = 'person';

    public const TYPE_URL = 'url';

    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_NUMBER,
        self::TYPE_DATE,
        self::TYPE_SELECT,
        self::TYPE_CHECKBOX,
        self::TYPE_PERSON,
        self::TYPE_URL,
    ];

    /**
     * Colors available for select options (Tailwind classes in the views).
     */
    public const COLORS = ['gray', 'blue', 'green', 'yellow', 'orange', 'red', 'purple', 'pink'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
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
        return $this->options ?? [];
    }

    /**
     * @return array{id: string, name: string, color: string}|null
     */
    public function option(?string $id): ?array
    {
        return collect($this->selectOptions())->firstWhere('id', $id);
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
