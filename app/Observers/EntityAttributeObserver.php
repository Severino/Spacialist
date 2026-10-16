<?php

namespace App\Observers;

use App\AttributeValue;
use App\Events\AttributeValueDeleted;
use App\Events\AttributeValueSaved;

class EntityAttributeObserver {
    /**
     * Handle the AttributeValue "saved" event.
     * dispatching update events require persisted new AttributeValue data
     */
    public function saved(AttributeValue $attributeValue) : void {
        event(new AttributeValueSaved(
            $attributeValue,
            auth()->user(),
            $attributeValue->wasRecentlyCreated,
            $attributeValue->isModerated(),
        ));
    }

    /**
     * Handle the AttributeValue "deleting" event.
     */
    public function deleting(AttributeValue $attributeValue) : void {
        event(new AttributeValueDeleted($attributeValue, auth()->user()));
    }
}
