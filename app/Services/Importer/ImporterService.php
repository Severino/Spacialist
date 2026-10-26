<?php

namespace App\Services\Importer;

use App\Import\ImportResolution;

abstract class ImporterService {

    public readonly string $columnName;
    protected ImportResolution $resolver;
    
    public function __construct(public readonly string $headerKey) {}

    public function init(string $columnName, ImportResolution $resolver): void {
        $this->columnName = trim($columnName);
        $this->resolver = $resolver;
    }

    private function requireColumn(): bool {
        return isset($this->columnName) && $this->columnName !== '';
    }

    /**
     * Checks the header array if the specified column exists.
     *
     * @return void
     */
    public function verifyHeader(array $headers): bool {
        // When the column name is not set, we don't process the column
        // so the header is 'valid'.
        if(!$this->requireColumn()) return true;
        if(!in_array($this->columnName, $headers)) {
            $this->resolver->conflict(__("entity-importer.column-does-not-exist", ["column" => $this->columnName]));
            return false;
        }
        return true;
    }

    /**
     * Imports the row into the system.
     * @param array $row
     * @param int $index
     * @return void
     */
    abstract function import(array $row, int $index): void;

    protected function rowConflict($rowIndex, $msg, $args = []) {
        $tmsg = __($msg, $args);
        $this->resolver->conflict("[" . ($rowIndex + 1) . "] " . $tmsg);
    }
}