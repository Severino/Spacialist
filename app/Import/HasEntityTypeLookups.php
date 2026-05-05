<?php

namespace App\Import;

use App\Entity;
use App\EntityType;
use App\EntityTypeRelation;
use App\ThConcept;

/**
 * Provides cached entity-type helper lookups shared across the import pipeline.
 *
 * Both {@see EntityImporter} and {@see EntityImportValidator} need to:
 *  - check whether a parent→child entity-type relation is permitted, and
 *  - resolve a human-readable label for an entity type ID.
 *
 * Without this trait both classes would maintain separate caches and duplicate
 * the same DB queries. The trait keeps a single in-process cache per consuming
 * instance so every type pair is resolved at most once per import run.
 */
trait HasEntityTypeLookups {
    private array $relationAllowedByTypeKey = [];
    private array $entityTypeLabelById = [];
    private array $cachedEntityTypeIdByEntityId = [];

    protected function isRelationAllowed(?int $parentTypeId, int $childTypeId): bool {
        $relationKey = ($parentTypeId ?? 'null') . '-' . $childTypeId;
        if(!array_key_exists($relationKey, $this->relationAllowedByTypeKey)) {
            $this->relationAllowedByTypeKey[$relationKey] = EntityTypeRelation::isAllowed($parentTypeId, $childTypeId);
        }
        return $this->relationAllowedByTypeKey[$relationKey];
    }

    protected function getEntityTypeLabel(int $entityTypeId): string {
        if(!array_key_exists($entityTypeId, $this->entityTypeLabelById)) {
            $entityType = EntityType::find($entityTypeId);
            $this->entityTypeLabelById[$entityTypeId] = ThConcept::getLabel($entityType->thesaurus_url);
        }
        return $this->entityTypeLabelById[$entityTypeId];
    }

    protected function getEntityTypeIdForEntity(int $entityId): int {
        if(!array_key_exists($entityId, $this->cachedEntityTypeIdByEntityId)) {
            $this->cachedEntityTypeIdByEntityId[$entityId] = (int) Entity::find($entityId)->entity_type_id;
        }
        return $this->cachedEntityTypeIdByEntityId[$entityId];
    }
}
