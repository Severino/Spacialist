<?php

namespace App\Import;

/**
 * Shared configuration constants for the entity import pipeline.
 *
 * Centralises every "magic number" used across {@see EntityImporter},
 * {@see EntityImportValidator}, and related helpers so that tuning thresholds
 * (e.g. chunk sizes, progress units) only requires a change in one place.
 */
class ImportConstants {
    public const PARENT_DELIMITER = "\\\\";
    public const PROGRESS_EVENT_RATE_LIMIT_SECONDS = 2;
    public const ATTRIBUTE_UPSERT_CHUNK_SIZE = 5000;
    public const ENTITY_INSERT_CHUNK_SIZE = 1000;
    public const IMPORT_PROGRESS_TOTAL_UNITS = 1000;
    public const IMPORT_PARSE_PROGRESS_UNITS = 900;
}
