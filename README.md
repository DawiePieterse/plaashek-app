# Plaashek

Farm software sold as modules per farm, one Laravel app. The website `plaashek.co.za` is the separate
`DawiePieterse/Plaashek` repo. Each farm has its own install, folder, `.env` and
database on Afrihost shared hosting, and the modules it has bought share that database. `docs/PLAN.md` is the
plan; `CLAUDE.md` the working notes.

Built so far: the minimal core (setup, office login, Farm settings, modules) and the free **Weather** module.

## Running it locally

Needs PHP 8.2+, Composer, MariaDB or MySQL and Node 22 (tests only). In a Claude Code cloud session,
`.claude/hooks/session-start.sh` does all of this.

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan farm:create          # or open http://localhost:8000/setup
php artisan serve                # http://localhost:8000
```

The Weather screens call Open-Meteo through the farm's server. With no `OPEN_METEO_API_KEY` (the setting
while Plaashek is in development and marketing) the free API is used; setting a key switches to the commercial
API once farms pay.

## Checks

```bash
composer check                   # Pint, Larastan, Pest
npm test                         # the Weather screens' logic (node:test)
```

## Layout

| Where | What |
|---|---|
| `app/` | The core: users, settings (`Support/Settings`, `Support/Farm`), modules (`Modules/Module`, `Modules/ModuleRegistry`), farm setup, the office panel and Farm settings |
| `app/Modules/Weer/` | The Weather module: Open-Meteo proxy and cache, `weather_days`, the page, its routes, migrations and words |
| `public/modules/weer/` | The Weather screens: Weather Compare's plain JS, no build step |
| `config/plaashek.php` | Which modules this build has; Open-Meteo key; the support account's email |
| `scripts/build-afrihost.sh` | `plaashek.zip` to upload to a farm's folder |
| `docs/` | Plan, Afrihost deployment, the farm list |

## Data

Weather data by [Open-Meteo](https://open-meteo.com/) (CC BY 4.0). The Weather screens are adapted from
Weather Compare (`DawiePieterse/weather-app`).
