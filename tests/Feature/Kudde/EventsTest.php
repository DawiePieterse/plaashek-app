<?php

// Recording what happens to an animal, and what that does to its status (old Kudde's test_events.py).

beforeEach(fn () => kudde());

it('records an event against the tag the phone read', function () {
    $id = addAnimal('A1')['id'];

    $body = recordEvent('A1', 'weight', ['value' => 310.5])->assertOk()->json();

    expect($body['animal_id'])->toBe($id)->and($body['value'])->toBe(310.5);
});

it('refuses an event for an unknown animal', function () {
    recordEvent('NOPE', 'treatment')->assertNotFound();
});

it('refuses a weight event without a weight', function (mixed $bad) {
    addAnimal('A1');

    recordEvent('A1', 'weight', ['value' => $bad])->assertStatus(422);
    expect($this->getJson(kapi('/animals/A1/events'))->json())->toBe([]);
    recordEvent('A1', 'treatment', ['note' => 'Dectomax'])->assertOk();
})->with([null, 0, -5]);

it('takes a dead or sold animal out of the herd', function () {
    addAnimal('A1');
    $before = $this->getJson(kapi('/animals/A1'))->json();
    $this->travel(5)->seconds();

    recordEvent('A1', 'death')->assertOk();
    $after = $this->getJson(kapi('/animals/A1'))->json();

    expect($after['status'])->toBe('dead')->and($after['updated_at'] > $before['updated_at'])->toBeTrue();
});

it('leaves the status alone for ordinary events', function () {
    addAnimal('A1');
    foreach (['birth', 'weight', 'treatment', 'movement'] as $kind) {
        recordEvent('A1', $kind, ['value' => 300])->assertOk();
    }

    expect($this->getJson(kapi('/animals/A1'))->json('status'))->toBe('alive');
});

it("lists an animal's history newest first, in a stable order", function () {
    addAnimal('A1');
    recordEvent('A1', 'weight', ['event_date' => longAgo(), 'value' => 200]);
    recordEvent('A1', 'treatment', ['note' => 'first']);
    recordEvent('A1', 'movement', ['location' => 'Camp 3']);

    $events = $this->getJson(kapi('/animals/A1/events'))->json();
    $ids = array_column($events, 'id');

    expect(array_column($events, 'event_date'))->toBe([recently(), recently(), longAgo()])
        ->and($ids)->toBe(collect($ids)->sortDesc()->values()->all())
        ->and($this->getJson(kapi('/animals/A1/events'))->json())->toBe($events);
});

it('shows an animal only its own history', function () {
    smallHerd();

    expect(array_column($this->getJson(kapi('/animals/C3/events'))->json(), 'kind'))->toBe(['sale']);
});

it('feeds the whole herd newest first, filtered by kind, bounded', function () {
    smallHerd();
    $dates = array_column($this->getJson(kapi('/events'))->json(), 'event_date');

    expect($dates)->toHaveCount(3)->and($dates)->toBe(collect($dates)->sortDesc()->values()->all());
    expect($this->getJson(kapi('/events?kind=weight'))->json())->toHaveCount(2);
    $this->getJson(kapi('/events?kind=nonsense'))->assertStatus(422);
    expect($this->getJson(kapi('/events?limit=1'))->json())->toHaveCount(1);
    $this->getJson(kapi('/events?limit=-1'))->assertStatus(422);
    $this->getJson(kapi('/events?limit=0'))->assertStatus(422);
    $this->getJson(kapi('/events?limit=100000'))->assertStatus(422);
});

// A phone cannot tell a request that failed from one that landed slowly, and replays it from its outbox.

it('records a replayed event once', function () {
    addAnimal('A1');
    $body = ['value' => 312.5, 'client_uuid' => 'cafe-1234'];

    $first = recordEvent('A1', 'weight', $body)->assertOk()->json('id');
    $second = recordEvent('A1', 'weight', $body)->assertOk()->json('id');

    expect($second)->toBe($first)->and($this->getJson(kapi('/animals/A1/events'))->json())->toHaveCount(1);
});

it('keeps two genuine events on one day', function () {
    addAnimal('A1');
    recordEvent('A1', 'treatment', ['client_uuid' => 'cafe-1'])->assertOk();
    recordEvent('A1', 'treatment', ['client_uuid' => 'cafe-2'])->assertOk();

    expect($this->getJson(kapi('/animals/A1/events'))->json())->toHaveCount(2);
});

