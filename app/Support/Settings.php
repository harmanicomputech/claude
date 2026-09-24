<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Key-value settings editable from the admin console, cached briefly so the
 * USSD callback doesn't query them on every request.
 */
class Settings
{
    private const CACHE_KEY = 'election-shield:settings';

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function bool(string $key): bool
    {
        return filter_var(self::get($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function set(string $key, mixed $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value, 'updated_at' => now(), 'created_at' => now()],
        );

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string>
     */
    private static function all(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, 30, fn () => DB::table('settings')->pluck('value', 'key')->all());
        } catch (Throwable) {
            return []; // Before the settings table exists.
        }
    }
}
