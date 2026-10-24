<?php

use App\Models\User;
use App\Support\Farm;
use App\Support\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function forecastBody(): array
{
    return ['latitude' => -23.83, 'longitude' => 30.16, 'daily' => ['time' => ['2026-10-24'], 'temperature_2m_max' => [31.2]]];
}

it('answers a signed-out request with 401 JSON, not a login page', function () {
    farm();

    $this->getJson('/weer/api/fc?daily=temperature_2m_max')
        ->assertStatus(401)->assertJson(['error' => true]);
    Http::assertNothingSent();
});

it('only knows the four weather endpoints', function () {
    $this->actingAs(farm());

    $this->get('/weer/api/air')->assertNotFound();
    $this->get('/weer/api/geocode')->assertNotFound();
});

it('says so when the farm has no position, without calling Open-Meteo', function () {
    $this->actingAs(farm(['latitude' => null, 'longitude' => null]));

    $this->get('/weer/api/fc?daily=temperature_2m_max')
        ->assertStatus(409)->assertJsonPath('error', true);
    Http::assertNothingSent();
});

it("always asks for the farm's position, whatever the page sent", function () {
    Http::fake(['api.open-meteo.com/*' => Http::response(forecastBody())]);
    $this->actingAs(farm());

    $this->get('/weer/api/fc?latitude=51.5&longitude=-0.12&daily=temperature_2m_max&timezone=auto')
        ->assertOk()->assertJsonPath('daily.temperature_2m_max.0', 31.2);

    Http::assertSent(fn (Request $r) => $r['latitude'] == -23.8336 && $r['longitude'] == 30.1636
        && str_starts_with($r->url(), 'https://api.open-meteo.com/v1/forecast'));
});

it("uses the commercial hosts with Plaashek's key, and never shows the key", function () {
    config(['plaashek.open_meteo.key' => 'secret-key']);
    Http::fake(['customer-archive-api.open-meteo.com/*' => Http::response(['daily' => ['time' => []]])]);
    $this->actingAs(farm());

    $response = $this->get('/weer/api/archive?start_date=2020-01-01&end_date=2020-12-31&daily=precipitation_sum&apikey=stolen');

    $response->assertOk();
    expect($response->getContent())->not->toContain('secret-key');
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://customer-archive-api.open-meteo.com/v1/archive')
        && $r['apikey'] === 'secret-key');
    expect(DB::table('weer_responses')->value('query'))->not->toContain('secret-key');
});

it('keeps a finished range for ever and fetches it only once', function () {
    Http::fake(['archive-api.open-meteo.com/*' => Http::response(['daily' => ['time' => ['2020-01-01'], 'precipitation_sum' => [4.2]]])]);
    $this->actingAs(farm());

    $url = '/weer/api/archive?start_date=2020-01-01&end_date=2020-12-31&daily=precipitation_sum';
    $this->get($url)->assertOk()->assertHeader('X-Weer-Cache', 'miss');
    $this->get($url)->assertOk()->assertHeader('X-Weer-Cache', 'hit')->assertJsonPath('daily.precipitation_sum.0', 4.2);

    Http::assertSentCount(1);
    expect(DB::table('weer_responses')->value('expires_at'))->toBeNull();
});

it('keeps the forecast an hour', function () {
    Http::fake(['api.open-meteo.com/*' => Http::response(forecastBody())]);
    $this->actingAs(farm());

    $this->get('/weer/api/fc?forecast_days=16&daily=temperature_2m_max')->assertOk();
    $this->get('/weer/api/fc?daily=temperature_2m_max&forecast_days=16')->assertHeader('X-Weer-Cache', 'hit'); // same query, other order

    $this->travel(61)->minutes();
    $this->get('/weer/api/fc?forecast_days=16&daily=temperature_2m_max')->assertHeader('X-Weer-Cache', 'miss');
    Http::assertSentCount(2);
});

it("passes Open-Meteo's refusal through so the page can drop the field, and keeps it a day", function () {
    Http::fake(['api.open-meteo.com/*' => Http::response(['error' => true, 'reason' => 'Cannot initialize WeatherVariable from invalid String value nonsense'], 400)]);
    $this->actingAs(farm());

    $this->get('/weer/api/fc?daily=nonsense')->assertStatus(400)->assertJsonPath('error', true);
    $this->get('/weer/api/fc?daily=nonsense')->assertStatus(400)->assertHeader('X-Weer-Cache', 'hit');
    Http::assertSentCount(1);
});

it('never caches a failure, and falls back to an old answer when Open-Meteo is down', function () {
    Http::fakeSequence('api.open-meteo.com/*')
        ->push('down', 503)
        ->push(forecastBody())
        ->push('down', 503);
    $this->actingAs(farm());
    $url = '/weer/api/fc?daily=temperature_2m_max';

    $this->get($url)->assertStatus(502);
    expect(DB::table('weer_responses')->count())->toBe(0);

    $this->get($url)->assertOk()->assertHeader('X-Weer-Cache', 'miss');
    $this->travel(2)->hours();

    $this->get($url)->assertOk()->assertHeader('X-Weer-Cache', 'hit')->assertJsonPath('daily.temperature_2m_max.0', 31.2);
});

it('is not usable for a farm that moved: the cache follows the position', function () {
    Http::fake(['api.open-meteo.com/*' => Http::response(forecastBody())]);
    $this->actingAs(farm());

    $this->get('/weer/api/fc?daily=temperature_2m_max')->assertHeader('X-Weer-Cache', 'miss');
    app(Settings::class)->set(Farm::LATITUDE, '-24.0');
    $this->get('/weer/api/fc?daily=temperature_2m_max')->assertHeader('X-Weer-Cache', 'miss');
});

it('lets a viewer use the weather too', function () {
    Http::fake(['api.open-meteo.com/*' => Http::response(forecastBody())]);
    farm();

    $this->actingAs(User::factory()->role(User::VIEWER)->create())
        ->get('/weer/api/fc?daily=temperature_2m_max')->assertOk();
});