it('accepts several events without a capture id', function () {
    addAnimal('A1');
    foreach (range(1, 3) as $_) {
        recordEvent('A1', 'treatment')->assertOk();
    }

    expect($this->getJson(kapi('/animals/A1/events'))->json())->toHaveCount(3);
});

it('does not re-apply a status change on replay', function () {
    addAnimal('A1');
    recordEvent('A1', 'sale', ['client_uuid' => 'cafe-sale']);
    expect($this->getJson(kapi('/animals/A1'))->json('status'))->toBe('sold');

    $this->patchJson(kapi('/animals/A1'), ['status' => 'alive'])->assertOk();
    recordEvent('A1', 'sale', ['client_uuid' => 'cafe-sale'])->assertOk();

    expect($this->getJson(kapi('/animals/A1'))->json('status'))->toBe('alive');
});

// Moving a whole camp at once.

function campOf(array $tags, string $location): void
{
    foreach ($tags as $tag) {
        addAnimal($tag);
    }
    moveTo($tags, $location, longAgo());
}

it('moves a camp in one call', function () {
    campOf(['A1', 'A2', 'A3'], 'Bo-kamp');

    $this->postJson(kapi('/events/movement/bulk'), ['tags' => ['A1', 'A2', 'A3'], 'event_date' => recently(),
        'location' => 'Onder-kamp', 'note' => 'after the rain'])->assertOk()->assertJsonCount(3);

    foreach (['A1', 'A2', 'A3'] as $tag) {
        $newest = $this->getJson(kapi("/animals/{$tag}/events"))->json(0);
        expect([$newest['kind'], $newest['location']])->toBe(['movement', 'Onder-kamp']);
    }
});

it('moves nobody when one tag is unknown', function () {
    campOf(['A1', 'A2'], 'Bo-kamp');

    $this->postJson(kapi('/events/movement/bulk'), ['tags' => ['A1', 'NOPE'], 'event_date' => recently(), 'location' => 'Onder-kamp'])
        ->assertNotFound();

    expect($this->getJson(kapi('/animals/A1/events'))->json('0.location'))->toBe('Bo-kamp');
});

it('needs at least one animal and a camp to move', function () {
    addAnimal('A1');
    $this->postJson(kapi('/events/movement/bulk'), ['tags' => [], 'event_date' => recently(), 'location' => 'X'])->assertStatus(400);
    $this->postJson(kapi('/events/movement/bulk'), ['tags' => ['A1'], 'event_date' => recently(), 'location' => ' '])->assertStatus(422);
});

it('moves a replayed camp once, and completes one that half landed', function () {
    campOf(['A1', 'A2'], 'Bo-kamp');
    $body = ['event_date' => recently(), 'location' => 'Onder-kamp', 'client_uuid' => 'batch-1'];

    $this->postJson(kapi('/events/movement/bulk'), ['tags' => ['A1']] + $body)->assertOk();
    $first = $this->postJson(kapi('/events/movement/bulk'), ['tags' => ['A1', 'A2']] + $body)->assertOk()->json();
    $replay = $this->postJson(kapi('/events/movement/bulk'), ['tags' => ['A1', 'A2']] + $body)->assertOk()->json();

    expect(array_column($replay, 'id'))->toBe(array_column($first, 'id'));
    foreach (['A1', 'A2'] as $tag) {
        $moves = collect($this->getJson(kapi("/animals/{$tag}/events"))->json())->where('location', 'Onder-kamp');
        expect($moves)->toHaveCount(1);
    }
});

it('keeps two genuine camp moves', function () {
    campOf(['A1'], 'Bo-kamp');
    moveTo(['A1'], 'Onder-kamp');
    moveTo(['A1'], 'Bo-kamp');

    expect($this->getJson(kapi('/animals/A1/events'))->json())->toHaveCount(3);
});

it('groups the alive herd by where it is now', function () {
    campOf(['A1', 'A2'], 'Bo-kamp');
    campOf(['B1'], 'Onder-kamp');
    moveTo(['A2'], 'Onder-kamp');
    recordEvent('A1', 'sale');
    addAnimal('Z1');

    expect($this->getJson(kapi('/locations'))->json())->toBe([
        ['location' => 'Bo-kamp', 'animals' => []],
        ['location' => 'Onder-kamp', 'animals' => [['tag' => 'A2', 'name' => ''], ['tag' => 'B1', 'name' => '']]],
        ['location' => null, 'animals' => [['tag' => 'Z1', 'name' => '']]],
    ]);
});
