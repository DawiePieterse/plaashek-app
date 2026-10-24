<?php

namespace App\Modules\Weer\Console;

use App\Modules\Weer\Models\WeatherDay;
use App\Modules\Weer\OpenMeteoProxy;
use App\Support\Farm;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Fills weather_days for the farm's position: the last fortnight from the forecast API (which covers the
 * days the reanalysis hasn't reached yet), or with --from, everything since that date from the archive.
 * Runs nightly; re-running is harmless, a day is simply overwritten with Open-Meteo's latest figure.
 */
class FillWeatherDays extends Command
{
    protected $signature = 'weer:days {--from= : Backfill from this date (YYYY-MM-DD) using the archive}';

    protected $description = "Store the farm's daily weather in weather_days";

    private const DAILY = 'temperature_2m_max,temperature_2m_min,precipitation_sum,et0_fao_evapotranspiration,wind_speed_10m_max';

    public function handle(OpenMeteoProxy $proxy, Farm $farm): int
    {
        if ($farm->position() === null) {
            $this->warn('The farm has no position yet; nothing to fill.');

            return self::SUCCESS;
        }

        $from = $this->option('from');
        $query = ['daily' => self::DAILY, 'timezone' => 'Africa/Johannesburg'];

        if (is_string($from) && $from !== '') {
            $start = Carbon::parse($from);
            $data = $proxy->json('archive', $query + [
                'start_date' => $start->toDateString(),
                'end_date' => today()->subDays(6)->toDateString(),
            ]);
        } else {
            $data = $proxy->json('fc', $query + ['past_days' => 14, 'forecast_days' => 1]);
        }

        if ($data === null || ! isset($data['daily']['time'])) {
            $this->error('Open-Meteo gave no daily weather.');

            return self::FAILURE;
        }

        $today = today()->toDateString();
        $rows = [];
        foreach ($data['daily']['time'] as $i => $date) {
            // Today is not over yet; it is stored tomorrow night.
            if ($date >= $today) {
                continue;
            }
            $rows[] = [
                'date' => $date,
                'temp_max' => $data['daily']['temperature_2m_max'][$i] ?? null,
                'temp_min' => $data['daily']['temperature_2m_min'][$i] ?? null,
                'rain' => $data['daily']['precipitation_sum'][$i] ?? null,
                'et0' => $data['daily']['et0_fao_evapotranspiration'][$i] ?? null,
                'wind_max' => $data['daily']['wind_speed_10m_max'][$i] ?? null,
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            WeatherDay::query()->upsert($chunk, ['date'], ['temp_max', 'temp_min', 'rain', 'et0', 'wind_max', 'updated_at']);
        }

        $this->info(count($rows).' days stored.');

        return self::SUCCESS;
    }
}
