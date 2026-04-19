<?php

namespace NickWelsh\EloquentZero\Generators;

final readonly class GeneratedSchemaResult
{
    public function __construct(
        public string $outputPath,
        public bool $wasWritten,
    ) {}
}
