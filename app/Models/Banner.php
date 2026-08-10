<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Banner extends Model
{
    protected $guarded = [];

    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'order' => 'integer'];
    }

    protected function image(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                if ($value === null) {
                    return null;
                }

                $base = rtrim(config('app.url'), '/');
                $storagePrefix = $base.'/storage/';

                if (Str::startsWith($value, $storagePrefix)) {
                    return Str::after($value, $storagePrefix);
                }

                return $value;
            },
        );
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                $image = $this->attributes['image'] ?? null;
                if ($image === null) {
                    return null;
                }

                if (Str::startsWith($image, 'http://') || Str::startsWith($image, 'https://')) {
                    return $image;
                }

                return url(Storage::url($image));
            },
        );
    }
}
