<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class EntityTypeAttributeRemoved implements ShouldDispatchAfterCommit {
    use Dispatchable;

    public function __construct(
        public int $attributeId,
        public int $entityTypeId,
    ) {
    }
}
