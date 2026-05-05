<?php

namespace App\Import;

use App\Attribute;
use App\AttributeTypes\AttributeBase;
use App\AttributeValue;
use App\Entity;
use App\Events\EntityImportProgress;
use App\Exceptions\AmbiguousValueException;
use App\Exceptions\CsvColumnMismatchException;
use App\Exceptions\ImportException;
use App\Exceptions\InvalidDataException;
use App\Exceptions\Structs\ImportExceptionStruct;
use App\File\Csv;
use App\Import\Caches\EntitiesEntityTypeIdCache;
use App\Import\Caches\PathCache;
use App\Import\ImportPipelineContext;
use App\Import\ImportResolution;
use App\Utils\NumberUtils;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the full CSV-to-database entity import pipeline.
 *
 * High-level flow inside {@see importData()}:
 *  1. Opens the CSV file and pre-warms the {@see EntityPathCache} with a single
 *     recursive SQL query so that every path lookup during row processing is an
 *     in-memory operation.
 *  2. Streams rows through {@see processImportRow()}, resolving parent entities,
 *     creating or looking up child entities, and queuing attribute upsert rows.
 *  3. Entities whose parent has not yet been persisted are given a negative
 *     temporary ID and batched in an {@see EntityImportBuffer}. When the pending
 *     buffer hits {@see ImportConstants::ENTITY_INSERT_CHUNK_SIZE} (or at the end
 *     of the file) a PostgreSQL CTE bulk-insert is executed and temporary IDs are
 *     reconciled via {@see applyTempEntityResolution()}.
 *  4. Attribute values accumulate in the buffer until
 *     {@see ImportConstants::ATTRIBUTE_UPSERT_CHUNK_SIZE} rows are pending, at
 *     which point a stage-and-merge strategy is used: rows are loaded into a
 *     temporary table and merged into `attribute_values` via a single ON CONFLICT
 *     upsert, minimising per-parameter overhead.
 *  5. Real-time progress is broadcast to subscribers via
 *     {@see \App\Events\EntityImportProgress}, throttled to avoid flooding the
 *     WebSocket channel on large files.
 *
 * Pre-validation (column existence, entity-type relations, etc.) is handled
 * separately by {@see EntityImportValidator}; this class assumes the mapping is
 * already known to be structurally valid.
 */
class EntityImporter {
    use HasEntityTypeLookups;

    private $metadata;
    private array $attributesMap;
    private array $attributeImportClassById = [];
    // private EntityPathCache $pathCache;

    private PathCache $pathCache;
    private EntitiesEntityTypeIdCache $entitiesTypeIdCache;
    private array $entityTypeIdByEntityId = [];
    private int $nextTempEntityId = -1;
    private array $nextRankByParentKey = [];
    private int $entityTypeId;
    private string $nameColumn;
    private ?string $parentColumn = null;
    private ?\Illuminate\Support\Carbon $importTimestamp = null;


