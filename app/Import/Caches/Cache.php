<?php

namespace App\Import\Caches;

class Cache {
    private array $cache = [];
    
    public function has(string $key): bool {
        return array_key_exists($key, $this->cache);
    }
    
    public function get(string $key): mixed {
        return $this->cache[$key] ?? null;
    }
    
    public function set(string $key, mixed $value): void {
        $this->cache[$key] = $value;
    }
    
    public function clear(): void {
        $this->cache = [];
    }
    
    public function delete(string $key): void {
        unset($this->cache[$key]);
    }
}

