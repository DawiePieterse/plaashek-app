<?php

// An animal's family on its page (PLAN.md section 2.2, lineage): parents to great-grandparents by tag within the
// species, offspring, and a mother's births with the interval between them.

use App\Modules\Kudde\Models\Species;

beforeEach(fn () => kudde());

function lineage(string $tag, string $query = ''): array
{
    return test()->getJson(kapi('/animals/'.$tag.'/lineage'.$query))->assertOk()->json();
}

it('traces three generations, showing a parent never on record by its tag', function () {
    addAnimal('GG1', ['birth_date' => daysFromToday(-3000)]);
    addAnimal('G1', ['birth_date' => daysFromToday(-2000), 'dam_tag' => 'GG1', 'sire_tag' => 'AI-77']);
    addAnimal('M1', ['birth_date' => daysFromToday(-1200), 'dam_tag' => 'G1']);
    addAnimal('BULL', ['sex' => 'male', 'birth_date' => daysFromToday(-1500)]);
    addAnimal('K1', ['birth_date' => daysFromToday(-300), 'dam_tag' => 'M1', 'sire_tag' => 'BULL']);

    $tree = lineage('K1');

    expect($tree['dam'])->toMatchArray(['tag' => 'M1', 'on_record' => true, 'class' => 'cow'])
        ->and($tree['sire'])->toMatchArray(['tag' => 'BULL', 'on_record' => true, 'sex' => 'male'])
        ->and($tree['dam']['dam']['tag'])->toBe('G1')
        ->and($tree['dam']['dam']['dam']['tag'])->toBe('GG1')
        ->and($tree['dam']['dam']['sire'])->toBe(['tag' => 'AI-77', 'on_record' => false])
        // Three generations: a great-grandparent's own parents are not followed.
        ->and($tree['dam']['dam']['dam'])->not->toHaveKey('dam')
        ->and($tree['sire']['dam'])->toBeNull();
});

it('lists a mother\'s young and her births with the days between them', function () {
    addAnimal('C1', ['birth_date' => daysFromToday(-2000)]);
    foreach ([['K1', -800], ['K2', -420], ['K3', -420]] as [$tag, $born]) {
        addAnimal($tag, ['dam_tag' => 'C1', 'birth_date' => daysFromToday($born)]);
    }

    $tree = lineage('C1');

    expect(array_column($tree['offspring'], 'tag'))->toBe(['K1', 'K2', 'K3'])
        ->and($tree['births'])->toBe([
            ['date' => daysFromToday(-800), 'young' => ['K1'], 'litter' => null, 'interval_days' => null],
            ['date' => daysFromToday(-420), 'young' => ['K2', 'K3'], 'litter' => null, 'interval_days' => 380],
        ]);
});

it('gives a sire his offspring, within his own species', function () {
    Species::query()->where('code', 'sheep')->update(['enabled' => true]);
    addAnimal('B1', ['sex' => 'male', 'species' => 'cattle', 'birth_date' => daysFromToday(-4000)]);
    addAnimal('K1', ['sire_tag' => 'B1', 'species' => 'cattle', 'birth_date' => daysFromToday(-3000)]);
    // Ram B1 shares the tag; cow K1's sire is the bull.
    addAnimal('B1', ['sex' => 'male', 'species' => 'sheep', 'birth_date' => daysFromToday(-500)]);

    expect(array_column(lineage('B1', '?species=cattle')['offspring'], 'tag'))->toBe(['K1'])
        ->and(lineage('B1', '?species=sheep')['offspring'])->toBe([])
        ->and(lineage('K1', '?species=cattle')['sire']['birth_date'])->toBe(daysFromToday(-4000));
});

it('counts a litter on the mother as a birth', function () {
    Species::query()->where('code', 'pigs')->update(['enabled' => true]);
    addAnimal('S1', ['species' => 'pigs', 'birth_date' => daysFromToday(-700)]);
    $this->postJson(kapi('/calvings'), ['dam_tag' => 'S1', 'species' => 'pigs', 'event_date' => daysFromToday(-200),
        'litter' => ['born_alive' => 10, 'stillborn' => 1]])->assertOk();
    $this->postJson(kapi('/calvings'), ['dam_tag' => 'S1', 'species' => 'pigs', 'event_date' => daysFromToday(-50),
        'litter' => ['born_alive' => 12]])->assertOk();

    $births = lineage('S1', '?species=pigs')['births'];

    expect(array_column($births, 'interval_days'))->toBe([null, 150])
        ->and($births[0]['litter'])->toBe(['born_alive' => 10, 'stillborn' => 1, 'weaned' => null]);
});
