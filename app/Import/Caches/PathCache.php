<?php

namespace App\Import\Caches;

use App\Import\ImportConstants;
use Illuminate\Support\Facades\DB;

class PathCache extends Cache {
    public function preload(): void {
        $rows = DB::select(
            <<<'SQL'
                WITH RECURSIVE entity_paths AS (
                    SELECT
                        e.id,
                        e.entity_type_id,
                        e.root_entity_id,
                        e.name::text AS pathstr
                    FROM entities e
                    WHERE e.root_entity_id IS NULL
                    UNION ALL
                    SELECT
                        e.id,
                        e.entity_type_id,
                        e.root_entity_id,
                        p.pathstr || ? || e.name AS pathstr
                    FROM entities e
                    INNER JOIN entity_paths p ON p.id = e.root_entity_id
                )
                SELECT id, entity_type_id, pathstr
                FROM entity_paths
            SQL,
            [ImportConstants::PARENT_DELIMITER]
        );
        
        foreach($rows as $row) {
            $this->set($row->pathstr, ['id' => $row->id, "entity_type_id" => $row->entity_type_id]);
        }
    }
}