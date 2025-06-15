<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class NotificationEvent implements ShouldBroadcast
{
    use SerializesModels;

    public $payload;
    public $receiver_id;
    public $isBroadcast;

    public function __construct($payload, $receiver_id = null, $isBroadcast = false)
    {
        $this->payload = $payload;
        $this->receiver_id = $receiver_id;
        $this->isBroadcast = $isBroadcast;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('notifications'),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'user_id'     => $this->receiver_id,
            'isBroadcast' => $this->isBroadcast,
            'data'        => $this->payload,
        ];
    }

    public function broadcastAs()
    {
        return 'notification.received';
    }
}

