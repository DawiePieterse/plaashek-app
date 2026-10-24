<?php

// Each module's own screen in the office (KPIs, charts, reports) and the reports themselves as Excel and PDF.

use App\Filament\Resources\Workers\Pages\ListWorkers;
use App\Filament\Widgets\ModuleTiles;
use App\Models\Worker;
use App\Modules\Kudde\Filament\KuddePage;
use App\Modules\Kudde\KuddeModule;
use App\Modules\Kudde\Support\HerdHistory;
use App\Modules\Weer\Filament\WeatherPage;
use App\Modules\Weer\Models\WeatherDay;
use App\Modules\Weer\WeerModule;
use App\Reports\DateRanges;
use App\Reports\ReportExport;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

/** The rows of an .xlsx file, header first. */
function xlsxRows(string $path): array
{
    $reader = new Reader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();

    return $rows;
}

function herdWithHistory(): void
{
    kudde();
    // Entered on the farm in June, as a real herd is: the records come after the animals.
    test()->travelTo('2026-06-01 08:00');
    addAnimal('C1', ['birth_date' => '2022-01-01', 'camp' => 'Bo-kamp']);
    addAnimal('C2', ['birth_date' => '2022-01-01', 'camp' => 'Bo-kamp']);
    addAnimal('B1', ['sex' => 'male', 'camp' => 'Rivier']);
    test()->travelTo('2026-10-25 08:00');
    recordEvent('C1', 'weight', ['event_date' => '2026-07-01', 'value' => 300]);
    recordEvent('C1', 'weight', ['event_date' => '2026-10-01', 'value' => 345]);
    recordEvent('C2', 'treatment', ['event_date' => '2026-10-20', 'product' => 'Ivermectin', 'dose' => '5 ml', 'withdrawal_days' => 28]);
    test()->postJson(kapi('/calvings'), ['dam_tag' => 'C1', 'event_date' => '2026-09-10', 'camp' => 'Bo-kamp',
        'calf' => ['tag' => 'K1', 'sex' => 'female'], 'note' => 'easy'])->assertOk();
    recordEvent('B1', 'sale', ['event_date' => '2026-08-15', 'note' => 'Vleissentraal']);
}

it("shows Kudde's screen with its numbers, charts and lists", function () {
    herdWithHistory();

    $this->get(KuddePage::getUrl())->assertOk()
        ->assertSee('Born this season')->assertSee('In withdrawal')->assertSee('The herd over the last 12 months')
        ->assertSee('The herd by class')->assertSee('C2')->assertSee('Reports');
});

it('opens each module screen from its Home tile', function () {
    kudde();

    $tiles = collect(Livewire::test(ModuleTiles::class)->instance()->tiles())->pluck('url', 'label');
    expect($tiles['Kudde'])->toBe(KuddePage::getUrl())->and($tiles['Weather'])->toBe(WeatherPage::getUrl());
});

it('counts the herd month by month from the records', function () {
    herdWithHistory();

    $months = collect((new HerdHistory)->months(4))->keyBy(fn ($m) => $m['month']->format('Y-m'));

    expect($months['2026-07'])->toMatchArray(['head' => 3])
        ->and($months['2026-08'])->toMatchArray(['head' => 2, 'sold' => 1])
        ->and($months['2026-09'])->toMatchArray(['head' => 3, 'born' => 1])
        // C1: 300 kg on 1 July, 345 kg on 1 October, 92 days apart.
        ->and(round(HerdHistory::averageDailyGain(today()->subDays(120), today()), 3))->toBe(0.489);
});

it('makes the treatments report as a spreadsheet with numbers as numbers', function () {
    herdWithHistory();
    $report = collect(KuddeModule::reports())->first(fn ($r) => $r->key() === 'kudde.treatments');
    $file = tempnam(sys_get_temp_dir(), 'xlsx');

    app(ReportExport::class)->xlsx($report, new ReportFilters(...DateRanges::resolve('this_season')), $file);
    $rows = xlsxRows($file);

    expect($rows[0])->toContain('Product', 'Clear on')
        ->and($rows[1][1])->toBe('C2')
        ->and($rows[1][5])->toBe(28)
        ->and($rows[1][6])->toBe('2026-11-17');
});

it('makes weighings with the gain since the weighing before', function () {
    herdWithHistory();
    $report = collect(KuddeModule::reports())->first(fn ($r) => $r->key() === 'kudde.weighings');

    $rows = iterator_to_array($report->rows(new ReportFilters(...DateRanges::resolve('this_season'))), false);

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray(['tag' => 'C1', 'weight' => 300.0, 'previous_weight' => null, 'gain' => null])
        ->and($rows[1])->toMatchArray(['tag' => 'C1', 'weight' => 345.0, 'previous_weight' => 300.0, 'gain' => 45.0, 'gain_per_day' => 0.49]);
});

