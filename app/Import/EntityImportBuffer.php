<?php

namespace App\Import;

/**
 * Mutable runtime state bag for the entity import pipeline.
 *
 * Accumulates the intermediate results produced while processing CSV rows and
 * exposes behaviour-oriented methods so that the caller ({@see EntityImporter})
 * never manipulates the raw arrays directly.
 *
 * Lifecycle overview:
 *  1. **Pending entities** – rows whose parent has not yet been flushed receive
 *     a negative temporary ID. They are queued here until {@see EntityImporter::flushPendingEntities()}
 *     writes them to the DB and reconciles real IDs.
 *  2. **Changed entities** – the flat list of every entity ID touched during the
 *     import (both new and updated). Temporary IDs are patched in-place by
 *     {@see replaceChangedEntityAt()} once the DB flush returns real IDs.
 *  3. **Upsert rows** – prepared attribute-value rows destined for the
 *     `attribute_values` table, accumulated here until the chunk threshold is
 *     reached and {@see EntityImporter::flushAttributeUpsertRows()} drains them.
 *  4. **Pending attribute rows** – attribute data whose entity ID is still
 *     temporary. Kept separate until the matching entity is flushed.
 */
class EntityImportBuffer {
    public array $changedEntities = [];
    public array $upsertRows = [];
    public array $pendingEntities = [];
    public array $pendingEntityPathsByTempId = [];
    public array $pendingChangedEntityIndexesByTempId = [];
    public array $pendingAttributeRows = [];

    public function appendChangedEntity(int $entityId): int {
        $this->changedEntities[] = $entityId;
        return count($this->changedEntities) - 1;
    }

    public function replaceChangedEntityAt(int $index, int $entityId): void {
        $this->changedEntities[$index] = $entityId;
    }

    public function addPendingEntity(int $tempEntityId, array $row, string $entityPath): void {
        $this->pendingEntities[$tempEntityId] = $row;
        $this->pendingEntityPathsByTempId[$tempEntityId][] = $entityPath;
    }

    public function hasPendingEntities(): bool {
        return !empty($this->pendingEntities);
    }

    public function pendingEntitiesCount(): int {
        return count($this->pendingEntities);
    }

    public function consumePendingEntityPaths(int $tempEntityId): array {
        if(!isset($this->pendingEntityPathsByTempId[$tempEntityId])) {
            return [];
        }

        $paths = $this->pendingEntityPathsByTempId[$tempEntityId];
        unset($this->pendingEntityPathsByTempId[$tempEntityId]);

        return $paths;
    }

    public function trackPendingChangedEntityIndex(int $tempEntityId, int $changedIndex): void {
        $this->pendingChangedEntityIndexesByTempId[$tempEntityId][] = $changedIndex;
    }

    public function consumePendingChangedEntityIndexes(int $tempEntityId): array {
        if(!isset($this->pendingChangedEntityIndexesByTempId[$tempEntityId])) {
            return [];
        }

        $indexes = $this->pendingChangedEntityIndexesByTempId[$tempEntityId];
        unset($this->pendingChangedEntityIndexesByTempId[$tempEntityId]);

        return $indexes;
    }

    public function appendPendingAttributeRow(array $pendingAttributeRow): void {
        $this->pendingAttributeRows[] = $pendingAttributeRow;
    }

    public function setPendingAttributeRows(array $pendingAttributeRows): void {
        $this->pendingAttributeRows = $pendingAttributeRows;
    }

    public function clearPendingEntities(): void {
        $this->pendingEntities = [];
    }

    public function hasUpsertRows(): bool {
        return !empty($this->upsertRows);
    }
}
