<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use NickWelsh\EloquentZero\Tests\Fixtures\Types\SettingsData;

class JsonCastThing extends Model
{
    protected $table = 'json_typed_things';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => SettingsData::class,
        ];
    }
}
