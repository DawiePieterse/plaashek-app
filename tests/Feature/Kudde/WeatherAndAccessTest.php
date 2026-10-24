<?php

// The weather each event happened in (old Kudde's test_weather.py, now from weather_days rather than a live
// call), and who may reach Kudde at all.

use App\Models\User;
use App\Modules\Weer\Models\WeatherDay;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

function weatherOn(string $date): void
{
    WeatherDay::query()->create(['date' => $date, 'temp_max' => 31.2, 'temp_min' => 12.0, 'rain' => 4.5]);
}

it("stamps an event with its own day's weather, never a live call", function () {
    kudde();
    weatherOn(recently());
    addAnimal('A1');

    $e = recordEvent('A1', 'treatment')->assertOk()->json();

    expect([$e['weather_temp_max'], $e['weather_temp_min'], $e['weather_rain']])->toEqual([31.2, 12.0, 4.5]);
});

it('saves the event when the day has no weather yet, and fills it in the next morning', function () {
    kudde();
    addAnimal('A1');
    $e = recordEvent('A1', 'treatment')->assertOk()->json();
    expect($e['weather_temp_max'])->toBeNull();

    weatherOn(recently());
    $this->artisan('kudde:weather')->expectsOutputToContain('1 events stamped')->assertSuccessful();

    expect($this->getJson(kapi('/animals/A1/events'))->json('0.weather_rain'))->toBe(4.5);
});

it('stamps a camp move and a calving too', function () {
    kudde();
    weatherOn(recently());
    addAnimal('A1');

    expect(moveTo(['A1'], 'Bo')[0]['weather_temp_max'])->toBe(31.2);
    $this->postJson(kapi('/calvings'), ['dam_tag' => 'A1', 'event_date' => recently(), 'calf' => ['tag' => 'K1', 'sex' => 'male']])
        ->assertOk()->assertJsonPath('dam_event.weather_rain', 4.5);
});

it('schedules the weather stamp after weer:days', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'kudde:weather'));

    expect($events->first()?->expression)->toBe('25 5 * * *');
});

it('does not exist for a farm without Kudde', function () {
    $this->actingAs(farm());

    $this->getJson(kapi('/animals'))->assertNotFound();
    $this->postJson(kapi('/animals'), ['tag' => 'A1', 'sex' => 'female'])->assertNotFound();
});

it('refuses a signed-out request', function () {
    farm(['modules' => ['kudde']]);

    $this->getJson(kapi('/animals'))->assertUnauthorized();
});

it('lets a viewer read and export but never write', function () {
    kudde();
    addAnimal('A1');
    $this->actingAs(User::query()->create(['name' => 'Vet', 'email' => 'vet@example.com', 'password' => 'password', 'role' => User::VIEWER]));

    $this->getJson(kapi('/animals'))->assertOk();
    $this->get(kapi('/export/events.csv'))->assertOk();
    $this->postJson(kapi('/animals'), ['tag' => 'A2', 'sex' => 'female'])->assertForbidden();
    recordEvent('A1', 'treatment')->assertForbidden();
    $this->patchJson(kapi('/animals/A1'), ['name' => 'x'])->assertForbidden();
});
