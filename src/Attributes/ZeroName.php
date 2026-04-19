<?php

namespace NickWelsh\EloquentZero\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class ZeroName
{
    public function __construct(
        public string $name
    ) {}
}
