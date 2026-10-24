<?php

it('serves the office manifest without a login, named for the farm', function () {
    farm();

    $this->get('/manifest.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('short_name', 'Plaashek')
        ->assertJsonPath('start_url', '/admin')
        ->assertJsonPath('icons.2.purpose', 'maskable');

    foreach (['icon-180.png', 'icon-192.png', 'icon-512.png', 'icon-maskable-512.png'] as $icon) {
        expect(public_path('icons/'.$icon))->toBeFile();
    }
});

it('links the manifest and icon from the office', function () {
    $this->actingAs(farm());

    $this->get('/admin')->assertOk()
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
        ->assertSee('icons/icon-180.png', false);
});
