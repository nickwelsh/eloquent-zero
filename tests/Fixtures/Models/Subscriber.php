<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Subscriber extends Model
{
    protected $table = 'subscribers';

    public $timestamps = false;

    protected $guarded = [];
}
