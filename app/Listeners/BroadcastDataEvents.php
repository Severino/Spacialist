<?php

namespace App\Listeners;

use App\Events\AttributeValueDeleted;
use App\Events\AttributeValueSaved;
use App\Events\Broadcast\AttributeValueCreated as AttributeValueCreatedBroadcast;
use App\Events\Broadcast\AttributeValueDeleted as AttributeValueDeletedBroadcast;
use App\Events\Broadcast\AttributeValueUpdated as AttributeValueUpdatedBroadcast;
use App\Events\Broadcast\EntityCreated as EntityCreatedBroadcast;
use App\Events\Broadcast\EntityDeleted as EntityDeletedBroadcast;
use App\Events\EntityCreated;
use App\Events\EntityDeleted;
use Illuminate\Broadcasting\BroadcastException;

/**
 * Translates application events into websocket broadcasts.
 * A failing broadcast server must never affect other listeners or the request.
 */
class BroadcastDataEvents {
    public function onAttributeValueSaved(AttributeValueSaved $event): void {
        $this->send(fn() => $event->wasCreated
            ? new AttributeValueCreatedBroadcast($event->attributeValue, $event->user)
            : new AttributeValueUpdatedBroadcast($event->attributeValue, $event->user)
        );
    }

    public function onAttributeValueDeleted(AttributeValueDeleted $event): void {
        $this->send(fn() => new AttributeValueDeletedBroadcast($event->attributeValue, $event->user));
    }

    public function onEntityCreated(EntityCreated $event): void {
        $this->send(fn() => new EntityCreatedBroadcast($event->entity, $event->user));
    }

    public function onEntityDeleted(EntityDeleted $event): void {
        $this->send(fn() => new EntityDeletedBroadcast($event->entity, $event->user));
    }

    private function send(callable $makeEvent): void {
        try {
            broadcast($makeEvent())->toOthers();
        } catch(BroadcastException $e) {
            if(env('APP_DEBUG')) {
                info("BroadcastException while broadcasting data event: " . $e->getMessage());
            }
        }
    }
}
