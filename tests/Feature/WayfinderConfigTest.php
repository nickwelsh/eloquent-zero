<?php

use NickWelsh\EloquentZero\Support\WayfinderConfig;

it('normalizes boolean and array wayfinder configuration', function () {
    expect(WayfinderConfig::from(false))->toBeNull()
        ->and(WayfinderConfig::from(true)?->method)->toBe('import')
        ->and(WayfinderConfig::from(true)?->importSource())->toBe('@/wayfinder/types')
        ->and(WayfinderConfig::from([])?->importSource())->toBe('@/wayfinder/types')
        ->and(WayfinderConfig::from(['import_path' => '~/wayfinder'])?->importSource())->toBe('~/wayfinder/types')
        ->and(WayfinderConfig::from([
            'method' => 'global',
            'import_path' => '~/ignored',
        ])?->importSource())->toBeNull();
});

it('rejects invalid wayfinder configuration', function (mixed $configuration, string $message) {
    expect(fn () => WayfinderConfig::from($configuration))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'invalid type' => ['yes', 'use_wayfinder must be a boolean or array.'],
    'invalid method' => [['method' => 'imports'], 'use_wayfinder.method must be import or global.'],
    'empty import path' => [['import_path' => ''], 'use_wayfinder.import_path must be a non-empty string.'],
]);
