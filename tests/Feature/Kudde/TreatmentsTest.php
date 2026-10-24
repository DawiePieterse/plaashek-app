<?php

// Treatments, the withdrawal that follows, and treating a group at once (old Kudde's test_treatments.py and
// test_bulk_treatment.py).

beforeEach(fn () => kudde());

function treat(string $tag, int $daysAgo, array $fields = []): array
{
    return recordEvent($tag, 'treatment', ['event_date' => daysFromToday(-$daysAgo)] + $fields)->assertOk()->json();
}

function withdrawals(): array
{
    return collect(test()->getJson(kapi('/animals'))->json())->pluck('withdrawal_until', 'tag')->all();
}

it('records product, dose and withdrawal', function () {
    addAnimal('A1');

    $e = treat('A1', 7, ['product' => '  Ivermectin ', 'dose' => '5 ml', 'withdrawal_days' => 28, 'note' => 'ticks']);
    expect([$e['product'], $e['dose'], $e['withdrawal_days'], $e['note']])->toBe(['Ivermectin', '5 ml', 28, 'ticks']);

    $e = treat('A1', 7, ['note' => 'dipped']);
    expect([$e['product'], $e['dose'], $e['withdrawal_days']])->toBe(['', '', null]);

    recordEvent('A1', 'treatment', ['withdrawal_days' => -1])->assertStatus(422);
});

it('drops treatment fields from other kinds', function () {
    addAnimal('A1');

    $e = recordEvent('A1', 'weight', ['value' => 300, 'product' => 'Ivermectin', 'withdrawal_days' => 28])->assertOk()->json();

    expect([$e['product'], $e['withdrawal_days']])->toBe(['', null])->and(withdrawals()['A1'])->toBeNull();
});

it('says until when an animal is in withdrawal', function () {
    addAnimal('A1');
    addAnimal('A2');
    addAnimal('A3');
    treat('A1', 10, ['product' => 'X', 'withdrawal_days' => 28]);
    treat('A2', 40, ['product' => 'X', 'withdrawal_days' => 28]);
    treat('A3', 0, ['product' => 'Y', 'withdrawal_days' => 0]);

    expect(withdrawals())->toBe(['A1' => daysFromToday(18), 'A2' => null, 'A3' => daysFromToday(0)]);
});

it('lets the longest withdrawal win', function () {
    addAnimal('A1');
    treat('A1', 1, ['product' => 'Long', 'withdrawal_days' => 60]);
    treat('A1', 0, ['product' => 'Short', 'withdrawal_days' => 7]);

    expect(withdrawals()['A1'])->toBe(daysFromToday(59));
});

it('lists who is in withdrawal on the dashboard, soonest clear first', function () {
    foreach (['B10', 'B2', 'C1', 'D1'] as $tag) {
        addAnimal($tag);
    }
    treat('B10', 0, ['product' => 'X', 'withdrawal_days' => 5]);
    treat('B2', 0, ['product' => 'X', 'withdrawal_days' => 5]);
    treat('C1', 0, ['product' => 'Y', 'withdrawal_days' => 2]);
    treat('D1', 0, ['product' => 'Y', 'withdrawal_days' => 30]);
    recordEvent('D1', 'sale', ['event_date' => daysFromToday(0)]);

    $rows = array_map(fn ($r) => [$r['tag'], $r['until']], $this->getJson(kapi('/dashboard'))->json('in_withdrawal'));
    expect($rows)->toBe([['C1', daysFromToday(2)], ['B2', daysFromToday(5)], ['B10', daysFromToday(5)]]);
});

it('offers the bottles used before, most recent first', function () {
    addAnimal('A1');
    treat('A1', 30, ['product' => 'Ivermectin', 'dose' => '5 ml', 'withdrawal_days' => 28]);
    treat('A1', 20, ['product' => 'Terramycin', 'dose' => '10 ml', 'withdrawal_days' => 21]);
    treat('A1', 10, ['product' => 'ivermectin', 'dose' => '6 ml', 'withdrawal_days' => 28]);
    treat('A1', 0, ['note' => 'dipped']);

    expect($this->getJson(kapi('/products'))->json())->toBe([
        ['product' => 'ivermectin', 'dose' => '6 ml', 'withdrawal_days' => 28],
        ['product' => 'Terramycin', 'dose' => '10 ml', 'withdrawal_days' => 21],
    ]);
});

it('treats a whole group at once', function () {
    foreach (['A1', 'A2', 'A3'] as $tag) {
        addAnimal($tag);
    }

    $events = $this->postJson(kapi('/events/treatment/bulk'), ['tags' => ['A1', 'A2'], 'event_date' => daysFromToday(0),
        'product' => ' Ivermectin ', 'dose' => '5 ml', 'withdrawal_days' => 28, 'note' => 'dosing day'])->assertOk()->json();

    expect(array_map(fn ($e) => [$e['kind'], $e['product'], $e['dose'], $e['withdrawal_days']], $events))
        ->toBe(array_fill(0, 2, ['treatment', 'Ivermectin', '5 ml', 28]))
        ->and(withdrawals())->toBe(['A1' => daysFromToday(28), 'A2' => daysFromToday(28), 'A3' => null])
        ->and($this->getJson(kapi('/products'))->json())->toBe([['product' => 'Ivermectin', 'dose' => '5 ml', 'withdrawal_days' => 28]]);
});

it('records a replayed group treatment once', function () {
    addAnimal('A1');
    addAnimal('A2');
    $body = ['tags' => ['A1', 'A2'], 'event_date' => recently(), 'product' => 'X', 'withdrawal_days' => 7, 'client_uuid' => 'batch-1'];

    $this->postJson(kapi('/events/treatment/bulk'), $body)->assertOk();
    $this->postJson(kapi('/events/treatment/bulk'), $body)->assertOk();

    expect($this->getJson(kapi('/events?kind=treatment'))->json())->toHaveCount(2);
});

it('fails the whole group on one unknown tag', function () {
    addAnimal('A1');

    $this->postJson(kapi('/events/treatment/bulk'), ['tags' => ['A1', 'nobody'], 'event_date' => recently()])->assertNotFound();
    expect($this->getJson(kapi('/animals/A1/events'))->json())->toBe([]);
    $this->postJson(kapi('/events/treatment/bulk'), ['tags' => [], 'event_date' => recently()])->assertStatus(400);
    $this->postJson(kapi('/events/treatment/bulk'), ['tags' => ['A1'], 'event_date' => recently(), 'withdrawal_days' => -1])->assertStatus(422);
});
