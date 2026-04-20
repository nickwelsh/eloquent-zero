<?php

namespace NickWelsh\EloquentZero\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class ZeroExclude
{
    /**
     * @param  array<int, string>  $columns
     */
    public function __construct(
        public array $columns
    ) {}
}
