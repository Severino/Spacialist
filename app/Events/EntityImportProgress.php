<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EntityImportProgress implements ShouldBroadcast {
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly int $processed,
        public readonly int $total,
        public readonly ?string $topic = ''
    ) {
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array {
        return [
            new PrivateChannel('entity-import-progress'),
        ];
    }

    public static function dispatchLimited(int $processed, int $total, float $limitInSeconds = 2, ?string $topic= ''): void {
        static $lastDispatchAt = 0.0;

        if($total <= 0) {
            return;
        }

        $now = microtime(true);
        if(($now - $lastDispatchAt) >= $limitInSeconds || $processed >= $total) {
            EntityImportProgress::dispatch($processed, $total, $topic);
            $lastDispatchAt = $now;
        }
    }
}
