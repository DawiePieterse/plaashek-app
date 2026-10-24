<?php

// The dashboard and the CSV export (old Kudde's test_dashboard.py and test_export.py).

use App\Modules\Kudde\Http\KuddeApiController;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => kudde());

function needsWeighing(): array
{
    return array_column(test()->getJson(kapi('/dashboard'))->json('needs_weighing'), 'tag');
}

it('answers for an empty herd with zeros, not missing keys', function () {
    $body = $this->getJson(kapi('/dashboard'))->assertOk()->json();

    expect($body['herd_counts'])->toBe(['alive' => 0, 'dead' => 0, 'sold' => 0])
        ->and($body['total_alive'])->toBe(0)
        ->and($body['recent_events'])->toBe([])
        ->and($body['needs_weighing'])->toBe([]);
});

it('counts every status and names the animal on recent events', function () {
    smallHerd();
    $body = $this->getJson(kapi('/dashboard'))->json();

    expect($body['herd_counts'])->toBe(['alive' => 2, 'dead' => 0, 'sold' => 1])
        ->and($body['total_alive'])->toBe(2)
        ->and(collect($body['recent_events'])->firstWhere('kind', 'sale')['tag'])->toBe('C3');
    $dates = array_column($body['recent_events'], 'event_date');
    expect($dates)->toBe(collect($dates)->sortDesc()->values()->all());
});

it('lists the overdue and the never weighed', function () {
    foreach (['A1', 'B2', 'C3'] as $tag) {
        addAnimal($tag);
    }
    recordEvent('A1', 'weight', ['value' => 300]);
    recordEvent('B2', 'weight', ['event_date' => longAgo(), 'value' => 280]);

    expect(needsWeighing())->toBe(['B2', 'C3']);
});

it('compares the weighing cutoff as dates', function () {
    addAnimal('IN');
    addAnimal('OUT');
    recordEvent('IN', 'weight', ['event_date' => daysFromToday(-KuddeApiController::STALE_WEIGHT_DAYS + 1), 'value' => 300]);
    recordEvent('OUT', 'weight', ['event_date' => daysFromToday(-KuddeApiController::STALE_WEIGHT_DAYS - 1), 'value' => 300]);

    expect(needsWeighing())->toBe(['OUT']);
});

it('counts only the latest weighing, and never asks a sold animal for one', function () {
    addAnimal('A1');
    addAnimal('C3');
    recordEvent('A1', 'weight', ['event_date' => longAgo(), 'value' => 250]);
    recordEvent('A1', 'weight', ['value' => 310]);
    recordEvent('C3', 'treatment');
    expect(needsWeighing())->toBe(['C3']);

    recordEvent('C3', 'sale');
    expect(needsWeighing())->toBe([]);
});

it('counts the herd by camp', function () {
    $this->postJson(kapi('/camps'), ['name' => 'Onder']);
    foreach (['A1', 'A2', 'A3'] as $tag) {
        addAnimal($tag);
    }
    moveTo(['A1', 'A2'], 'Bo');

    expect($this->getJson(kapi('/dashboard'))->json('camp_counts'))
        ->toBe([['camp' => 'Onder', 'alive' => 0], ['camp' => 'Bo', 'alive' => 2], ['camp' => null, 'alive' => 1]]);
});

function csvRows(TestResponse $response): array
{
    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=utf-8');
    expect($response->headers->get('content-disposition'))->toContain('attachment');
    $text = $response->streamedContent();
    expect(str_starts_with($text, "\u{FEFF}"))->toBeTrue();

    $lines = array_map('str_getcsv', array_filter(explode("\r\n", substr($text, 3))));
    $header = array_shift($lines);

    return array_map(fn ($line) => array_combine($header, $line), $lines);
}

it('exports animals with their context', function () {
    smallHerd();
    recordEvent('A1', 'movement', ['location' => 'Bo-kamp']);
    $rows = collect(csvRows($this->get(kapi('/export/animals.csv'))))->keyBy('tag');

    expect($rows->keys()->all())->toBe(['A1', 'B2', 'C3'])
        ->and($rows['A1'])->toMatchArray(['name' => 'Bessie', 'breed' => 'Nguni', 'sex' => 'female', 'status' => 'alive',
            'camp' => 'Bo-kamp', 'last_weight_kg' => '310.0', 'class' => 'heifer', 'species' => 'cattle'])
        ->and([$rows['C3']['status'], $rows['C3']['camp']])->toBe(['sold', ''])
        ->and($rows['B2']['last_weight_kg'])->toBe('640.0');
});

it('exports events oldest first, naming the animal on each row', function () {
    smallHerd();
    $rows = csvRows($this->get(kapi('/export/events.csv')));

    expect(array_map(fn ($r) => [$r['tag'], $r['kind']], $rows))->toBe([['A1', 'weight'], ['B2', 'weight'], ['C3', 'sale']])
        ->and($rows[0]['weight_kg'])->toBe('310.0')->and($rows[0]['species'])->toBe('cattle')
        ->and($rows[2]['note'])->toBe('Vleissentraal')
        ->and(array_keys($rows[0]))->toContain('camp', 'product', 'dose', 'withdrawal_days', 'temp_min_c', 'rain_mm', 'recorded_at');
});

it('exports a header only for an empty herd', function () {
    expect(csvRows($this->get(kapi('/export/animals.csv'))))->toBe([])
        ->and(csvRows($this->get(kapi('/export/events.csv'))))->toBe([]);
});
