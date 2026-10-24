<?php

namespace App\Modules\Weer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One day's weather at the farm's position, filled nightly by `weer:days`. A core table: any module that
 * stamps weather on a record reads it here instead of calling Open-Meteo.
 *
 * @property Carbon $date
 * @property float|null $temp_max
 * @property float|null $temp_min
 * @property float|null $rain
 * @property float|null $et0
 * @property float|null $wind_max
 */
class WeatherDay extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'date';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'temp_max' => 'float',
            'temp_min' => 'float',
            'rain' => 'float',
            'et0' => 'float',
            'wind_max' => 'float',
        ];
    }
}
