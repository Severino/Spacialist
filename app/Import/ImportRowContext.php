<?php

namespace App\Import;

/**
 * Parsed context for a single CSV row being processed during import.
 *
 * Created once per row inside {@see EntityImporter::processImportRow()} after
 * the raw CSV values have been extracted and trimmed. Passed down to sub-methods
 * so they can reference row-specific data without needing to re-parse the raw
 * CSV array.
 */
class ImportRowContext {
    public function __construct(
        public readonly int $rowIndex,
        public readonly string $entityName,
        public readonly bool $hasParent,
        public readonly ?string $rootEntityPath,
    ) {}
}
