<?php

namespace NickWelsh\EloquentZero\Publications;

use Illuminate\Console\OutputStyle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use NickWelsh\EloquentZero\Attributes\ZeroExclude;
use NickWelsh\EloquentZero\Attributes\ZeroGenerate;
use NickWelsh\EloquentZero\Attributes\ZeroIgnore;
use NickWelsh\EloquentZero\Support\Mode;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

final class ZeroPublicationSynchronizer
{
    /**
     * @param  array<int, class-string<Model>>  $explicitModels
     * @return list<string>
     */
    public function plan(
        array $explicitModels,
        ?string $forcedConnection,
        ?string $publicationName,
        OutputStyle $output,
    ): array {
        $models = $this->resolveModels($explicitModels, $output);

        if ($models === []) {
            throw new RuntimeException('No models selected for publication sync.');
        }

        $connectionName = $this->resolveConnectionName($models, $forcedConnection);
        $connection = DB::connection($connectionName);

        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('eloquent-zero only supports Postgres connections.');
        }

        $this->assertMigrationsAreCurrent($connectionName);

        $publicationName ??= $this->defaultPublicationName();
        $requiredColumnsByTable = $this->requiredColumnsByTable($models);
        $tablePlans = [];

        foreach ($models as $model) {
            $tablePlans[] = $this->tablePlanForModel(
                $model,
                $connectionName,
                $requiredColumnsByTable[$model->getTable()] ?? [],
                $output,
            );
        }

        foreach ($this->pivotTableNames($models) as $pivotTable) {
            if (collect($tablePlans)->contains(fn (array $tablePlan): bool => $tablePlan['table'] === $pivotTable)) {
                continue;
            }

            $tablePlans[] = $this->tablePlanForTable(
                tableName: $pivotTable,
                connectionName: $connectionName,
                requiredColumns: $requiredColumnsByTable[$pivotTable] ?? [],
                output: $output,
            );
        }

        usort($tablePlans, fn (array $left, array $right): int => $left['table'] <=> $right['table']);

        $tableClauses = array_map(fn (array $tablePlan): string => $tablePlan['sql'], $tablePlans);

