<?php

namespace NickWelsh\EloquentZero\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class MorphRelationship
{
    public static function pivot(string $relation): string
    {
        return '__zeroMorph'.Str::studly($relation).'Pivot';
    }

    public static function related(Model|string $model, string $relation): string
    {
        $table = $model instanceof Model ? $model->getTable() : $model;

        return '__zeroMorph'.Str::studly($table).Str::studly($relation).'Related';
    }
}
