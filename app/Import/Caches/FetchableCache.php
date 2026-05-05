<?php

namespace App\Import\Caches;


/**
 * A Cache that implements a fetch function that automatically
 * populates thte cache with missing values.
 */
abstract class FetchableCache extends Cache {

    public function get(string $key): mixed {
        $value = parent::get($key);
        if($value === null) {
            $value = $this->fetch($key);
            if(!is_null($value)) {
                $this->set($key, $value);
            }
        }
        return $value;
    }

    /**
     * 
     * 
     * @param string $key
     * @return mixed
     */
    abstract public function fetch(string $key): mixed; 
}