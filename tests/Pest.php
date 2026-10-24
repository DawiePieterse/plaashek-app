<?php

use App\Models\User;
use App\Services\FarmSetup;
use App\Support\Farm;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Tests run against MySQL/MariaDB (see phpunit.xml), the same kind of database as production, so
 * constraints behave as they will live. Open-Meteo is never called: tests use Http::fake().
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/** A farm set up the way `farm:create` does it, positioned near Tzaneen unless told otherwise. */
function farm(array $overrides = []): User
{
    [$owner] = app(FarmSetup::class)->create(array_merge([
        'name' => 'Bekfontein',
        'language' => 'en',
        'owner_name' => 'Andre',
        'owner_email' => 'andre@example.com',
        'owner_password' => 'password',
        'latitude' => -23.8336,
        'longitude' => 30.1636,
        'modules' => [],
    ], $overrides));

    return $owner;
}

function setting(string $key): ?string
{
    return app(Settings::class)->get($key);
}

function position(): ?array
{
    return app(Farm::class)->position();
}
