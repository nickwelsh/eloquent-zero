<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use NickWelsh\EloquentZero\Attributes\ZeroJson;
use NickWelsh\EloquentZero\Tests\Fixtures\Types\RelationMetadata;

#[ZeroJson('metadata', RelationMetadata::class)]
class WayfinderJsonTypedThing extends Model
{
    protected $table = 'json_typed_things';

    public $timestamps = false;

    protected $guarded = [];
}
