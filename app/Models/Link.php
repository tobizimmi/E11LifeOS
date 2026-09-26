<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A link between two "nodes" (item or recipe), see App\Support\Links.
 */
#[Fillable(['household_id', 'from_type', 'from_id', 'to_type', 'to_id', 'source', 'created_by'])]
class Link extends Model
{
    public const SOURCE_BODY = 'body';

    public const SOURCE_PROPERTY = 'property';

    public const SOURCE_MANUAL = 'manual';
}
