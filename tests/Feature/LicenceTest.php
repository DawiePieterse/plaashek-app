<?php

// A paid module's licence (PLAN.md section 6): set by hand with farm:licence; grace saves 14 days, then read-only;
// suspended is read-only; cancelled is off. Free modules have none.

use App\Filament\Pages\FarmSettings;
use App\Models\Module;
use App\Models\Worker;
use App\Modules\Kudde\Filament\Resources\Animals\AnimalResource;
use App\Modules\ModuleRegistry;

function registry(): ModuleRegistry
{
    // A fresh one each time: the registry reads the table once per request.
    app()->forgetInstance(ModuleRegistry::class);

    return app(ModuleRegistry::class);
}

beforeEach(fn () => kudde());

it('starts a module switched on as active, and one switched off as cancelled', function () {
    $kudde = Module::query()->find('kudde');
    expect($kudde->licence)->toBe(Module::ACTIVE)->and($kudde->licence_since->toDateString())->toBe(today()->toDateString());

    $this->artisan('farm:modules', ['codes' => ''])->expectsOutputToContain('cancelled')->assertSuccessful();
    expect(Module::query()->find('kudde')->licence)->toBe(Module::CANCELLED)
        ->and(Module::query()->find('weer')->licence)->toBeNull();
});

it('refuses a free module, an unknown one and an unknown status', function () {
    $this->artisan('farm:licence', ['module' => 'weer', 'status' => 'suspended'])->assertFailed();
    $this->artisan('farm:licence', ['module' => 'boord', 'status' => 'active'])->assertFailed();
    $this->artisan('farm:licence', ['module' => 'kudde', 'status' => 'paid'])->assertFailed();

    expect(registry()->isWritable('weer'))->toBeTrue();
});

it('keeps saving through 14 days of grace, then turns read-only', function () {
    $this->artisan('farm:licence', ['module' => 'kudde', 'status' => 'grace'])
        ->expectsOutputToContain('grace until '.today()->addDays(14)->toDateString())->assertSuccessful();
    $this->assertDatabaseHas('audit_log', ['action' => 'licence.changed', 'subject' => 'kudde']);

    $this->travel(5)->days();
    // Set again: the grace is not stretched.
    $this->artisan('farm:licence', ['module' => 'kudde', 'status' => 'grace'])->assertSuccessful();
    expect(Module::query()->find('kudde')->grace_until->toDateString())->toBe(today()->addDays(9)->toDateString());

    $this->travel(9)->days();
    expect(registry()->isWritable('kudde'))->toBeTrue();
    $this->travel(1)->days();
    expect(registry()->isWritable('kudde'))->toBeFalse()->and(registry()->isEnabled('kudde'))->toBeTrue();
});

it('lets a suspended herd be read and exported, but saves nothing, from the office or a phone', function () {
    addAnimal('A1');
    $this->artisan('farm:licence', ['module' => 'kudde', 'status' => 'suspended'])->assertSuccessful();
    registry();

    $this->getJson(kapi('/animals'))->assertOk()->assertJsonCount(1);
    $this->get(kapi('/export/animals.csv'))->assertOk();
    $this->postJson(kapi('/animals'), ['tag' => 'B2', 'sex' => 'male'])->assertStatus(423)
        ->assertJsonPath('message', __('kudde::ui.read_only'));
    recordEvent('A1', 'weight', ['value' => 300])->assertStatus(423);
    expect(AnimalResource::mayCorrect())->toBeFalse();

    Worker::query()->create(['number' => '001', 'first_name' => 'Jan', 'last_name' => 'Mathebula']);
    $token = $this->postJson('/kudde/field/api/sign-in', ['number' => '001'])->assertOk()->json('token');
    $this->postJson('/kudde/field/api/events', ['tag' => 'A1', 'kind' => 'weight', 'event_date' => recently(), 'value' => 300],
        ['Authorization' => "Bearer {$token}"])->assertStatus(423);

    $this->artisan('farm:licence', ['module' => 'kudde', 'status' => 'active'])->assertSuccessful();
    registry();
    recordEvent('A1', 'weight', ['value' => 300])->assertOk();
});

it('switches a cancelled module off, its records kept', function () {
    addAnimal('A1');
    $this->artisan('farm:licence', ['module' => 'kudde', 'status' => 'cancelled'])->assertSuccessful();

    expect(registry()->isEnabled('kudde'))->toBeFalse();
    $this->getJson(kapi('/animals'))->assertNotFound();
    $this->assertDatabaseHas('kudde_animals', ['tag' => 'A1']);
});

it('shows the owner each paid module\'s licence on Farm settings', function () {
    $this->artisan('farm:licence', ['module' => 'kudde', 'status' => 'grace'])->assertSuccessful();

    $this->get(FarmSettings::getUrl())->assertOk()->assertSee('Licences')->assertSee('payment due')
        ->assertSee('saving until '.today()->addDays(14)->toDateString());
});

it('shows no licences to a farm that never had a paid module', function () {
    // As farm:create leaves a farm set up without Kudde: no row with a licence.
    Module::query()->where('code', 'kudde')->update(['enabled' => false, 'licence' => null, 'licence_since' => null]);
    registry();

    expect(FarmSettings::licences())->toBe([])->and(Module::query()->find('kudde')?->licence)->toBeNull();
    $this->get(FarmSettings::getUrl())->assertOk()->assertDontSee('Licences');
});
