<?php

// Cow, heifer, bull, steer, calf: derived from the record, never typed (old Kudde's test_classes.py).

beforeEach(fn () => kudde());

function classes(): array
{
    return collect(test()->getJson(kapi('/animals'))->json())->pluck('class', 'tag')->all();
}

it('gives the class from sex, age and castration', function () {
    $young = daysFromToday(-100);
    $grown = daysFromToday(-800);
    addAnimal('HC', ['birth_date' => $young]);
    addAnimal('H', ['birth_date' => $grown]);
    addAnimal('H2');
    addAnimal('BC', ['sex' => 'male', 'birth_date' => $young]);
    addAnimal('B', ['sex' => 'male', 'birth_date' => $grown]);
    addAnimal('SC', ['sex' => 'male', 'birth_date' => $young, 'castrated' => true]);
    addAnimal('S', ['sex' => 'male', 'birth_date' => $grown, 'castrated' => true]);

    expect(classes())->toEqual(['HC' => 'heifer calf', 'H' => 'heifer', 'H2' => 'heifer', 'BC' => 'bull calf',
        'B' => 'bull', 'SC' => 'steer calf', 'S' => 'steer']);
});

it('makes a heifer a cow when she calves', function () {
    addAnimal('C1', ['birth_date' => daysFromToday(-1000)]);
    expect(classes()['C1'])->toBe('heifer');

    $this->postJson(kapi('/calvings'), ['dam_tag' => 'C1', 'event_date' => today()->toDateString(),
        'calf' => ['tag' => 'C1-26', 'sex' => 'female']])->assertOk();

    expect(classes())->toEqual(['C1' => 'cow', 'C1-26' => 'heifer calf']);
});

it('makes her a cow from a calf recorded by dam tag alone', function () {
    addAnimal('C1');
    addAnimal('K1', ['birth_date' => daysFromToday(-30), 'dam_tag' => 'c1']);

    expect(classes()['C1'])->toBe('cow');
});

it('does not take her own birth event for a calving', function () {
    $born = today()->subDays(500);
    addAnimal('K1', ['birth_date' => $born->toDateString()]);
    recordEvent('K1', 'birth', ['event_date' => $born->toDateString()]);
    expect(classes()['K1'])->toBe('heifer');

    recordEvent('K1', 'birth', ['event_date' => $born->copy()->addDays(400)->toDateString()]);
    expect(classes()['K1'])->toBe('cow');
});

it('can castrate later', function () {
    addAnimal('B1', ['sex' => 'male']);
    expect(classes()['B1'])->toBe('bull');

    $this->patchJson(kapi('/animals/B1'), ['castrated' => true])->assertOk();
    expect(classes()['B1'])->toBe('steer');
});

it('counts the live herd by class on the dashboard', function () {
    $grown = daysFromToday(-800);
    addAnimal('H1', ['birth_date' => $grown]);
    addAnimal('H2', ['birth_date' => $grown]);
    addAnimal('B1', ['sex' => 'male', 'birth_date' => $grown]);
    addAnimal('S1', ['sex' => 'male', 'birth_date' => $grown, 'castrated' => true]);
    recordEvent('S1', 'sale');

    expect($this->getJson(kapi('/dashboard'))->json('class_counts'))->toBe(['cow' => 0, 'heifer' => 2, 'bull' => 1,
        'steer' => 0, 'heifer calf' => 0, 'bull calf' => 0, 'steer calf' => 0]);
});
