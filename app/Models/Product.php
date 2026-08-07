<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'images' => 'array', 'sizes' => 'array', 'data' => 'array', 'featured' => 'boolean', 'is_active' => 'boolean'];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
