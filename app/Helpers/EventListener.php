<?php

namespace App\Helpers;

class EventListener {
    private array $listeners = [];
    private int $nextId = 1;

    private function getNextId(): int {
        return $this->nextId++;
    }
    
    public function add(callable $listener): int {
        $id = $this->getNextId();
        $this->listeners[$id] = $listener;
        return $id;
    }
    
    public function call(mixed ...$args): void {
        foreach($this->listeners as $listener) {
            $listener(...$args);
        }
    }
}