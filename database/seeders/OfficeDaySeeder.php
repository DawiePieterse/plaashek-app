<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Kudde\Support\Herd;
use App\Services\FarmSetup;
use Illuminate\Database\Seeder;

/**
 * The farm the office browser test works through (tests/e2e/office.e2e.js): Bekfontein with Kudde on, an owner
 * and a viewer, and a small herd with the day's work waiting in it: a weight typed as 41 instead of 412, a move
 * captured twice, a cow in withdrawal and one overdue a weighing. Only ever run against the e2e database.
 */
class OfficeDaySeeder extends Seeder
{
    public const OWNER_EMAIL = 'andre@example.com';

    public const VIEWER_EMAIL = 'kantoor@example.com';

    public const PASSWORD = 'password';

    public function run(FarmSetup $setup): void
    {
        [$owner] = $setup->create([
            'name' => 'Bekfontein', 'language' => 'en', 'owner_name' => 'Andre', 'owner_email' => self::OWNER_EMAIL,
            'owner_password' => self::PASSWORD, 'latitude' => -23.8336, 'longitude' => 30.1636, 'modules' => ['kudde'],
        ]);
        User::query()->create(['name' => 'Kantoor', 'email' => self::VIEWER_EMAIL, 'password' => self::PASSWORD, 'role' => User::VIEWER]);

        $herd = app(Herd::class)->by($owner->id);
        $day = fn (int $ago) => today()->subDays($ago)->toDateString();

        $herd->createAnimal(['tag' => 'A1', 'sex' => 'female', 'name' => 'Bessie', 'breed' => 'Ngnui', 'birth_date' => '2021-09-02']);
        $herd->createAnimal(['tag' => 'A2', 'sex' => 'female', 'name' => 'Daisy', 'breed' => 'Nguni', 'birth_date' => '2022-01-15']);
        $herd->createAnimal(['tag' => 'B10', 'sex' => 'male', 'name' => 'Bul', 'breed' => 'Bonsmara', 'birth_date' => '2020-11-20']);
        $herd->createAnimal(['tag' => 'C3', 'sex' => 'female', 'breed' => 'Nguni', 'birth_date' => '2025-10-01', 'dam_tag' => 'A1', 'sire_tag' => 'B10']);

        // Placed a month ago, as a herd keyed in from the office would be.
        $herd->moveAnimals(['A1', 'A2', 'C3'], $day(30), 'Rivier');
        $herd->moveAnimals(['B10'], $day(30), 'Bo-kamp');

        $herd->recordEvent(['tag' => 'A1', 'kind' => 'weight', 'event_date' => $day(3), 'value' => 41]);
        $herd->recordEvent(['tag' => 'A2', 'kind' => 'weight', 'event_date' => $day(400), 'value' => 380]);
        $herd->recordEvent(['tag' => 'B10', 'kind' => 'weight', 'event_date' => $day(10), 'value' => 690]);
        $herd->recordEvent(['tag' => 'C3', 'kind' => 'weight', 'event_date' => $day(10), 'value' => 160]);
        $herd->treatAnimals(['A2'], $day(2), ['product' => 'Dectomax', 'dose' => '10 ml', 'withdrawal_days' => 35]);
        // The same move twice: a worker pressed Save again with no signal.
        $herd->moveAnimals(['B10'], $day(1), 'Rivier');
        $herd->moveAnimals(['B10'], $day(1), 'Rivier');
    }
}
