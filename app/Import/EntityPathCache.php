<?php

namespace App\Import;

use App\Entity;
use App\Exceptions\AmbiguousValueException;

/**
 * In-memory cache that maps entity path strings to their database IDs.
 *
 * A "path" is the backslash-delimited chain of entity names from the tree root
 * down to a given entity, e.g. `Site\\Trench A\\Context 1`.
 *
 * Two operating modes are supported:
 *
 * - **Cold (live-lookup)**: used during the validation phase. Each unknown path
 *   triggers a single SQL query via {@see \App\Entity::getFromPath()} and the
 *   result is cached for subsequent calls within the same pass.
 *
 * - **Pre-loaded (hot)**: used during the actual import phase. The importer
 *   calls {@see preload()} once before processing rows, populating the full path
 *   table in a single recursive SQL query. After that, any path not present in
 *   the cache is treated as non-existent without further DB access.
 *
 * Ambiguous paths (same name under different parents resolving to more than one
 * entity) are stored under a sentinel value and re-thrown as
 * {@see \App\Exceptions\AmbiguousValueException} on access.
 */
class EntityPathCache {
    private const AMBIGUOUS_PATH_CACHE = '__AMBIGUOUS_PATH__';

    private array $idByPath = [];
    private bool $isPreloaded = false;

    public function getIdFromPath(string $path): ?int {
        if(array_key_exists($path, $this->idByPath)) {
            if($this->idByPath[$path] === self::AMBIGUOUS_PATH_CACHE) {
                throw new AmbiguousValueException("Path '$path' is ambiguous");
            }
            return $this->idByPath[$path];
        }

        if($this->isPreloaded) {
            // During import with preload, unknown paths don't exist.
            $this->idByPath[$path] = null;
            return null;
        }

        // Live DB lookup for validation phase.
        try {
            $id = Entity::getFromPath($path);
            $this->idByPath[$path] = $id;
            return $id;
        } catch(AmbiguousValueException $e) {
            $this->idByPath[$path] = self::AMBIGUOUS_PATH_CACHE;
            throw $e;
        }
    }

    public function setIdForPath(string $path, int $id): void {
        $this->idByPath[$path] = $id;
    }

    public function preload(array $rows): void {
        foreach($rows as $row) {
            $path = $row->pathstr;
            if(array_key_exists($path, $this->idByPath) && $this->idByPath[$path] !== (int) $row->id) {
                $this->idByPath[$path] = self::AMBIGUOUS_PATH_CACHE;
                continue;
            }

            $this->idByPath[$path] = (int) $row->id;
        }

        $this->isPreloaded = true;
    }

    public function isPreloaded(): bool {
        return $this->isPreloaded;
    }
}
