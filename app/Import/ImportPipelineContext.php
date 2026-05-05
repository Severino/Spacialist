<?php

namespace App\Import;

/**
 * Immutable reference bundle for the shared state threaded through the import
 * pipeline for a single {@see EntityImporter::importData()} run.
 *
 * Groups the three values that every row-processing method needs so they can be
 * passed as a single argument instead of being repeated in every signature.
 * The buffer itself is mutable (it accumulates results); only the reference to
 * it is fixed for the lifetime of one import call.
 */
class ImportPipelineContext {
    public function __construct(
        public readonly EntityImportBuffer $buffer,
        public readonly array $attributeDefinitions,
        public readonly int $userId,
    ) {}
}
