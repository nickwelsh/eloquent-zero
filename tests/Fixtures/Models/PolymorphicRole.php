<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class PolymorphicRole extends Model
{
    protected $table = 'roles';

    public $timestamps = false;

    protected $guarded = [];
}