    public function __construct($metadata, $data) {
        $this->metadata = $metadata;
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

        $buffer = new EntityImportBuffer();
        $attributeDefinitions = $this->resolveAttributeDefinitions();
        $ctx = new ImportPipelineContext($buffer, $attributeDefinitions, $user->id);

        // We load all entity paths into memory to avoid recursive SQL calls during import.
        // Getting an path value from the cache returns an array with entity_id and entity_type_id.
        $this->pathCache = new PathCache();
        $this->pathCache->preload();

        $this->entitiesTypeIdCache = new EntitiesEntityTypeIdCache();
        $this->entitiesTypeIdCache->preload();


        DB::beginTransaction();
        try {

            $csvTable = new Csv($this->metadata['has_header_row'], $this->metadata['delimiter'], $this->metadata['encoding']);

            $csvTable->progressListener->add(function ($processedBytes, $totalBytes) {
                EntityImportProgress::dispatchLimited(
                    NumberUtils::scaleProgress((int) $processedBytes, (int) $totalBytes, 0, ImportConstants::IMPORT_PARSE_PROGRESS_UNITS),
                    ImportConstants::IMPORT_PROGRESS_TOTAL_UNITS,
                    ImportConstants::PROGRESS_EVENT_RATE_LIMIT_SECONDS,
                    'CSV Parsing'
                );
            });



            $csvTable->batchListener->add(function ($rows) use ($ctx) {
                $this->importBatch($rows, $ctx);
            });

            try {
                $csvTable->parseHeaders($handle);
            } catch(Exception $e) {
                throw new ImportException($e->getMessage(), 400, new ImportExceptionStruct());
            }

            // Build a complete in-memory path lookup once to avoid thousands of
            // recursive SQL calls for getFromPath during large imports.

            EntityImportProgress::dispatch(0, ImportConstants::IMPORT_PROGRESS_TOTAL_UNITS);

            $csvTable->parse($handle, function ($row, $index) {});

            EntityImportProgress::dispatch(ImportConstants::IMPORT_PARSE_PROGRESS_UNITS, ImportConstants::IMPORT_PROGRESS_TOTAL_UNITS, 'Flush pending entities');

            $this->flushPendingEntities($ctx);

            EntityImportProgress::dispatch(975, ImportConstants::IMPORT_PROGRESS_TOTAL_UNITS, 'Flush pending attributes');

            if($buffer->hasUpsertRows()) {
                $this->flushAttributeUpsertRows($buffer->upsertRows);
            }

            EntityImportProgress::dispatch(995, ImportConstants::IMPORT_PROGRESS_TOTAL_UNITS, 'Finalizing');

            if($csvTable->getDataRows() === 0) {
                throw new ImportException(__('entity-importer.empty'), 400, new ImportExceptionStruct());
            }

            DB::commit();
            EntityImportProgress::dispatch(ImportConstants::IMPORT_PROGRESS_TOTAL_UNITS, ImportConstants::IMPORT_PROGRESS_TOTAL_UNITS, 'Completed');
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

        return $buffer->changedEntities;
    }

    private function importBatch(array $rows, ImportPipelineContext $ctx): void {

        $entities = [];
        foreach($rows as $index => $row) {
            $entity = [];
            $entityPath = $row[$this->parentColumn] . ImportConstants::PARENT_DELIMITER . $row[$this->nameColumn];
            
            $entity['name'] = $row[$this->nameColumn];
            $entity['root_entity_id'] = $this->pathCache->has($row[$this->parentColumn]) ? $this->pathCache->get($row[$this->parentColumn])['id'] : null;
            $entity['entity_type_id'] = $this->entityTypeId;
            $entity['user_id'] = $ctx->userId;
            
            if($this->pathCache->has($entityPath)) {
                $path = $this->pathCache->get($entityPath);
                if(!isset($path['id'])) {
                    throw new ImportException('The requested entity does not have a valid id.', 400, new ImportExceptionStruct(
                        count: $index + 1,
                        entry: $row[$this->nameColumn],
                        on: $entityPath
                    ));
                }

                $row['id'] = $path['id'];
            } 
            
            $entities[] = $entity;
        }

        Entity::upsert(
            $entities,
            ['id'],
            ['name', 'entity_type_id', 'root_entity_id', 'user_id']
        );

    }

    private function processImportRow(array $row, int $index, ImportPipelineContext $ctx): void {
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
        $parentEntityId = $this->resolveParentEntityId($hasParent, $rootEntityPath, $entityName, $index, $ctx);
        if(isset($parentEntityId) && !empty($rootEntityPath)) {
            $entityPath = implode(ImportConstants::PARENT_DELIMITER, [$rootEntityPath, $entityName]);
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
            $entityId = $this->queuePendingEntity($ctx, $entityName, $entityPath, $parentEntityId);

            $this->entityTypeIdByEntityId[$entityId] = $this->entityTypeId;
            $this->pathCache->set($entityPath, $entityId);

            if($ctx->buffer->pendingEntitiesCount() >= ImportConstants::ENTITY_INSERT_CHUNK_SIZE) {
                $this->flushPendingEntities($ctx);
                $entityId = $this->getEntityIdFromPath($entityPath);
            }
        }

        $changedIndex = $ctx->buffer->appendChangedEntity($entityId);
        if($entityId < 0) {
            $ctx->buffer->trackPendingChangedEntityIndex($entityId, $changedIndex);
            $ctx->buffer->appendPendingAttributeRow([
                'temp_entity_id' => $entityId,
                'row' => $row,
                'row_index' => $index,
                'entity_name' => $entityName,
                'user_id' => $ctx->userId,
            ]);
            return;
        }

        $this->appendAttributeUpsertRows($ctx, $row, $index, $entityId, $entityName);
        if(count($ctx->buffer->upsertRows) >= ImportConstants::ATTRIBUTE_UPSERT_CHUNK_SIZE) {
            $this->flushAttributeUpsertRows($ctx->buffer->upsertRows);
        }
    }

    private function resolveParentEntityId(
        bool $hasParent,
        ?string $rootEntityPath,
        string $entityName,
        int $index,
        ImportPipelineContext $ctx
    ): ?int {
        if(!$hasParent || empty($rootEntityPath)) {
            return null;
        }

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
            $this->flushPendingEntities($ctx);
            $parentEntityId = $this->getEntityIdFromPath($rootEntityPath);
        }

        return $parentEntityId;
    }

