<?php

// The Kudde field app opened by a worker's card scan, once a day, on a phone the office has paired (PLAN.md 5.1).

use App\Models\Device;
use App\Models\User;
use App\Models\Worker;
use App\Modules\Kudde\Models\AnimalEvent;
use App\Modules\Kudde\Models\Species;
use App\Modules\ModuleRegistry;
use App\Support\Devices;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    farm(['modules' => ['kudde']]);
    $this->travelTo(today()->setTime(7, 0));
    Worker::query()->create(['number' => '001', 'first_name' => 'Johannes', 'last_name' => 'Mathebula', 'preferred_name' => 'Jan']);
    Worker::query()->create(['number' => '002', 'first_name' => 'Sipho', 'last_name' => 'Ndlovu']);
    $this->phone = pairPhone();
});

/** The card scanned on the test's paired phone. */
function scanCard(string $number): TestResponse
{
    return test()->postJson('/field/api/sign-in', ['number' => $number], phoneHeaders(test()->phone));
}

function fieldApi(string $token): array
{
    return phoneHeaders(test()->phone, $token);
}

it('signs a worker in by their card until midnight', function () {
    $body = scanCard('001')->assertOk()->json();

    expect($body['worker'])->toBe(['number' => '001', 'name' => 'Jan Mathebula'])
        ->and(strlen($body['token']))->toBe(48)
        ->and($body['expires_at'])->toBe(today()->endOfDay()->toIso8601String());
    expect($body['modules'])->toBe(['kudde']);
    $this->assertDatabaseMissing('field_sessions', ['token_hash' => $body['token']]);
    expect(Device::query()->sole()->lastWorker->number)->toBe('001');
});

it('opens nothing on a phone the office has not paired, or has disabled', function () {
    $this->postJson('/field/api/sign-in', ['number' => '001'])->assertUnauthorized()->assertJsonPath('unpaired', true);

    $token = scanCard('001')->json('token');
    app(Devices::class)->disable(Device::query()->sole());
    $this->getJson('/kudde/field/api/animals', fieldApi($token))->assertUnauthorized()->assertJsonPath('unpaired', true);
    scanCard('001')->assertUnauthorized();
});

it('keeps a day\'s token to the phone it was scanned on', function () {
    $token = scanCard('001')->json('token');
    $other = pairPhone('Spare phone');

    $this->getJson('/kudde/field/api/animals', phoneHeaders($other, $token))->assertUnauthorized()->assertJsonPath('signed_out', true);
    $this->getJson('/kudde/field/api/animals', fieldApi($token))->assertOk();
});

it('shows a worker only the modules the office ticked for them', function () {
    Worker::query()->where('number', '002')->update(['modules' => []]);
    $token = scanCard('002')->assertOk()->assertJsonPath('modules', [])->json('token');

    $this->getJson('/kudde/field/api/animals', fieldApi($token))->assertForbidden();
    expect(collect($this->getJson('/field/api/workers', fieldApi($token))->json())->pluck('modules', 'number')->all())
        ->toBe(['001' => ['kudde'], '002' => []]);
});

it('names the phone on what is captured with it', function () {
    $token = scanCard('001')->json('token');
    $this->postJson('/kudde/field/api/animals', ['tag' => 'A1', 'sex' => 'female', 'camp' => 'Bo'], fieldApi($token))->assertOk();

    expect(AnimalEvent::query()->sole()->device_id)->toBe(Device::query()->sole()->id)
        ->and(Device::query()->sole()->last_sync_at)->not->toBeNull();
});

it('refuses an unknown card and a worker who has left, each saying why', function () {
    Worker::query()->where('number', '002')->update(['active' => false]);

    scanCard('999')->assertNotFound()->assertJsonFragment(['message' => "Card 999 is not one of this farm's workers"]);
    scanCard('002')->assertForbidden()->assertJsonFragment(['message' => 'Sipho Ndlovu no longer works here']);
});

it('opens the herd to a scanned-in phone, and to nobody else', function () {
    $token = scanCard('001')->json('token');

    $this->getJson('/kudde/field/api/animals')->assertUnauthorized()->assertJsonPath('unpaired', true);
    $this->getJson('/kudde/field/api/animals', phoneHeaders(test()->phone))->assertUnauthorized()->assertJsonPath('signed_out', true);
    $this->getJson('/kudde/field/api/animals', fieldApi('not-a-token'))->assertUnauthorized();
    $this->getJson('/kudde/field/api/animals', fieldApi($token))->assertOk();
});

it('needs one scan a day: the session ends at midnight', function () {
    $token = scanCard('001')->json('token');

    $this->travelTo(today()->setTime(23, 59));
    $this->getJson('/kudde/field/api/camps', fieldApi($token))->assertOk();

    $this->travelTo(today()->addDay()->setTime(0, 1));
    $this->getJson('/kudde/field/api/camps', fieldApi($token))->assertUnauthorized();
});

it('stops a worker switched off in the office at their next request', function () {
    $token = scanCard('001')->json('token');
    Worker::query()->where('number', '001')->update(['active' => false]);

    $this->getJson('/kudde/field/api/animals', fieldApi($token))->assertUnauthorized();
});