        return [
            sprintf('DROP PUBLICATION IF EXISTS %s;', $this->quoteIdentifier($publicationName)),
            sprintf(
                'CREATE PUBLICATION %s FOR TABLE %s;',
                $this->quoteIdentifier($publicationName),
                implode(', ', $tableClauses),
            ),
        ];
    }

    /**
     * @param  list<string>  $sql
     */
    public function apply(array $sql, ?string $forcedConnection, OutputStyle $output): void
    {
        $connectionName = $forcedConnection;

        if (! is_string($connectionName) || $connectionName === '') {
            $configuredConnection = config('eloquent-zero.connection');
            $connectionName = is_string($configuredConnection) && $configuredConnection !== ''
                ? $configuredConnection
                : null;
        }

        $connection = DB::connection($connectionName);

        $connection->transaction(function () use ($connection, $output, $sql): void {
            foreach ($sql as $statement) {
                $output->writeln($statement);
                $connection->statement($statement);
            }
        });
    }

    /**
     * @param  array<int, Model>  $models
     */
    private function resolveConnectionName(array $models, ?string $forcedConnection): ?string
    {
        if ($forcedConnection !== null) {
            return $forcedConnection;
        }

        $configuredConnection = config('eloquent-zero.connection');

        if (is_string($configuredConnection) && $configuredConnection !== '') {
            return $configuredConnection;
        }

        $connectionNames = array_values(array_unique(array_map(
            static fn (Model $model): string => $model->getConnectionName() ?: config('database.default'),
            $models,
        )));

        if (count($connectionNames) > 1 && ! config('eloquent-zero.allow_multiple_connections', false)) {
            throw new RuntimeException('Selected models span multiple database connections.');
        }

        return $connectionNames[0] ?? null;
    }

    /**
     * @param  array<int, class-string<Model>>  $explicitModels
     * @return array<int, Model>
     */
    private function resolveModels(array $explicitModels, OutputStyle $output): array
    {
        $configuredModels = config('eloquent-zero.models', []);
        $discoveredModels = $this->discoverModels(
            config('eloquent-zero.model_search_directories', []),
        );

        $modelClasses = collect([...$discoveredModels, ...$configuredModels, ...$explicitModels])
            ->unique()
            ->values();

        return $modelClasses
            ->map(function (string $modelClass) use ($explicitModels, $output): ?Model {
                if (! class_exists($modelClass)) {
                    return null;
                }

                $reflection = new ReflectionClass($modelClass);

                if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                    return null;
                }

                $isExplicit = in_array($modelClass, $explicitModels, true);

                if (! $isExplicit && ! $this->shouldIncludeModel($reflection, $output)) {
                    return null;
                }

                return $reflection->newInstance();
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $directories
     * @return array<int, class-string<Model>>
     */
    private function discoverModels(array $directories): array
    {
        return collect($directories)
            ->filter(fn (?string $directory): bool => is_string($directory))
            ->flatMap(fn (string $directory): array => $this->expandModelSearchDirectory($directory))
            ->filter(fn (string $directory): bool => is_dir($directory))
            ->unique()
            ->flatMap(function (string $directory): array {
                return collect(File::allFiles($directory))
                    ->filter(fn ($file): bool => $file->getExtension() === 'php')
                    ->map(function ($file): ?string {
                        $class = $this->extractClassName($file->getRealPath());

                        if ($class !== null && ! class_exists($class, false)) {
                            require_once $file->getRealPath();
                        }

                        return $class;
                    })
                    ->all();
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function expandModelSearchDirectory(string $directory): array
    {
        if (! strpbrk($directory, '*?[')) {
            return [$directory];
        }

        return File::glob($directory) ?: [];
    }

    private function extractClassName(string $path): ?string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $tokens = token_get_all($contents);
        $namespace = '';
        $className = null;

        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->collectTokenValue($tokens, $index + 1);
            }

            if ($token[0] === T_CLASS) {
                $className = $this->collectTokenValue($tokens, $index + 1, true);
                break;
            }
        }

        if ($className === null) {
            return null;
        }

        return $namespace !== '' ? $namespace.'\\'.$className : $className;
    }

    /**
     * @param  array<int, mixed>  $tokens
     */
    private function collectTokenValue(array $tokens, int $index, bool $stopAfterName = false): string
    {
        $value = '';

        for ($cursor = $index; $cursor < count($tokens); $cursor++) {
            $token = $tokens[$cursor];

            if (! is_array($token)) {
                if ($stopAfterName || $token === ';' || $token === '{') {
                    break;
                }

                continue;
            }

            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                $value .= $token[1];

                if ($stopAfterName) {
                    break;
                }
            }
        }

        return $value;
    }

    private function shouldIncludeModel(ReflectionClass $reflection, OutputStyle $output): bool
    {
        $hasIgnore = $reflection->getAttributes(ZeroIgnore::class) !== [];
        $hasGenerate = $reflection->getAttributes(ZeroGenerate::class) !== [];

        if ($hasIgnore && $hasGenerate) {
            $output->writeln("Warning: Model [{$reflection->getName()}] has both ZeroIgnore and ZeroGenerate attributes.");
        }

        $mode = config('eloquent-zero.mode', Mode::OptOut);
        $mode = $mode instanceof Mode ? $mode : Mode::from($mode);

        return match ($mode) {
            Mode::OptIn => $hasGenerate,
            Mode::OptOut => ! $hasIgnore,
        };
    }

    /**
     * @param  array<int, Model>  $models
     * @return array<string, array<int, string>>
     */
    private function requiredColumnsByTable(array $models): array
    {
        $requiredColumns = [];

        foreach ($models as $model) {
            foreach ($this->relationMethods($model) as $method) {
                $relation = $model->{$method->getName()}();

                if ($relation instanceof BelongsTo) {
                    $requiredColumns[$relation->getChild()->getTable()][] = $relation->getForeignKeyName();
                }

                if ($relation instanceof HasOne || $relation instanceof HasMany) {
                    $requiredColumns[$relation->getRelated()->getTable()][] = $relation->getForeignKeyName();
                }

                if ($relation instanceof BelongsToMany) {
                    $requiredColumns[$relation->getTable()][] = $relation->getForeignPivotKeyName();
                    $requiredColumns[$relation->getTable()][] = $relation->getRelatedPivotKeyName();
                }
            }
        }

        return collect($requiredColumns)
            ->map(fn (array $columns): array => array_values(array_unique($columns)))
            ->all();
    }

    /**
     * @param  array<int, Model>  $models
     * @return list<string>
     */
    private function pivotTableNames(array $models): array
    {
        return collect($models)
            ->flatMap(function (Model $model): Collection {
                return $this->relationMethods($model)
                    ->map(fn (ReflectionMethod $method): mixed => $model->{$method->getName()}())
                    ->filter(fn (mixed $relation): bool => $relation instanceof BelongsToMany)
                    ->map(fn (BelongsToMany $relation): string => $relation->getTable());
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, ReflectionMethod>
     */
    private function relationMethods(Model $model): Collection
    {
        return collect((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->class === $model::class)
            ->filter(fn (ReflectionMethod $method): bool => $method->getNumberOfParameters() === 0)
            ->filter(fn (ReflectionMethod $method): bool => ! $method->isStatic())
            ->filter(function (ReflectionMethod $method): bool {
                $returnType = $method->getReturnType();

                return $returnType instanceof ReflectionNamedType
                    && ! $returnType->isBuiltin()
                    && is_a($returnType->getName(), Relation::class, true);
            })
            ->values();
    }

    /**
     * @param  array<int, string>  $requiredColumns
     * @return array{table: string, sql: string}
     */
    private function tablePlanForModel(Model $model, ?string $connectionName, array $requiredColumns, OutputStyle $output): array
    {
        $columns = Schema::connection($connectionName)->getColumns($model->getTable());
        $columnNames = array_column($columns, 'name');
        $excludedColumns = $this->excludedColumnsForModel($model, $columnNames);

        return $this->buildTablePlan($model->getTable(), $columnNames, $excludedColumns, $requiredColumns, $output);
    }

    /**
     * @param  array<int, string>  $requiredColumns
     * @return array{table: string, sql: string}
     */
    private function tablePlanForTable(string $tableName, ?string $connectionName, array $requiredColumns, OutputStyle $output): array
    {
        $columns = Schema::connection($connectionName)->getColumns($tableName);
        $columnNames = array_column($columns, 'name');

        return $this->buildTablePlan($tableName, $columnNames, [], $requiredColumns, $output);
    }

    /**
     * @param  array<int, string>  $columnNames
     * @param  array<int, string>  $excludedColumns
     * @param  array<int, string>  $requiredColumns
     * @return array{table: string, sql: string}
     */
    private function buildTablePlan(
        string $tableName,
        array $columnNames,
        array $excludedColumns,
        array $requiredColumns,
        OutputStyle $output,
    ): array {
        foreach ($excludedColumns as $excludedColumn) {
            if (! in_array($excludedColumn, $columnNames, true)) {
                throw new RuntimeException("ZeroExclude on [{$tableName}] references missing column [{$excludedColumn}].");
            }
        }

        $effectiveExcludedColumns = array_values(array_intersect($excludedColumns, $columnNames));

        foreach ($requiredColumns as $requiredColumn) {
            if (in_array($requiredColumn, $effectiveExcludedColumns, true)) {
                $output->writeln("Warning: Forcing column [{$tableName}.{$requiredColumn}] into publication because it is required.");
                $effectiveExcludedColumns = array_values(array_diff($effectiveExcludedColumns, [$requiredColumn]));
            }

            if (! in_array($requiredColumn, $columnNames, true)) {
                throw new RuntimeException("Required relation column [{$tableName}.{$requiredColumn}] is missing from database.");
            }
        }

        if ($effectiveExcludedColumns === []) {
            return [
                'table' => $tableName,
                'sql' => $this->quoteTableIdentifier($tableName),
            ];
        }

        $includedColumns = array_values(array_diff($columnNames, $effectiveExcludedColumns));

        if ($includedColumns === []) {
            throw new RuntimeException("Table [{$tableName}] has no columns left for publication.");
        }

        return [
            'table' => $tableName,
            'sql' => sprintf(
                '%s (%s)',
                $this->quoteTableIdentifier($tableName),
                implode(', ', array_map($this->quoteIdentifier(...), $includedColumns)),
            ),
        ];
    }

    /**
     * @param  array<int, string>  $columnNames
     * @return array<int, string>
     */
    private function excludedColumnsForModel(Model $model, array $columnNames): array
    {
        $attributes = (new ReflectionClass($model))->getAttributes(ZeroExclude::class);

        if ($attributes !== []) {
            /** @var ZeroExclude $instance */
            $instance = $attributes[0]->newInstance();

            return $instance->columns;
        }

        return array_values(array_intersect($model->getHidden(), $columnNames));
    }

    private function defaultPublicationName(): string
    {
        $configuredPublicationName = config('eloquent-zero.publication_name');

        if (is_string($configuredPublicationName) && $configuredPublicationName !== '') {
            return $configuredPublicationName;
        }

        $appId = config('app.id');

        if (! is_string($appId) || $appId === '') {
            throw new RuntimeException('Publication name not configured. Set eloquent-zero.publication_name or pass --name.');
        }

        return "_{$appId}_public_0";
    }

    private function quoteIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    private function quoteTableIdentifier(string $value): string
    {
        return collect(explode('.', $value))
            ->map(fn (string $segment): string => $this->quoteIdentifier($segment))
            ->implode('.');
    }

    private function assertMigrationsAreCurrent(?string $connectionName): void
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $connectionName ??= config('database.default');

        $pendingMigrations = $migrator->usingConnection($connectionName, function () use ($migrator, $connectionName): array {
            $repository = $migrator->getRepository();
            $repository->setSource($connectionName);

            if (! $migrator->repositoryExists()) {
                throw new RuntimeException('Migration repository not found. Run `php artisan migrate` before syncing Zero publication.');
            }

            $paths = array_unique(array_merge(
                $migrator->paths(),
                [database_path('migrations')],
            ));

            $files = $migrator->getMigrationFiles($paths);
            $ran = $repository->getRan();

            return array_values(array_diff(array_keys($files), $ran));
        });

        if ($pendingMigrations !== []) {
            $preview = implode(', ', array_slice($pendingMigrations, 0, 3));
            $suffix = count($pendingMigrations) > 3 ? ', ...' : '';

            throw new RuntimeException(
                'Database schema may be out of date. Run `php artisan migrate` before syncing Zero publication. Pending migrations: '
                .$preview
                .$suffix
            );
        }
    }
}
