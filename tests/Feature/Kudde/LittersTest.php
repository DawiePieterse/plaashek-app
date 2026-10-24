<?php

// Births with several young, and litters counted on the mother until the young are tagged at weaning
// (PLAN.md section 2.2, phase 4b; owner's call: pigs are tagged at weaning).

use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\Litter;
use App\Modules\Kudde\Models\Species;

beforeEach(function () {
    kudde();
    Species::query()->whereIn('code', ['sheep', 'pigs'])->update(['enabled' => true]);
});

it('records twin lambs on one ewe, as one record', function () {
    addAnimal('E1', ['species' => 'sheep', 'birth_date' => daysFromToday(-800)]);

    $body = $this->postJson(kapi('/calvings'), ['dam_tag' => 'E1', 'species' => 'sheep', 'event_date' => recently(), 'camp' => 'Lamkamp',
        'client_uuid' => 'tw-1', 'young' => [['tag' => 'L1', 'sex' => 'female'], ['tag' => 'L2', 'sex' => 'male']]])->assertOk()->json();

    expect(array_column($body['young'], 'tag'))->toBe(['L1', 'L2'])
        ->and($body['dam_event']['note'])->toBe('Young L1, L2')
        ->and(Animal::query()->where('dam_tag', 'E1')->pluck('species_id')->unique()->all())->toBe([Species::query()->where('code', 'sheep')->value('id')]);

    // A replay makes nothing new, and gives back both lambs.
    $again = $this->postJson(kapi('/calvings'), ['dam_tag' => 'E1', 'species' => 'sheep', 'event_date' => recently(), 'client_uuid' => 'tw-1',
        'young' => [['tag' => 'L1', 'sex' => 'female'], ['tag' => 'L2', 'sex' => 'male']]])->assertOk()->json();
    expect($again['replayed'])->toBeTrue()->and(array_column($again['young'], 'tag'))->toBe(['L1', 'L2'])
        ->and(Animal::query()->count())->toBe(3);
});

it('refuses a birth with neither young nor a litter, and keeps the ewe untouched when a twin tag is taken', function () {
    addAnimal('E1', ['species' => 'sheep']);
    addAnimal('L2', ['species' => 'sheep']);

    $this->postJson(kapi('/calvings'), ['dam_tag' => 'E1', 'species' => 'sheep', 'event_date' => recently()])->assertStatus(422);
    $this->postJson(kapi('/calvings'), ['dam_tag' => 'E1', 'species' => 'sheep', 'event_date' => recently(),
        'young' => [['tag' => 'L1', 'sex' => 'female'], ['tag' => 'L2', 'sex' => 'male']]])->assertStatus(409);

    expect(Animal::query()->count())->toBe(2)->and($this->getJson(kapi('/animals/E1/events?species=sheep'))->json())->toBe([]);
});

it('counts a litter on the sow, and tags the piglets at weaning', function () {
    addAnimal('S1', ['species' => 'pigs', 'birth_date' => daysFromToday(-400)]);
    $born = daysFromToday(-28);

    $body = $this->postJson(kapi('/calvings'), ['dam_tag' => 'S1', 'species' => 'pigs', 'event_date' => $born, 'camp' => 'Kraamhok',
        'litter' => ['born_alive' => 11, 'stillborn' => 1, 'sire_tag' => 'B7']])->assertOk()->json();
    expect($body['dam_event']['note'])->toBe('Litter 11 alive, 1 stillborn')->and($body['young'])->toBe([])
        ->and(collect($this->getJson(kapi('/animals?species=pigs'))->json())->pluck('class_role', 'tag')->all())->toBe(['S1' => 'mother']);

    $open = $this->getJson(kapi('/litters'))->assertOk()->json();
    expect($open)->toHaveCount(1)->and($open[0])->toMatchArray(['dam_tag' => 'S1', 'species' => 'pigs', 'born_alive' => 11, 'age_days' => 28, 'weaned' => null]);

    $wean = fn () => $this->postJson(kapi('/litters/'.$open[0]['id'].'/wean'), ['event_date' => recently(), 'camp' => 'Speenhok', 'fostered' => -1,
        'client_uuid' => 'wean-1', 'young' => [['tag' => 'P1', 'sex' => 'female'], ['tag' => 'P2', 'sex' => 'male', 'castrated' => true]]]);
    $weaned = $wean()->assertOk()->json();

    expect($weaned['litter'])->toMatchArray(['weaned' => 2, 'fostered' => -1, 'weaned_on' => recently()])
        ->and(array_column($weaned['young'], 'tag'))->toBe(['P1', 'P2'])
        ->and(Animal::query()->where('tag', 'P1')->sole())->dam_tag->toBe('S1')->sire_tag->toBe('B7')
        ->and(Animal::query()->where('tag', 'P1')->sole()->birth_date->toDateString())->toBe($born)
        ->and(collect($this->getJson(kapi('/animals?species=pigs'))->json())->pluck('camp', 'tag')->all())
        ->toMatchArray(['P1' => 'Speenhok', 'P2' => 'Speenhok'])
        ->and($this->getJson(kapi('/litters'))->json())->toBe([]);

    // The same weaning replayed is harmless; a different one is refused.
    expect($wean()->assertOk()->json('replayed'))->toBeTrue()->and(Animal::query()->count())->toBe(3);
    $this->postJson(kapi('/litters/'.$open[0]['id'].'/wean'), ['event_date' => recently(), 'young' => []])->assertStatus(409);
});

it('will not wean a litter before its birth', function () {
    addAnimal('S1', ['species' => 'pigs']);
    $this->postJson(kapi('/calvings'), ['dam_tag' => 'S1', 'species' => 'pigs', 'event_date' => recently(), 'litter' => ['born_alive' => 8]])->assertOk();

    $this->postJson(kapi('/litters/'.Litter::query()->value('id').'/wean'), ['event_date' => daysFromToday(-30), 'young' => []])->assertStatus(422);
});
