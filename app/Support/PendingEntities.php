<?php

class PendingEntities {
    
    public static function insertBulk (array $pendingEntities) {
        if(empty($pendingEntities)) {
            return [];
        }

        $values = [];
        $bindings = [];

        foreach($pendingEntities as $tempId => $row) {
            $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $bindings[] = (int) $tempId;
            $bindings[] = $row['name'];
            $bindings[] = (int) $row['entity_type_id'];
            $bindings[] = $row['root_entity_id'];
            $bindings[] = $row['rank'];
            $bindings[] = (int) $row['user_id'];
            $bindings[] = $row['created_at'];
            $bindings[] = $row['updated_at'];
            $bindings[] = $row['created_at']->timestamp;
        }

        $sql = sprintf(
            <<<'SQL'
                WITH input (
                    temp_id,
                    name,
                    entity_type_id,
                    root_entity_id,
                    rank,
                    user_id,
                    created_at,
                    updated_at,
                    unique_seed
                ) AS (
                    VALUES %s
                ),
                inserted AS (
                    INSERT INTO entities (
                        name,
                        entity_type_id,
                        root_entity_id,
                        rank,
                        user_id,
                        created_at,
                        updated_at
                    )
                    SELECT
                        input.name,
                        input.entity_type_id::integer,
                        input.root_entity_id::integer,
                        input.rank::integer,
                        input.user_id::integer,
                        input.created_at::timestamptz,
                        input.updated_at::timestamptz
                    FROM input
                    ORDER BY input.unique_seed::bigint, input.temp_id::integer
                    RETURNING
                        id,
                        name,
                        entity_type_id,
                        root_entity_id,
                        rank,
                        user_id,
                        created_at,
                        updated_at
                )
                SELECT
                    input.temp_id::integer,
                    inserted.id
                FROM inserted
                INNER JOIN input i
                    ON inserted.name = input.name
                    AND inserted.entity_type_id = input.entity_type_id::integer
                    AND inserted.user_id = input.user_id::integer
                    AND inserted.created_at = input.created_at::timestamptz
                    AND inserted.updated_at = input.updated_at::timestamptz
                    /* 
                        rank and root_entity_id may be null and null == null => null therefore we need the 
                        IS NOT DISTINCT FROM operator.
                    */
                    AND inserted.rank IS NOT DISTINCT FROM input.rank::integer
                    AND inserted.root_entity_id IS NOT DISTINCT FROM input.root_entity_id::integer
                ORDER BY input.temp_id::integer
            SQL,
            implode(', ', $values)
        );

        $rows = DB::select($sql, $bindings);
        $tempToRealId = [];
        foreach($rows as $row) {
            $tempToRealId[(int) $row->temp_id] = (int) $row->id;
        }

        return $tempToRealId;
    }
}