<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    /** Shape of the `general` settings blob, with the values used before an admin saves anything. */
    public const GENERAL_DEFAULTS = [
        'whatsappNumber' => '255683568254',
        'mobileMoneyEnabled' => true,
    ];

    private const CACHE_KEY = 'settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /**
     * The general settings blob, always filled out with the defaults so callers
     * never have to null-check a key that was added after the row was saved.
     *
     * @return array<string, mixed>
     */
    public static function general(): array
    {
        $stored = Cache::remember(self::CACHE_KEY, 300, fn () => static::where('key', 'general')->value('value') ?? []);

        return [...self::GENERAL_DEFAULTS, ...$stored];
    }

    /**
     * @param  array<string, mixed>  $values  Merged over the current settings.
     * @return array<string, mixed>
     */
    public static function putGeneral(array $values): array
    {
        $merged = [...static::general(), ...$values];
        static::updateOrCreate(['key' => 'general'], ['value' => $merged]);
        Cache::forget(self::CACHE_KEY);

        return $merged;
    }
}
