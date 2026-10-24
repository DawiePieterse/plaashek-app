<?php

use App\Models\User;
use App\Modules\Weer\Filament\WeatherPage;

it('sends a signed-out visitor to the office login', function () {
    farm();

    $this->get('/weer')->assertRedirect('/admin/login');
});

it("writes the farm's details and words into the page", function () {
    $this->actingAs(farm(['language' => 'af']));

    $this->get('/weer')->assertOk()
        ->assertSee('Weer · Bekfontein', false)
        ->assertSee('"lat":-23.8336', false)
        ->assertSee('"seasonMonth":7', false)
        ->assertSee('Opsomming')
        ->assertDontSee('leaflet')
        ->assertDontSee('open-meteo.com/v1', false);
});

it('offers Farm settings only to those who may change it', function () {
    farm();

    $this->actingAs(User::factory()->role(User::VIEWER)->create())
        ->get('/weer')->assertSee('"settingsUrl":null', false);
});

it('serves a service worker scoped to /weer whose cache name follows the files', function () {
    farm();

    $response = $this->get('/weer/sw.js');
    $response->assertOk()
        ->assertHeader('Service-Worker-Allowed', '/weer')
        ->assertHeader('Content-Type', 'application/javascript');
    expect($response->getContent())->toContain('plaashek-weer-')
        ->toContain('/modules/weer/js/app.js')
        ->not->toContain('/weer/api/fc');
});

it('serves an install manifest for the farm', function () {
    farm();

    $this->get('/weer/manifest.webmanifest')->assertOk()
        ->assertJsonPath('start_url', '/weer')
        ->assertJsonPath('name', 'Bekfontein · Weather');
});

it('has a Weather entry in the office menu that opens the page', function () {
    $this->actingAs(farm());

    expect(WeatherPage::getNavigationUrl())->toEndWith('/weer');
    $this->get('/admin/weer')->assertRedirect(route('weer.page'));
});
