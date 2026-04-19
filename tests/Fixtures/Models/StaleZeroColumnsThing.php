<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use NickWelsh\EloquentZero\Attributes\ZeroColumns;

#[ZeroColumns(['id', 'missing_column'])]
class StaleZeroColumnsThing extends Model
{
    protected $table = 'stale_zero_columns_things';

    public $timestamps = false;

    protected $guarded = [];
}
