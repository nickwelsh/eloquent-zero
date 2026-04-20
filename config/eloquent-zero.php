<?php

use NickWelsh\EloquentZero\Support\Casing;
use NickWelsh\EloquentZero\Support\Mode;

return [
    'mode' => Mode::OptOut,
    'model_search_directories' => [
        app_path('Models'),
    ],
    'models' => [],
    'output_path' => resource_path('js/zero/schema.ts'),
    'table_name_casing' => Casing::Camel,
    'column_name_casing' => Casing::Camel,
    'use_wayfinder' => false,
    'connection' => null,
    'allow_multiple_connections' => false,
    'publication_name' => null,
];
