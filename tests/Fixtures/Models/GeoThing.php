<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class GeoThing extends Model
{
    protected $table = 'geo_things';

    public $timestamps = false;

    protected $guarded = [];
}