it('stamps what the worker captures with their name', function () {
    $token = scanCard('001')->json('token');
    $jan = Worker::query()->where('number', '001')->value('id');

    $this->postJson('/kudde/field/api/animals', ['tag' => 'A1', 'sex' => 'female', 'camp' => 'Bo'], fieldApi($token))
        ->assertOk()->assertJsonPath('worker_id', $jan);
    $this->postJson('/kudde/field/api/events', ['tag' => 'A1', 'kind' => 'weight', 'event_date' => today()->toDateString(), 'value' => 300], fieldApi($token))
        ->assertOk()->assertJsonPath('worker_id', $jan)->assertJsonPath('user_id', null);
    $this->postJson('/kudde/field/api/events/movement/bulk', ['tags' => ['A1'], 'event_date' => today()->toDateString(), 'location' => 'Onder'], fieldApi($token))
        ->assertOk()->assertJsonPath('0.worker_id', $jan);

    expect(AnimalEvent::query()->whereNull('worker_id')->count())->toBe(0);
});

it('credits an outbox record to whoever captured it, even after the phone changed hands', function () {
    scanCard('001');
    $sipho = scanCard('002')->json('token');
    $jan = Worker::query()->where('number', '001')->value('id');
    addAnimalAsOffice();

    $this->postJson('/kudde/field/api/events', ['tag' => 'A1', 'kind' => 'treatment', 'event_date' => today()->toDateString(),
        'client_uuid' => 'q-1', 'captured_by' => '001'], fieldApi($sipho))->assertOk()->assertJsonPath('worker_id', $jan);

    // A number that is no worker of this farm is ignored: the record is the session's.
    $this->postJson('/kudde/field/api/events', ['tag' => 'A1', 'kind' => 'treatment', 'event_date' => today()->toDateString(),
        'captured_by' => 'X99'], fieldApi($sipho))->assertOk()->assertJsonPath('worker_id', Worker::query()->where('number', '002')->value('id'));
});

function addAnimalAsOffice(): void
{
    $owner = User::query()->where('role', 'owner')->firstOrFail();
    test()->actingAs($owner)->postJson(kapi('/animals'), ['tag' => 'A1', 'sex' => 'female'])->assertOk();
    auth()->guard('web')->forgetUser();
}

it('keeps the office corrections, camp management and exports off the phone', function () {
    $token = scanCard('001')->json('token');

    $this->patchJson('/kudde/field/api/animals/A1', ['name' => 'x'], fieldApi($token))->assertNotFound();
    $this->postJson('/kudde/field/api/camps', ['name' => 'X'], fieldApi($token))->assertStatus(405);
    $this->get('/kudde/field/api/export/events.csv', fieldApi($token))->assertNotFound();
});

it('lets the phone recognise cards offline, without anyone\'s cell number', function () {
    Worker::query()->create(['number' => '010', 'first_name' => 'Gone', 'active' => false, 'cell' => '082']);
    $token = scanCard('001')->json('token');

    expect($this->getJson('/field/api/workers', fieldApi($token))->json())
        ->toBe([['number' => '001', 'name' => 'Jan Mathebula', 'modules' => ['kudde']], ['number' => '002', 'name' => 'Sipho Ndlovu', 'modules' => ['kudde']]]);
});

it('ends the session when the worker hands over', function () {
    $token = scanCard('001')->json('token');

    $this->deleteJson('/field/api/sign-in', [], fieldApi($token))->assertNoContent();
    $this->getJson('/kudde/field/api/animals', fieldApi($token))->assertUnauthorized();
});

it('limits how fast cards can be tried, apart from the phone\'s ordinary requests', function () {
    $token = scanCard('001')->json('token');
    foreach (range(1, 30) as $_) {
        $this->getJson('/kudde/field/api/camps', fieldApi($token))->assertOk();
    }
    scanCard('002')->assertOk();

    foreach (range(1, 18) as $_) {
        scanCard('999');
    }
    scanCard('001')->assertStatus(429);
});

it('serves the field app and its service worker, and nothing for a farm without Kudde', function () {
    $this->get('/kudde/field')->assertOk()->assertSee('signinScreen', false)->assertSee('js/signin.js', false);
    $this->get('/kudde/field/sw.js')->assertOk()->assertHeader('Service-Worker-Allowed', '/kudde/field');
    $this->get('/kudde/field/manifest.webmanifest')->assertOk()->assertJsonPath('start_url', '/kudde/field');

    $token = scanCard('001')->json('token');
    app(ModuleRegistry::class)->setEnabled([]);
    $this->get('/kudde/field')->assertNotFound();
    $this->getJson('/kudde/field/api/animals', fieldApi($token))->assertNotFound();
    expect(scanCard('001')->json('modules'))->toBe([]);
});

it('lets the page use the camera', function () {
    expect($this->get('/kudde/field')->headers->get('Permissions-Policy'))->toContain('camera=(self)');
});

it('weans a litter from the field app', function () {
    Species::query()->where('code', 'pigs')->update(['enabled' => true]);
    $token = scanCard('001')->json('token');
    $this->postJson('/kudde/field/api/animals', ['tag' => 'S1', 'sex' => 'female', 'species' => 'pigs'], fieldApi($token))->assertOk();
    $this->postJson('/kudde/field/api/calvings', ['dam_tag' => 'S1', 'species' => 'pigs', 'event_date' => recently(),
        'litter' => ['born_alive' => 6]], fieldApi($token))->assertOk();
    $id = $this->getJson('/kudde/field/api/litters', fieldApi($token))->assertOk()->json('0.id');

    $this->postJson("/kudde/field/api/litters/{$id}/wean", ['event_date' => today()->toDateString(), 'species' => 'pigs',
        'young' => [['tag' => 'P1', 'sex' => 'female']]], fieldApi($token))->assertOk()->assertJsonPath('litter.weaned', 1);
});
