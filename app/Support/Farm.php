<?php

namespace App\Support;

/**
 * The farm's own settings, typed, with their defaults (PLAN.md section 2.1, Farm settings). Every module
 * reads the farm through here rather than through raw setting keys.
 */
class Farm
{
    public const NAME = 'farm.name';

    public const FARMER = 'farm.farmer';

    public const PHONE = 'farm.phone';

    public const LATITUDE = 'farm.latitude';

    public const LONGITUDE = 'farm.longitude';

    public const LANGUAGE = 'farm.language';

    public const SEASON_START_MONTH = 'farm.season_start_month';

    public const FROST_THRESHOLD = 'farm.frost_threshold';

    public const LANGUAGES = ['af', 'en'];

    public function __construct(private Settings $settings) {}

    public function name(): string
    {
        return $this->settings->get(self::NAME) ?? 'Plaashek';
    }

    public function farmer(): ?string
    {
        return $this->settings->get(self::FARMER);
    }

    public function phone(): ?string
    {
        return $this->settings->get(self::PHONE);
    }

    /**
     * The farm's position, rounded to 4 decimals (~11 m) so a re-typed last digit never changes a weather
     * cache key. Null until someone sets it in Farm settings.
     *
     * @return array{lat: float, lon: float}|null
     */
    public function position(): ?array
    {
        $lat = $this->settings->get(self::LATITUDE);
        $lon = $this->settings->get(self::LONGITUDE);

        if (! is_numeric($lat) || ! is_numeric($lon)) {
            return null;
        }

        return ['lat' => round((float) $lat, 4), 'lon' => round((float) $lon, 4)];
    }

    public function language(): string
    {
        $language = $this->settings->get(self::LANGUAGE);

        return in_array($language, self::LANGUAGES, true) ? $language : (string) config('app.locale', 'en');
    }

    /** The month a season starts, for rain totals "this season". July suits summer rainfall areas. */
    public function seasonStartMonth(): int
    {
        $month = (int) $this->settings->get(self::SEASON_START_MONTH, '7');

        return $month >= 1 && $month <= 12 ? $month : 7;
    }

    /** Nights forecast at or below this many °C are marked as frost risk. */
    public function frostThreshold(): float
    {
        $value = $this->settings->get(self::FROST_THRESHOLD);

        return is_numeric($value) ? (float) $value : 2.0;
    }
}
