<?php

// The species and their rules are the farm's to set, under Kudde's settings (owner's call, phase 4b).

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Kudde\Filament\Resources\Species\Pages\CreateSpecies;
use App\Modules\Kudde\Filament\Resources\Species\Pages\EditSpecies;
use App\Modules\Kudde\Filament\Resources\Species\Pages\ListSpecies;
use App\Modules\Kudde\Filament\Resources\Species\SpeciesResource;
use App\Modules\Kudde\Models\Species;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(farm(['modules' => ['kudde']])));

function species(string $code): Species
{
    return Species::query()->where('code', $code)->firstOrFail();
}

it('lists the species under Kudde settings, for the owner only', function () {
    $this->get(SpeciesResource::getUrl())->assertOk()->assertSee('Sheep')->assertSee('Pigs');

    $this->actingAs(User::factory()->role(User::MANAGER)->create());
    expect(SpeciesResource::canViewAny())->toBeFalse();
});

it('switches a species on, and never the last one off', function () {
    Livewire::test(ListSpecies::class)->call('updateTableColumnState', 'enabled', (string) species('sheep')->id, true);
    expect(species('sheep')->enabled)->toBeTrue();

    Livewire::test(EditSpecies::class, ['record' => species('sheep')->id])->set('data.enabled', false)->call('save')->assertHasNoErrors();
    Livewire::test(EditSpecies::class, ['record' => species('cattle')->id])->set('data.enabled', false)->call('save')
        ->assertHasErrors(['data.enabled']);
    expect(species('cattle')->enabled)->toBeTrue();
});

it('saves a species\' rules and class names, and the screens follow', function () {
    Livewire::test(EditSpecies::class, ['record' => species('cattle')->id])
        ->set('data.class_names.en.young_female', 'heifer weaner')
        ->set('data.young_until_days', 240)
        ->set('data.weigh_every_days', 60)
        ->call('save')->assertHasNoErrors();

    $cattle = species('cattle');
    expect($cattle->className('young_female'))->toBe('heifer weaner')
        ->and($cattle->className('mother'))->toBe('cow')
        ->and($cattle->young_until_days)->toBe(240)
        ->and($cattle->weighEveryDays())->toBe(60)
        ->and(AuditLog::query()->where('action', 'kudde.species.updated')->exists())->toBeTrue();
});

it('lets the farm add a species of its own', function () {
    $names = [];
    foreach (Species::ROLES as $role) {
        $names['en'][$role] = 'horse '.$role;
        $names['af'][$role] = 'perd '.$role;
    }

    Livewire::test(CreateSpecies::class)->fillForm([
        'name_en' => 'Horses', 'name_af' => 'Perde', 'birth_word' => ['en' => 'foaling', 'af' => 'vul'],
        'enabled' => true, 'young_until_days' => 730, 'gestation_days' => 340, 'class_names' => $names,
    ])->call('create')->assertHasNoErrors();

    $horses = species('horses');
    expect($horses->enabled)->toBeTrue()->and($horses->sort_order)->toBe(4)->and($horses->weigh_every_days)->toBeNull();
    $this->postJson('/kudde/api/animals', ['tag' => 'H1', 'sex' => 'male', 'species' => 'horses'])
        ->assertOk()->assertJsonPath('class', 'horse male');
});
