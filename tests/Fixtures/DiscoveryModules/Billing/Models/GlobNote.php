<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\DiscoveryModules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class GlobNote extends Model
{
    protected $table = 'glob_notes';

    public $timestamps = false;

    protected $guarded = [];
}
