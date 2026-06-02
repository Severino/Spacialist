<?php

namespace App\Listeners;

use App\Events\UserLogin;
use App\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Broadcasting\BroadcastException;

class BroadcastUserLogin {
    public function handle(Login $event): void {
        if(!$event->user instanceof User) {
            return;
        }

        try {
            UserLogin::dispatch($event->user);
        } catch(BroadcastException $e) {
            if(env('APP_DEBUG')) {
                info('Error dispatching UserLogin event: ' . $e->getMessage());
            }
        }
    }
}
