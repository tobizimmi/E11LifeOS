<?php

namespace App\Models\Concerns;

use App\Support\Security\ShareToken;
use Illuminate\Database\Eloquent\Builder;

/**
 * Link token attributes that are stored as an HMAC (column itself) plus an encrypted copy
 * ("<column>_encrypted"). Reading the attribute returns the plain token, writing one stores both;
 * look tokens up with the whereShareToken() scope.
 */
trait HasShareTokens
{
    /**
     * @return list<string>
     */
    abstract protected function shareTokenAttributes(): array;

    public function getAttribute($key)
    {
        if (is_string($key) && in_array($key, $this->shareTokenAttributes(), true)) {
            return ($this->attributes[$key] ?? null) === null ? null : ShareToken::decrypt($this->attributes[$key.'_encrypted'] ?? null);
        }

        return parent::getAttribute($key);
    }

    public function setAttribute($key, $value)
    {
        if (is_string($key) && in_array($key, $this->shareTokenAttributes(), true)) {
            $this->attributes[$key] = $value === null ? null : ShareToken::hash((string) $value);
            $this->attributes[$key.'_encrypted'] = $value === null ? null : ShareToken::encrypt((string) $value);

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    public function scopeWhereShareToken(Builder $query, string $attribute, string $token, ?string $expiresColumn = null): void
    {
        $query->where($attribute, ShareToken::hash($token))
            ->when($expiresColumn, fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNull($expiresColumn)->orWhere($expiresColumn, '>', now())));
    }
}
