<?php
// app/Events/UserRealtimeNotification.php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MemberAccountUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public $userId;
    public $data;

    public function __construct($userId, $data)
    {
        $this->userId  = $userId;
        $this->data   = $data;
    }

    public function broadcastOn()
    {
        return new Channel('user-accounts');
    }

    public function broadcastAs()
    {
        return 'user.account';
    }

    public function broadcastWith()
    {
        return [
            'userId'  => $this->userId,
            'account'   => $this->data
        ];
    }
}
