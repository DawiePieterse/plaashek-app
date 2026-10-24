<?php

use Illuminate\Testing\TestResponse;

// A calving: the cow's birth event and the new calf, as one record (old Kudde's test_calvings.py).

beforeEach(function () {
    kudde();
    addAnimal('C1', ['breed' => 'Nguni']);
});

function calve(array $override = []): TestResponse
{
    return test()->postJson(kapi('/calvings'), array_merge([
        'dam_tag' => 'C1', 'event_date' => recently(), 'calf' => ['tag' => 'C1-24', 'sex' => 'male', 'breed' => 'Nguni'],
        'camp' => 'Bo-kamp', 'note' => 'pulled, up and sucking', 'client_uuid' => 'cap-1',
    ], $override));
}

function timeline(string $tag): array
{
    return array_map(fn ($e) => [$e['kind'], $e['location'], $e['note']], test()->getJson(kapi("/animals/{$tag}/events"))->json());
}

it('records the cow and creates the calf', function () {
    $body = calve()->assertOk()->json();

    expect($body['replayed'])->toBeFalse()
        ->and([$body['calf']['tag'], $body['calf']['dam_tag'], $body['calf']['birth_date'], $body['calf']['status']])
        ->toBe(['C1-24', 'C1', recently(), 'alive'])
        ->and(timeline('C1'))->toBe([['birth', 'Bo-kamp', 'Calf C1-24 - pulled, up and sucking']])
        ->and(timeline('C1-24'))->toBe([['birth', 'Bo-kamp', 'Born to C1']])
        ->and(collect($this->getJson(kapi('/animals'))->json())->pluck('camp', 'tag')->all())->toBe(['C1' => 'Bo-kamp', 'C1-24' => 'Bo-kamp'])
        ->and(array_column($this->getJson(kapi('/camps'))->json(), 'name'))->toBe(['Bo-kamp']);
});

it('records a replayed calving once', function () {
    calve()->assertOk();

    expect(calve()->assertOk()->json('replayed'))->toBeTrue()
        ->and($this->getJson(kapi('/animals'))->json())->toHaveCount(2)
        ->and(timeline('C1'))->toHaveCount(1);
});

it('leaves the cow untouched when the calf tag is taken', function () {
    addAnimal('C1-24');

    calve()->assertStatus(409)->assertJsonFragment(['message' => 'Tag C1-24 is already in use']);
    expect(timeline('C1'))->toBe([]);
});

it('lets only a cow calve', function () {
    addAnimal('B1', ['sex' => 'male']);

    calve(['dam_tag' => 'B1'])->assertStatus(422);
    calve(['dam_tag' => 'nobody'])->assertNotFound();
    calve(['calf' => ['tag' => '  ', 'sex' => 'male']])->assertStatus(422);
});

it('works without a camp', function () {
    calve(['camp' => '', 'note' => ''])->assertOk();

    expect(timeline('C1')[0][2])->toBe('Calf C1-24')
        ->and(timeline('C1-24'))->toBe([['birth', '', 'Born to C1']])
        ->and(collect($this->getJson(kapi('/animals'))->json())->pluck('camp', 'tag')->all())->toBe(['C1' => null, 'C1-24' => null]);
});
