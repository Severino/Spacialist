<?php

namespace App\Events\Broadcast;

use App\Entity;
use App\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EntityCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Entity $entity,
        public User $user,
    )
    {
        $this->entity = $entity;
        $this->user = $user;
    }

    /**
     * Keeps the event name the frontend listens to stable.
     */
    public function broadcastAs(): string
    {
        return 'App\\Events\\EntityCreated';
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('channel.system'),
        ];
    }
}
