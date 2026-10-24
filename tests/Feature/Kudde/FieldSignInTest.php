<?php

// The Kudde field app opened by a worker's card scan, once a day.

use App\Models\User;
use App\Models\Worker;
use App\Modules\Kudde\Models\AnimalEvent;
use App\Modules\ModuleRegistry;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    farm(['modules' => ['kudde']]);
    $this->travelTo(today()->setTime(7, 0));
    Worker::query()->create(['number' => '001', 'first_name' => 'Johannes', 'last_name' => 'Mathebula', 'preferred_name' => 'Jan']);
    Worker::query()->create(['number' => '002', 'first_name' => 'Sipho', 'last_name' => 'Ndlovu']);
});

function scanCard(string $number): TestResponse
{
    return test()->postJson('/kudde/field/api/sign-in', ['number' => $number]);
}

function fieldApi(string $token): array
{
    return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
}

it('signs a worker in by their card until midnight', function () {
    $body = scanCard('001')->assertOk()->json();

    expect($body['worker'])->toBe(['number' => '001', 'name' => 'Jan Mathebula'])
        ->and(strlen($body['token']))->toBe(48)
        ->and($body['expires_at'])->toBe(today()->endOfDay()->toIso8601String());
    $this->assertDatabaseMissing('kudde_field_sessions', ['token_hash' => $body['token']]);
});

it('refuses an unknown card and a worker who has left, each saying why', function () {
    Worker::query()->where('number', '002')->update(['active' => false]);

    scanCard('999')->assertNotFound()->assertJsonFragment(['message' => "Card 999 is not one of this farm's workers"]);
    scanCard('002')->assertForbidden()->assertJsonFragment(['message' => 'Sipho Ndlovu no longer works here']);
});

it('opens the herd to a scanned-in phone, and to nobody else', function () {
    $token = scanCard('001')->json('token');

    $this->getJson('/kudde/field/api/animals')->assertUnauthorized()->assertJsonPath('signed_out', true);
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

    expect($this->getJson('/kudde/field/api/workers', fieldApi($token))->json())
        ->toBe([['number' => '001', 'name' => 'Jan Mathebula'], ['number' => '002', 'name' => 'Sipho Ndlovu']]);
});

it('ends the session when the worker hands over', function () {
    $token = scanCard('001')->json('token');

    $this->deleteJson('/kudde/field/api/sign-in', [], fieldApi($token))->assertNoContent();
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

    app(ModuleRegistry::class)->setEnabled([]);
    $this->get('/kudde/field')->assertNotFound();
    scanCard('001')->assertNotFound();
});

it('lets the page use the camera', function () {
    expect($this->get('/kudde/field')->headers->get('Permissions-Policy'))->toContain('camera=(self)');
});
