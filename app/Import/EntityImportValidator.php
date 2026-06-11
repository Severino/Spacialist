<?php

namespace App\Import;

use App\Attribute;
use App\AttributeTypes\AttributeBase;
use App\EntityType;
use App\Events\EntityImportProgress;
use App\Exceptions\AmbiguousValueException;
use App\Exceptions\CsvColumnMismatchException;
use App\File\Csv;
use Exception;

/**
 * Validates a CSV import file and its column mapping before any data is written.
 *
 * Called before {@see EntityImporter::importData()} to give the user early
 * feedback without touching the database in a destructive way. Validation
 * covers:
 *  - Presence of required metadata fields (`name_column`, `entity_type_id`).
 *  - Existence of the named columns (`name_column`, `parent_column`) in the CSV
 *    header row.
 *  - Existence of the target entity type in the database.
 *  - Existence of every mapped attribute ID and its column in the CSV header.
 *  - Per-row checks:
 *    - Non-empty entity name.
 *    - Parent entity exists (path resolved via {@see EntityPathCache}).
 *    - Parent→child entity-type relation is permitted.
 *    - Each mapped attribute value can be parsed by its import class.
 *
 * Results are collected in an {@see ImportResolution} which tallies expected
 * creates, updates, and conflicts, allowing the UI to show a pre-flight summary
 * without committing anything.
 */
class EntityImportValidator {
    use HasEntityTypeLookups;

    private array $metadata;
    private string $nameColumn;
    private ?string $parentColumn;
    private int $entityTypeId;
    private array $attributesMap;
    private ImportResolution $resolver;
    private EntityPathCache $pathCache;
    private array $attributeIdToAttributeValue = [];
    private array $attributeImportClassById = [];

    public function __construct(array $metadata, string $nameColumn, ?string $parentColumn, int $entityTypeId, array $attributesMap) {
        $this->resolver = new ImportResolution();
        $this->metadata = $metadata;
        $this->nameColumn = $nameColumn;
        $this->parentColumn = $parentColumn;
        $this->entityTypeId = $entityTypeId;
        $this->attributesMap = $attributesMap;
        $this->pathCache = new EntityPathCache();
    }

    public function validate(string $filepath): ImportResolution {
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
            } catch(Exception $e) {
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
                        $this->validatePlacement($row, $index);
                    }
                    $this->validateAttributesInRow($row, $index);
                }, function ($processedBytes, $totalBytes, $rowIndex) {
                    EntityImportProgress::dispatchLimited($processedBytes, $totalBytes, ImportConstants::PROGRESS_EVENT_RATE_LIMIT_SECONDS, "Verify rows (#{$rowIndex})");
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

    private function validateStringData(string $varName, string $dataName): void {
        if(gettype($this->{$varName}) == "string") {
            $this->{$varName} = trim($this->{$varName});
        }

        if(empty($this->{$varName})) {
            $this->resolver->conflict(__("entity-importer.missing-data", ["column" => $dataName]));
        }
    }

    private function validateAttributeData(): void {
        if(!is_array($this->attributesMap)) {
            $this->resolver->conflict(__("entity-importer.invalid-data", ["column" => "attributes", "value" => json_encode($this->attributesMap)]));
            return;
        }

        $this->attributesMap = array_map(function ($value) {
            return trim($value);
        }, $this->attributesMap);
    }

    private function verifyNameColumn(array $headers): bool {
        if(empty($this->nameColumn) || !in_array($this->nameColumn, $headers)) {
            $this->resolver->conflict(__("entity-importer.name-column-does-not-exist", ["column" => $this->nameColumn]));
            return false;
        }
        return true;
    }

    private function verifyParentColumn(array $headers): bool {
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

    private function verifyAttributeMapping(array $headers): bool {
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

    private function validateName(array $row, int $rowIndex): bool {
        $entityName = $row[$this->nameColumn];

        if(gettype($entityName) == "string")
            $entityName = trim($entityName);

        if(empty($entityName)) {
            $this->rowConflict($rowIndex, "entity-importer.missing-name-in-row");
            return false;
        }

        return true;
    }

    private function validatePlacement(array $row, int $rowIndex): bool {
        $parentTypeId = null;
        $parentPath = $this->getParentColumn($row);
        if(!empty($parentPath)) {
            $parentId = $this->getEntityIdFromPath($parentPath);
            if($parentId == null) {
                $this->rowConflict($rowIndex, "entity-importer.parent-entity-does-not-exist", ["entity" => $parentPath]);
                return false;
            }

            $parentTypeId = $this->getEntityTypeIdForEntity($parentId);
        }

        $isAllowedAsChild = $this->isRelationAllowed($parentTypeId, $this->entityTypeId);
        if(!$isAllowedAsChild) {
            $childName = $this->getEntityTypeLabel($this->entityTypeId);
            $parentName = $parentTypeId ? $this->getEntityTypeLabel($parentTypeId) : "TOP";
            $this->rowConflict($rowIndex, "entity-importer.entity-type-relation-not-allowed", ["child" => $childName, "parent" => $parentName]);
            return false;
        }

        $filepath = implode(ImportConstants::PARENT_DELIMITER, array_filter([$parentPath, $row[$this->nameColumn]], fn($part) => !empty($part)));
        if($this->checkIfEntityExists($filepath)) {
            $this->resolver->update();
        } else {
            $this->resolver->create();
        }

        return true;
    }

    private function validateAttributesInRow(array $row, int $index): bool {
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

    private function rowConflict(int $rowIndex, string $msg, array $args = []): void {
        $tmsg = __($msg, $args);
        $this->resolver->conflict("[" . ($rowIndex + 1) . "] " . $tmsg);
    }

    private function getParentColumn(array $row): ?string {
        if(!isset($this->parentColumn)) {
            return null;
        }

        return trim($row[$this->parentColumn]);
    }

    private function checkIfEntityExists(string $path): bool {
        return $this->checkEntityResolution($path) != ImportResolutionType::CREATE;
    }

    private function checkEntityResolution(string $path): ImportResolutionType {
        try {
            $id = $this->getEntityIdFromPath($path);
            return $id == null ? ImportResolutionType::CREATE : ImportResolutionType::UPDATE;
        } catch(AmbiguousValueException $e) {
            return ImportResolutionType::CONFLICT;
        }
    }

    private function getEntityIdFromPath(string $path): ?int {
        return $this->pathCache->getIdFromPath($path);
    }
}