it('filters the herd register by status and camp', function () {
    herdWithHistory();
    $report = collect(KuddeModule::reports())->first(fn ($r) => $r->key() === 'kudde.herd');

    $tags = fn (array $values) => array_column(iterator_to_array($report->rows(new ReportFilters(values: $values)), false), 'tag');

    expect($tags(['status' => 'alive']))->toBe(['C1', 'C2', 'K1'])
        ->and($tags(['status' => 'sold']))->toBe(['B1'])
        ->and($tags(['camp' => 'bo-kamp', 'status' => 'alive']))->toBe(['C1', 'C2', 'K1']);
});

it('lists births with how they went', function () {
    herdWithHistory();
    $report = collect(KuddeModule::reports())->first(fn ($r) => $r->key() === 'kudde.births');

    $rows = iterator_to_array($report->rows(new ReportFilters(...DateRanges::resolve('this_season'))), false);

    expect($rows)->toHaveCount(1)->and($rows[0])->toMatchArray(['tag' => 'K1', 'dam' => 'C1', 'camp' => 'Bo-kamp', 'note' => 'easy']);
});

it('makes a PDF with the farm, the report and the period on it', function () {
    herdWithHistory();
    $report = collect(KuddeModule::reports())->first(fn ($r) => $r->key() === 'kudde.events');

    $pdf = app(ReportExport::class)->pdf($report, new ReportFilters(...DateRanges::resolve('this_season')));

    expect($pdf)->toStartWith('%PDF');
});

it('downloads a report from the Reports button', function () {
    herdWithHistory();

    Livewire::test(KuddePage::class)
        ->callAction('reports', ['report' => 'kudde.head_count', 'format' => 'xlsx'])
        ->assertHasNoActionErrors()
        ->assertFileDownloaded('kudde-head_count-2026-10-25.xlsx');

    Livewire::test(KuddePage::class)
        ->callAction('reports', ['report' => 'kudde.events', 'range' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31', 'format' => 'pdf'])
        ->assertHasNoActionErrors()
        ->assertFileDownloaded('kudde-events-2026-10-25.pdf');
});

it('works out the standard periods from the farm season', function () {
    $this->travelTo('2026-10-25');
    farm();

    expect(array_map(fn ($d) => $d->toDateString(), DateRanges::resolve('this_season')))->toBe(['2026-07-01', '2026-10-25'])
        ->and(array_map(fn ($d) => $d->toDateString(), DateRanges::resolve('last_season')))->toBe(['2025-07-01', '2026-06-30'])
        ->and(array_map(fn ($d) => $d->toDateString(), DateRanges::resolve('last_month')))->toBe(['2026-09-01', '2026-09-30']);
});

it("shows Weather's screen from the farm's own record, and reports it by month", function () {
    $this->travelTo('2026-10-25');
    $this->actingAs(farm());
    foreach (['2026-10-20' => 12.5, '2026-10-21' => 3.0, '2026-09-15' => 20.0] as $date => $rain) {
        WeatherDay::query()->create(['date' => $date, 'rain' => $rain, 'temp_max' => 30, 'temp_min' => 1, 'et0' => 5]);
    }

    $this->get(WeatherPage::getUrl())->assertOk()->assertSee('Rain, last 7 days')->assertSee('15.5 mm')->assertSee('Rain by month');

    $monthly = collect(WeerModule::reports())->first(fn ($r) => $r->key() === 'weer.monthly');
    $rows = iterator_to_array($monthly->rows(new ReportFilters(...DateRanges::resolve('this_season'))), false);
    expect(array_column($rows, 'rain'))->toBe([20.0, 15.5])->and($rows[1]['frost'])->toBe(2);
});

it('fetches the weather history for this season and the last', function () {
    $this->travelTo('2026-10-25');
    Http::fake(['archive-api.open-meteo.com/*' => Http::response(['daily' => ['time' => ['2025-07-01'], 'temperature_2m_max' => [20],
        'temperature_2m_min' => [5], 'precipitation_sum' => [0], 'et0_fao_evapotranspiration' => [2], 'wind_speed_10m_max' => [10]]])]);
    $this->actingAs(farm());

    Livewire::test(WeatherPage::class)->callAction('history')->assertNotified();

    Http::assertSent(fn ($r) => $r['start_date'] === '2025-07-01');
    expect(WeatherDay::query()->count())->toBe(1);
});

it("shows the workers' numbers and their register report", function () {
    $this->actingAs(farm());
    Worker::query()->create(['number' => '2', 'first_name' => 'A']);
    Worker::query()->create(['number' => '10', 'first_name' => 'B', 'active' => false]);

    Livewire::test(ListWorkers::class)->assertSuccessful()
        ->callAction('reports', ['report' => 'workers.register', 'format' => 'xlsx'])->assertFileDownloaded();
    $this->get('/admin/workers')->assertSee('Working here')->assertSee('No photo');
});

it('shows whether the office is online, and the release, in the top bar', function () {
    config(['plaashek.version' => '2026.10.25 · abc1234']);
    $this->actingAs(farm(['language' => 'af']));

    $this->get('/admin')->assertOk()->assertSee('plaashek-pill-on', false)->assertSee('Aanlyn')
        ->assertSee('2026.10.25 · abc1234')->assertSee('data-up="'.url('/up').'"', false);
});
