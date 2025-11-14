<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserDeactivated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public $userId;

    public function __construct($userId)
    {
        $this->userId = $userId;
    }

    // Canal Redis
    public function broadcastOn()
    {
        return new Channel('user-status');
    }

    // Nom de l’événement côté Node.js
    public function broadcastAs()
    {
        return 'user.deactivated';
    }

    // Données envoyées à Node.js
    public function broadcastWith()
    {
        return [
            'userId' => $this->userId
        ];
    }
}
