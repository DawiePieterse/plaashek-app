<?php

use App\Modules\Weer\Models\WeatherDay;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

it("stores the farm's past days, not today", function () {
    $this->travelTo('2026-10-24 05:10');
    Http::fake(['api.open-meteo.com/*' => Http::response(['daily' => [
        'time' => ['2026-10-22', '2026-10-23', '2026-10-24'],
        'temperature_2m_max' => [30.1, 28.4, 27.0],
        'temperature_2m_min' => [15.2, 14.0, 13.1],
        'precipitation_sum' => [0, 12.6, 3.0],
        'et0_fao_evapotranspiration' => [5.1, 3.2, 4.0],
        'wind_speed_10m_max' => [18.0, 25.5, 12.0],
    ]])]);
    farm();

    $this->artisan('weer:days')->expectsOutputToContain('2 days stored')->assertSuccessful();

    expect(WeatherDay::query()->count())->toBe(2)
        ->and(WeatherDay::query()->find('2026-10-23')->rain)->toBe(12.6);

    Http::assertSent(fn ($r) => $r['past_days'] == 14 && $r['latitude'] == -23.8336);
});

it('does nothing for a farm without a position', function () {
    Http::preventStrayRequests();
    farm(['latitude' => null, 'longitude' => null]);

    $this->artisan('weer:days')->assertSuccessful();
    expect(WeatherDay::query()->count())->toBe(0);
});

it('is scheduled every morning', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'weer:days'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('10 5 * * *');
});
