<?php

namespace NickWelsh\EloquentZero\Generators;

use BackedEnum;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use NickWelsh\EloquentZero\Attributes\ZeroColumns;
use NickWelsh\EloquentZero\Attributes\ZeroGenerate;
use NickWelsh\EloquentZero\Attributes\ZeroIgnore;
use NickWelsh\EloquentZero\Attributes\ZeroName;
use NickWelsh\EloquentZero\Support\Casing;
use NickWelsh\EloquentZero\Support\Mode;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

final class ZeroSchemaGenerator
{
    /**
     * @param  array<int, class-string<Model>>  $explicitModels
     */
    public function generate(
        string $outputPath,
        array $explicitModels,
        ?string $forcedConnection,
        OutputStyle $output,
    ): GeneratedSchemaResult {
        $models = $this->resolveModels($explicitModels, $output);

        if ($models === []) {
            throw new RuntimeException('No models selected for generation.');
        }

        $connectionName = $this->resolveConnectionName($models, $forcedConnection);
        $connection = app('db')->connection($connectionName);

        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('eloquent-zero only supports Postgres connections.');
        }

        $this->assertMigrationsAreCurrent($connectionName);

        $enumTypes = $this->getEnumTypes($connectionName);
        $requiredColumnsByTable = $this->requiredColumnsByTable($models);
        $tables = [];

        foreach ($models as $model) {
            $tables[] = $this->buildTableDefinition(
                $model,
                $connectionName,
                $enumTypes,
                $output,
                $requiredColumnsByTable[$model->getTable()] ?? [],
            );
        }

        $tables = $this->ensurePivotTables($models, $tables, $connectionName, $enumTypes, $output, $requiredColumnsByTable);

        $contents = $this->render(
            $tables,
            $this->buildRelationships($models, $tables, $connectionName, $output),
        );

        File::ensureDirectoryExists(dirname($outputPath));

        $existing = File::exists($outputPath) ? File::get($outputPath) : null;

        if ($existing === $contents) {
            return new GeneratedSchemaResult($outputPath, false);
        }

        File::put($outputPath, $contents);

