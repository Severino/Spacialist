<?php

namespace App\Events;

use App\Entity;
use App\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class EntityCreated implements ShouldDispatchAfterCommit {
    use Dispatchable;

    public function __construct(
        public Entity $entity,
        public ?User $user,
    ) {
    }
}
