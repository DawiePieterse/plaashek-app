<?php

use App\Models\Camp;

// Camps as a managed list, kept in step with the plain-text locations on the events (old Kudde's test_camps.py).

beforeEach(fn () => kudde());

function campNames(string $query = ''): array
{
    return array_column(test()->getJson(kapi('/camps'.$query))->json(), 'name');
}

it('creates, renames, orders and archives camps', function () {
    $a = $this->postJson(kapi('/camps'), ['name' => '  Bo-kamp '])->assertOk()->json();
    $b = $this->postJson(kapi('/camps'), ['name' => 'Onder-kamp'])->assertOk()->json();
    expect([$a['name'], $a['alive_count']])->toBe(['Bo-kamp', 0])->and(campNames())->toBe(['Bo-kamp', 'Onder-kamp']);

    // The farmer's order, not the alphabet.
    $this->putJson(kapi('/camps/order'), ['ids' => [$b['id'], $a['id']]])->assertOk();
    expect(campNames())->toBe(['Onder-kamp', 'Bo-kamp']);

    expect($this->patchJson(kapi("/camps/{$a['id']}"), ['name' => 'Bokamp'])->json('name'))->toBe('Bokamp');
    $this->patchJson(kapi("/camps/{$a['id']}"), ['archived' => true])->assertOk();
    expect(campNames())->toBe(['Onder-kamp'])->and(campNames('?include_archived=1'))->toBe(['Onder-kamp', 'Bokamp']);

    $this->postJson(kapi('/camps'), ['name' => ''])->assertStatus(422);
    $this->patchJson(kapi('/camps/999'), ['name' => 'x'])->assertNotFound();
    $this->assertDatabaseHas('audit_log', ['action' => 'camp.updated', 'subject' => 'Bokamp']);
});

it('is one camp whatever its capitals', function () {
    $this->postJson(kapi('/camps'), ['name' => 'Noordkamp']);
    $this->postJson(kapi('/camps'), ['name' => 'noordkamp'])->assertStatus(409);
    $suid = $this->postJson(kapi('/camps'), ['name' => 'Suid'])->json('id');

    $this->patchJson(kapi("/camps/{$suid}"), ['name' => 'NOORDKAMP'])->assertStatus(409);
});

it('folds Afrikaans diacritics in PHP, not SQL', function () {
    $this->postJson(kapi('/camps'), ['name' => 'Ëerste kamp'])->assertOk();
    $this->postJson(kapi('/camps'), ['name' => 'ëerste KAMP'])->assertStatus(409);
    addAnimal('A1');

    expect(moveTo(['A1'], 'ËERSTE kamp')[0]['location'])->toBe('Ëerste kamp');
});

it('snaps a typed location to the spelling the office holds', function () {
    $this->postJson(kapi('/camps'), ['name' => 'Noordkamp']);
    addAnimal('A1');

    expect(recordEvent('A1', 'movement', ['location' => '  NOORDKAMP '])->json('location'))->toBe('Noordkamp')
        ->and(moveTo(['A1'], 'noordkamp')[0]['location'])->toBe('Noordkamp')
        ->and(campNames())->toBe(['Noordkamp']);
});

it('adds a location nobody set up', function () {
    addAnimal('A1');
    moveTo(['A1'], 'Rivierkamp');

    $camp = $this->getJson(kapi('/camps'))->json(0);
    expect([$camp['name'], $camp['alive_count']])->toBe(['Rivierkamp', 1]);
});

it('brings an archived camp back when it is used again', function () {
    $id = $this->postJson(kapi('/camps'), ['name' => 'Winterveld'])->json('id');
    $this->patchJson(kapi("/camps/{$id}"), ['archived' => true]);
    addAnimal('A1');
    moveTo(['A1'], 'winterveld');

    expect(campNames())->toBe(['Winterveld']);
    $this->postJson(kapi('/camps'), ['name' => 'WINTERVELD'])->assertStatus(409);
});

it('will not archive a camp that holds animals', function () {
    $id = $this->postJson(kapi('/camps'), ['name' => 'Bo-kamp'])->json('id');
    addAnimal('A1');
    addAnimal('A2');
    moveTo(['A1', 'A2'], 'Bo-kamp');

    $this->patchJson(kapi("/camps/{$id}"), ['archived' => true])->assertStatus(409)
        ->assertJsonFragment(['message' => 'Bo-kamp still holds 2 animals - move them first']);

    // Sold animals do not count: the camp is about the live herd.
    recordEvent('A1', 'sale');
    moveTo(['A2'], 'Elsewhere');
    $this->patchJson(kapi("/camps/{$id}"), ['archived' => true])->assertOk();
});

it('enters a new animal straight into a camp', function () {
    addAnimal('A1', ['camp' => 'Bo-kamp']);
    addAnimal('A2');

    $events = $this->getJson(kapi('/animals/A1/events'))->json();
    expect(array_map(fn ($e) => [$e['kind'], $e['location']], $events))->toBe([['movement', 'Bo-kamp']])
        ->and($this->getJson(kapi('/animals/A2/events'))->json())->toBe([])
        ->and(campNames())->toBe(['Bo-kamp']);
});

it('shows the whole farm in the move picker', function () {
    $this->postJson(kapi('/camps'), ['name' => 'Onder-kamp']);
    $this->postJson(kapi('/camps'), ['name' => 'Bo-kamp']);
    foreach (['B10', 'B2', 'C1', 'D1'] as $tag) {
        addAnimal($tag);
    }
    moveTo(['B10', 'B2'], 'bo-kamp');
    moveTo(['C1'], 'Stray');

    $rows = array_map(fn ($r) => [$r['location'], array_column($r['animals'], 'tag')], $this->getJson(kapi('/locations'))->json());
    expect($rows)->toBe([['Onder-kamp', []], ['Bo-kamp', ['B2', 'B10']], ['Stray', ['C1']], [null, ['D1']]]);
});

it('still lists a camp the events know but the office does not', function () {
    addAnimal('A1');
    recordEvent('A1', 'movement', ['location' => 'Old place']);
    Camp::query()->delete();

    expect($this->getJson(kapi('/locations'))->json())->toBe([['location' => 'Old place', 'animals' => [['tag' => 'A1', 'name' => '']]]]);
});