        return new GeneratedSchemaResult($outputPath, true);
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
     * @param  array<int, Model>  $models
     */
    private function resolveConnectionName(array $models, ?string $forcedConnection): ?string
    {
        if ($forcedConnection !== null) {
            return $forcedConnection;
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
     * @param  array<string, list<string>>  $enumTypes
     * @return array{name: string, variable: string, serverName: string, columns: array<int, array{name: string, serverName: string, type: string, optional: bool}>, primaryKey: array<int, string>}
     */
    private function buildTableDefinition(Model $model, ?string $connectionName, array $enumTypes, OutputStyle $output, array $requiredColumns = []): array
    {
        return $this->buildTableDefinitionForTable(
            $model->getTable(),
            $connectionName,
            $enumTypes,
            $output,
            $model->getHidden(),
            $model->getCasts(),
            $this->allowedColumnsForModel($model),
            $requiredColumns,
            $this->schemaNameForModel($model),
        );
    }

    /**
     * @param  array<string, list<string>>  $enumTypes
     * @param  array<int, string>  $hiddenColumns
     * @param  array<string, mixed>  $casts
     * @param  array<int, string>|null  $allowedColumns
     * @param  array<int, string>  $requiredColumns
     * @return array{name: string, variable: string, serverName: string, columns: array<int, array{name: string, serverName: string, type: string, optional: bool}>, primaryKey: array<int, string>}
     */
    private function buildTableDefinitionForTable(
        string $tableName,
        ?string $connectionName,
        array $enumTypes,
        OutputStyle $output,
        array $hiddenColumns = [],
        array $casts = [],
        ?array $allowedColumns = null,
        array $requiredColumns = [],
        ?string $schemaName = null,
    ): array {
        $schema = Schema::connection($connectionName);
        $columns = $schema->getColumns($tableName);
        $indexes = $schema->getIndexes($tableName);
        $columnNames = array_column($columns, 'name');

        if ($allowedColumns !== null) {
            foreach ($allowedColumns as $allowedColumn) {
                if (! in_array($allowedColumn, $columnNames, true)) {
                    throw new RuntimeException("ZeroColumns on [{$tableName}] references missing column [{$allowedColumn}].");
                }
            }
        }

        $primaryKey = collect($indexes)
            ->firstWhere('primary', true)['columns'] ?? null;

        if ($primaryKey === null) {
            $uniqueIndexes = collect($indexes)
                ->filter(fn (array $index): bool => (bool) $index['unique'])
                ->values();

            if ($uniqueIndexes->count() === 1) {
                $primaryKey = $uniqueIndexes->first()['columns'];
            }
        }

        if ($primaryKey === null) {
            throw new RuntimeException("Table [{$tableName}] is missing a primary key or a single usable unique index. If this is unexpected, ensure your migrations are current with `php artisan migrate`.");
        }

        $tableZeroName = $schemaName ?? $this->transformName($tableName, config('eloquent-zero.table_name_casing'));
        $tableVariable = $this->sanitizeVariableName(Str::singular($tableZeroName));

        $tableColumns = [];

        foreach ($columns as $column) {
            $isRequired = in_array($column['name'], $requiredColumns, true) || in_array($column['name'], $primaryKey, true);
            $isHidden = in_array($column['name'], $hiddenColumns, true);
            $isAllowed = $allowedColumns === null || in_array($column['name'], $allowedColumns, true);

            if (($isHidden || ! $isAllowed) && ! $isRequired) {
                continue;
            }

            if (($isHidden || ! $isAllowed) && $isRequired) {
                $this->emitWarning($output, "Forcing column [{$tableName}.{$column['name']}] into schema because it is required.");
            }

            try {
                $type = $this->resolveColumnType($casts, $tableName, $column, $enumTypes, $output);
            } catch (RuntimeException $exception) {
                if ($isRequired) {
                    throw $exception;
                }

                $this->emitWarning($output, "Unsupported column type [{$column['type_name']}] on [{$tableName}.{$column['name']}]; skipping column.");

                continue;
            }

            $tableColumns[] = [
                'name' => $this->transformName($column['name'], config('eloquent-zero.column_name_casing')),
                'serverName' => $column['name'],
                'type' => $type,
                'optional' => (bool) $column['nullable'] || $column['default'] !== null,
            ];
        }

        return [
            'name' => $tableZeroName,
            'variable' => $tableVariable,
            'serverName' => $tableName,
            'columns' => $tableColumns,
            'primaryKey' => array_map(
                fn (string $column): string => $this->transformName($column, config('eloquent-zero.column_name_casing')),
                $primaryKey,
            ),
        ];
    }

    private function transformName(string $name, Casing|string|null $casing): string
    {
        if ($casing instanceof Casing) {
            return $casing->transform($name);
        }

        if (is_string($casing) && $casing !== '' && method_exists(Str::class, $casing)) {
            /** @var string $transformed */
            $transformed = Str::{$casing}($name);

            return $transformed;
        }

        return $name;
    }

    private function sanitizeVariableName(string $name): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? $name;
        $sanitized = preg_replace('/^[^A-Za-z_]+/', '', $sanitized) ?? $sanitized;

        return $sanitized === '' ? 'tableSchema' : $sanitized;
    }

    /**
     * @param  array{name: string, type: string, type_name: string, nullable: bool, default: mixed, auto_increment: bool, comment: ?string, generation: ?array}  $column
     * @param  array<string, list<string>>  $enumTypes
     */
    private function resolveColumnType(array $casts, string $tableName, array $column, array $enumTypes, OutputStyle $output): string
    {
        $cast = $casts[$column['name']] ?? null;

        if (is_string($cast) && enum_exists($cast)) {
            if (! is_subclass_of($cast, BackedEnum::class)) {
                $this->emitWarning($output, "Non-backed enum cast [{$cast}] on [{$tableName}.{$column['name']}] fell back to string().");

                return 'string()';
            }

            $enumValues = array_map(
                static fn (BackedEnum $case): string => (string) $case->value,
                $cast::cases(),
            );

            $databaseEnumValues = $enumTypes[$column['type_name']] ?? null;

            if ($databaseEnumValues !== null && $databaseEnumValues !== $enumValues) {
                throw new RuntimeException("Enum cast [{$cast}] does not match database enum [{$column['type_name']}].");
            }

            if (config('eloquent-zero.use_wayfinder', false)) {
                $wayfinderType = str_replace('\\', '.', $cast);

                return "enumeration<{$wayfinderType}>()";
            }

            return 'enumeration<'.$this->renderEnumerationType($enumValues).'>()';
        }

        if (array_key_exists($column['type_name'], $enumTypes)) {
            return 'enumeration<'.$this->renderEnumerationType($enumTypes[$column['type_name']]).'>()';
        }

        return $this->mapScalarColumnType($column['type_name']);
    }

    private function mapScalarColumnType(string $typeName): string
    {
        return match ($typeName) {
            'bool', 'boolean' => 'boolean()',
            'json', 'jsonb' => 'json()',
            'date',
            'time',
            'time without time zone',
            'timetz',
            'time with time zone',
            'timestamp',
            'timestamptz',
            'timestamp without time zone',
            'timestamp with time zone' => 'number()',
            'int2',
            'smallint',
            'int4',
            'integer',
            'int8',
            'bigint',
            'serial',
            'serial4',
            'bigserial',
            'serial8',
            'decimal',
            'numeric',
            'float4',
            'real',
            'float8',
            'double precision' => 'number()',
            'char',
            'bpchar',
            'varchar',
            'text',
            'uuid' => 'string()',
            default => throw new RuntimeException("Unsupported column type [{$typeName}]."),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $tables
     * @param  array<int, array<string, mixed>>  $relationships
     */
    private function render(array $tables, array $relationships): string
    {
        $lines = [
            '// @generated by eloquent-zero',
            '// Do not edit directly.',
            '',
            "import { createBuilder, createSchema, table, string, number, boolean, json, enumeration, relationships } from '@rocicorp/zero';",
            "import type { Row } from '@rocicorp/zero';",
            '',
        ];

        foreach ($tables as $table) {
            $lines[] = "const {$table['variable']} = table('{$table['name']}')";

            if ($table['serverName'] !== $table['name']) {
                $lines[] = "  .from('{$table['serverName']}')";
            }

            $lines[] = '  .columns({';

            foreach ($table['columns'] as $column) {
                $key = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column['name']) === 1
                    ? $column['name']
                    : "'{$column['name']}'";

                $value = $column['type'];

                if ($column['serverName'] !== $column['name']) {
                    $value .= ".from('{$column['serverName']}')";
                }

                if ($column['optional']) {
                    $value .= '.optional()';
                }

                $lines[] = "    {$key}: {$value},";
            }

            $primaryKeys = implode("', '", $table['primaryKey']);

            $lines[] = '  })';
            $lines[] = "  .primaryKey('{$primaryKeys}')";
            $lines[] = '';
        }

        foreach ($relationships as $relationshipGroup) {
            $helpers = implode(', ', array_unique(array_map(
                static fn (array $relation): string => $relation['helper'],
                $relationshipGroup['relations'],
            )));

            $lines[] = "const {$relationshipGroup['variable']} = relationships(";
            $lines[] = "  {$relationshipGroup['tableVariable']},";
            $lines[] = "  ({ {$helpers} }) => ({";

            foreach ($relationshipGroup['relations'] as $relation) {
                $relationLines = [];

                foreach ($relation['chain'] as $index => $hop) {
                    $relationLines[] = ($index === 0 ? "{$relation['helper']}(" : '      {');
                    $relationLines[] = "        sourceField: ['".implode("', '", $hop['source'])."'],";
                    $relationLines[] = "        destSchema: {$hop['destVariable']},";
                    $relationLines[] = "        destField: ['".implode("', '", $hop['dest'])."'],";
                    $relationLines[] = $index === count($relation['chain']) - 1 ? '      })' : '      },';
                }

                if (count($relation['chain']) > 1) {
                    $relationLines[count($relationLines) - 1] = '      }';
                    array_unshift($relationLines, "{$relation['helper']}(");
                    $relationLines[] = '    )';
                }

                if (count($relation['chain']) === 1) {
                    $body = [
                        "    {$relation['name']}: {$relation['helper']}({",
                        "      sourceField: ['".implode("', '", $relation['chain'][0]['source'])."'],",
                        "      destSchema: {$relation['chain'][0]['destVariable']},",
                        "      destField: ['".implode("', '", $relation['chain'][0]['dest'])."'],",
                        '    }),',
                    ];
                } else {
                    $body = [
                        "    {$relation['name']}: {$relation['helper']}(",
                    ];

                    foreach ($relation['chain'] as $hopIndex => $hop) {
                        $body[] = '      {';
                        $body[] = "        sourceField: ['".implode("', '", $hop['source'])."'],";
                        $body[] = "        destSchema: {$hop['destVariable']},";
                        $body[] = "        destField: ['".implode("', '", $hop['dest'])."'],";
                        $body[] = $hopIndex === count($relation['chain']) - 1 ? '      },' : '      },';
                    }

                    $body[] = '    ),';
                }

                array_push($lines, ...$body);
            }

            $lines[] = '  }),';
            $lines[] = ');';
            $lines[] = '';
        }

        $tableVariables = implode(', ', array_map(
            static fn (array $table): string => $table['variable'],
            $tables,
        ));
        $relationshipVariables = implode(', ', array_map(
            static fn (array $relationship): string => $relationship['variable'],
            $relationships,
        ));

        $lines[] = 'export const schema = createSchema({';
        $lines[] = "  tables: [{$tableVariables}],";
        $lines[] = '  relationships: ['.($relationshipVariables !== '' ? $relationshipVariables : '').'],';
        $lines[] = '});';
        $lines[] = '';
        $lines[] = 'export type Schema = Row<typeof schema>;';

        foreach ($tables as $table) {
            $typeName = Str::studly($table['variable']);
            $lines[] = "export type {$typeName} = Schema['{$table['name']}'];";
        }

        $lines[] = '';
        $lines[] = 'export const zql = createBuilder(schema);';
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  array<int, Model>  $models
     * @param  array<int, array<string, mixed>>  $tables
     * @return array<int, array<string, mixed>>
     */
    private function buildRelationships(array $models, array $tables, ?string $connectionName, OutputStyle $output): array
    {
        $tableMap = collect($tables)->keyBy('serverName');
        $selectedTables = $tableMap->keys()->all();
        $relationshipGroups = [];

        foreach ($models as $model) {
            $table = $tableMap->get($model->getTable());

            if ($table === null) {
                continue;
            }

            $relations = [];

            foreach ($this->relationMethods($model) as $method) {
                $relation = $model->{$method->getName()}();

                if (! $relation instanceof Relation) {
                    continue;
                }

                $relationDefinition = $this->buildRelationDefinition(
                    $method->getName(),
                    $relation,
                    $selectedTables,
                    $tableMap,
                    $connectionName,
                    $output,
                );

                if ($relationDefinition !== null) {
                    $relations[] = $relationDefinition;
                }
            }

            if ($relations === []) {
                continue;
            }

            $relationshipGroups[] = [
                'variable' => $table['variable'].'Relationships',
                'tableVariable' => $table['variable'],
                'kind' => 'group',
                'relations' => $relations,
            ];
        }

        return $relationshipGroups;
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
            });
    }

    /**
     * @param  array<int, string>  $selectedTables
     * @param  Collection<(int|string), array<string, mixed>>  $tableMap
     * @return array{name: string, helper: string, chain: array<int, array{source: array<int, string>, destVariable: string, dest: array<int, string>}>}|null
     */
    private function buildRelationDefinition(
        string $relationName,
        Relation $relation,
        array $selectedTables,
        Collection $tableMap,
        ?string $connectionName,
        OutputStyle $output,
    ): ?array {
        return match (true) {
            $relation instanceof BelongsTo => $this->buildBelongsToRelation($relationName, $relation, $selectedTables, $tableMap, $connectionName, $output),
            $relation instanceof HasMany => $this->buildHasOneOrManyRelation($relationName, $relation, 'many', $selectedTables, $tableMap, $connectionName, $output),
            $relation instanceof HasOne => $this->buildHasOneOrManyRelation($relationName, $relation, 'one', $selectedTables, $tableMap, $connectionName, $output),
            $relation instanceof BelongsToMany => $this->buildBelongsToManyRelation($relationName, $relation, $selectedTables, $tableMap, $connectionName, $output),
            default => tap(null, fn () => $this->emitWarning($output, 'Unsupported relation type ['.get_class($relation)."] for [{$relationName}].")),
        };
    }

    /**
     * @param  array<int, string>  $selectedTables
     * @param  Collection<(int|string), array<string, mixed>>  $tableMap
     * @return array{name: string, helper: string, chain: array<int, array{source: array<int, string>, destVariable: string, dest: array<int, string>}>}|null
     */
    private function buildBelongsToRelation(
        string $relationName,
        BelongsTo $relation,
        array $selectedTables,
        Collection $tableMap,
        ?string $connectionName,
        OutputStyle $output,
    ): ?array {
        $relatedTable = $relation->getRelated()->getTable();
        $relationLabel = class_basename($relation->getChild()).'::'.$relationName;

        if (! in_array($relatedTable, $selectedTables, true)) {
            return null;
        }

        $this->assertTableHasColumns(
            $relation->getChild()->getTable(),
            [$relation->getForeignKeyName()],
            $connectionName,
            $relationLabel,
        );
        $this->assertTableHasColumns(
            $relatedTable,
            [$relation->getOwnerKeyName()],
            $connectionName,
            $relationLabel,
        );

        if (! $this->hasMatchingForeignKey(
            $relation->getChild()->getTable(),
            [$relation->getForeignKeyName()],
            $relatedTable,
            [$relation->getOwnerKeyName()],
            $connectionName,
        )) {
            throw new RuntimeException("Relation [{$relationLabel}] does not match a database foreign key.");
        }

        return [
            'name' => $relationName,
            'helper' => 'one',
            'chain' => [[
                'source' => [$this->transformName($relation->getForeignKeyName(), config('eloquent-zero.column_name_casing'))],
                'destVariable' => $tableMap->get($relatedTable)['variable'],
                'dest' => [$this->transformName($relation->getOwnerKeyName(), config('eloquent-zero.column_name_casing'))],
            ]],
        ];
    }

    /**
     * @param  array<int, string>  $selectedTables
     * @param  Collection<(int|string), array<string, mixed>>  $tableMap
     * @return array{name: string, helper: string, chain: array<int, array{source: array<int, string>, destVariable: string, dest: array<int, string>}>}|null
     */
    private function buildHasOneOrManyRelation(
        string $relationName,
        HasOne|HasMany $relation,
        string $helper,
        array $selectedTables,
        Collection $tableMap,
        ?string $connectionName,
        OutputStyle $output,
    ): ?array {
        $relatedTable = $relation->getRelated()->getTable();
        $relationLabel = class_basename($relation->getParent()).'::'.$relationName;

        if (! in_array($relatedTable, $selectedTables, true)) {
            return null;
        }

        $this->assertTableHasColumns(
            $relatedTable,
            [$relation->getForeignKeyName()],
            $connectionName,
            $relationLabel,
        );
        $this->assertTableHasColumns(
            $relation->getParent()->getTable(),
            [$relation->getLocalKeyName()],
            $connectionName,
            $relationLabel,
        );

        if (! $this->hasMatchingForeignKey(
            $relatedTable,
            [$relation->getForeignKeyName()],
            $relation->getParent()->getTable(),
            [$relation->getLocalKeyName()],
            $connectionName,
        )) {
            throw new RuntimeException("Relation [{$relationLabel}] does not match a database foreign key.");
        }

        return [
            'name' => $relationName,
            'helper' => $helper,
            'chain' => [[
                'source' => [$this->transformName($relation->getLocalKeyName(), config('eloquent-zero.column_name_casing'))],
                'destVariable' => $tableMap->get($relatedTable)['variable'],
                'dest' => [$this->transformName($relation->getForeignKeyName(), config('eloquent-zero.column_name_casing'))],
            ]],
        ];
    }

    /**
     * @param  array<int, string>  $selectedTables
     * @param  Collection<(int|string), array<string, mixed>>  $tableMap
     * @return array{name: string, helper: string, chain: array<int, array{source: array<int, string>, destVariable: string, dest: array<int, string>}>}|null
     */
    private function buildBelongsToManyRelation(
        string $relationName,
        BelongsToMany $relation,
        array $selectedTables,
        Collection $tableMap,
        ?string $connectionName,
        OutputStyle $output,
    ): ?array {
        $relatedTable = $relation->getRelated()->getTable();
        $pivotTable = $relation->getTable();
        $relationLabel = class_basename($relation->getParent()).'::'.$relationName;

        if (! in_array($relatedTable, $selectedTables, true) || ! in_array($pivotTable, $selectedTables, true)) {
            return null;
        }

        $parentPivotKey = $relation->getForeignPivotKeyName();
        $relatedPivotKey = $relation->getRelatedPivotKeyName();

        $this->assertTableHasColumns(
            $pivotTable,
            [$parentPivotKey, $relatedPivotKey],
            $connectionName,
            $relationLabel,
        );
        $this->assertTableHasColumns(
            $relation->getParent()->getTable(),
            [$relation->getParentKeyName()],
            $connectionName,
            $relationLabel,
        );
        $this->assertTableHasColumns(
            $relatedTable,
            [$relation->getRelatedKeyName()],
            $connectionName,
            $relationLabel,
        );

        if (! $this->hasMatchingForeignKey(
            $pivotTable,
            [$parentPivotKey],
            $relation->getParent()->getTable(),
            [$relation->getParentKeyName()],
            $connectionName,
        ) || ! $this->hasMatchingForeignKey(
            $pivotTable,
            [$relatedPivotKey],
            $relatedTable,
            [$relation->getRelatedKeyName()],
            $connectionName,
        )) {
            throw new RuntimeException("Relation [{$relationLabel}] does not match pivot foreign keys in the database.");
        }

        return [
            'name' => $relationName,
            'helper' => 'many',
            'chain' => [
                [
                    'source' => [$this->transformName($relation->getParentKeyName(), config('eloquent-zero.column_name_casing'))],
                    'destVariable' => $tableMap->get($pivotTable)['variable'],
                    'dest' => [$this->transformName($parentPivotKey, config('eloquent-zero.column_name_casing'))],
                ],
                [
                    'source' => [$this->transformName($relatedPivotKey, config('eloquent-zero.column_name_casing'))],
                    'destVariable' => $tableMap->get($relatedTable)['variable'],
                    'dest' => [$this->transformName($relation->getRelatedKeyName(), config('eloquent-zero.column_name_casing'))],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $foreignColumns
     */
    private function hasMatchingForeignKey(
        string $table,
        array $columns,
        string $foreignTable,
        array $foreignColumns,
        ?string $connectionName,
    ): bool {
        $foreignKeys = Schema::connection($connectionName)->getForeignKeys($table);

        foreach ($foreignKeys as $foreignKey) {
            if (
                $foreignKey['columns'] === $columns
                && $foreignKey['foreign_table'] === $foreignTable
                && $foreignKey['foreign_columns'] === $foreignColumns
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $expectedColumns
     */
    private function assertTableHasColumns(
        string $table,
        array $expectedColumns,
        ?string $connectionName,
        string $relationLabel,
    ): void {
        $actualColumns = array_column(
            Schema::connection($connectionName)->getColumns($table),
            'name',
        );

        foreach ($expectedColumns as $expectedColumn) {
            if (! in_array($expectedColumn, $actualColumns, true)) {
                throw new RuntimeException("Relation [{$relationLabel}] references missing column [{$table}.{$expectedColumn}].");
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function getEnumTypes(?string $connectionName): array
    {
        $rows = app('db')->connection($connectionName)->select(
            <<<'SQL'
            select
                t.typname as name,
                array_agg(e.enumlabel order by e.enumsortorder) as labels
            from pg_type t
            join pg_enum e on e.enumtypid = t.oid
            join pg_namespace n on n.oid = t.typnamespace
            group by t.typname
            SQL
        );

        $enumTypes = [];

        foreach ($rows as $row) {
            $enumTypes[$row->name] = $this->parsePostgresArray($row->labels);
        }

        return $enumTypes;
    }

    /**
     * @param  list<string>  $values
     */
    private function renderEnumerationType(array $values): string
    {
        return implode(' | ', array_map(
            static fn (string $value): string => "'".str_replace("'", "\\'", $value)."'",
            $values,
        ));
    }

    /**
     * @return list<string>
     */
    private function parsePostgresArray(string $value): array
    {
        $trimmed = trim($value, '{}');

        if ($trimmed === '') {
            return [];
        }

        return array_map(
            static fn (string $item): string => trim($item, '"'),
            str_getcsv($trimmed),
        );
    }

    /**
     * @param  array<int, Model>  $models
     * @param  array<int, array{name: string, variable: string, serverName: string, columns: array<int, array{name: string, serverName: string, type: string, optional: bool}>, primaryKey: array<int, string>}>  $tables
     * @param  array<string, list<string>>  $enumTypes
     * @return array<int, array{name: string, variable: string, serverName: string, columns: array<int, array{name: string, serverName: string, type: string, optional: bool}>, primaryKey: array<int, string>}>
     */
    private function ensurePivotTables(
        array $models,
        array $tables,
        ?string $connectionName,
        array $enumTypes,
        OutputStyle $output,
        array $requiredColumnsByTable,
    ): array {
        $tableNames = array_column($tables, 'serverName');

        foreach ($models as $model) {
            foreach ($this->relationMethods($model) as $method) {
                $relation = $model->{$method->getName()}();

                if (! $relation instanceof BelongsToMany) {
                    continue;
                }

                $pivotTable = $relation->getTable();

                if (in_array($pivotTable, $tableNames, true)) {
                    continue;
                }

                $tables[] = $this->buildTableDefinitionForTable(
                    $pivotTable,
                    $connectionName,
                    $enumTypes,
                    $output,
                    requiredColumns: $requiredColumnsByTable[$pivotTable] ?? [],
                );

                $tableNames[] = $pivotTable;
            }
        }

        return $tables;
    }

    /**
     * @param  array<int, string>  $directories
     * @return array<int, class-string<Model>>
     */
    private function discoverModels(array $directories): array
    {
        return collect($directories)
            ->filter(fn (?string $directory): bool => is_string($directory) && is_dir($directory))
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
            $this->emitWarning($output, "Model [{$reflection->getName()}] has both ZeroIgnore and ZeroGenerate attributes.");
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
     * @return array<int, string>|null
     */
    private function allowedColumnsForModel(Model $model): ?array
    {
        $attributes = (new ReflectionClass($model))->getAttributes(ZeroColumns::class);

        if ($attributes === []) {
            return null;
        }

        /** @var ZeroColumns $instance */
        $instance = $attributes[0]->newInstance();

        return $instance->columns;
    }

    private function schemaNameForModel(Model $model): string
    {
        $attributes = (new ReflectionClass($model))->getAttributes(ZeroName::class);

        if ($attributes === []) {
            return $this->transformName($model->getTable(), config('eloquent-zero.table_name_casing'));
        }

        /** @var ZeroName $instance */
        $instance = $attributes[0]->newInstance();

        return $instance->name;
    }

    private function emitWarning(OutputStyle $output, string $message): void
    {
        $output->writeln("Warning: {$message}");
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
                throw new RuntimeException('Migration repository not found. Run `php artisan migrate` before generating a Zero schema.');
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
                'Database schema may be out of date. Run `php artisan migrate` before generating a Zero schema. Pending migrations: '
                .$preview
                .$suffix
            );
        }
    }
}
