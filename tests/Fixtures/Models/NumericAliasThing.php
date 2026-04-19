<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class NumericAliasThing extends Model
{
    protected $table = 'numeric_alias_things';

    public $timestamps = false;

    protected $guarded = [];
}
