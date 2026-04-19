<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\DiscoveryModels;

use Illuminate\Database\Eloquent\Model;
use NickWelsh\EloquentZero\Attributes\ZeroIgnore;

#[ZeroIgnore]
class IgnoredNote extends Model
{
    protected $table = 'ignored_notes';

    public $timestamps = false;

    protected $guarded = [];
}
