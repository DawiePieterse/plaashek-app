<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Farm settings from the settings table, cached until the next change. The cache is a database table on
 * shared hosting, so the values are also kept in memory for the request. Typed reads of the farm's own
 * settings are in App\Support\Farm.
 */
class Settings
{
    private const CACHE_KEY = 'settings';

    /** @var array<string, string>|null */
    private ?array $all = null;

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            Setting::query()->where('key', $key)->delete();
        } else {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE_KEY);
        $this->all = null;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        try {
            return $this->all ??= Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->all());
        } catch (QueryException) {
            // Before the first migration (the setup page, `migrate` itself) there is nothing to read.
            return [];
        }
    }
}
