<?php

// The office herd list's derived columns and filters: camp, class, withdrawal and weighing come from HerdState.

use App\Models\Camp;
use App\Modules\Kudde\Filament\Resources\Animals\Pages\ListAnimals;
use App\Modules\Kudde\Models\Animal;
use Livewire\Livewire;

beforeEach(fn () => kudde());

/** @return list<Animal> */
function herdOf(string ...$tags): array
{
    // In the order asked, so a sort can be checked with it.
    return Animal::query()->whereIn('tag', $tags)->get()->sortBy(fn (Animal $a) => array_search($a->tag, $tags, true))->values()->all();
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

it('sorts by last weight, withdrawal and camp, the animals without one last either way', function () {
    smallHerd();
    addAnimal('D4');
    recordEvent('A1', 'treatment', ['product' => 'Dectomax', 'withdrawal_days' => 60])->assertOk();
    recordEvent('B2', 'treatment', ['product' => 'Ivomec', 'withdrawal_days' => 20])->assertOk();
    moveTo(['D4'], 'Bo-kamp');
    moveTo(['A1'], 'Rivier');
    // The farm's order, not the alphabet's: Rivier before Bo-kamp.
    Camp::query()->where('name', 'Rivier')->update(['sort_order' => 0]);
    Camp::query()->where('name', 'Bo-kamp')->update(['sort_order' => 1]);

    Livewire::test(ListAnimals::class)
        ->sortTable('last_weight')->assertCanSeeTableRecords(herdOf('A1', 'B2', 'D4'), inOrder: true)
        ->sortTable('last_weight', 'desc')->assertCanSeeTableRecords(herdOf('B2', 'A1', 'D4'), inOrder: true)
        ->sortTable('withdrawal_until')->assertCanSeeTableRecords(herdOf('B2', 'A1', 'D4'), inOrder: true)
        ->sortTable('withdrawal_until', 'desc')->assertCanSeeTableRecords(herdOf('A1', 'B2', 'D4'), inOrder: true)
        ->sortTable('camp')->assertCanSeeTableRecords(herdOf('A1', 'D4', 'B2'), inOrder: true)
        ->sortTable('camp', 'desc')->assertCanSeeTableRecords(herdOf('D4', 'A1', 'B2'), inOrder: true);
});