    private function queuePendingEntity(
        ImportPipelineContext $ctx,
        string $entityName,
        string $entityPath,
        ?int $parentEntityId
    ): int {
        $nextRank = $this->getNextRankForParent($parentEntityId);
        $tempEntityId = $this->nextTempEntityId--;
        $now = now()->addMicroseconds(abs($tempEntityId));
        $ctx->buffer->addPendingEntity($tempEntityId, [
            'name' => $entityName,
            'entity_type_id' => $this->entityTypeId,
            'root_entity_id' => $parentEntityId,
            'rank' => $nextRank,
            'user_id' => $ctx->userId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $entityPath);

        return $tempEntityId;
    }

    private function flushPendingEntities(ImportPipelineContext $ctx): void {
        if(!$ctx->buffer->hasPendingEntities()) {
            return;
        }

        $tempToRealId = $this->bulkInsertEntities($ctx->buffer->pendingEntities);

        foreach($tempToRealId as $tempId => $realId) {
            $this->applyTempEntityResolution($ctx->buffer, $tempId, $realId);
        }

        $remainingPendingAttributeRows = [];
        foreach($ctx->buffer->pendingAttributeRows as $pendingAttributeRow) {
            $tempEntityId = $pendingAttributeRow['temp_entity_id'];
            if(!isset($tempToRealId[$tempEntityId])) {
                $remainingPendingAttributeRows[] = $pendingAttributeRow;
                continue;
            }

            $entityId = $tempToRealId[$tempEntityId];
            $this->appendAttributeUpsertRows(
                $ctx,
                $pendingAttributeRow['row'],
                $pendingAttributeRow['row_index'],
                $entityId,
                $pendingAttributeRow['entity_name']
            );

            if(count($ctx->buffer->upsertRows) >= ImportConstants::ATTRIBUTE_UPSERT_CHUNK_SIZE) {
                $this->flushAttributeUpsertRows($ctx->buffer->upsertRows);
            }
        }

        $ctx->buffer->setPendingAttributeRows($remainingPendingAttributeRows);
        $ctx->buffer->clearPendingEntities();
    }

    private function applyTempEntityResolution(EntityImportBuffer $buffer, int $tempId, int $realId): void {
        foreach($buffer->consumePendingEntityPaths($tempId) as $path) {
            $this->pathCache->setIdForPath($path, $realId);
        }

        if(isset($this->entityTypeIdByEntityId[$tempId])) {
            $this->entityTypeIdByEntityId[$realId] = $this->entityTypeIdByEntityId[$tempId];
            unset($this->entityTypeIdByEntityId[$tempId]);
        }

        foreach($buffer->consumePendingChangedEntityIndexes($tempId) as $changedIndex) {
            $buffer->replaceChangedEntityAt($changedIndex, $realId);
        }
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
                inserted AS (
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
                        input.name,
                        input.entity_type_id::integer,
                        input.root_entity_id::integer,
                        input.rank::integer,
                        input.user_id::integer,
                        input.created_at::timestamptz,
                        input.updated_at::timestamptz
                    FROM input
                    ORDER BY input.unique_seed::bigint, input.temp_id::integer
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
                    input.temp_id::integer,
                    inserted.id
                FROM inserted
                INNER JOIN input i
                    ON inserted.name = input.name
                    AND inserted.entity_type_id = input.entity_type_id::integer
                    AND inserted.user_id = input.user_id::integer
                    AND inserted.created_at = input.created_at::timestamptz
                    AND inserted.updated_at = input.updated_at::timestamptz
                    /*
                        rank and root_entity_id may be null and null == null => null therefore we need the
                        IS NOT DISTINCT FROM operator.
                    */
                    AND inserted.rank IS NOT DISTINCT FROM input.rank::integer
                    AND inserted.root_entity_id IS NOT DISTINCT FROM input.root_entity_id::integer
                ORDER BY input.temp_id::integer
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

    // private function preloadEntityPathCache(): void {


    //     $this->pathCache->preload($rows);

    //     foreach($rows as $row) {
    //         $this->entityTypeIdByEntityId[(int) $row->id] = (int) $row->entity_type_id;
    //     }
    // }

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

    private function appendAttributeUpsertRows(ImportPipelineContext $ctx, array $row, int $rowIndex, int $entityId, string $entityName): void {
        if(empty($ctx->attributeDefinitions)) {
            return;
        }

        foreach($ctx->attributeDefinitions as $attributeId => $definition) {
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
            $ctx->buffer->upsertRows[] = [
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
                'user_id' => $ctx->userId,
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

    private function getEntityIdFromPath(string $path): ?int {
        try {
            return $this->pathCache->getIdFromPath($path);
        } catch(AmbiguousValueException $e) {
            if(!$this->pathCache->isPreloaded()) {
                throw $e;
            }

            // During importData we preload all current paths once. Unknown paths
            // are therefore non-existing and can be treated as CREATE without DB lookup.
            if(!in_array($path, [])) {
                $this->entityTypeIdByEntityId[(int) $this->pathCache->getIdFromPath($path)] = $this->entityTypeId;
            }
            return null;
        }
    }

}
