<?php

use App\Models\User;
use App\Modules\Kudde\Http\KuddeApiController;
use App\Services\FarmSetup;
use App\Support\Farm;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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

/*
 * Kudde. The helpers mirror old Kudde's conftest: a farm with Kudde on and its owner signed in, and the two
 * dates the "needs weighing" cutoff sits between, relative to today so they never go stale.
 */
function kudde(array $overrides = []): User
{
    $owner = farm(array_merge(['modules' => ['kudde']], $overrides));
    test()->actingAs($owner);

    return $owner;
}

function kapi(string $path = ''): string
{
    return '/kudde/api'.$path;
}

function recently(): string
{
    return today()->subDays(7)->toDateString();
}

function longAgo(): string
{
    return today()->subDays(KuddeApiController::STALE_WEIGHT_DAYS + 30)->toDateString();
}

function daysFromToday(int $days): string
{
    return today()->addDays($days)->toDateString();
}

/** One animal, female unless told otherwise, asserted to have landed. */
function addAnimal(string $tag, array $fields = []): array
{
    return test()->postJson(kapi('/animals'), array_merge(['tag' => $tag, 'sex' => 'female'], $fields))->assertOk()->json();
}

/** One camp move for $tags, the way the apps record it. */
function moveTo(array $tags, string $location, ?string $on = null): array
{
    return test()->postJson(kapi('/events/movement/bulk'), ['tags' => $tags, 'event_date' => $on ?? recently(), 'location' => $location])
        ->assertOk()->json();
}

function recordEvent(string $tag, string $kind, array $fields = []): TestResponse
{
    return test()->postJson(kapi('/events'), array_merge(['tag' => $tag, 'kind' => $kind, 'event_date' => recently()], $fields));
}

/**
 * A small herd with a history: A1 alive and overdue a weighing, B2 alive and recently weighed, C3 sold. The
 * three cases every dashboard figure has to tell apart.
 */
function smallHerd(): void
{
    addAnimal('A1', ['name' => 'Bessie', 'breed' => 'Nguni', 'birth_date' => '2022-03-15']);
    addAnimal('B2', ['name' => 'Bull', 'sex' => 'male']);
    addAnimal('C3');
    recordEvent('A1', 'weight', ['event_date' => longAgo(), 'value' => 310])->assertOk();
    recordEvent('B2', 'weight', ['value' => 640])->assertOk();
    recordEvent('C3', 'sale', ['note' => 'Vleissentraal'])->assertOk();
}
