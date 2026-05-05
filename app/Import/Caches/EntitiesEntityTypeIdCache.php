<?php

namespace App\Import\Caches;

use Illuminate\Support\Facades\DB;

class EntitiesEntityTypeIdCache extends Cache {
    public function preload(): void {
        $rows = DB::select(
            <<<'SQL'
                SELECT id, entity_type_id
                FROM entities
            SQL
        );
        foreach($rows as $row) {
            $this->set($row->id, $row->entity_type_id);
        }
    }
}