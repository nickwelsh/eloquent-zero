<?php

namespace NickWelsh\EloquentZero\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \NickWelsh\EloquentZero\EloquentZero
 */
class EloquentZero extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \NickWelsh\EloquentZero\EloquentZero::class;
    }
}
