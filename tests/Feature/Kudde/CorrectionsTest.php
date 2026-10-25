<?php

// The office puts captures right on the animal page: the record, an event, or an event removed. All audited.

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Kudde\Filament\Resources\Animals\Pages\ViewAnimal;
use App\Modules\Kudde\Filament\Resources\Animals\RelationManagers\EventsRelationManager;
use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\AnimalEvent;
use App\Modules\Kudde\Models\Species;
use App\Modules\Kudde\Support\Herd;
use App\Modules\Kudde\Support\HerdException;
use Livewire\Livewire;

beforeEach(fn () => kudde());

function animalTagged(string $tag): Animal
{
    return Animal::query()->where('tag', $tag)->sole();
}

function eventsOn(Animal $animal): mixed
{
    return Livewire::test(EventsRelationManager::class, ['ownerRecord' => $animal, 'pageClass' => ViewAnimal::class]);
}

it('corrects the record, never the tag, and audits what changed', function () {
    addAnimal('A1', ['name' => 'Bessie', 'breed' => 'Ngnui']);

    Livewire::test(ViewAnimal::class, ['record' => animalTagged('A1')->id])
        ->callAction('correct', ['name' => 'Bessie', 'breed' => 'Nguni', 'sex' => 'female', 'castrated' => false,
            'birth_date' => '2022-03-15', 'dam_tag' => ' OUMA ', 'sire_tag' => null])
        ->assertHasNoActionErrors();

    $a1 = animalTagged('A1');
    expect($a1->breed)->toBe('Nguni')->and($a1->dam_tag)->toBe('OUMA')->and($a1->birth_date->toDateString())->toBe('2022-03-15');
    $log = AuditLog::query()->where('action', 'kudde.animal.updated')->sole();
    expect($log->before)->toMatchArray(['breed' => 'Ngnui'])->and($log->after)->toMatchArray(['breed' => 'Nguni']);
});

it('corrects a mistyped weight and moves the event to another day', function () {
    addAnimal('A1');
    recordEvent('A1', 'weight', ['value' => 41])->assertOk();
    $event = AnimalEvent::query()->where('kind', 'weight')->sole();

    eventsOn(animalTagged('A1'))
        ->callTableAction('edit', $event, ['event_date' => daysFromToday(-3), 'value' => 412, 'note' => 'scale'])
        ->assertHasNoTableActionErrors();

    $event->refresh();
    expect($event->value)->toBe(412.0)->and($event->event_date->toDateString())->toBe(daysFromToday(-3))
        ->and($event->note)->toBe('scale');
    expect(AuditLog::query()->where('action', 'kudde.event.corrected')->sole()->before)
        ->toEqual(['event_date' => recently(), 'value' => 41, 'note' => '']);
});

it('keeps a correction to the fields of its kind', function () {
    addAnimal('A1');
    recordEvent('A1', 'weight', ['value' => 300])->assertOk();
    $event = AnimalEvent::query()->where('kind', 'weight')->sole();

    app(Herd::class)->correctEvent($event, ['product' => 'Dectomax', 'withdrawal_days' => 30, 'kind' => 'sale']);

    expect($event->refresh()->product)->toBe('')->and($event->withdrawal_days)->toBeNull()->and($event->kind)->toBe('weight')
        ->and(AuditLog::query()->where('action', 'kudde.event.corrected')->exists())->toBeFalse();
});

it('brings back an animal whose sale is removed', function () {
    addAnimal('A1');
    recordEvent('A1', 'sale')->assertOk();
    expect(animalTagged('A1')->status)->toBe(Animal::SOLD);

    eventsOn(animalTagged('A1'))->callTableAction('remove', AnimalEvent::query()->where('kind', 'sale')->sole());

    expect(animalTagged('A1')->status)->toBe(Animal::ALIVE)
        ->and(AnimalEvent::query()->where('kind', 'sale')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'kudde.event.removed')->sole()->before)->toMatchArray(['kind' => 'sale']);
});

it('leaves a removed sale\'s animal dead if it also died', function () {
    addAnimal('A1');
    recordEvent('A1', 'death', ['event_date' => daysFromToday(-10)])->assertOk();
    recordEvent('A1', 'sale')->assertOk();

    app(Herd::class)->removeEvent(AnimalEvent::query()->where('kind', 'sale')->sole());

    expect(animalTagged('A1')->status)->toBe(Animal::DEAD);
});

it('keeps a birth that holds a litter', function () {
    Species::query()->where('code', 'pigs')->update(['enabled' => true]);
    addAnimal('S1', ['species' => 'pigs']);
    $this->postJson(kapi('/calvings'), ['dam_tag' => 'S1', 'species' => 'pigs', 'event_date' => recently(),
        'litter' => ['born_alive' => 9]])->assertOk();

    expect(fn () => app(Herd::class)->removeEvent(AnimalEvent::query()->where('kind', 'birth')->sole()))
        ->toThrow(HerdException::class);
});

it('lets a viewer read the history but correct nothing', function () {
    addAnimal('A1');
    recordEvent('A1', 'weight', ['value' => 300])->assertOk();
    $this->actingAs(User::factory()->role(User::VIEWER)->create());

    eventsOn(animalTagged('A1'))->assertTableActionHidden('edit', AnimalEvent::query()->sole())
        ->assertTableActionHidden('remove', AnimalEvent::query()->sole());
    Livewire::test(ViewAnimal::class, ['record' => animalTagged('A1')->id])->assertActionHidden('correct');
});
