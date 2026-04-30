<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use NickWelsh\EloquentZero\Attributes\ZeroJson;

#[ZeroJson('metadata', type: 'RelationMetadata', import: '@/types/crm')]
class JsonTypedThing extends Model
{
    protected $table = 'json_typed_things';

    public $timestamps = false;

    protected $guarded = [];
}
