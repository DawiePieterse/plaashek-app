<?php

// The office's Home page (a tile per module with its number), the top bar (farm, weather, release), and each
// module's own settings screen beside Farm settings.

use App\Filament\Pages\FarmSettings;
use App\Filament\Pages\Home;
use App\Filament\Widgets\ModuleTiles;
use App\Models\Camp;
use App\Models\User;
use App\Models\Worker;
use App\Modules\Kudde\Filament\KuddeSettingsPage;
use App\Modules\Kudde\Filament\Resources\Camps\CampResource;
use App\Modules\Kudde\Filament\Resources\Camps\Pages\ListCamps;
use App\Modules\Kudde\Support\KuddeSettings;
use App\Modules\ModuleRegistry;
use App\Modules\Weer\Filament\WeatherSettings;
use App\Modules\Weer\Models\WeatherDay;
use App\Support\Farm;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('shows a tile per module with its number, and none for a module the farm does not have', function () {
    $this->actingAs(farm(['modules' => ['kudde']]));
    Worker::query()->create(['number' => '1', 'first_name' => 'A']);
    Worker::query()->create(['number' => '2', 'first_name' => 'B', 'active' => false]);
    WeatherDay::query()->create(['date' => today()->subDay(), 'rain' => 5.5]);
    WeatherDay::query()->create(['date' => today()->subDays(3), 'rain' => 2.1]);
    addAnimal('A1');
    addAnimal('A2');
    recordEvent('A2', 'sale');

    $tiles = collect(Livewire::test(ModuleTiles::class)->instance()->tiles())->keyBy('label');

    expect($tiles->keys()->all())->toBe(['Workers', 'Weather', 'Kudde'])
        ->and([$tiles['Workers']->value, $tiles['Workers']->caption])->toBe(['1', 'active'])
        ->and($tiles['Weather']->value)->toBe('7.6 mm')
        ->and([$tiles['Kudde']->value, $tiles['Kudde']->caption])->toBe(['1', 'alive']);

    app(ModuleRegistry::class)->setEnabled([]);
    $tiles = collect(Livewire::test(ModuleTiles::class)->instance()->tiles())->pluck('label');
    expect($tiles->all())->toBe(['Workers', 'Weather']);
});

it('calls the first page Home, in the farm language', function () {
    $this->actingAs(farm(['language' => 'af']));

    $this->get(Home::getUrl())->assertOk()->assertSee('Tuis')->assertSee('aktief')->assertDontSee('Dashboard');
});

it('puts the farm, its weather and the release in the top bar', function () {
    config(['plaashek.version' => '2026.10.25 · abc1234']);
    $this->actingAs(farm(['name' => 'Bekfontein']));

    $this->get(Home::getUrl())->assertOk()
        ->assertSee('plaashek-topbar-farm', false)->assertSee('Bekfontein')
        ->assertSee(route('weer.now', absolute: false), false)
        ->assertSee('2026.10.25 · abc1234');
});

it("answers the weather now from the farm's forecast, in the farm language", function () {
    Http::fake(['api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => 21.4, 'weather_code' => 0, 'is_day' => 0]])]);
    $this->actingAs(farm(['language' => 'af']));

    $this->getJson(route('weer.now'))->assertOk()->assertExactJson(['temp' => 21, 'text' => 'Helder', 'icon' => '🌙']);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'current=temperature_2m'));
});

it('shows no weather rather than an error when there is none', function () {
    Http::fake(['*' => Http::response('down', 500)]);
    $this->actingAs(farm(['latitude' => null, 'longitude' => null]));

    $this->getJson(route('weer.now'))->assertNoContent();
});

it("keeps Weather's season and frost on Weather's own settings screen", function () {
    $owner = farm();
    $this->actingAs($owner);

    Livewire::test(WeatherSettings::class)
        ->assertSet('data.season_start_month', '7')
        ->set('data.season_start_month', '9')
        ->set('data.frost_threshold', '1.5')
        ->call('save')->assertHasNoErrors();

    expect(app(Farm::class)->seasonStartMonth())->toBe(9)
        ->and(app(Farm::class)->frostThreshold())->toBe(1.5);
    $this->assertDatabaseHas('audit_log', ['action' => 'settings.changed', 'subject' => 'weer', 'user_id' => $owner->id]);
    $this->get(FarmSettings::getUrl())->assertOk()->assertDontSee('Frost below');
});

