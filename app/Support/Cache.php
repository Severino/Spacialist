<?php

namespace App\Support;

class Cache {

    public readonly array $cache = [];

    /**
     * Stores a value in the cache.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, $value): void {
        $this->cache[$key] = $value;
    }

    /**
     * Retrieves a value from the cache.
     *
     * @param string $key
     * @return mixed
     */
    public function get(string $key) {
        return $this->cache[$key] ?? null;
    }

    /**
     * Checks if a key exists in the cache.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool {
        return array_key_exists($key, $this->cache);
    }
    
    /**
     * Removes a key from the cache.
     *
     * @param string $key
     * @return void
     */
    public function delete(string $key): void {
        unset($this->cache[$key]);
    }
    
    /**
     * Clears the entire cache.
     *
     * @return void
     */
    public function clear(): void {
        $this->cache = [];
    }
    
}