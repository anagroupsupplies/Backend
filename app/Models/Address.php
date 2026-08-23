<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'country' => 'Tanzania',
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'accuracy' => 'float', 'is_default' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Make this the customer's default address, demoting any previous one.
     */
    public function makeDefault(): void
    {
        static::where('user_id', $this->user_id)->whereKeyNot($this->getKey())->update(['is_default' => false]);
        $this->forceFill(['is_default' => true])->save();
    }
}