it("sets how often Kudde asks for a weighing, on Kudde's settings screen", function () {
    $this->actingAs(farm(['modules' => ['kudde']]));
    addAnimal('A1');
    recordEvent('A1', 'weight', ['event_date' => daysFromToday(-100), 'value' => 300]);
    expect(test()->getJson(kapi('/dashboard'))->json('needs_weighing'))->toBe([]);

    Livewire::test(KuddeSettingsPage::class)->set('data.weigh_every_days', '90')->call('save')->assertHasNoErrors();

    expect(KuddeSettings::weighEveryDays())->toBe(90)
        ->and(array_column(test()->getJson(kapi('/dashboard'))->json('needs_weighing'), 'tag'))->toBe(['A1']);
    $this->get('/kudde/field')->assertSee('"weighEveryDays":90', false);
    Livewire::test(KuddeSettingsPage::class)->set('data.weigh_every_days', '3')->call('save')->assertHasErrors();
});

it("shows a module's settings only to the owner, and only while the farm has the module", function () {
    farm(['modules' => []]);
    $this->actingAs(User::query()->where('role', User::OWNER)->firstOrFail());
    expect(KuddeSettingsPage::canAccess())->toBeFalse()->and(WeatherSettings::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->role(User::MANAGER)->create());
    expect(WeatherSettings::canAccess())->toBeFalse();
});

it('shows the field app address as a QR code on Kudde settings', function () {
    $this->actingAs(farm(['modules' => ['kudde']]));

    $this->get(KuddeSettingsPage::getUrl())->assertOk()->assertSee(route('kudde.field'))->assertSee('<svg', false);
});

it('keeps the camps under Kudde: one name whatever its capitals, never archived while holding animals', function () {
    $this->actingAs(farm(['modules' => ['kudde']]));

    Livewire::test(ListCamps::class)->callAction('create', ['name' => ' Bo-kamp '])->assertHasNoActionErrors();
    Livewire::test(ListCamps::class)->callAction('create', ['name' => 'bo-KAMP'])->assertHasActionErrors(['name']);
    $camp = Camp::query()->sole();
    expect($camp->name)->toBe('Bo-kamp');

    addAnimal('A1', ['camp' => 'Bo-kamp']);
    Livewire::test(ListCamps::class)
        ->assertSee('1 animal')
        ->callTableAction('archive', $camp);
    expect($camp->refresh()->archived)->toBeFalse();

    recordEvent('A1', 'sale');
    Livewire::test(ListCamps::class)->callTableAction('archive', $camp);
    expect($camp->refresh()->archived)->toBeTrue();

    // Adding an archived name brings that camp back rather than making a second one.
    Livewire::test(ListCamps::class)->callAction('create', ['name' => 'BO-KAMP'])->assertHasNoActionErrors();
    expect(Camp::query()->count())->toBe(1)->and($camp->refresh()->archived)->toBeFalse();
});

it('groups Farm settings, workers and each module under Settings, with the camps under Kudde', function () {
    $this->actingAs(farm(['modules' => ['kudde']]));

    $this->get(Home::getUrl())->assertOk()
        ->assertSeeInOrder(['Settings', 'The farm', 'Workers', 'Weather', 'Kudde']);
    // Opened from Kudde's settings, and nested under it in the menu while there.
    $this->get(KuddeSettingsPage::getUrl())->assertOk()->assertSee('Manage camps')
        ->assertSee(CampResource::getUrl());
    $this->get(CampResource::getUrl())->assertOk()
        ->assertSeeInOrder(['Kudde', 'Camps']);
});

it('has no camps screen for a farm without Kudde', function () {
    $this->actingAs(farm(['modules' => []]));

    expect(CampResource::canViewAny())->toBeFalse();
    $this->get('/admin/settings/kudde/camps')->assertForbidden();
    $this->get(Home::getUrl())->assertDontSee('Camps');
});
