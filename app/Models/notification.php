<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class notification extends Model
{
    // The table name is explicitly set to 'notification'
    protected $table = 'notification';

    // Disable timestamps as the table doesn't seem to have created_at/updated_at columns
    public $timestamps = false;

    // Specify the primary key if it's different from 'id' (but here it's 'id' by default)
    protected $primaryKey = 'id';

    // Define which fields are mass assignable
    protected $fillable = [
        'title',
        'description',
        'url',
        'notification_date',
        'sender',
        'reciever',
        'Brodcast',
        'TL_sender_id',
        'Student_Section',
        'TL_receiver_id',
    ];

    // Define the relationship to the Section model
    public function section()
    {
        return $this->belongsTo(Section::class, 'Student_Section', 'id');
    }
    public function senderUser()
    {
        return $this->belongsTo(User::class, 'TL_sender_id', 'id');
    }    public function receiverUser()
    {
        return $this->belongsTo(User::class, 'TL_receiver_id', 'id');
    }
    public static function broadcastMessage($title, $description, $sender_user_id, $receiver = null)
    {
        $senderRole = (new \App\Models\user)->getRoleByID($sender_user_id);
        if (!$senderRole) {
            return null;
        }
        $notificationData = [
            'title' => $title,
            'description' => $description,
            'url' => null,
            'notification_date' => now(),
            'sender' => $senderRole,
            'reciever' => $receiver,
            'Brodcast' => 1,
            'TL_sender_id' => $sender_user_id,
            'Student_Section' => null,
            'TL_receiver_id' => null,
        ];
        return self::create($notificationData);
    }
    public static function sendToUser($title, $description, $sender_user_id, $receiver_user_id)
    {
        $senderRole = (new \App\Models\user)->getRoleByID($sender_user_id);
        $receiverRole = (new \App\Models\user)->getRoleByID($receiver_user_id);

        if (!$senderRole || !$receiverRole) {
            return null;
        }
        $notificationData = [
            'title' => $title,
            'description' => $description,
            'url' => null,
            'notification_date' => now(),
            'sender' => $senderRole,
            'reciever' => $receiverRole,
            'Brodcast' => 0,
            'TL_sender_id' => $sender_user_id,
            'Student_Section' => null,
            'TL_receiver_id' => $receiver_user_id,
        ];
        return self::create($notificationData);
    }


}
