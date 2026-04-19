<?php

namespace NickWelsh\EloquentZero\Support;

use Illuminate\Support\Str;

enum Casing: string
{
    case Camel = 'camel';
    case Snake = 'snake';
    case Studly = 'studly';
    case Kebab = 'kebab';
    case Lower = 'lower';
    case Upper = 'upper';
    case Title = 'title';
    case Headline = 'headline';
    case Apa = 'apa';

    public function transform(string $value): string
    {
        return match ($this) {
            self::Camel => Str::camel($value),
            self::Snake => Str::snake($value),
            self::Studly => Str::studly($value),
            self::Kebab => Str::kebab($value),
            self::Lower => Str::lower($value),
            self::Upper => Str::upper($value),
            self::Title => Str::title($value),
            self::Headline => Str::headline($value),
            self::Apa => Str::apa($value),
        };
    }
}
