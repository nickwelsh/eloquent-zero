<?php

use NickWelsh\EloquentZero\Support\Casing;
use NickWelsh\EloquentZero\Support\Mode;

return [
    'mode' => Mode::OptOut,
    'model_search_directories' => [
        app_path('Models'),
    ],
    'models' => [],
    'tables' => [],
    'output_path' => resource_path('js/zero/schema.ts'),
    'table_name_casing' => Casing::Camel,
    'column_name_casing' => Casing::Camel,
    // Laravel Zero enables these to provide runtime row coercion and safe
    // polymorphic include helpers.
    'generate_zod_schemas' => false,
    'generate_polymorphic_helpers' => false,
    // true is equivalent to ['method' => 'import', 'import_path' => '@/wayfinder'].
    // Use ['method' => 'global'] when Wayfinder namespaces are globally available.
    'use_wayfinder' => false,
    'connection' => null,
    'allow_multiple_connections' => false,
    'publication_name' => env('ZERO_APP_PUBLICATIONS'), // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
];
