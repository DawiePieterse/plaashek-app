<?php

// Livestock types (PLAN.md section 2.2, phase 4b): every animal has a species, tags are unique per species,
// and each species names its own classes and has its own age, gestation and weighing rules.

use App\Modules\Kudde\KuddeModule;
use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\Species;
use App\Reports\ReportFilters;
use App\Support\Farm;
use App\Support\Settings;

beforeEach(fn () => kudde());

function keep(string ...$codes): void
{
    Species::query()->update(['enabled' => false]);
    Species::query()->whereIn('code', $codes)->update(['enabled' => true]);
}

it('makes every animal cattle until the farm keeps something else', function () {
    expect(addAnimal('A1')['species'])->toBe('cattle')
        ->and(Animal::query()->sole()->species->code)->toBe('cattle');
});

it('puts a new animal in the farm\'s only species when it names none', function () {
    keep('sheep');

    expect(addAnimal('S1')['species'])->toBe('sheep')
        ->and(addAnimal('S2', ['species' => 'sheep'])['class'])->toBe('ewe');
});

it('lets two species use one tag, and asks which when a request does not say', function () {
    keep('cattle', 'sheep');
    addAnimal('001', ['species' => 'cattle']);
    addAnimal('001', ['species' => 'sheep']);

    $this->postJson(kapi('/animals'), ['tag' => '001', 'sex' => 'female', 'species' => 'sheep'])->assertStatus(409);
    recordEvent('001', 'treatment')->assertStatus(409)
        ->assertJsonFragment(['message' => 'More than one species has an animal tagged 001: say which species']);
    $event = recordEvent('001', 'treatment', ['species' => 'sheep'])->assertOk()->json();

    expect(Animal::query()->find($event['animal_id'])->species->code)->toBe('sheep');
    $this->getJson(kapi('/animals/001?species=cattle'))->assertOk()->assertJsonPath('species', 'cattle');
    $this->getJson(kapi('/animals?species=sheep'))->assertJsonCount(1);
    recordEvent('001', 'treatment', ['species' => 'unicorns'])->assertStatus(422);
});

it('names classes by each species, in the farm language', function () {
    keep('cattle', 'sheep', 'pigs');
    $grown = daysFromToday(-800);
    addAnimal('E1', ['species' => 'sheep', 'birth_date' => $grown]);
    addAnimal('L1', ['species' => 'sheep', 'birth_date' => daysFromToday(-100)]);
    addAnimal('W1', ['species' => 'sheep', 'sex' => 'male', 'castrated' => true, 'birth_date' => $grown]);
    // 100 days: a lamb (young until 180), but a grown gilt (young until 70).
    addAnimal('P1', ['species' => 'pigs', 'birth_date' => daysFromToday(-100)]);
    addAnimal('C1', ['species' => 'cattle', 'birth_date' => daysFromToday(-100)]);

    expect(collect($this->getJson(kapi('/animals'))->json())->pluck('class', 'tag')->all())
        ->toBe(['C1' => 'heifer calf', 'E1' => 'ewe', 'L1' => 'ewe lamb', 'P1' => 'gilt', 'W1' => 'wether']);

    app(Settings::class)->set(Farm::LANGUAGE, 'af');
    expect(collect($this->getJson(kapi('/animals'))->json())->pluck('class', 'tag')->all())
        ->toBe(['C1' => 'verskalf', 'E1' => 'ooi', 'L1' => 'ooilam', 'P1' => 'jongsog', 'W1' => 'hamel']);
});

it('makes a lamb the ewe\'s species, and her a mother', function () {
    keep('cattle', 'sheep');
    addAnimal('E1', ['species' => 'sheep', 'birth_date' => daysFromToday(-800)]);
    addAnimal('E1', ['species' => 'cattle', 'birth_date' => daysFromToday(-800)]);

    $this->postJson(kapi('/calvings'), ['dam_tag' => 'E1', 'species' => 'sheep', 'event_date' => today()->toDateString(),
        'calf' => ['tag' => 'L9', 'sex' => 'male']])->assertOk()->assertJsonPath('calf.species_id', Species::query()->where('code', 'sheep')->value('id'));

    $rows = collect($this->getJson(kapi('/animals'))->json())->map(fn ($a) => $a['species'].' '.$a['tag'].' '.$a['class_role'])->all();
    expect($rows)->toContain('sheep E1 mother', 'cattle E1 female', 'sheep L9 young_male');
});

it('counts a mixed farm by species and class', function () {
    keep('cattle', 'sheep');
    addAnimal('C1', ['species' => 'cattle']);
    addAnimal('E1', ['species' => 'sheep']);
    addAnimal('E2', ['species' => 'sheep']);

    $counts = $this->getJson(kapi('/dashboard'))->json('class_counts');

    expect($counts['cattle: heifer'])->toBe(1)->and($counts['sheep: ewe'])->toBe(2)
        ->and(array_key_first($counts))->toBe('cattle: cow');
});

it('weighs each species by its own interval, else Kudde\'s', function () {
    keep('cattle', 'sheep');
    Species::query()->where('code', 'sheep')->update(['weigh_every_days' => 30]);
    addAnimal('C1', ['species' => 'cattle']);
    addAnimal('E1', ['species' => 'sheep']);
    recordEvent('C1', 'weight', ['event_date' => daysFromToday(-60), 'value' => 300, 'species' => 'cattle']);
    recordEvent('E1', 'weight', ['event_date' => daysFromToday(-60), 'value' => 60, 'species' => 'sheep']);

    expect(array_column($this->getJson(kapi('/dashboard'))->json('needs_weighing'), 'tag'))->toBe(['E1'])
        ->and(collect($this->getJson(kapi('/animals'))->json())->pluck('weigh_every_days', 'tag')->all())
        ->toBe(['C1' => 182, 'E1' => 30]);
});

it('puts the species in the herd register', function () {
    keep('cattle', 'sheep');
    addAnimal('C1', ['species' => 'cattle']);
    addAnimal('E1', ['species' => 'sheep']);
    $report = collect(KuddeModule::reports())->first(fn ($r) => $r->key() === 'kudde.herd');

    $rows = iterator_to_array($report->rows(new ReportFilters(values: ['species' => 'sheep'])), false);

    expect(array_column($rows, 'tag'))->toBe(['E1'])->and($rows[0]['species'])->toBe('Sheep')
        ->and($rows[0]['class'])->toBe('Sheep: ewe');
});

it('gives the field app the species the farm keeps, with their rules', function () {
    keep('cattle', 'pigs');
    Species::query()->where('code', 'pigs')->update(['weigh_every_days' => 28]);

    $page = $this->get('/kudde/field')->assertOk()->getContent();
    preg_match('/window\.PLAASHEK = (\{.*?\});<\/script>/', $page, $m);
    $species = json_decode($m[1], true)['species'];

    expect(array_column($species, 'code'))->toBe(['cattle', 'pigs'])
        ->and($species[1])->toMatchArray(['taggedAtWeaning' => true, 'weighEveryDays' => 28, 'youngUntilDays' => 70])
        ->and($species[1]['classNames']['mother'])->toBe('sow');
});
