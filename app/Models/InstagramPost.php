<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An Instagram post or video of a finance profile and what the AI read from it.
 */
#[Fillable(['profile', 'instagram_id', 'shortcode', 'caption', 'thumbnail_url', 'video_url', 'post_url', 'published_at', 'duration_sec', 'view_count', 'transcript', 'summary', 'tickers', 'stock_name', 'sentiment', 'key_points', 'entry_price', 'entry_type', 'stop_loss', 'currency', 'tranches', 'analyzed_at', 'notified_at'])]
class InstagramPost extends Model
{
    public const SENTIMENTS = ['bullish', 'bearish', 'neutral', 'mixed'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'tickers' => 'array',
            'key_points' => 'array',
            'tranches' => 'array',
            'entry_price' => 'float',
            'stop_loss' => 'float',
            'analyzed_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function mainTicker(): ?string
    {
        return $this->tickers[0] ?? null;
    }
}
