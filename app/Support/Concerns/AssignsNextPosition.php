<?php

namespace App\Support\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * The next free "position" in a reorderable list (tasks, pantry entries, collection
 * properties …).
 *
 * IMPORTANT: this only locks the read — call it from inside a DB::transaction() that also
 * performs the insert/update using the returned position, in the same transaction. A
 * SELECT ... FOR UPDATE lock is released as soon as its transaction commits; if the write
 * happens in a separate transaction (or with no transaction at all), two concurrent callers can
 * still both read the same max and collide, as if this had never been called.
 */
trait AssignsNextPosition
{
    protected static function nextPosition(Builder $scope, string $column = 'position'): int
    {
        $max = (clone $scope)->lockForUpdate()->max($column);

        return ((int) $max) + 1;
    }
}
