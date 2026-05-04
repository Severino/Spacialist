<?php

namespace App\Import;

use App\Exceptions\CsvColumnMismatchException;
use App\Exceptions\ImportException;
use App\Exceptions\Structs\ImportExceptionStruct;


use App\Attribute;
use App\AttributeTypes\AttributeBase;
use App\AttributeValue;
use App\Entity;
use App\EntityType;
use App\EntityTypeRelation;
use App\Events\EntityImportProgress;
use App\Exceptions\AmbiguousValueException;
use App\Exceptions\InvalidDataException;
use App\File\Csv;
use App\Import\ImportResolution;
use App\ThConcept;
use Exception;
use Illuminate\Support\Facades\DB;

enum Action {
    case CREATE;
    case UPDATE;
    case DELETE;
}
;

class EntityImporter {

    const PARENT_DELIMITER = "\\\\";
    const AMBIGUOUS_PATH_CACHE = '__AMBIGUOUS_PATH__';
    const progressEventRateLimitInSeconds = 2;
    const attributeUpsertChunkSize = 5000;
    const entityInsertChunkSize = 1000;
    const importProgressTotalUnits = 1000;
    const importParseProgressUnits = 900;

    private $metadata;
    private array $attributesMap;
    private array $attributeIdToAttributeValue = [];
    private array $attributeImportClassById = [];
    private array $entityIdByPath = [];
    private array $entityTypeIdByEntityId = [];
    private bool $pathCachePreloaded = false;
    private int $nextTempEntityId = -1;
    private array $parentTypeIdByPath = [];
    private array $relationAllowedByTypeKey = [];
    private array $entityTypeLabelById = [];
    private array $nextRankByParentKey = [];
    private int $entityTypeId;
    private string $nameColumn;
    private ?string $parentColumn = null;
    private ImportResolution $resolver;

    private string $delimiter = ",";
    private bool $hasHeaderRow = false;
    private ?\Illuminate\Support\Carbon $importTimestamp = null;


    public function __construct($metadata, $data) {
        $this->resolver = new ImportResolution();
        $this->metadata = $metadata;
        if(isset($metadata['delimiter'])) {
            $this->delimiter = $metadata['delimiter'];
        }
        if(isset($metadata['has_header_row'])) {
            $this->hasHeaderRow = $metadata['has_header_row'];
        }

        $this->nameColumn = $data['name_column'] ?? '';
        $this->entityTypeId = $data['entity_type_id'];
        $this->attributesMap = array_map(fn($col) => trim($col), $data['attributes']);

        // The parent column is optional, therefore we only set it
        // to another value than null, if there is valid data set.
        if(array_key_exists('parent_column', $data)) {
            $parentColumn = trim($data['parent_column']);
            if(!empty($parentColumn)) {
                $this->parentColumn = trim($data['parent_column']);
            }
        }
    }

