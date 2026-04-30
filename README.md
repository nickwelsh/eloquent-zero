# Generate Zero schemas from Eloquent Models

[![Latest Version on Packagist](https://img.shields.io/packagist/v/nickwelsh/eloquent-zero.svg?style=flat-square)](https://packagist.org/packages/nickwelsh/eloquent-zero)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/nickwelsh/eloquent-zero/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/nickwelsh/eloquent-zero/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/nickwelsh/eloquent-zero/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/nickwelsh/eloquent-zero/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/nickwelsh/eloquent-zero.svg?style=flat-square)](https://packagist.org/packages/nickwelsh/eloquent-zero)

`eloquent-zero` generates a typed [Zero](https://zero.rocicorp.dev/) schema from your Laravel Eloquent models and Postgres database.

It reads your models, database columns, primary keys, enum types, and Eloquent relationships, then writes a `schema.ts` file ready for Zero. It can also sync a Postgres publication for those models.

> Warning
> This package is very early. Expect rough edges, missing features, and breaking changes.

## What it does

- generates Zero tables from Eloquent models
- maps `belongsTo`, `hasOne`, `hasMany`, and `belongsToMany` relationships
- respects model hidden fields and column allowlists
- syncs Postgres publication column lists from model metadata
- supports renaming the emitted Zero schema with a PHP attribute
- validates that migrations are current before generating

## Requirements

- PHP 8.4+
- Laravel 11+
- PostgreSQL

`eloquent-zero` currently only supports Postgres connections.

## Installation

You can install the package via composer:

```bash
composer require nickwelsh/eloquent-zero:dev-main
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="eloquent-zero-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="eloquent-zero-config"
```

Published config:

```php
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
    'use_wayfinder' => false,
    'connection' => null,
    'allow_multiple_connections' => false,
    'publication_name' => null,
];
```

## Usage

Generate the schema:

```bash
php artisan generate:zero-schema
```

Generate from explicit models only:

```bash
php artisan generate:zero-schema \
  --model="App\\Models\\User" \
  --model="App\\Models\\Post"
```

Override output path:

```bash
php artisan generate:zero-schema --path=resources/js/zero/custom-schema.ts
```

Force a connection:

```bash
php artisan generate:zero-schema --connection=pgsql
```

Sync Postgres publication:

```bash
php artisan zero:sync-publication
```

Validate publication changes without applying:

```bash
php artisan zero:sync-publication --dry-run
```

## Example

Given these models:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}

class Post extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

`eloquent-zero` will generate a schema shaped like:

```ts
const user = table('users').columns({
  id: string(),
}).primaryKey('id')

const post = table('posts').columns({
  id: string(),
  userId: string().from('user_id'),
}).primaryKey('id')
```

with matching `relationships(...)` blocks and exported Zero types.

## Attributes

### `#[ZeroName('...')]`

Rename the emitted Zero schema while still reading from the model's underlying table.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use NickWelsh\EloquentZero\Attributes\ZeroName;

#[ZeroName('people')]
class User extends Model {}
```

That generates:

```ts
const person = table('people')
  .from('users')
```

### `#[ZeroColumns([...])]`

Limit which columns are included in the generated schema.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use NickWelsh\EloquentZero\Attributes\ZeroColumns;

#[ZeroColumns(['id', 'title'])]
class Comment extends Model {}
```

If a relation needs a foreign key column that you excluded, `eloquent-zero` will force it back in and emit a warning.

`#[ZeroColumns]` only affects emitted TypeScript schema. It does not change Postgres publication columns.

### `#[ZeroJson('column', ...)]`

Type a JSON or JSONB column with a custom TypeScript type.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use NickWelsh\EloquentZero\Attributes\ZeroJson;

#[ZeroJson('metadata', type: 'RelationMetadata', import: '@/types/crm')]
class PartyRelation extends Model {}
```

That generates:

```ts
import type { RelationMetadata } from '@/types/crm';

metadata: json<RelationMetadata>()
```

When `use_wayfinder` is enabled, you may pass a PHP class without an import:

```php
#[ZeroJson('metadata', App\Data\RelationMetadata::class)]
class PartyRelation extends Model {}
```

That generates:

```ts
metadata: json<App.Data.RelationMetadata>()
```

If `import` is set, the imported TypeScript type is always used, even when `use_wayfinder` is enabled. Without `use_wayfinder`, `import` is required.

### `#[ZeroExclude([...])]`

Exclude columns from Zero Postgres publication sync.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use NickWelsh\EloquentZero\Attributes\ZeroExclude;

#[ZeroExclude(['password'])]
class User extends Model {}
```

Publication rules:

- default: infer excluded publication columns from model `$hidden`
- override: if `#[ZeroExclude([...])]` exists, use only that list for publication exclusion
- safety: required relation columns are forced back into publication with warning

### `#[ZeroIgnore]`

Skip a model when running in opt-out mode.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use NickWelsh\EloquentZero\Attributes\ZeroIgnore;

#[ZeroIgnore]
class InternalAuditLog extends Model {}
```

### `#[ZeroGenerate]`

Only include marked models when running in opt-in mode.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use NickWelsh\EloquentZero\Attributes\ZeroGenerate;

#[ZeroGenerate]
class User extends Model {}
```

## Model selection

`mode` controls how models are picked:

- `Mode::OptOut`: include discovered models unless they have `#[ZeroIgnore]`
- `Mode::OptIn`: include only models with `#[ZeroGenerate]`

Models can come from:

- `model_search_directories`
- explicit `models` config entries
- `--model` CLI arguments

`model_search_directories` accepts plain directories and glob patterns, for example `base_path('modules/*/Models')`.

Tables without Eloquent models can come from `tables` config:

```php
'tables' => [
    'model_has_roles' => true,
    'model_has_permissions' => ['permission_id', 'model_type', 'model_id'],
],
```

Use `true` for all columns. Use an array to allow only listed columns. These tables are added to generated Zero schema and Postgres publication sync.

## Name casing

By default, table and column names are emitted in camelCase.

Examples:

- table `blog_posts` -> Zero schema `blogPosts`
- column `created_at` -> Zero column `createdAt`

You can change this with:

- `table_name_casing`
- `column_name_casing`

## Safety checks

Before generation, the package:

- verifies pending migrations do not exist
- verifies relations match real database foreign keys
- verifies `ZeroColumns` entries point at real columns
- falls back to a single unique index if a table has no primary key

## Testing

```bash
vendor/bin/pest
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Nick Welsh](https://github.com/nickwelsh)
- [All Contributors](https://github.com/nickwelsh/eloquent-zero/contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
