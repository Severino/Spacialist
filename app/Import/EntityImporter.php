<?php

namespace App\Import;

use App\Exceptions\CsvColumnMismatchException;
use App\File\Csv;
use App\File\CsvOptions;
use App\Entity;
use App\EntityType;
use App\EntityTypeRelation;
use App\Exceptions\AmbiguousValueException;
use App\Import\ImportResolution;
use App\Attribute;
use App\AttributeTypes\AttributeBase;
use App\ThConcept;
use Exception;

enum Action {
    case CREATE;
    case UPDATE;
    case DELETE;
}

class EntityImporter {

    const PARENT_DELIMITER = "\\\\";

    private $metadata;
    private array $attributeIdToAttributeValue = [];
    private ImportResolution $resolver;


    public function __construct(
        private array $data,
        private string $nameColumn,
        private int $entityTypeId,
        private array $attributesMap,
        private CsvOptions $csvOptions,
        private ?string $parentColumn = null,
    ) {
        $this->resolver = new ImportResolution();
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

    public function validate($filepath) {
        $this->validateStringData('nameColumn', 'name_column');
        $this->validateStringData('entityTypeId', 'entity_type_id');
        if(isset($this->parentColumn)) {
            $this->validateStringData('parentColumn', 'parent_column');
        }

        $this->validateAttributeData();

        if($this->resolver->hasErrors()) {
            return $this->resolver;
        }

        $handle = fopen($filepath, 'r');

        if(!$handle) {
            return $this->resolver->conflict(__("entity-importer.file-not-found", ["file" => $filepath]));
        }

        $csvTable = new Csv($this->csvOptions->hasHeaderRow, $this->csvOptions->delimiter, $this->csvOptions->encoding);

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
            $csvTable->parse($handle, function ($row, $rowNumber) use ($csvTable) {
                
                // We use the row number only to track the current human readable row (1-n)
                // Therefore we must increase the row number by one, otherwise  the header
                // row would be zero.
                if(!$csvTable->hasHeaderRow) {
                    $rowNumber = $rowNumber + 1;
                }
            
                $nameValid = $this->validateName($row, $rowNumber);
                $attributeValid = $this->validateAttributesInRow($row, $rowNumber);
                $parentPath = "";
                $locationValid = $this->validateLocation($row, $rowNumber, $parentPath);

                if(!$nameValid || !$attributeValid || !$locationValid) {
                    return;
                }

                $filepath = implode(self::PARENT_DELIMITER, array_filter([$parentPath, $row[$this->nameColumn]], fn($part) => !empty($part)));
                if($this->checkIfEntityExists($filepath)) {
                    $this->resolver->update();
                } else {
                    $this->resolver->create();
                }
            });

            if($csvTable->getDataRows() == 0) {
                $this->resolver->conflict(__("entity-importer.empty"));
            }
        } catch(CsvColumnMismatchException $csvMismatchException) {
            return $this->resolver->conflict(__("entity-importer.csv-column-mismatch", [
                "data" => $csvMismatchException->dataLine,
                "data_count" => $csvMismatchException->dataColumns,
                "header_data" => $csvMismatchException->headerLine,
                "header_count" => $csvMismatchException->headerColumns,
            ]));
        }

        fclose($handle);
        return $this->resolver;
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

    private function validateLocation(array $row, int $rowNumber, string &$parentPath): bool {

        $parentTypeId = null;
        $parentPath = $this->getParentColumn($row);
        if(!empty($parentPath)) {
            $parentId = Entity::getFromPath($parentPath);
            if($parentId == null) {
                $this->rowConflict($rowNumber, "entity-importer.parent-entity-does-not-exist", ["entity" => $parentPath]);
                return false;
            }

            $parent = Entity::find($parentId);
            $parentTypeId = $parent->entity_type_id;
        }

        $isAllowedAsChild = EntityTypeRelation::isAllowed($parentTypeId, $this->entityTypeId);
        if(!$isAllowedAsChild) {
            $childTh = EntityType::find($this->entityTypeId)->thesaurus_url;
            $childName = ThConcept::getLabel($childTh);

            $parentName = "TOP";
            if($parentTypeId){
                $parentTh = EntityType::find($parentTypeId)->thesaurus_url;
                $parentName = ThConcept::getLabel($parentTh);
            }

            $this->rowConflict($rowNumber, "entity-importer.entity-type-relation-not-allowed", ["child" => $childName, "parent" => $parentName]);
            return false;
        }

        return true;
    }

    private function validateAttributesInRow(array $row, int $rowNumber): bool {
        $errors = [];
        foreach($this->attributeIdToAttributeValue as $attributeId => $attribute) {
            try {
                $column = $this->attributesMap[$attributeId];
                $datatype = $attribute->datatype;
                $attrClass = AttributeBase::getMatchingClass($datatype);
                $attrClass::fromImport($row[$column]);
            } catch(Exception $e) {
                array_push($errors, ["column" => $column, "value" => $row[$column]]);
            }
        }

        if(count($errors) > 0) {
            $errorStrings = array_map(function ($error) {
                return "{{" . $error['column'] . "}}" . " => " . "{{" . $error['value'] . "}}";
            }, $errors);
            $this->rowConflict($rowNumber, "entity-importer.attribute-could-not-be-imported", ["attributeErrors" => implode(", ", $errorStrings)]);
        }
        return count($errors) == 0;
    }

    private function rowConflict(int $rowNumber, string $msg, array $args = []) {
        $tmsg = __($msg, $args);
        $this->resolver->conflict("[" . $rowNumber . "] " . $tmsg);
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
            $id = Entity::getFromPath($path);
            return $id == null ? ImportResolutionType::CREATE : ImportResolutionType::UPDATE;
        } catch(AmbiguousValueException $e) {
            return ImportResolutionType::CONFLICT;
        }
    }
}
