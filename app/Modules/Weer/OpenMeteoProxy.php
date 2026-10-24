<?php

namespace App\Modules\Weer;

use App\Support\Farm;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The only way anything in Plaashek reaches Open-Meteo (PLAN.md section 2.3).
 *
 * - While Plaashek is in development and marketing it uses Open-Meteo's free API (no key). Once farms pay, a
 *   key in .env switches it to the commercial API: the `customer-` hosts with an apikey that never reaches a
 *   phone. The cache below keeps either well inside its limits.
 * - Only the four APIs the Weather screens use are allowed, and only for the farm's own position: the
 *   latitude and longitude a request sends are replaced by the farm's, so the proxy cannot be used as free
 *   Open-Meteo for anywhere else.
 * - Every answer is cached in weer_responses by its query. A date range that ended more than a week ago
 *   (past the reanalysis lag) never changes, so it never expires; anything touching the last week or the
 *   forecast is kept an hour. Open-Meteo's own refusals (400, e.g. a renamed field) are kept a day so the
 *   screens' retry-without-that-field logic doesn't hammer it; a failed call is never cached.
 */
class OpenMeteoProxy
{
    /** Endpoint name the screens use => Open-Meteo host and path. */
    public const ENDPOINTS = [
        'archive' => ['archive-api.open-meteo.com', '/v1/archive'],
        'hf' => ['historical-forecast-api.open-meteo.com', '/v1/forecast'],
        'fc' => ['api.open-meteo.com', '/v1/forecast'],
        'climate' => ['climate-api.open-meteo.com', '/v1/climate'],
    ];

    /** A range that ended this many days ago or more is final (the reanalysis lags about six days). */
    private const FINAL_AFTER_DAYS = 8;

    private const LIVE_TTL_MINUTES = 60;

    private const REFUSAL_TTL_HOURS = 24;

    public function __construct(private Farm $farm) {}

    /**
     * @param  array<string, mixed>  $query  what the screens asked for; position and key are replaced
     * @return array{status: int, body: string, cached: bool}
     */
    public function get(string $endpoint, array $query): array
    {
        if (! isset(self::ENDPOINTS[$endpoint])) {
            return self::error(404, 'Unknown weather endpoint.');
        }

        $position = $this->farm->position();
        if ($position === null) {
            return self::error(409, 'The farm has no position yet. Set it in Farm settings.');
        }

        unset($query['apikey'], $query['latitude'], $query['longitude']);
        $query = array_filter($query, fn ($v) => is_scalar($v) && $v !== '');
        $query['latitude'] = $position['lat'];
        $query['longitude'] = $position['lon'];
        ksort($query);

        $canonical = http_build_query($query);
        $hash = hash('sha256', $endpoint.'?'.$canonical);

        $row = DB::table('weer_responses')->where('query_hash', $hash)->first();
        if ($row !== null && ($row->expires_at === null || Carbon::parse($row->expires_at)->isFuture())) {
            return ['status' => (int) $row->status, 'body' => (string) $row->body, 'cached' => true];
        }

        try {
            $response = Http::timeout((int) config('plaashek.open_meteo.timeout', 30))
                ->acceptJson()
                ->get($this->url($endpoint), $this->withKey($query));
        } catch (ConnectionException) {
            return $row !== null
                // Out of date beats nothing when Open-Meteo can't be reached.
                ? ['status' => (int) $row->status, 'body' => (string) $row->body, 'cached' => true]
                : self::error(502, 'Open-Meteo could not be reached.');
        }

        $status = $response->status();
        // 400 is Open-Meteo refusing the query (kept below); anything else from 401 up is its fault or ours.
        if ($status > 400) {
            return $row !== null
                ? ['status' => (int) $row->status, 'body' => (string) $row->body, 'cached' => true]
                : self::error(502, 'Open-Meteo answered '.$status.'.');
        }

        $expires = match (true) {
            $status === 400 => now()->addHours(self::REFUSAL_TTL_HOURS),
            self::isFinal($query) => null,
            default => now()->addMinutes(self::LIVE_TTL_MINUTES),
        };

        DB::table('weer_responses')->upsert([[
            'query_hash' => $hash,
            'endpoint' => $endpoint,
            'query' => $canonical,
            'status' => $status,
            'body' => $response->body(),
            'fetched_at' => now(),
            'expires_at' => $expires,
        ]], ['query_hash'], ['status', 'body', 'fetched_at', 'expires_at']);

        return ['status' => $status, 'body' => $response->body(), 'cached' => false];
    }

    /**
     * The decoded JSON of a successful answer, or null. For server-side use (weather_days).
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    public function json(string $endpoint, array $query): ?array
    {
        $result = $this->get($endpoint, $query);
        if ($result['status'] !== 200) {
            return null;
        }
        $data = json_decode($result['body'], true);

        return is_array($data) ? $data : null;
    }

    private function url(string $endpoint): string
    {
        [$host, $path] = self::ENDPOINTS[$endpoint];

        return 'https://'.($this->key() !== null ? 'customer-'.$host : $host).$path;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function withKey(array $query): array
    {
        $key = $this->key();

        return $key === null ? $query : $query + ['apikey' => $key];
    }

    private function key(): ?string
    {
        $key = config('plaashek.open_meteo.key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** @param array<string, mixed> $query */
    private static function isFinal(array $query): bool
    {
        $end = $query['end_date'] ?? null;
        if (! is_string($end) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            return false;
        }

        return Carbon::parse($end)->lte(today()->subDays(self::FINAL_AFTER_DAYS));
    }

    /** @return array{status: int, body: string, cached: bool} */
    private static function error(int $status, string $reason): array
    {
        return ['status' => $status, 'body' => (string) json_encode(['error' => true, 'reason' => $reason]), 'cached' => false];
    }
}
