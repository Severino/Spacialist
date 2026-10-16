<?php

namespace App\Events;

use App\AttributeValue;
use App\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class AttributeValueSaved implements ShouldDispatchAfterCommit {
    use Dispatchable;

    public mixed $value;

    public function __construct(
        public AttributeValue $attributeValue,
        public ?User $user,
        public bool $wasCreated,
        public bool $isModerated,
    ) {
        $this->value = $attributeValue->getValue();
    }
}