    public function importData(string $filepath) {
        $user = auth()->user();
        $this->importTimestamp = now();

        $handle = fopen($filepath, 'r');
        if(!$handle) {
            throw new ImportException(__('entity-importer.file-not-found', ['file' => $filepath]), 400, new ImportExceptionStruct());
        }

        $changedEntities = [];

        $attributeDefinitions = $this->resolveAttributeDefinitions();
        $upsertRows = [];
        $upsertChunkSize = self::attributeUpsertChunkSize;
        $pendingEntities = [];
        $pendingEntityPathsByTempId = [];
        $pendingChangedEntityIndexesByTempId = [];
        $pendingAttributeRows = [];

        DB::beginTransaction();
        try {

            $csvTable = new Csv($this->metadata['has_header_row'], $this->metadata['delimiter'], $this->metadata['encoding']);
            try {
                $csvTable->parseHeaders($handle);
            } catch(Exception $e) {
                throw new ImportException($e->getMessage(), 400, new ImportExceptionStruct());
            }

            // Build a complete in-memory path lookup once to avoid thousands of
            // recursive SQL calls for getFromPath during large imports.
            $this->preloadEntityPathCache();
            
            EntityImportProgress::dispatch(0, self::importProgressTotalUnits);

            $csvTable->parse($handle, function ($row, $index) use (&$changedEntities, &$upsertRows, &$pendingEntities, &$pendingEntityPathsByTempId, &$pendingChangedEntityIndexesByTempId, &$pendingAttributeRows, $attributeDefinitions, $upsertChunkSize, $user) {
                if(!array_key_exists($this->nameColumn, $row)) {
                    throw new ImportException('Name column is missing in row', 400, new ImportExceptionStruct(
                        count: $index + 1,
                        on: $this->nameColumn
                    ));
                }

                $entityName = trim($row[$this->nameColumn]);
                $hasParent = isset($this->parentColumn);
                if($hasParent && !array_key_exists($this->parentColumn, $row)) {
                    throw new ImportException("Parent column '" . $this->parentColumn . "' is missing in row", 400, new ImportExceptionStruct(
                        count: $index + 1,
                        entry: $entityName,
                        on: $this->parentColumn
                    ));
                }

                $rootEntityPath = $hasParent ? trim($row[$this->parentColumn]) : null;
                $entityPath = $entityName;
                $parentEntityId = null;

                if($hasParent && !empty($rootEntityPath)) {
                    $parentEntityId = $this->getEntityIdFromPath($rootEntityPath);

                    if(!isset($parentEntityId)) {
                        throw new ImportException('Parent entity does not exist', 400, new ImportExceptionStruct(
                            count: $index + 1,
                            entry: $entityName,
                            on: $rootEntityPath,
                            on_value: $rootEntityPath
                        ));
                    }

                    if($parentEntityId < 0) {
                        $this->flushPendingEntities(
                            $pendingEntities,
                            $pendingEntityPathsByTempId,
                            $pendingChangedEntityIndexesByTempId,
                            $pendingAttributeRows,
                            $changedEntities,
                            $upsertRows,
                            $attributeDefinitions,
                            $upsertChunkSize,
                            $user->id
                        );
                        $parentEntityId = $this->getEntityIdFromPath($rootEntityPath);
                    }

                    $entityPath = implode(self::PARENT_DELIMITER, [$rootEntityPath, $entityName]);
                }

                $parentTypeId = isset($parentEntityId) ? ($this->entityTypeIdByEntityId[$parentEntityId] ?? null) : null;
                if(!$this->isRelationAllowed($parentTypeId, $this->entityTypeId)) {
                    $childName = $this->getEntityTypeLabel($this->entityTypeId);
                    $parentName = isset($parentTypeId) ? $this->getEntityTypeLabel($parentTypeId) : 'TOP';
                    throw new ImportException(__('entity-importer.entity-type-relation-not-allowed', [
                        'child' => $childName,
                        'parent' => $parentName,
                    ]), 400, new ImportExceptionStruct(
                        count: $index + 1,
                        entry: $entityName,
                        on: $rootEntityPath
                    ));
                }

                $entityId = $this->getEntityIdFromPath($entityPath);

                if(!$entityId) {
                    $nextRank = $this->getNextRankForParent($parentEntityId);
                    $entityId = $this->nextTempEntityId--;
                    $now = now()->addMicroseconds(abs($entityId));
                    $pendingEntities[$entityId] = [
                        'name' => $entityName,
                        'entity_type_id' => $this->entityTypeId,
                        'root_entity_id' => $parentEntityId,
                        'rank' => $nextRank,
                        'user_id' => $user->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $this->entityIdByPath[$entityPath] = $entityId;
                    $this->entityTypeIdByEntityId[$entityId] = $this->entityTypeId;
                    $pendingEntityPathsByTempId[$entityId][] = $entityPath;

                    if(count($pendingEntities) >= self::entityInsertChunkSize) {
                        $this->flushPendingEntities(
                            $pendingEntities,
                            $pendingEntityPathsByTempId,
                            $pendingChangedEntityIndexesByTempId,
                            $pendingAttributeRows,
                            $changedEntities,
                            $upsertRows,
                            $attributeDefinitions,
                            $upsertChunkSize,
                            $user->id
                        );
                        $entityId = $this->getEntityIdFromPath($entityPath);
                    }
                }

                $changedEntities[] = $entityId;
                if($entityId < 0) {
                    $pendingChangedEntityIndexesByTempId[$entityId][] = count($changedEntities) - 1;
                    $pendingAttributeRows[] = [
                        'temp_entity_id' => $entityId,
                        'row' => $row,
                        'row_index' => $index,
                        'entity_name' => $entityName,
                        'user_id' => $user->id,
                    ];
                } else {
                    $this->appendAttributeUpsertRows($upsertRows, $attributeDefinitions, $row, $index, $entityId, $entityName, $user->id);
                    if(count($upsertRows) >= $upsertChunkSize) {
                        $this->flushAttributeUpsertRows($upsertRows);
                    }
                }
            }, function ($processedBytes, $totalBytes) {
                EntityImportProgress::dispatchLimited(
                    $this->scaleImportProgress((int) $processedBytes, (int) $totalBytes, 0, self::importParseProgressUnits),
                    self::importProgressTotalUnits,
                    self::progressEventRateLimitInSeconds,
                    'CSV Parsing'
                );
            });

            EntityImportProgress::dispatch(self::importParseProgressUnits, self::importProgressTotalUnits, 'Flush pending entities');

            $this->flushPendingEntities(
                $pendingEntities,
                $pendingEntityPathsByTempId,
                $pendingChangedEntityIndexesByTempId,
                $pendingAttributeRows,
                $changedEntities,
                $upsertRows,
                $attributeDefinitions,
                $upsertChunkSize,
                $user->id
            );

            EntityImportProgress::dispatch(975, self::importProgressTotalUnits, 'Flush pending attributes');

            if(!empty($upsertRows)) {
                $this->flushAttributeUpsertRows($upsertRows);
            }

            EntityImportProgress::dispatch(995, self::importProgressTotalUnits, 'Finalizing');

            if($csvTable->getDataRows() === 0) {
                throw new ImportException(__('entity-importer.empty'), 400, new ImportExceptionStruct());
            }

            DB::commit();
            EntityImportProgress::dispatch(self::importProgressTotalUnits, self::importProgressTotalUnits, 'Completed');
        } catch(CsvColumnMismatchException $csvMismatchException) {
            DB::rollBack();
            throw new ImportException(__("entity-importer.csv-column-mismatch", [
                "data" => $csvMismatchException->dataLine,
                "data_count" => $csvMismatchException->dataColumns,
                "header_data" => $csvMismatchException->headerLine,
                "header_count" => $csvMismatchException->headerColumns,
            ]), 400, new ImportExceptionStruct());
        } catch(ImportException $e) {
            DB::rollBack();
            throw $e;
        } catch(Exception $e) {
            DB::rollBack();
            $message = $e->getMessage() ?: 'UNKNOWN';
            throw new ImportException($message, 400, new ImportExceptionStruct());
        } finally {
            fclose($handle);
        }

        return $changedEntities;
    }

    private function scaleImportProgress(int $processed, int $total, int $start, int $end): int {
        $boundedTotal = max(1, $total);
        $boundedProcessed = min(max(0, $processed), $boundedTotal);
        $span = max(0, $end - $start);

        return $start + (int) floor(($boundedProcessed / $boundedTotal) * $span);
    }

    private function createImportedEntity($entityName, int $entityTypeId, $user, ?int $rootEntityId = null, ?int $rank = null) {
        
        // To improve eprformance we try to avoid using Entity::create
        $now = now();
        $entityId = DB::table('entities')->insertGetId([
            'name' => $entityName,
            'entity_type_id' => $entityTypeId,
            'root_entity_id' => $rootEntityId,
            'rank' => $rank,
            'user_id' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'type' => 'entity',
            'entity' => (object) ['id' => $entityId],
        ];
    }

    private function flushPendingEntities(
        array &$pendingEntities,
        array &$pendingEntityPathsByTempId,
        array &$pendingChangedEntityIndexesByTempId,
        array &$pendingAttributeRows,
        array &$changedEntities,
        array &$upsertRows,
        array $attributeDefinitions,
        int $upsertChunkSize,
        int $userId
    ): void {
        if(empty($pendingEntities)) {
            return;
        }

        $tempToRealId = $this->bulkInsertEntities($pendingEntities);

        foreach($tempToRealId as $tempId => $realId) {
            if(isset($pendingEntityPathsByTempId[$tempId])) {
                foreach($pendingEntityPathsByTempId[$tempId] as $path) {
                    $this->entityIdByPath[$path] = $realId;
                }
                unset($pendingEntityPathsByTempId[$tempId]);
            }

            if(isset($this->entityTypeIdByEntityId[$tempId])) {
                $this->entityTypeIdByEntityId[$realId] = $this->entityTypeIdByEntityId[$tempId];
                unset($this->entityTypeIdByEntityId[$tempId]);
            }

            if(isset($pendingChangedEntityIndexesByTempId[$tempId])) {
                foreach($pendingChangedEntityIndexesByTempId[$tempId] as $changedIndex) {
                    $changedEntities[$changedIndex] = $realId;
                }
                unset($pendingChangedEntityIndexesByTempId[$tempId]);
            }
        }

        $remainingPendingAttributeRows = [];
        foreach($pendingAttributeRows as $pendingAttributeRow) {
            $tempEntityId = $pendingAttributeRow['temp_entity_id'];
            if(!isset($tempToRealId[$tempEntityId])) {
                $remainingPendingAttributeRows[] = $pendingAttributeRow;
                continue;
            }

            $entityId = $tempToRealId[$tempEntityId];
            $this->appendAttributeUpsertRows(
                $upsertRows,
                $attributeDefinitions,
                $pendingAttributeRow['row'],
                $pendingAttributeRow['row_index'],
                $entityId,
                $pendingAttributeRow['entity_name'],
                $pendingAttributeRow['user_id']
            );

            if(count($upsertRows) >= $upsertChunkSize) {
                $this->flushAttributeUpsertRows($upsertRows);
            }
        }

        $pendingAttributeRows = $remainingPendingAttributeRows;
        $pendingEntities = [];
    }

    private function bulkInsertEntities(array $pendingEntities): array {
        if(empty($pendingEntities)) {
            return [];
        }

        $values = [];
        $bindings = [];

        foreach($pendingEntities as $tempId => $row) {
            $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $bindings[] = (int) $tempId;
            $bindings[] = $row['name'];
            $bindings[] = (int) $row['entity_type_id'];
            $bindings[] = $row['root_entity_id'];
            $bindings[] = $row['rank'];
            $bindings[] = (int) $row['user_id'];
            $bindings[] = $row['created_at'];
            $bindings[] = $row['updated_at'];
            $bindings[] = $row['created_at']->timestamp;
        }

        $sql = sprintf(
            <<<'SQL'
                WITH input (
                    temp_id,
                    name,
                    entity_type_id,
                    root_entity_id,
                    rank,
                    user_id,
                    created_at,
                    updated_at,
                    unique_seed
                ) AS (
                    VALUES %s
                ),
                ins AS (
                    INSERT INTO entities (
                        name,
                        entity_type_id,
                        root_entity_id,
                        rank,
                        user_id,
                        created_at,
                        updated_at
                    )
                    SELECT
                        i.name,
                        i.entity_type_id::integer,
                        i.root_entity_id::integer,
                        i.rank::integer,
                        i.user_id::integer,
                        i.created_at::timestamptz,
                        i.updated_at::timestamptz
                    FROM input i
                    ORDER BY i.unique_seed::bigint, i.temp_id::integer
                    RETURNING
                        id,
                        name,
                        entity_type_id,
                        root_entity_id,
                        rank,
                        user_id,
                        created_at,
                        updated_at
                )
                SELECT
                    i.temp_id::integer,
                    ins.id
                FROM ins
                INNER JOIN input i
                    ON ins.name = i.name
                    AND ins.entity_type_id = i.entity_type_id::integer
                    AND ins.root_entity_id IS NOT DISTINCT FROM i.root_entity_id::integer
                    AND ins.rank IS NOT DISTINCT FROM i.rank::integer
                    AND ins.user_id = i.user_id::integer
                    AND ins.created_at = i.created_at::timestamptz
                    AND ins.updated_at = i.updated_at::timestamptz
                ORDER BY i.temp_id::integer
            SQL,
            implode(', ', $values)
        );

        $rows = DB::select($sql, $bindings);
        $tempToRealId = [];
        foreach($rows as $row) {
            $tempToRealId[(int) $row->temp_id] = (int) $row->id;
        }

        return $tempToRealId;
    }

    private function preloadEntityPathCache(): void {
        $rows = DB::select(
            <<<'SQL'
                WITH RECURSIVE entity_paths AS (
                    SELECT
                        e.id,
                        e.entity_type_id,
                        e.root_entity_id,
                        e.name::text AS pathstr
                    FROM entities e
                    WHERE e.root_entity_id IS NULL
                    UNION ALL
                    SELECT
                        e.id,
                        e.entity_type_id,
                        e.root_entity_id,
                        p.pathstr || ? || e.name AS pathstr
                    FROM entities e
                    INNER JOIN entity_paths p ON p.id = e.root_entity_id
                )
                SELECT id, entity_type_id, pathstr
                FROM entity_paths
            SQL,
            [self::PARENT_DELIMITER]
        );

        foreach($rows as $row) {
            $path = $row->pathstr;
            if(array_key_exists($path, $this->entityIdByPath) && $this->entityIdByPath[$path] !== (int) $row->id) {
                $this->entityIdByPath[$path] = self::AMBIGUOUS_PATH_CACHE;
                continue;
            }

            $this->entityIdByPath[$path] = (int) $row->id;
            $this->entityTypeIdByEntityId[(int) $row->id] = (int) $row->entity_type_id;
        }

        $this->pathCachePreloaded = true;
    }

    private function resolveAttributeDefinitions(): array {
        $attributeIds = [];
        foreach($this->attributesMap as $attributeId => $column) {
            if(trim($column) !== '') {
                $attributeIds[] = (int) $attributeId;
            }
        }

        if(empty($attributeIds)) {
            return [];
        }

        $attributesById = Attribute::whereIn('id', $attributeIds)->get()->keyBy('id');
        $definitions = [];

        foreach($attributeIds as $attributeId) {
            if(!isset($attributesById[$attributeId])) {
                throw new ImportException(__('entity-importer.attribute-id-does-not-exist', ['attributes' => (string) $attributeId]), 400, new ImportExceptionStruct());
            }

            $column = trim($this->attributesMap[$attributeId]);
            $type = $attributesById[$attributeId]->datatype;
            $valueColumn = AttributeValue::getValueColumn($type);
            $attributeImportClass = $this->attributeImportClassById[$attributeId] ?? null;
            if(!$attributeImportClass) {
                $attributeImportClass = AttributeBase::getMatchingClass($type);
                $this->attributeImportClassById[$attributeId] = $attributeImportClass;
            }
            $definitions[$attributeId] = [
                'column' => $column,
                'type' => $type,
                'valueColumn' => $valueColumn,
                'importClass' => $attributeImportClass,
            ];
        }

        return $definitions;
    }

    private function appendAttributeUpsertRows(array &$upsertRows, array $attributeDefinitions, array $row, int $rowIndex, int $entityId, string $entityName, int $userId): void {
        if(empty($attributeDefinitions)) {
            return;
        }

        foreach($attributeDefinitions as $attributeId => $definition) {
            $column = $definition['column'];
            if(!array_key_exists($column, $row)) {
                throw new ImportException(__('entity-importer.attribute-column-does-not-exist', ['columns' => $column]), 400, new ImportExceptionStruct(
                    count: $rowIndex + 1,
                    entry: $entityName,
                    on: $column
                ));
            }

            $rawValue = (string) $row[$column];

            try {
                $parsedValue = $definition['importClass']::fromImport(trim($rawValue));
            } catch(InvalidDataException | AmbiguousValueException $e) {
                throw new ImportException($e->getMessage(), 422, new ImportExceptionStruct(
                    count: $rowIndex + 1,
                    entry: $entityName,
                    on: $column,
                    on_value: $rawValue
                ));
            }

            $valueColumn = $definition['valueColumn'];
            if(!isset($valueColumn)) {
                continue;
            }

            if($valueColumn === 'json_val' && isset($parsedValue) && !is_string($parsedValue)) {
                $parsedValue = json_encode($parsedValue);
            }

            $now = $this->importTimestamp ?? now();
            $upsertRows[] = [
                'entity_id' => $entityId,
                'attribute_id' => (int) $attributeId,
                'str_val' => $valueColumn === 'str_val' ? $parsedValue : null,
                'int_val' => $valueColumn === 'int_val' ? $parsedValue : null,
                'dbl_val' => $valueColumn === 'dbl_val' ? $parsedValue : null,
                'dt_val' => $valueColumn === 'dt_val' ? $parsedValue : null,
                'entity_val' => $valueColumn === 'entity_val' ? $parsedValue : null,
                'thesaurus_val' => $valueColumn === 'thesaurus_val' ? $parsedValue : null,
                'json_val' => $valueColumn === 'json_val' ? $parsedValue : null,
                'geography_val' => $valueColumn === 'geography_val' ? $parsedValue : null,
                'user_id' => $userId,
                'updated_at' => $now,
                'created_at' => $now,
            ];
        }
    }

    private function flushAttributeUpsertRows(array &$upsertRows): void {
        if(empty($upsertRows)) {
            return;
        }

        // Create the staging temp table once per DB session; reuse across flush calls.
        DB::statement('
            CREATE TEMP TABLE IF NOT EXISTS tmp_attr_import (
                entity_id      integer,
                attribute_id   integer,
                str_val        text,
                int_val        integer,
                dbl_val        double precision,
                dt_val         date,
                entity_val     integer,
                thesaurus_val  text,
                json_val       jsonb,
                geography_val  geography,
                user_id        integer,
                updated_at     timestamp with time zone,
                created_at     timestamp with time zone
            )
        ');

        // Clear previous batch contents.
        DB::statement('TRUNCATE TABLE tmp_attr_import');

        // Bulk-load rows into temp table. Simple INSERT with no conflict logic –
        // smaller per-parameter overhead than multi-value ON CONFLICT upserts.
        foreach(array_chunk($upsertRows, 1000) as $chunk) {
            DB::table('tmp_attr_import')->insert($chunk);
        }

        // Single SQL merge from staging → attribute_values.
        DB::statement('
            INSERT INTO attribute_values
                (entity_id, attribute_id, str_val, int_val, dbl_val, dt_val,
                 entity_val, thesaurus_val, json_val, geography_val,
                 user_id, updated_at, created_at)
            SELECT entity_id, attribute_id, str_val, int_val, dbl_val, dt_val,
                   entity_val, thesaurus_val, json_val, geography_val,
                   user_id, updated_at, created_at
            FROM tmp_attr_import
            ON CONFLICT (entity_id, attribute_id) DO UPDATE SET
                str_val       = EXCLUDED.str_val,
                int_val       = EXCLUDED.int_val,
                dbl_val       = EXCLUDED.dbl_val,
                dt_val        = EXCLUDED.dt_val,
                entity_val    = EXCLUDED.entity_val,
                thesaurus_val = EXCLUDED.thesaurus_val,
                json_val      = EXCLUDED.json_val,
                geography_val = EXCLUDED.geography_val,
                user_id       = EXCLUDED.user_id,
                updated_at    = EXCLUDED.updated_at
        ');

        $upsertRows = [];
    }

    private function getNextRankForParent(?int $parentEntityId): int {
        $key = isset($parentEntityId) ? (string) $parentEntityId : 'null';
        if(!array_key_exists($key, $this->nextRankByParentKey)) {
            if(isset($parentEntityId)) {
                $this->nextRankByParentKey[$key] = (int) Entity::where('root_entity_id', $parentEntityId)->max('rank');
            } else {
                $this->nextRankByParentKey[$key] = (int) Entity::whereNull('root_entity_id')->max('rank');
            }
        }

        $this->nextRankByParentKey[$key]++;
        return $this->nextRankByParentKey[$key];
    }

    private function validateStringData(string $varName, string $dataName) {
        if(gettype($this->{$varName}) == "string") {
            $this->{$varName} = trim($this->{$varName});
        }

        if(empty($this->{$varName})) {
            $this->resolver->conflict(__("entity-importer.missing-data", ["column" => $dataName]));
        }
    }

    private function validateAttributeData() {
        if(!is_array($this->attributesMap)) {
            $this->resolver->conflict(__("entity-importer.invalid-data", ["column" => "attributes", "value" => json_encode($this->attributesMap)]));
            return;
        }

        $this->attributesMap = array_map(function ($value) {
            return trim($value);
        }, $this->attributesMap);
    }

    public function validateImportData(string $filepath) {
        $this->validateStringData('nameColumn', 'name_column');
        $this->validateStringData('entityTypeId', 'entity_type_id');
        $this->validateAttributeData();

        if($this->resolver->hasErrors()) {
            return $this->resolver;
        }

        $handle = fopen($filepath, 'r');

        if(!$handle) {
            return $this->resolver->conflict(__("entity-importer.file-not-found", ["file" => $filepath]));
        }

        try {
            $totalBytes = fstat($handle)['size'] ?? 1;
            EntityImportProgress::dispatch(0, $totalBytes, 'Verifying Mapping');    
        
            $csvTable = new Csv($this->metadata['has_header_row'], $this->metadata['delimiter'], $this->metadata['encoding']);

            try {
                $headers = $csvTable->parseHeaders($handle);
            } catch(\Exception $e) {
                return $this->resolver->conflict(__("entity-importer.empty"));
            }

            $this->verifyNameColumn($headers);
            $this->verifyParentColumn($headers);
            $this->verifyEntityType($this->entityTypeId);
            $this->verifyAttributeMapping($headers);

            if($this->resolver->hasErrors()) {
                return $this->resolver;
            }

            try {
                $csvTable->parse($handle, function ($row, $index) {
                    $namesValid = $this->validateName($row, $index);
                    if($namesValid) {
                        // The location depends on the name column. If the name is not correct, we can't check the location.
                        $this->validateLocation($row, $index);
                    }
                    $this->validateAttributesInRow($row, $index);
                }, function ($processedBytes, $totalBytes, $rowIndex) {
                    EntityImportProgress::dispatchLimited($processedBytes, $totalBytes, self::progressEventRateLimitInSeconds, "Verify rows (#{$rowIndex})");
                });

                EntityImportProgress::dispatch($totalBytes, $totalBytes, 'Verification completed');
            } catch(CsvColumnMismatchException $csvMismatchException) {
                return $this->resolver->conflict(__("entity-importer.csv-column-mismatch", [
                    "data" => $csvMismatchException->dataLine,
                    "data_count" => $csvMismatchException->dataColumns,
                    "header_data" => $csvMismatchException->headerLine,
                    "header_count" => $csvMismatchException->headerColumns,
                ]));
            }

            if($csvTable->getDataRows() == 0) {
                $this->resolver->conflict(__("entity-importer.empty"));
            }

            return $this->resolver;
        } finally {
            fclose($handle);
        }
    }

    private function verifyNameColumn($headers): bool {
        if(empty($this->nameColumn) || !in_array($this->nameColumn, $headers)) {
            $this->resolver->conflict(__("entity-importer.name-column-does-not-exist", ["column" => $this->nameColumn]));
            return false;
        }
        return true;
    }

    private function verifyParentColumn($headers): bool {
        if(isset($this->parentColumn) && !in_array($this->parentColumn, $headers)) {
            $this->resolver->conflict(__("entity-importer.parent-column-does-not-exist", ["column" => $this->parentColumn]));
            return false;
        }
        return true;
    }

    private function verifyEntityType($entityTypeId): bool {
        if(!EntityType::find($entityTypeId)) {
            $this->resolver->conflict(__("entity-importer.entity-type-does-not-exist", ["entity_type_id" => $entityTypeId]));
            return false;
        }
        return true;
    }

    private function verifyAttributeMapping($headers): bool {
        $nameErrors = [];
        $indexErrors = [];
        foreach($this->attributesMap as $attributeId => $column) {
            $column = trim($column);
            if($column == "") {
                continue;
            }

            if(!in_array($column, $headers)) {
                array_push($nameErrors, $column);
            }

            $attr = Attribute::find($attributeId);
            if(!$attr) {
                array_push($indexErrors, $attributeId);
            } else {
                $this->attributeIdToAttributeValue[$attributeId] = $attr;
                $this->attributeImportClassById[$attributeId] = AttributeBase::getMatchingClass($attr->datatype);
            }
        }

        $valid = true;
        if(count($indexErrors) > 0) {
            $this->resolver->conflict(__("entity-importer.attribute-id-does-not-exist", ["attributes" => implode(", ", $indexErrors)]));
            $valid = false;
        }

        if(count($nameErrors) > 0) {
            $this->resolver->conflict(__("entity-importer.attribute-column-does-not-exist", ["columns" => implode(", ", $nameErrors)]));
            $valid = false;
        }

        return $valid;
    }

    private function validateName($row, $rowIndex): bool {
        $entityName = $row[$this->nameColumn];

        if(gettype($entityName) == "string")
            $entityName = trim($entityName);

        if(empty($entityName)) {
            $this->rowConflict($rowIndex, "entity-importer.missing-name-in-row");
            return false;
        }

        return true;
    }

    private function validateLocation($row, $rowIndex): bool {

        $parentTypeId = null;
        $parentPath = $this->getParentColumn($row);
        if(!empty($parentPath)) {
            $parentId = $this->getEntityIdFromPath($parentPath);
            if($parentId == null) {
                $this->rowConflict($rowIndex, "entity-importer.parent-entity-does-not-exist", ["entity" => $parentPath]);
                return false;
            }

            if(!array_key_exists($parentPath, $this->parentTypeIdByPath)) {
                $parent = Entity::find($parentId);
                $this->parentTypeIdByPath[$parentPath] = $parent->entity_type_id;
            }
            $parentTypeId = $this->parentTypeIdByPath[$parentPath];
        }

        $isAllowedAsChild = $this->isRelationAllowed($parentTypeId, $this->entityTypeId);
        if(!$isAllowedAsChild) {
            $childName = $this->getEntityTypeLabel($this->entityTypeId);

            $parentName = "TOP";
            if($parentTypeId) {
                $parentName = $this->getEntityTypeLabel($parentTypeId);
            }

            $this->rowConflict($rowIndex, "entity-importer.entity-type-relation-not-allowed", ["child" => $childName, "parent" => $parentName]);
            return false;
        }

        $filepath = implode(self::PARENT_DELIMITER, array_filter([$parentPath, $row[$this->nameColumn]], fn($part) => !empty($part)));
        if($this->checkIfEntityExists($filepath)) {
            $this->resolver->update();
        } else {
            $this->resolver->create();
        }

        return true;
    }

    private function validateAttributesInRow($row, $index): bool {
        $errors = [];
        foreach($this->attributeIdToAttributeValue as $attributeId => $_attribute) {
            try {
                $column = $this->attributesMap[$attributeId];
                $attrClass = $this->attributeImportClassById[$attributeId];
                $attrClass::fromImport($row[$column]);
            } catch(Exception $e) {
                array_push($errors, ["column" => $column, "value" => $row[$column]]);
            }
        }

        if(count($errors) > 0) {
            $errorStrings = array_map(function ($error) {
                return "{{" . $error['column'] . "}}" . " => " . "{{" . $error['value'] . "}}";
            }, $errors);
            $this->rowConflict($index, "entity-importer.attribute-could-not-be-imported", ["attributeErrors" => implode(", ", $errorStrings)]);
        }
        return count($errors) == 0;
    }

    private function rowConflict($rowIndex, $msg, $args = []) {
        $tmsg = __($msg, $args);
        $this->resolver->conflict("[" . ($rowIndex + 1) . "] " . $tmsg);
    }

    private function getParentColumn($row) {
        if(!isset($this->parentColumn)) {
            return null;
        }

        return trim($row[$this->parentColumn]);
    }


    private function checkIfParentDoesExist($row) {
        $parent = $this->getParentColumn($row);
        // When parent column is not set, or it is empty, it's a top level entity
        if($parent == null || $parent == "") {
            return true;
        }

        return $this->checkIfEntityExists($parent);
    }

    // private function resolveRootPath($row, $rowIndex) {
    //     $rootPath = "";
    //     $entityName = $row[$this->nameColumn];
    //     if(isset($this->parentColumn)) {
    //         $parent =  $row[$this->parentColumn];
    //         $parentEntity = Entity::getFromPath($parent);
    //         if(!isset($parentEntity)) {
    //             $exceptionData = new ImportExceptionStruct(
    //                 count: $rowIndex,
    //                 entry: $entityName,
    //                 on: $parent,
    //                 on_index: $this->parentColumn,
    //                 on_value: $parent
    //             );
    //             throw new ImportException("Parent entity does not exist at: '$parent'", 422, $exceptionData);
    //         }
    //         $rootPath = $parent . self::PARENT_DELIMITER . $entityName;
    //     }
    //     return $rootPath;
    // }

    private function checkIfEntityExists($path) {
        return $this->checkEntityResolution($path) != ImportResolutionType::CREATE;
    }

    private function checkEntityResolution($path): ImportResolutionType {
        try {
            $id = $this->getEntityIdFromPath($path);
            return $id == null ? ImportResolutionType::CREATE : ImportResolutionType::UPDATE;
        } catch(AmbiguousValueException $e) {
            return ImportResolutionType::CONFLICT;
        }
    }

    private function getEntityIdFromPath(string $path): ?int {
        if(array_key_exists($path, $this->entityIdByPath)) {
            if($this->entityIdByPath[$path] === self::AMBIGUOUS_PATH_CACHE) {
                throw new AmbiguousValueException("Path '$path' is ambiguous");
            }
            return $this->entityIdByPath[$path];
        }

        if($this->pathCachePreloaded) {
            // During importData we preload all current paths once. Unknown paths
            // are therefore non-existing and can be treated as CREATE without DB lookup.
            $this->entityIdByPath[$path] = null;
            return null;
        }

        try {
            $id = Entity::getFromPath($path);
            $this->entityIdByPath[$path] = $id;
            if(isset($id)) {
                $this->entityTypeIdByEntityId[$id] = (int) Entity::where('id', $id)->value('entity_type_id');
            }
            return $id;
        } catch(AmbiguousValueException $e) {
            $this->entityIdByPath[$path] = self::AMBIGUOUS_PATH_CACHE;
            throw $e;
        }
    }

    private function isRelationAllowed(?int $parentTypeId, int $childTypeId): bool {
        $relationKey = ($parentTypeId ?? 'null') . '-' . $childTypeId;
        if(!array_key_exists($relationKey, $this->relationAllowedByTypeKey)) {
            $this->relationAllowedByTypeKey[$relationKey] = EntityTypeRelation::isAllowed($parentTypeId, $childTypeId);
        }
        return $this->relationAllowedByTypeKey[$relationKey];
    }

    private function getEntityTypeLabel(int $entityTypeId): string {
        if(!array_key_exists($entityTypeId, $this->entityTypeLabelById)) {
            $entityType = EntityType::find($entityTypeId);
            $this->entityTypeLabelById[$entityTypeId] = ThConcept::getLabel($entityType->thesaurus_url);
        }
        return $this->entityTypeLabelById[$entityTypeId];
    }
}
