<?php

namespace App\Services\Importer;

use App\Support\Cache;
use Exception;

abstract class ReferenceService extends ImporterValidatedService {
    protected Cache $bibliographyCache;

    public function __construct() {
        parent::__construct('entity_reference');
        $this->bibliographyCache = new Cache();
    }

    public function validate(array $row, int $index): bool {
        $reference = $row[$this->columnName];
        $errors = [];
        if(!empty($reference)) {
            $parsedReferences = Reference::parseReferencedFromString($reference);
            foreach($parsedReferences as $parsedReference) {
                if(!$parsedReference["valid"]) {
                    $errors[] = "Wrong format of Reference: " . $parsedReference['input'];
                    continue;
                }

                $hasValidCiteKey = Reference::checkIfCiteKeyIsValid($parsedReference['citeKey'], $this->bibliographyCache);
                if(!$hasValidCiteKey) {
                    $errors[] = "Bibliography with citekey does not exist: " . $parsedReference['citeKey'];
                    continue;
                }
            }

            if(count($errors) > 0) {
                $this->rowConflict($index, "Error importing Biliography:\n" . implode("; ", $errors));
            }
        }
        return count($errors) == 0;
    }

    public function import(array $row, int $index): void {
        throw new Exception("Not implemented");
    }

}