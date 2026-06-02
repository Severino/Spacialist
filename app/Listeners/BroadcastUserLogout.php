<?php

namespace App\Listeners;

use App\Events\UserLogout;
use App\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Broadcasting\BroadcastException;

class BroadcastUserLogout {
    public function handle(Logout $event): void {
        if(!$event->user instanceof User) {
            return;
        }

        try {
            UserLogout::dispatch($event->user);
        } catch(BroadcastException $e) {
            if(env('APP_DEBUG')) {
                info('Error dispatching UserLogout event: ' . $e->getMessage());
            }
        }
    }
}
