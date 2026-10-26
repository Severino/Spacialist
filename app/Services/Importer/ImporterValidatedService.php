<?php

namespace App\Services\Importer;


/**
 * Some imported values do not need to undergo validation
 * they can use the default ImporterService. 
 */
abstract class  ImporterValidatedService extends ImporterService {
    

    /**
     * Validates if the row can be imported.
     * @param array $row
     * @param int $index
     * @return void
     */
    abstract function validate(array $row, int $index): bool;
    
}