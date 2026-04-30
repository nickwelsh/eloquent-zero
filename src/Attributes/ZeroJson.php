<?php

namespace NickWelsh\EloquentZero\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ZeroJson
{
    public function __construct(
        public string $column,
        public string $type,
        public ?string $import = null,
    ) {}
}
