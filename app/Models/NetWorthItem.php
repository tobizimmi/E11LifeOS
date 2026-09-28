<?php

namespace App\Models;

use Database\Factories\NetWorthItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An asset or debt entered by hand for the net worth (cash, car, private loan …).
 */
#[Fillable(['user_id', 'name', 'kind', 'value', 'position'])]
class NetWorthItem extends Model
{
    /** @use HasFactory<NetWorthItemFactory> */
    use HasFactory;

    public const ASSET = 'asset';

    public const LIABILITY = 'liability';

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'position' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLiability(): bool
    {
        return $this->kind === self::LIABILITY;
    }
}
