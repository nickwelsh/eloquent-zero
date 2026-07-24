<?php

namespace NickWelsh\EloquentZero\Support;

use RuntimeException;

final readonly class WayfinderConfig
{
    private const DEFAULT_IMPORT_PATH = '@/wayfinder';

    private function __construct(
        public string $method,
        public ?string $importPath,
    ) {}

    public static function from(mixed $value): ?self
    {
        if ($value === false || $value === null) {
            return null;
        }

        if ($value === true) {
            return new self('import', self::DEFAULT_IMPORT_PATH);
        }

        if (! is_array($value)) {
            throw new RuntimeException('use_wayfinder must be a boolean or array.');
        }

        $method = $value['method'] ?? 'import';

        if (! is_string($method) || ! in_array($method, ['import', 'global'], true)) {
            throw new RuntimeException('use_wayfinder.method must be import or global.');
        }

        if ($method === 'global') {
            return new self($method, null);
        }

        $importPath = $value['import_path'] ?? self::DEFAULT_IMPORT_PATH;

        if (! is_string($importPath) || trim($importPath) === '') {
            throw new RuntimeException('use_wayfinder.import_path must be a non-empty string.');
        }

        return new self($method, rtrim($importPath, '/'));
    }

    public function importSource(): ?string
    {
        return $this->importPath === null ? null : $this->importPath.'/types';
    }
}
