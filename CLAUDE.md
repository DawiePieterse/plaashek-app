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
- **Module codes are the Afrikaans names:** `weer`, `eienaar`, `kudde`, `werkers`. Free modules (`weer`,
  `eienaar`) are always on, have no licence status and are never switched off for non-payment.
- **A paid module's licence** (`modules.licence`: active, grace, suspended, cancelled; `farm:licence`) decides
  whether it saves: `ModuleRegistry::isWritable()`, or `Module::writable()` in the module. Every write path checks
  it (Kudde: the API's `mayWrite`, 423 when read-only; `AnimalResource::mayCorrect`, camps). Settings stay
  editable; suspended still reads and exports; cancelled is off. Never mention the licence on a phone.
- **Settings go through `App\Support\Settings`; the farm's own settings through `App\Support\Farm`.**
- **Screens speak the farm's language** (`af`/`en`, `Farm::LANGUAGE`, applied by `SetFarmLocale`). English in
  the database and code. Core strings in `lang/{af,en}/plaashek.php`, a module's in its own `lang/`.
- **Nothing on a phone talks to another website.** No CDNs; Open-Meteo only through the farm's proxy
  (`/weer/api/*`), which replaces the position with the farm's and holds the commercial key once there is one
  (the free API for now, during development and marketing). The CSP in `SecurityHeaders` enforces `'self'`.
- **Office roles:** owner, manager, viewer, `plaashek_staff`. `User::isOwner()` covers owner and staff. Changes
  to settings write `AuditLog::record()`.
- Comments explain *why*, usually a farm constraint. Keep that style.

## Office layout

- **Home** (`Filament\Pages\Home`, "Tuis") is the panel's first page: the account card, then one tile per
  module (`Widgets\ModuleTiles`). The core adds the Workers tile; a module gives its own with
  `Module::homeTile()` (a `ModuleTile`: label, emoji icon, one number, caption, link), asked only when on.
- **Top bar**: the farm's name, then each enabled module's `Module::topbarView()` (Weather's "now", fetched
  from `/weer/now` after the page loads), and the release from `config('plaashek.version')` (the `VERSION`
  file `build-afrihost.sh` writes; "dev" in a clone). Global search is off: it crowded the phone's top bar.
- **Settings** is one menu group: Farm settings (only what sets the farm up: name, farmer, phone, language,
  position), Workers, then each module's own screen, a subclass of `App\Filament\ModuleSettings`
  (owner only, hidden while the module is off, saves through `Settings` and the audit log).
- **Each module has a screen** (its menu entry and Home tile): KPI cards (`StatsOverviewWidget`), charts on
  `App\Filament\Widgets\FarmChart` (fixed height, Filament's own Chart.js, no CDN), short lists, and the
  **Reports** button (`App\Filament\Actions\ReportsAction`). Kudde: `Filament\KuddePage`; Weather:
  `Filament\WeatherPage` (its record from `weather_days`, a button into `/weer` and one to backfill history);
  Workers: stats and reports on the list.
- **Reports** (`app/Reports`): a `Report` gives its key, label, own filters, columns and rows; the action adds
  the standard periods (`DateRanges`, "this season" from Weather's season start) and exports with
  `ReportExport` to Excel (OpenSpout) or PDF (Dompdf, DejaVu Sans for ê/ë, remote fetches off). A module lists
  its reports in a static `reports()`; Kudde's are in `Reports/`, derived like everything else (`HerdHistory`
  for the herd over time and daily gain).
- **Top bar pills**: online (the server's `/up` answering, checked every minute, not the phone's radio) and the
  release.
- The panel's Tailwind build has only Filament's classes: our views use the plain CSS in
  `resources/views/filament/styles.blade.php` or inline styles, not Tailwind utilities.
- A module adds Filament resources with `Module::filamentResources()` (Kudde: the animals, the camps and the species).

## Weather module

- `OpenMeteoProxy`: four endpoints (`archive`, `hf`, `fc`, `climate`), farm position only, cache in
  `weer_responses` (a range ended 8+ days ago: for ever; otherwise an hour; Open-Meteo's 400s: a day; failures
  never; Open-Meteo down: the last answer).
- `weer:days` fills `weather_days` nightly at 05:10 for any module that stamps weather.
- Its settings screen (`Filament\WeatherSettings`) holds the season start and frost threshold.
- The page is `/weer` (`views/page.blade.php`), its own installable app with a service worker served by a
  route (`/weer/sw.js`, scope `/weer`, cache name hashed from the shell files: no manual CACHE bump).
- Screens: `public/modules/weer/js/`, Weather Compare's code with the map, second location and AI removed and
  `farm.js` (forecast, rain record, frost) added. Pure logic is tested in `tests/js/`.
- Field names in `fields.js` and the quick read (`insights.js`) are English only for now.

## Workers (core)

- `workers` (`App\Models\Worker`) is the minimal register under Farm settings: number, names, cell, photo,
  active. Workers are never deleted, only made inactive; changes write `AuditLog`. Payroll, identity and time
  and attendance (Span, merged into Werkers) belong to the Werkers module's own tables.
- The card's QR holds the number only (`App\Support\WorkerCard`, SVG from `chillerlan/php-qrcode`, ECC H), the
  same as Boord's badges, so Bekfontein's printed cards keep working. Sheet at `/workers/cards?ids=`.
- Photos live on the private `local` disk (`serve` on, so the office shows them by signed URL only).
- `App\Support\NaturalOrder` sorts tags and numbers the way they are read (B2 before B10).

## Eienaar module (planned: PLAN.md §2.7, phase 3 with Weather)

- Free, on from `farm:create`: the first module a farm has after installation. The owner's summary of what is
  happening on the farm, plus a "needs attention" list (withdrawal ending, weighing due, frost forecast).
- Each enabled module contributes its own lines, the way it gives its Home tile; Eienaar never names a module.
  With only Weather on, it is the weather summary.
- One farm only (its own database), never across farms. No cost, yield, pack-out or crop-forecast figures: those
  stay with the modules that hold the records.
- AI analytics in broad terms only, optional and off until the owner switches it on; its scope is written in
  PLAN.md before any code, and the website and order form promise nothing more specific until then.

## Kudde module

- The farm-PC Kudde (`DawiePieterse/Kudde`) is the spec; its pytest cases are ported in `tests/Feature/Kudde/`.
- Stored: `kudde_animals` (identity) and `kudde_events` (everything that happens). Derived, never stored, in
  one place (`Support\HerdState`): current camp, last weight, withdrawal end, has calved, class
  (`Support\AnimalClass`). Every write goes through `Support\Herd`, idempotent on `client_uuid` (a batch
  member's is `<uuid>:<animal id>`, a calf's birth `<uuid>:calf`).
- Camps (owner's call: they are where the animals are kept, so they live with Kudde): the list is
  `Filament\Resources\Camps`, nested under Kudde's settings and opened from its "Manage camps" button, and refuses
  to archive a camp with alive animals. The `camps` table, model and `App\Support\CampNames` stay in the core
  (renaming them is churn for nothing): a record keeps the typed name as text, snapped to the office's spelling;
  an unknown camp is added as it is used; names compared with `MB_CASE_FOLD` in PHP.
- **Species** (`Models\Species`, `kudde_species`, phase 4b): every animal has one; all rules are the farm's, edited
  in `Filament\Resources\Species` under Kudde's settings (names, class names per role per language, young until,
  gestation, weigh every, birth word, young tagged at weaning). Never deleted; the last one on stays on.
  `AnimalClass::role` is one rule for every species (mother, female, male, castrated, young_*); the species only
  names the role. Class labels read "Sheep: ewe" once more than one species is in play (`HerdState::classLabel`).
- Tags are unique per species, ignoring capitals (the collation); sorted naturally by `Support\Tags::compare`.
  Requests carry `species`; with none, `Species::fallback()` (the only one kept, else cattle) for a new animal,
  and a lookup of a tag two species share is a 409 (`HerdException::ambiguousTag`). `Herd::of($species)` scopes.
- Births (`Herd::recordBirth`, `POST /calvings`): `calf` (one, as before), `young` (twins) or `litter` (counted on
  the mother, `kudde_litters`, for species tagged at weaning: pigs). `POST /litters/{id}/wean` tags the young,
  with her as dam and the litter's date as birth date. The field API has no route-model binding: look ids up.
- Lineage (`Support\Lineage`, `GET /animals/{tag}/lineage`): parents to great-grandparents found by tag within the
  species (the latest animal with that tag born before the young one), offspring, a mother's births (tagged young
  by date plus litters) with the interval between them. Shown on the office animal page only, not in the field app
  (owner's call).
- Office herd: `Filament\Resources\Animals` (under Kudde in the menu, read only): the list (alive by default, tags
  sorted as read in SQL, `AnimalResource::byTag`) and a page per animal: record, family, history. The derived herd
  is loaded once per request (`AnimalResource::state()`). Filament injects closure parameters by name: `$query`.
  The page's history and photos are relation managers (`RelationManagers\Events`, `Photos`), where the office
  (`AnimalResource::mayCorrect()`: not a viewer) corrects: `Herd::correctEvent` (the kind's own fields only),
  `removeEvent` (status back from what remains), `addPhoto`/`removePhoto`, all audited. The record's "Correct
  record" changes neither tag nor status.
- Photos (`Models\Photo`, `kudde_photos`): `photos/kudde/<animal id>/` on the private `local` disk, signed links
  for an hour (`Photo::url()`), `POST /animals/{tag}/photos` (office and field), idempotent on `client_uuid`.
- Weather comes from `weather_days` through `App\Modules\Weer\WeatherDays`, never a live call;
  `kudde:weather` (05:25) stamps events saved before their day was filled.
- API at `/kudde/api/*` behind the office login; a viewer only reads. The same methods at
  `/kudde/field/api/*` behind a worker's card session (`Support\FieldSession`, middleware `kudde.worker`,
  Bearer token, valid until the farm's midnight), less corrections, camp management and exports. Records get
  `worker_id` from the session, or from `captured_by` (the worker signed in when an outbox record was made).
- The field app is `/kudde/field` (`views/field.blade.php`, `public/modules/kudde/`): old Kudde's field screens
  plus `js/signin.js`, the daily card scan, and `js/species.js`, the species switch (only with more than one;
  `api.js` adds the species to every write, `idb.js` stamps it on an outbox entry when made). Service worker served by a route, cache name hashed from the shell.
  Card scans have their own named limiter (`kudde-sign-in`): a plain `throttle:N,1` shares its counter with
  the group's.
- Generated notes ("Calf K1", "Born to C1") are English: English in the database.
- Settings screen `Filament\KuddeSettingsPage`: "weigh every" (`Support\KuddeSettings`, default 182 days, also
  sent to the field app), the camps' "Manage camps" button and the field app's address as a QR code.

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
