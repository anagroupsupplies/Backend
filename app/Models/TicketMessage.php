<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketMessage extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'is_internal' => false,
    ];

    protected function casts(): array
    {
        return ['attachments' => 'array', 'is_internal' => 'boolean'];
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
