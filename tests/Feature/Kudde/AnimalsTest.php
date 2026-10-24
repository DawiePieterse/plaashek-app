<?php

// The animal register: identity, creation and correction (old Kudde's test_animals.py).

beforeEach(fn () => kudde());

it('answers an empty herd with an empty list, not an error', function () {
    $this->getJson(kapi('/animals'))->assertOk()->assertExactJson([]);
});

it('creates an animal and reads it back', function () {
    $body = $this->postJson(kapi('/animals'), ['tag' => 'A1', 'name' => 'Bessie', 'breed' => 'Nguni', 'sex' => 'female',
        'birth_date' => '2022-03-15', 'sire_tag' => 'S9', 'dam_tag' => 'D4'])->assertOk()->json();

    expect($body['birth_date'])->toBe('2022-03-15')
        ->and($body['status'])->toBe('alive')
        ->and($body['id'])->not->toBeNull()
        ->and($this->getJson(kapi('/animals/A1'))->json('name'))->toBe('Bessie');
});

it('uses a tag only once, whatever its capitals', function () {
    addAnimal('A1');

    $this->postJson(kapi('/animals'), ['tag' => 'A1', 'sex' => 'male'])->assertStatus(409)->assertJsonFragment(['message' => 'Tag A1 is already in use']);
    $this->postJson(kapi('/animals'), ['tag' => 'a1', 'sex' => 'male'])->assertStatus(409);
});

it('refuses a blank tag', function (string $blank) {
    $this->postJson(kapi('/animals'), ['tag' => $blank, 'sex' => 'female'])->assertStatus(422);
    expect($this->getJson(kapi('/animals'))->json())->toBe([]);
})->with(['', '   ', "\t"]);

it('reads a padded tag as the same tag', function () {
    addAnimal('A1');
    $this->postJson(kapi('/animals'), ['tag' => ' A1 ', 'sex' => 'male'])->assertStatus(409);

    addAnimal('  B2  ', ['sex' => 'male']);
    expect(collect($this->getJson(kapi('/animals'))->json())->pluck('tag')->all())->toBe(['A1', 'B2']);

    recordEvent(' B2 ', 'treatment')->assertOk();
});

it('tidies parent tags too', function () {
    $body = addAnimal('A1', ['sire_tag' => ' S9 ', 'dam_tag' => '   ']);

    expect($body['sire_tag'])->toBe('S9')->and($body['dam_tag'])->toBeNull();
});

it('refuses an unknown sex', function () {
    $this->postJson(kapi('/animals'), ['tag' => 'A1', 'sex' => 'steer'])->assertStatus(422);
});

it('answers a missing animal with a 404', function () {
    $this->getJson(kapi('/animals/NOPE'))->assertNotFound();
    $this->patchJson(kapi('/animals/NOPE'), ['name' => 'x'])->assertNotFound();
    $this->getJson(kapi('/animals/NOPE/events'))->assertNotFound();
});

it('patches only what it is sent, and logs the correction', function () {
    smallHerd();
    $before = $this->getJson(kapi('/animals/A1'))->json();
    $this->travel(5)->seconds();

    $after = $this->patchJson(kapi('/animals/A1'), ['name' => 'Bessie II'])->assertOk()->json();

    expect($after['name'])->toBe('Bessie II')
        ->and($after['breed'])->toBe('Nguni')
        ->and($after['birth_date'])->toBe($before['birth_date'])
        ->and($after['updated_at'] > $before['updated_at'])->toBeTrue();
    $this->assertDatabaseHas('audit_log', ['action' => 'kudde.animal.updated', 'subject' => 'A1']);
});

it('never renames a tag', function () {
    addAnimal('A1');

    expect($this->patchJson(kapi('/animals/A1'), ['tag' => 'ZZ', 'name' => 'renamed'])->assertOk()->json('tag'))->toBe('A1');
    $this->getJson(kapi('/animals/ZZ'))->assertNotFound();
});

it('filters by status and searches tag starts and names', function () {
    smallHerd();
    $tags = fn (string $query) => collect($this->getJson(kapi('/animals'.$query))->json())->pluck('tag')->all();

    expect($tags('?status=alive'))->toBe(['A1', 'B2'])
        ->and($tags('?status=sold'))->toBe(['C3'])
        ->and($tags('?q=a'))->toBe(['A1'])
        ->and($tags('?q=BESS'))->toBe(['A1'])
        ->and($tags('?q=zzz'))->toBe([]);
    $this->getJson(kapi('/animals?status=zombie'))->assertStatus(422);
});

it('lists tags in the order a farmer reads them', function () {
    foreach (['B10', 'b2', 'B1', 'A100', 'A20', 'A20X', '7', '12'] as $tag) {
        addAnimal($tag);
    }

    expect(collect($this->getJson(kapi('/animals'))->json())->pluck('tag')->all())
        ->toBe(['7', '12', 'A20', 'A20X', 'A100', 'B1', 'b2', 'B10']);
});

it("carries each animal's camp and last weight on the list", function () {
    smallHerd();
    recordEvent('A1', 'movement', ['location' => 'Bo-kamp'])->assertOk();
    recordEvent('A1', 'weight', ['value' => 355.5])->assertOk();

    $rows = collect($this->getJson(kapi('/animals'))->json())->keyBy('tag');

    expect($rows['A1']['camp'])->toBe('Bo-kamp')
        ->and($rows['A1']['last_weight'])->toBe(355.5)
        ->and($rows['A1']['last_weight_date'])->toBe(recently())
        ->and($rows['B2']['last_weight'])->toEqual(640)
        ->and($rows['B2']['camp'])->toBeNull()
        ->and($rows['C3']['camp'])->toBeNull()
        ->and($rows['C3']['last_weight'])->toBeNull();
});
