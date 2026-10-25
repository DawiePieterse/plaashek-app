<?php

// The herd list in the office (phase 5): the herd as HerdState derives it, searched, filtered and sorted.

use App\Models\User;
use App\Modules\Kudde\Filament\HerdPage;
use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\Species;
use App\Modules\ModuleRegistry;
use Livewire\Livewire;

beforeEach(fn () => kudde());

function herdKeys(string ...$tags): array
{
    return Animal::query()->whereIn('tag', $tags)->get()
        ->sortBy(fn (Animal $a) => array_search($a->tag, $tags, true))->values()->all();
}

it('lists the alive herd in tag order, as the farmer reads tags', function () {
    foreach (['B10', 'B2', 'A1'] as $tag) {
        addAnimal($tag);
    }
    addAnimal('C3');
    recordEvent('C3', 'sale')->assertOk();

    $this->get(HerdPage::getUrl())->assertOk()->assertSee('B10');
    Livewire::test(HerdPage::class)
        ->assertCanSeeTableRecords(herdKeys('A1', 'B2', 'B10'), inOrder: true)
        ->assertCanNotSeeTableRecords(herdKeys('C3'))
        ->filterTable('status', Animal::SOLD)
        ->assertCanSeeTableRecords(herdKeys('C3'))
        ->assertCanNotSeeTableRecords(herdKeys('A1'));
});

it('shows the derived camp, class, last weight and withdrawal', function () {
    smallHerd();
    moveTo(['A1'], 'Rivier');
    recordEvent('B2', 'treatment', ['product' => 'Dectomax', 'withdrawal_days' => 35])->assertOk();

    Livewire::test(HerdPage::class)
        ->assertTableColumnStateSet('camp', 'Rivier', (string) Animal::query()->where('tag', 'A1')->value('id'))
        ->assertTableColumnStateSet('last_weight', 640, (string) Animal::query()->where('tag', 'B2')->value('id'))
        ->assertTableColumnStateSet('class_label', 'Bull', (string) Animal::query()->where('tag', 'B2')->value('id'))
        ->filterTable('in_withdrawal', true)
        ->assertCanSeeTableRecords(herdKeys('B2'))
        ->assertCanNotSeeTableRecords(herdKeys('A1'));
});

it('searches by tag, name and breed, ignoring capitals', function () {
    smallHerd();

    Livewire::test(HerdPage::class)
        ->searchTable('nguni')->assertCanSeeTableRecords(herdKeys('A1'))->assertCanNotSeeTableRecords(herdKeys('B2'))
        ->searchTable('b2')->assertCanSeeTableRecords(herdKeys('B2'))->assertCanNotSeeTableRecords(herdKeys('A1'));
});

it('filters by camp and by animals due a weighing', function () {
    smallHerd();
    moveTo(['B2'], 'Rivier');

    Livewire::test(HerdPage::class)
        ->filterTable('camp', 'rivier')->assertCanSeeTableRecords(herdKeys('B2'))->assertCanNotSeeTableRecords(herdKeys('A1'))
        ->resetTableFilters()
        ->filterTable('needs_weighing', true)->assertCanSeeTableRecords(herdKeys('A1'))->assertCanNotSeeTableRecords(herdKeys('B2'));
});

it('sorts by last weight, the never weighed last either way', function () {
    smallHerd();
    addAnimal('D4');

    Livewire::test(HerdPage::class)
        ->sortTable('last_weight')->assertCanSeeTableRecords(herdKeys('A1', 'B2', 'D4'), inOrder: true)
        ->sortTable('last_weight', 'desc')->assertCanSeeTableRecords(herdKeys('B2', 'A1', 'D4'), inOrder: true);
});

it('names the species and filters by it once the farm keeps more than one', function () {
    Species::query()->whereIn('code', ['cattle', 'sheep'])->update(['enabled' => true]);
    addAnimal('001', ['species' => 'cattle']);
    addAnimal('001', ['species' => 'sheep']);
    [$cow, $ewe] = [Animal::query()->whereRelation('species', 'code', 'cattle')->sole(), Animal::query()->whereRelation('species', 'code', 'sheep')->sole()];

    Livewire::test(HerdPage::class)
        ->assertTableColumnVisible('species_name')
        ->assertTableColumnStateSet('class_label', 'Sheep: ewe', (string) $ewe->id)
        ->filterTable('species', 'sheep')
        ->assertCanSeeTableRecords([$ewe])->assertCanNotSeeTableRecords([$cow]);
});

it('is there for every office role while Kudde is on, and gone when it is off', function () {
    $this->actingAs(User::factory()->role(User::VIEWER)->create());
    $this->get(HerdPage::getUrl())->assertOk();

    app(ModuleRegistry::class)->setEnabled([]);
    expect(HerdPage::canAccess())->toBeFalse();
});
