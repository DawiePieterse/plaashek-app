# Plaashek: working notes for Claude

Farm software as modules per farm, rebuilt on the BowlsBuddy model (`DawiePieterse/bowlsbuddy-app`):
**Laravel 12 + Filament 4, PHP 8.3, MariaDB, Afrihost shared hosting, one install and database per farm.**
`docs/PLAN.md` is the plan; tick features off there as they are built.

## Rules that are easy to break

- **One farm per database, so no `farm_id` column anywhere.** The database is the farm. Never add code that
  reaches another farm's database.
- **The core never names a module.** A module is a service provider under `app/Modules/<Name>/` extending
  `App\Modules\Module`, listed in `config/plaashek.php`. It loads its own `migrations/`, `routes.php`, views
  and `lang/`, and adds office pages through `filamentPages()`. Module tables are prefixed with its code.
- **Every module's tables exist in every farm**, on or off. Switching a module on only shows it. Routes use the
  `module:<code>` middleware; Filament pages hide themselves in `canAccess()`.
- **Module codes are the Afrikaans names:** `weer`, `kudde`, `werkers`. Free modules (`weer`) are always on.
- **Settings go through `App\Support\Settings`; the farm's own settings through `App\Support\Farm`.**
- **Screens speak the farm's language** (`af`/`en`, `Farm::LANGUAGE`, applied by `SetFarmLocale`). English in
  the database and code. Core strings in `lang/{af,en}/plaashek.php`, a module's in its own `lang/`.
- **Nothing on a phone talks to another website.** No CDNs; Open-Meteo only through the farm's proxy
  (`/weer/api/*`), which replaces the position with the farm's and holds the commercial key once there is one
  (the free API for now, during development and marketing). The CSP in `SecurityHeaders` enforces `'self'`.
- **Office roles:** owner, manager, viewer, `plaashek_staff`. `User::isOwner()` covers owner and staff. Changes
  to settings write `AuditLog::record()`.
- Comments explain *why*, usually a farm constraint. Keep that style.

## Weather module

- `OpenMeteoProxy`: four endpoints (`archive`, `hf`, `fc`, `climate`), farm position only, cache in
  `weer_responses` (a range ended 8+ days ago: for ever; otherwise an hour; Open-Meteo's 400s: a day; failures
  never; Open-Meteo down: the last answer).
- `weer:days` fills `weather_days` nightly at 05:10 for any module that stamps weather.
- The page is `/weer` (`views/page.blade.php`), its own installable app with a service worker served by a
  route (`/weer/sw.js`, scope `/weer`, cache name hashed from the shell files: no manual CACHE bump).
- Screens: `public/modules/weer/js/`, Weather Compare's code with the map, second location and AI removed and
  `farm.js` (forecast, rain record, frost) added. Pure logic is tested in `tests/js/`.
- Field names in `fields.js` and the quick read (`insights.js`) are English only for now.

## Session setup (Claude Code on the web)

`.claude/hooks/session-start.sh` installs and starts MariaDB (databases `plaashek`, `plaashek_test`,
`plaashek_e2e`, user `pk`/`pk`), runs `composer install --prefer-source` (GitHub zipballs are blocked), creates
`.env` and migrates. Open-Meteo is not reachable from the sandbox: tests use `Http::fake()`. After a fresh
install run `php artisan filament:assets` if the office pages have no styling.

## Checks (run all before committing)

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G --no-progress
vendor/bin/pest
npm test
```
