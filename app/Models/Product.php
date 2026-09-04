<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'images' => 'array',
            'video' => 'array',
            'sizes' => 'array',
            'variants' => 'array',
            'specifications' => 'array',
            'data' => 'array',
            'featured' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Resolve a product from either its slug or its numeric id.
     *
     * New links use the slug; every id already in the wild — old bookmarks,
     * shared links, the current frontend bundle — must keep working, so the
     * numeric form stays valid. Slugs always carry a random alphabetic suffix,
     * so the two forms can never collide.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field) {
            return parent::resolveRouteBinding($value, $field);
        }

        return $this->where('slug', $value)->first()
            ?? (ctype_digit((string) $value) ? $this->whereKey($value)->first() : null);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
