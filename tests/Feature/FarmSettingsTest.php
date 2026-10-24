<?php

use App\Filament\Pages\FarmSettings;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Farm;
use Livewire\Livewire;

it('lets the owner change the farm, and logs what changed', function () {
    $owner = farm();
    $this->actingAs($owner);

    Livewire::test(FarmSettings::class)
        ->assertSet('data.name', 'Bekfontein')
        ->set('data.name', 'Bekfontein Noord')
        ->set('data.latitude', '-23.123456')
        ->set('data.longitude', '30.5')
        ->set('data.season_start_month', '9')
        ->set('data.frost_threshold', '1.5')
        ->call('save')
        ->assertHasNoErrors();

    expect(setting(Farm::NAME))->toBe('Bekfontein Noord')
        ->and(position())->toBe(['lat' => -23.1235, 'lon' => 30.5])
        ->and(app(Farm::class)->seasonStartMonth())->toBe(9)
        ->and(app(Farm::class)->frostThreshold())->toBe(1.5);

    $log = AuditLog::query()->where('action', 'settings.changed')->latest('id')->firstOrFail();
    expect($log->user_id)->toBe($owner->id)
        ->and($log->before['name'])->toBe('Bekfontein')
        ->and($log->after['name'])->toBe('Bekfontein Noord');
});

it('needs a whole position or none', function () {
    $this->actingAs(farm());

    Livewire::test(FarmSettings::class)
        ->set('data.latitude', '-23.5')
        ->set('data.longitude', null)
        ->call('save')
        ->assertHasErrors(['data.longitude']);
});

it('shows the screens in the farm language', function () {
    $this->actingAs(farm(['language' => 'af']));

    $this->get(FarmSettings::getUrl())->assertOk()->assertSee('Plaasinstellings')->assertSee('Gebruik my ligging');
});

it('is not for a manager', function () {
    farm();

    $this->actingAs(User::factory()->role(User::MANAGER)->create())
        ->get(FarmSettings::getUrl())->assertForbidden();
});

it('is open to Plaashek staff', function () {
    farm();

    $this->actingAs(User::query()->where('role', User::STAFF)->firstOrFail())
        ->get(FarmSettings::getUrl())->assertOk();
});
