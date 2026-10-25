<?php

// The office herd list's derived columns and filters: camp, class, withdrawal and weighing come from HerdState.

use App\Modules\Kudde\Filament\Resources\Animals\Pages\ListAnimals;
use App\Modules\Kudde\Models\Animal;
use Livewire\Livewire;

beforeEach(fn () => kudde());

/** @return list<Animal> */
function herdOf(string ...$tags): array
{
    return Animal::query()->whereIn('tag', $tags)->get()->all();
}

it('shows the withdrawal and the date of the last weighing', function () {
    smallHerd();
    recordEvent('B2', 'treatment', ['product' => 'Dectomax', 'withdrawal_days' => 35])->assertOk();
    [$b2] = herdOf('B2');

    Livewire::test(ListAnimals::class)
        ->assertTableColumnStateSet('withdrawal_until', today()->subDays(7)->addDays(35)->startOfDay(), $b2)
        ->assertTableColumnStateSet('last_weight', 640, $b2);
});

it('filters by camp and by class, both derived', function () {
    smallHerd();
    moveTo(['B2'], 'Rivier');

    Livewire::test(ListAnimals::class)
        ->filterTable('camp', 'rivier')->assertCanSeeTableRecords(herdOf('B2'))->assertCanNotSeeTableRecords(herdOf('A1'))
        ->resetTableFilters()
        ->filterTable('class', 'Bull')->assertCanSeeTableRecords(herdOf('B2'))->assertCanNotSeeTableRecords(herdOf('A1'));
});

it('lists only those in withdrawal, or only those due a weighing', function () {
    smallHerd();
    recordEvent('B2', 'treatment', ['product' => 'Dectomax', 'withdrawal_days' => 35])->assertOk();

    Livewire::test(ListAnimals::class)
        ->filterTable('in_withdrawal', true)->assertCanSeeTableRecords(herdOf('B2'))->assertCanNotSeeTableRecords(herdOf('A1'))
        ->resetTableFilters()
        ->filterTable('needs_weighing', true)->assertCanSeeTableRecords(herdOf('A1'))->assertCanNotSeeTableRecords(herdOf('B2'));
});

it('finds an animal by its breed or its dam', function () {
    smallHerd();
    addAnimal('K1', ['dam_tag' => 'A1']);

    Livewire::test(ListAnimals::class)
        ->searchTable('nguni')->assertCanSeeTableRecords(herdOf('A1'))->assertCanNotSeeTableRecords(herdOf('B2', 'K1'))
        ->searchTable('A1')->assertCanSeeTableRecords(herdOf('A1', 'K1'))->assertCanNotSeeTableRecords(herdOf('B2'));
});
