<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\DiscoveryModels;

use Illuminate\Database\Eloquent\Model;

class IncludedNote extends Model
{
    protected $table = 'included_notes';

    public $timestamps = false;

    protected $guarded = [];
}
