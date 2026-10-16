<?php

namespace App\Events;

use App\Entity;
use App\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class EntityDeleted implements ShouldDispatchAfterCommit {
    use Dispatchable;

    public int $entityId;

    public function __construct(
        public Entity $entity,
        public ?User $user,
    ) {
        $this->entityId = $entity->id;
    }
}
