<?php

// The farm's phones (PLAN.md section 5.1): named and paired by the office with a one-use code, disabled when lost.

use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;
use App\Support\Devices;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(farm()));

function phone(string $label = 'Crush phone'): Device
{
    return Device::query()->create(['label' => $label]);
}

function pairWith(string $code): TestResponse
{
    return test()->postJson('/field/api/pair', ['code' => $code]);
}

it('pairs a phone once with its code, typed as a person types it', function () {
    $device = phone();
    $code = app(Devices::class)->pairingCode($device);
    expect($code)->toMatch('/^[A-HJ-NP-Z2-9]{8}$/');

    $token = pairWith(strtolower(substr($code, 0, 4).' '.substr($code, 4)))->assertOk()
        ->assertJsonPath('device.label', 'Crush phone')->assertJsonPath('farm', 'Bekfontein')->json('token');

    expect($device->refresh()->isPaired())->toBeTrue()->and($device->token_hash)->not->toBe($token);
    $this->getJson('/field/api/device', ['X-Device' => $token])->assertOk()->assertJsonPath('device.label', 'Crush phone');
    pairWith($code)->assertStatus(422);
});

it('refuses a code after ten minutes, a wrong one and no token', function () {
    $code = app(Devices::class)->pairingCode(phone());
    pairWith('ABCD2345')->assertStatus(422);

    $this->travel(11)->minutes();
    pairWith($code)->assertStatus(422)->assertJsonPath('message', __('plaashek.devices.bad_code'));
    $this->getJson('/field/api/device')->assertUnauthorized()->assertJsonPath('unpaired', true);
});

it('stops a disabled phone at its next request, and lets a replaced one pair again', function () {
    $device = phone();
    $old = pairWith(app(Devices::class)->pairingCode($device))->json('token');

    app(Devices::class)->disable($device);
    $this->getJson('/field/api/device', ['X-Device' => $old])->assertUnauthorized();
    pairWith(app(Devices::class)->pairingCode($device))->assertStatus(422);

    app(Devices::class)->enable($device);
    $new = pairWith(app(Devices::class)->pairingCode($device))->assertOk()->json('token');
    $this->getJson('/field/api/device', ['X-Device' => $old])->assertUnauthorized();
    $this->getJson('/field/api/device', ['X-Device' => $new])->assertOk();
    expect(AuditLog::query()->whereIn('action', ['device.disabled', 'device.enabled', 'device.pairing'])->count())->toBe(5);
});

it('limits pairing attempts per address', function () {
    foreach (range(1, 10) as $i) {
        pairWith('WRONG'.$i)->assertStatus(422);
    }
    pairWith('WRONG11')->assertStatus(429);
});

it('adds a phone and shows its code and QR on the devices page', function () {
    Livewire::test(ListDevices::class)
        ->callAction('create', ['label' => 'Jan se foon'])->assertHasNoActionErrors();
    $device = Device::query()->sole();
    expect($device->created_by)->toBe(auth()->id());

    $page = Livewire::test(ListDevices::class)->mountTableAction('pair', $device);
    $code = $page->get('pairingCode');
    expect($code)->toHaveLength(8);
    $page->assertMountedActionModalSee(substr($code, 0, 4))->assertMountedActionModalSeeHtml('<svg');

    pairWith($code)->assertOk();
    Livewire::test(ListDevices::class)->assertSee('paired');
});

it('is for the owner and managers, not a viewer', function () {
    $this->get(DeviceResource::getUrl())->assertOk();

    $this->actingAs(User::factory()->role(User::MANAGER)->create());
    expect(DeviceResource::canViewAny())->toBeTrue();
    $this->actingAs(User::factory()->role(User::VIEWER)->create());
    expect(DeviceResource::canViewAny())->toBeFalse();
});
