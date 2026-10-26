<?php

namespace App\Support;

use App\Support\Cache;

class FetchableCache extends Cache {
    public function __construct(private $fetchFunction) {}

    public function fetch($key) {
        if(!$this->has($key)) {
            $this->set($key, ($this->fetchFunction)($key));
        }
        return $this->get($key);
    }
}