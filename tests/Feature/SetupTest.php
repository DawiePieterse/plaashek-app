<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Farm;

it('sends a fresh install to the setup page, and a set-up farm to the office', function () {
    $this->get('/')->assertRedirect('/setup');
    farm();
    $this->get('/')->assertRedirect('/admin');
});

it('creates the farm from the setup page, once', function () {
    $this->get('/setup')->assertOk()->assertSee('Set up the farm');

    $this->post('/setup', [
        'name' => 'Bekfontein',
        'language' => 'af',
        'latitude' => '-23.83361',
        'longitude' => '30.16364',
        'owner_name' => 'Andre',
        'owner_email' => 'andre@example.com',
        'owner_password' => 'a-good-password',
        'owner_password_confirmation' => 'a-good-password',
    ])->assertRedirect('/admin/login');

    $owner = User::query()->where('email', 'andre@example.com')->firstOrFail();
    expect($owner->role)->toBe(User::OWNER)
        ->and(setting(Farm::NAME))->toBe('Bekfontein')
        ->and(setting(Farm::LANGUAGE))->toBe('af')
        ->and(position())->toBe(['lat' => -23.8336, 'lon' => 30.1636]);

    // Plaashek's support account exists, with a password nobody was told.
    expect(User::query()->where('role', User::STAFF)->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'farm.created')->exists())->toBeTrue();

    $this->get('/setup')->assertNotFound();
    $this->post('/setup', [])->assertNotFound();
});

it('refuses a half-given position and a short password', function () {
    $this->post('/setup', [
        'name' => 'Bekfontein', 'language' => 'en', 'latitude' => '-23.8',
        'owner_name' => 'Andre', 'owner_email' => 'andre@example.com',
        'owner_password' => 'short', 'owner_password_confirmation' => 'short',
    ])->assertSessionHasErrors(['longitude', 'owner_password']);

    expect(User::query()->count())->toBe(0);
});

it('lets the owner sign in to the office', function () {
    farm();

    $this->post('/admin/login')->assertStatus(405); // Filament's login is a Livewire form, not a POST route
    $this->actingAs(User::query()->where('role', User::OWNER)->first())
        ->get('/admin')->assertOk()->assertSee('Bekfontein');
});
