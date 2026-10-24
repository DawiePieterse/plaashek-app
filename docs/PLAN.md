# Plaashek — Build Plan

**Version:** 0.9, 24 October 2026 (Phases 0–2 code written: minimal core, Farm settings, Weather)
**Repo:** `DawiePieterse/plaashek-app` (the website is `DawiePieterse/Plaashek`; GitHub names ignore case)
**Status:** Phase 4 (Kudde domain) code written 25 October 2026: animals, events, camps, calvings, derived herd
state and the JSON API at `/kudde/api` (office login until phase 6), with old Kudde's tests ported to Pest.
Phase 0–2 code written and tested locally (Pest 32, node:test 78, browser check at phone and desktop).
Not committed, not deployed: the repo and the Afrihost package don't exist yet.

Plaashek is farm software sold as modules per farm: Kudde, Boord, Veldnotas, Stoor, Water, Bespuitings,
Werkswinkel, Oudit, Span and Eienaar (the list on `plaashek.co.za`), plus a free Weather module (`weer`) and
the Werkers module (`werkers`), the worker register. It is **one Laravel app**. Each farm gets its
own install and its own database, and the modules it has bought share that database, so "every app runs on the
same blocks, camps, lines and people" (the website's promise) is true by construction.

**Build order: Weather first, then Kudde.** Weather is free and small, and needs only a minimal core (office login
and Farm settings), so it proves the skeleton, the Afrihost layout and the module framework on Bekfontein before
any farm records are at stake.

**Kudde is the first paid module**: cattle recording for small farms, the animals and the handful of things that
happen to them (birth, weighing, treatment, move between camps, sale, death). Building it also builds the core
every later module stands on.

**Weather (`weer`)**, free on every farm and built first: the farm's forecast, its rain record, and its weather
against any year since 1940. It is the browser-only Weather Compare app
(`DawiePieterse/weather-app`) brought inside Plaashek for the farm's own position only, without its second-place
comparison, and with its Open-Meteo calls moved behind the farm's server.

**Werkers (`werkers`) is the second paid module**: every person the farm employs, a photo and a printed ID card
whose QR code identifies them, and a **simple payroll** (Bekfontein pays by hand today): pay runs, UIF and PAYE,
payslips, and the bank and cash lists. It is the register Span, Boord's piece-work
and every later "who did this" will use.

References, copied from and never imported:
- the farm-PC Kudde (`DawiePieterse/Kudde`, FastAPI + SQLite): **behaviour** of the Kudde module;
- BowlsBuddy (`DawiePieterse/bowlsbuddy-app`): **stack, layout, build and Afrihost deployment**;
- `plaashek-suite`: **product rules** and the module scopes (`docs/*-build-scope.md`) for later modules;
- Weather Compare (`DawiePieterse/weather-app`): **behaviour and most of the front-end code** of the Weather
  module (chart, series, field catalog, request planning, quick read), less its two-location comparison;
- Boord's worker register and `frontend/admin/print-badge.html` (photo, QR, mm-exact print layout) and the
  suite's piece-work register (`docs/piecework-build-scope.md`, ADR 0011): **the Werkers module**.

---

## 1. Decisions (locked 24 October 2026)

| Decision | Call | Why |
|---|---|---|
| Stack | Laravel 12 + Filament 4, PHP 8.3, MariaDB | Runs on Afrihost shared hosting; same as BowlsBuddy, so its build, tests and deploy carry over |
| Hosting | Afrihost Linux shared hosting (cPanel), a **separate package** from BowlsBuddy | Separate domain, password, database quota and backups |
| Domain | `plaashek.co.za` | Landing page in `public_html`, farms on subdomains |
| App | **One app, `plaashek`, with modules** switched on per farm | One set of farm data across modules; one login per farm; one build to deploy |
| Tenancy | **One install, folder, `.env` and database per farm**, shared by all its modules | The database server refuses another farm's data: each farm's DB user has privileges on its own database only |
| Field sign-in | Username or cell + 4-digit PIN | Simpler than printed QR pairing (§5) |
| Pricing | R2 000 setup once; R150 pm hosting; R150 pm per paid module (Kudde first); Weather free | Owner's call, 24 October 2026 (§1.4) |
| Pilot | Bekfontein: Weather first, then Kudde | Owner's call, 24 October 2026 |
| Build order | Minimal core + Farm settings → Weather → Kudde → Werkers → Boord | Weather is free and needs least core; owner's call |
| Eienaar | One farm only, never across farms | Keeps the one-database-per-farm rule with no exceptions |
| Weather data | Open-Meteo's **free** API during development and marketing; the commercial API (a key in `.env`, no code change) before the first paying farm. Always called from the farm's server | Owner's call, 24 October 2026: no cost while nothing is sold (§2.3) |
| Platform | Replaces the `plaashek-suite` architecture (TypeScript, Postgres, PowerSync, VPS, shared DB with `farm_id`) | ADR 0014 in `plaashek-suite` |

### 1.1 Carried over from the Plaashek Suite plan

- **Modules are sold per farm.** Devices are free and uncapped.
- **The office sees one tab per licensed module**, plus Farm settings. A module that is off has no tab, no field
  tile and no menu entry.
- **Farm data belongs to the farm.** Excel/CSV export of everything; offboarding = full export, confirm
  receipt, delete within the window written into the order form.
- **Plaashek is operator, the farm is responsible party** (POPIA). In the order form.
- **Screen language is the farm's** (`af` or `en`, set at `farm:create`). English in the database and code.
- **A save is never blocked by missing config.** An unknown camp is added as it is used; missing weather is null.
- **Every record is stamped** with the user and device that captured it.
- **Offline windows** (ADR 0003): a phone works 21 days with no successful sync, then turns read-only; a lapsed
  licence keeps capturing 14 days.
- **Billing year one:** invoice over WhatsApp, paid by EFT, licence status flipped by hand (ADR 0004).
- **Field users never see billing copy.**
- Module product rules from ADRs 0006–0008 and 0011, when those modules are built.

### 1.2 Dropped from the Suite plan

- One shared Postgres with `farm_id` on every row → one MariaDB database per farm.
- PowerSync → the old apps' IndexedDB outbox with `client_uuid` idempotency.
- Cross-farm Plaashek Management console (`hek.`) → a staff account in each install plus artisan commands (§6).
  Revisit when there are too many farms to run by hand.
- Printed pairing QR and device tickets (plan §3.2–3.5, ADR 0009) → username or cell + PIN.
- Hosts `hek.`, `admin.`, `app.plaashek.co.za` → one subdomain per farm (§3).
- ADR 0010's pay boundary (calculate and export only, no payslips) → Werkers runs a simple payroll with payslips
  (§2.4). It still never pays anyone or files with SARS.

### 1.3 Non-goals for v1

- Many farms in one database or install.
- Single sign-on across farms. A vet on three farms has three logins.
- Stud/registry features (ICAR breeding values, DNA, herdbook). Same trim as old Kudde.
- Online payments.
- Native app or Play Store listing. PWA only.
- Building any module other than Kudde and Weather before both are live on Bekfontein.
- Eienaar across farms. One Eienaar per farm; an owner of two farms opens two.

### 1.4 Pricing

| Item | Price | Notes |
|---|---|---|
| Setup | R2 000 once per farm | Farm visit, `farm:create`, loading camps and animals, training |
| Hosting | R150 per month per farm | Covers the Afrihost share, backups and the Weather data |
| Kudde | R150 per month | First paid module |
| Werkers | R150 per month | Second paid module, payroll included; Span and Boord's piece-work need it |
| Each later module | R150 per month | Boord, Veldnotas, Stoor, Water, Bespuitings, Werkswinkel, Oudit, Span, Eienaar |
| Weather | Free | On for every farm, no licence status, never switched off for non-payment |

So Bekfontein on Weather alone is R2 000 once and R150 per month (hosting); with Kudde, R300 per month. Year one is invoiced over WhatsApp and paid
by EFT (ADR 0004). Running costs to cover from hosting fees: the Afrihost package (R135 pm for up to ~19 farms
on Bronze Pro), and the Open-Meteo commercial plan from the first paying farm (check its price then).

---

## 2. Core and modules

### 2.1 Core (used by every module)

**Minimal core, built with Weather**
- [x] `farm:create` and the first-run setup page
- [x] Modules table and module loading (each module a service provider under `app/Modules/`)
- [x] Office login (Filament, `/admin`): owner, manager, viewer; Plaashek staff account
- [x] **Farm settings** page (owner only): farm name, farmer, phone, GPS position (typed, or "Use my location" on
  a phone at the farm), language (`af`/`en`), season start month, frost threshold. Module settings appear here
  as each module adds them
- [x] **Home** (owner's sketch, 25 October 2026): one tile per module with its key number (workers active,
  rain in the last 7 days, alive animals), each opening the module; the farm's name, the weather now and the
  release in the top bar
- [x] **Module screens** (owner's call, 25 October 2026): each Home tile opens its module's screen with its key
  numbers, charts and lists, and a Reports picker (standard periods: this month, last month, this season, last
  season, this year, last 12 months, chosen dates; plus each report's own filters) that exports to Excel or PDF.
  Kudde: herd, births and losses this season, withdrawal, weighing due, daily gain; the herd over 12 months, by
  class, by camp, average weights; 7 reports. Weather: rain 7/30 days and season, frost nights, extremes, ET0;
  the last 30 days, rain by month against last season, temperature by month; 2 reports. Workers: 4 numbers and
  the register report. The top bar shows an online pill and the release
- [x] **Settings menu group**: Farm settings holds what sets the farm up (name, farmer, phone, language,
  position), with Workers beside it; each module has its own settings screen there (Weather: season start,
  frost; Kudde: weigh every, the camps (nested under it), the field app's QR code)
- [x] `af`/`en` screens (Filament's own buttons and messages stay English: Filament ships no Afrikaans)
- [x] Audit log of settings changes and staff access

**Built with Kudde**
- [ ] Settings: licence status and dates
- [ ] Modules switched on per farm (`farm:modules`), each with its own licence status
- [ ] One office tab per enabled module, plus the Settings group (§5)
- [ ] Field users with username/cell + PIN, the devices page, disable a person or a phone (§5)
- [ ] Field PWA shell: sign-in, change user, home screen with one tile per enabled module, the outbox and sync (§7)
- [x] **Worker register, minimal** (owner's call, 25 October 2026), under Farm settings: number, first names,
  surname, known-as, cell, photo, active; and the 58 × 78 mm cards whose QR holds the number (Boord's layout,
  QR drawn on the server as SVG). Enough for every module to scan and name a worker; the Werkers module adds
  identity and payroll beside it (§2.4)
- [x] Camps: shared master data (`camps`, `App\Support\CampNames`), used by Kudde now and by Water, Bespuitings and Veldnotas later
- [x] Weather data: the farm's Open-Meteo proxy and its cache (§2.3), plus `weather_days` (one row per date for
  the farm's position) that Kudde and later modules stamp from
- [ ] Audit log of office corrections, visible to the farm
- [ ] Nightly backup and Download backup (§9)
- [ ] Export framework: each module adds its sheets to one farm export

### 2.2 Kudde module (scope from the old app)

Everything the farm-PC Kudde does today is the v1 checklist. Tick off here as built.

**Herd**
- [x] Animals: ear tag (unique per farm, ignoring capitals), name, breed (free text), sex, birth date, sire/dam **by tag** (may
  point at nothing in this herd), castrated flag, status (alive/dead/sold, set only by a death or sale event)
- [x] Class derived, never stored: cow, heifer, bull, steer, calf (`classes.py`)
- [x] Derived from the event history in one place (`herd.py`, now `Support\HerdState`): current camp, last weight, withdrawal end date,
  whether a cow has calved
- [x] Camps as in old Kudde: farm order (not alphabetical), archive not delete, names compared ignoring case in
  PHP not SQL (Afrikaans diacritics), a typed camp snaps to the stored spelling, an unknown one is added

**Events**
- [x] Kinds: birth, weight, treatment, movement, death, sale
- [x] Treatment: product, dose, withdrawal days (stored as days, so a corrected date stays right)
- [x] Product chips: past treatments, most recent first, with their last dose and withdrawal
- [x] Bulk movement and bulk treatment (a group through the crush)
- [x] Calving: cow + new calf in one step, idempotent
- [x] Weather stamped on the event date from `weather_days`; never blocks the save (a day not yet filled is
  stamped next morning by `kudde:weather`)

**Photos**
- [ ] Per animal, idempotent upload by `client_uuid`, resized on the phone, served only through the app

**Field app, opened by a worker's card** (owner's call, 25 October 2026)
- [x] Old Kudde's field screens at `/kudde/field` (herd, weigh and treat sessions, move camp, calving, the
  offline outbox), its own installable app until the core field shell exists
- [x] A worker scans their card once when work starts; the phone is theirs until the farm's midnight. Any
  active worker may; "Not you?" hands the phone over. Offline, the phone recognises the card from its cached
  list of active workers and fetches the day's token when there is signal
- [x] Every animal and event records the worker who captured it (`worker_id`), queued records the worker
  who was signed in when they were made
- [ ] Field screens in Afrikaans (the sign-in screen is; the ported screens are English for now)
- [ ] Photos in the field app (with the photo API)

**Office tab**
- [x] Dashboard: head count by class, by camp, animals under withdrawal (Kudde's screen, with charts and reports)
- [ ] Herd list, animal page with timeline and photos, corrections
- [x] Export: animals and events (CSV, `/kudde/api/export/*.csv`; the office button comes with the tab)

**Livestock types** (owner's call, 25 October 2026: farms keep more than cattle)
- [x] Every animal has a species. **The species and all their rules are farm settings** (Kudde's part of Farm
  settings), seeded with cattle, sheep, goats and pigs; a farm switches on the ones it keeps and can add another
  (horses, game) with its own rules. A farm with one species never sees a species picker
- [x] Per species, editable in Settings: its name in `af`/`en`; the class names for young female, female,
  female that has given birth, young male, male, castrated male (e.g. cattle: heifer calf, heifer, cow, bull
  calf, bull, steer; sheep: ewe lamb, ewe, ewe, ram lamb, ram, wether; goats: doe kid, doe, doe, buck kid, buck,
  kapater; pigs: piglet, gilt, sow, piglet, boar, barrow); young until (cattle 365 days, sheep and goats 180,
  pigs 70); gestation (283, 147, 150, 114 days); weigh every (cattle 182 days; others set by the farm); the word
  for a birth (calving, lambing, kidding, farrowing)
- [x] `Support\AnimalClass` and `HerdState` read the species' rules instead of cattle's constants; the class
  logic itself (sex, young, has given birth, castrated) stays one rule for all
- [x] **Tags unique per species** (owner's call): sheep 001 and cow 001 may both exist, so every tag lookup, the
  API and the field app carry the species (a request that names none and hits a tag two species share gets a
  409). Existing animals become cattle
- [x] Births take one or more young (twins in sheep and goats); calving becomes "birth", named per species
  (`POST /calvings` takes `young`; the field app's form still adds one young at a time: twins in the field are
  still to do)
- [x] **Pigs: a litter on the sow, piglets tagged at weaning** (owner's call): the farrowing records born
  alive, stillborn and fostered; weaning records how many were weaned and creates the tagged animals, each
  with the sow as dam and the litter's date as birth date. Same option for any species set to "young tagged at
  weaning" in Settings
- [x] Field app: a species switch at the top (only the farm's species); camps stay shared across species
- [x] Office: counts per species, then class; export carries the species
- [ ] Not in v1: flock-level records without individual tags (bulk move and treat cover most of it; the
  planned herds become flocks)

**Lineage** (owner's call, 25 October 2026; built with breeding, phase 7)
- [ ] Parents stay recorded by tag (text) for animals never on this farm, and gain a link (`sire_id`,
  `dam_id`) to the parent's record when it is here, set when the record is saved, because tags are reused over
  the years and a tag alone can name the wrong animal. The office can correct a link
- [ ] Reference animals: a parent kept only for the pedigree (an AI sire, a bought cow's dam), status
  `reference`, never counted in the herd, so pedigrees can go further back than the farm
- [ ] Checks, warnings not blocks: sire male, dam female, same species, born before the offspring
- [ ] Animal page (office and field): three generations of pedigree, all offspring, a dam's births with the
  interval between them, a sire's offspring with their weaning weights
- [ ] With breeding: at a birth the sire is suggested from the male that was with her about one gestation
  earlier, or from her AI record; choosing a male for a female warns if they share a grandparent or
  great-grandparent
- [ ] Import: old Kudde's sire and dam tags become links
- [ ] Not in v1 (§1.3): herdbook numbers, breeding values, DNA

**Herds and breeding** (promised on the website, not in old Kudde; in v1 by the owner's call)
- [ ] Herds: named groups of animals, beside camps (an animal is in one herd and one camp at a time)
- [ ] Breeding: a male put in with a herd or females (dates in and out), or an AI event; pregnancy check
  (pregnant/empty, by whom); expected birth date derived from the service date and the species' gestation in
  Settings; a birth closes it
- [ ] Office: females due in the next 30 days, females empty after a check, birth interval per female

**Import**
- [ ] The farm-PC Kudde's SQLite and photos into a farm's install

### 2.3 Weather module (`weer`, free)

From Weather Compare, keeping its plain JS (no build step, so it drops into the field PWA and the office tab
unchanged in style) and its unit tests. Tick off here as built.

**For the farm**
- [x] Now and the next 16 days for the farm's position: temperature, rain chance and amount, wind and gusts,
  humidity, evapotranspiration (ET0); hourly for the next 48 hours
- [x] Rain record: last 24 hours, 7 and 30 days, and the season to date against the long-term average (ERA5,
  1940 onward). The season's start month is a farm setting (default 1 July)
- [x] Frost: nights forecast at or below a farm-set threshold (default 2 °C), highlighted
- [x] Year against year chart (Weather Compare's `chart.js`/`series.js`): any field, any years, the current year
  running on into the forecast, drawn faded
- [x] Summary table under the chart: each year's mean, min, max and total
- [x] Farm shortlist of fields first (temperature, rain, ET0, wind, humidity, VPD, soil moisture and temperature,
  sunshine); "All fields" opens Weather Compare's full weather and climate-projection catalog
- [ ] Growing degree days, chill hours and frost hours: not Open-Meteo fields; computed from hourly temperature
  when a farm asks
- [ ] Field names and the quick read in Afrikaans (English only so far)
- [x] Quick read: the summary in plain sentences (`insights.js`, no AI model)
- [x] PDF export of the chart and summary
- [x] Offline: the last forecast and every downloaded year stay on the phone (IndexedDB, as Weather Compare)
- [x] Office menu entry (opens `/weer`), `af`/`en`, on for every farm from `farm:create`
- [x] Until the field shell exists (built with Kudde), Weather is its own installable page at `/weer`, behind the
  office login, with its own service worker so it opens offline: Weather Compare's `index.html` adapted. With
  Kudde it becomes the field app's Weather tile, the same code

**How the data flows**
- The browser never calls Open-Meteo. Weather Compare's `ENDPOINTS` (`openmeteo.js`) point at the farm's own
  `/weer/api/{archive|hf|fc|climate}`, which forwards the same query to an allowlisted Open-Meteo host only:
  the free API while Plaashek is in development and marketing, the commercial one (`customer-` hosts, key in
  `.env`, never sent to the phone) once `OPEN_METEO_API_KEY` is set.
- Each answer is cached in `weer_responses` by its query: a finished year never expires; the current year and
  the forecast for an hour. A farm's daily use stays a handful of calls, and the phone's IndexedDB cache sits
  in front of that again.
- The nightly schedule fills `weather_days` for the farm's position from the same proxy, so Kudde's event
  weather and the rain record never wait on a live call.
- The only location is the farm's GPS position from Farm settings. No map, place search or geocoding in the
  module, so no Leaflet, OpenStreetMap tiles or Nominatim.

**Left out of v1**
- Weather Compare's "Ask" (WebLLM downloads ~1 GB to the phone; the cloud fallback needs the user's own key).
  The quick read stays. AI help returns later as a paid feature with Plaashek's key, if farms want it.
- Comparing with a second place: Weather Compare's location B, difference mode (A − B), the map picker and place
  search. Removed by the owner's call; every chart is the farm against its own years.
- Air quality, marine and flood APIs: not farm questions. Climate projections stay under "All fields".
- Sharing a chart by URL hash; a PDF is shared instead.

**Later, if Bekfontein asks**
- [ ] Rain gauge: the farm's own readings captured in the field, charted beside Open-Meteo's
- [ ] Spray windows (wind, rain and temperature limits) for Bespuitings, and weather risk for Eienaar

### 2.4 Werkers module (`werkers`, R150 pm)

A worker here is **someone the farm employs**, not someone who signs in. The core already keeps the minimal
register and prints the cards (§2.1); this module adds everything below that is not yet ticked. Most workers never touch a phone; a
foreman who does has a field login (§5) linked to their worker record. Tick off here as built.

**The register**
- [ ] Worker number: the farm's own, typed by the office, unique, the join key to payroll (ADR 0011)
- [ ] Names: first names, surname, preferred name (what is printed on the card and shown on phones)
- [ ] Identity: SA ID number (checked with its check digit; date of birth and sex filled from it) **or**
  passport number, nationality and work permit number and expiry (warned 30 days before it lapses)
- [ ] Contact: cell number, home address, next of kin and their number
- [ ] Employment: start date, end date and reason, type (permanent, seasonal, casual, contractor), job title,
  team, active or not
- [ ] Pay: basis (hourly, daily, weekly, monthly, piece) and rate, append-only with an effective date, like
  Boord Labour's `PayRate` (a change adds a row; history is kept)
- [ ] Payment method: bank (bank, branch code, account number, account type, account holder) or cash
- [ ] Tax and UIF: income tax number (if any), whether UIF applies
- [ ] Photo: taken on a phone or uploaded in the office, cropped and resized (~100 KB)
- [ ] Notes

**ID card and QR**
- [x] The QR holds the worker number and nothing else (ADR 0011). A lost card is reprinted with the same number;
  a worker who leaves is marked inactive, and their card then scans as "No longer working here"
- [x] Card: **58 × 78 mm portrait, the same as Boord's**, so it fits the holders the farm already has: farm name,
  photo, preferred name, worker number, QR, valid from. Printed in mm from the browser, several to an A4 sheet,
  from one worker, a selection or a team (Boord's `print-badge.html` layout and mm budget, copied)
- [ ] QR code generated in the browser from a vendored library, nothing fetched
- [ ] Scan in the field app: shows photo, preferred name, number, team and active, and the next of kin for an
  emergency. Never ID, bank or pay details on a phone. Works offline from the phone's cached register (number,
  names, team, active, photo thumbnail only)
- [ ] The same scan is what Span (clock in/out) and Boord (piece-work at the scale) will use

**Payroll exports and lists**
- [ ] Register export and import: CSV keyed on worker number, **without** ID, bank or pay details. An import adds
  and updates, never deletes (as the suite's piece-work register)
- [ ] Missing-detail list: workers without a bank account (if paid by bank), ID or valid permit, or a rate, so
  payday has no surprises

**Simple payroll** (Bekfontein runs payroll by hand; this replaces the book, not an accountant)
- [ ] Employer details in Settings: registered name, address, PAYE and UIF reference numbers, SDL registered or
  not, normal hours per week (default 45), pay frequencies used
- [ ] Each worker is paid weekly, fortnightly or monthly. A **pay run** is one frequency and one period
  (e.g. week 3–9 March), with a pay date
- [ ] A pay run opens with a line per active worker, pre-filled from their rate: a monthly worker's salary, an
  hourly or daily worker's normal hours or days left for the office to type. Later Span (hours) and Boord
  (piece-work kg) fill these lines themselves
- [ ] Earnings lines: normal hours or days, overtime (1.5×), Sunday (2×, or 1.5× for someone who normally works
  Sundays), public holiday (2×), leave days, piece-work units × rate, allowance, bonus. A monthly worker's hourly
  rate for overtime = monthly ÷ (weekly hours × 4.33)
- [ ] Deductions:
  - UIF: 1% of remuneration from the worker and 1% from the employer, up to the UIF ceiling (in the rate table)
  - PAYE: from SARS's tax tables for the tax year (annualised per frequency, primary/secondary/tertiary rebates
    by age from the ID number). Most farm workers fall under the threshold and pay none. An office user with
    Payroll can override a line, with a reason
  - Advances and loans: an amount and an instalment, taken each run until repaid, balance shown on the payslip
  - Accommodation, food and other: typed amounts with what they are for
- [ ] Warnings, never blocks: hourly rate below the national minimum wage (in the rate table), overtime above 10
  hours a week, a net below zero, a worker with no bank details on a bank run
- [ ] Draft → **finalised** (locked; a later mistake is corrected in the next run) → **paid** (date and method
  recorded). Only Payroll users can finalise
- [ ] **Payslips** (BCEA section 33: employer, worker and occupation, period, rate, hours and overtime, each
  earning and deduction with its purpose, employer's UIF, net): A5, two to an A4 sheet, PDF, in the farm's
  language. Printed to hand out, or one per worker to share
- [ ] **Bank list**: name, bank, branch code, account, amount, as CSV to load into the farm's online banking as a
  bulk payment. **Cash list**: name, amount and the notes and coins to draw, with a signature column
- [ ] **Monthly totals for SARS and UIF**: PAYE, UIF (worker + employer), SDL (1% of payroll if registered), for
  the EMP201 the farm submits on eFiling, and a per-worker UIF declaration CSV for uFiling
- [ ] **Tax year totals** per worker (1 March – end February): gross, PAYE, UIF, as CSV to capture IRP5s in
  e@syFile
- [ ] Payroll register per run and per month, on screen and as Excel

**Rate table** (`app/Modules/Werkers/rates/<tax year>.php`, in the code, not per farm): PAYE brackets, rebates,
thresholds, UIF ceiling, SDL rate, national minimum wage with its effective date. Plaashek updates it when SARS
publishes the Budget (February) and the minimum wage changes (March), then runs `update-all.sh` before 1 March.
A run for a date the table does not cover refuses to finalise.

**Not in this module**
- Paying anyone: Plaashek prints lists; the farm pays from its bank or cash.
- Filing anything: the EMP201, UIF declarations, EMP501 and IRP5s are submitted by the farm on eFiling, uFiling
  and e@syFile from Plaashek's totals.
- Leave balances (annual, sick, family responsibility): leave days are a paid line, but balances are kept by
  hand in v1.
- Medical aid, pension or provident funds, garnishee orders, retirement annuities, fringe benefits: not at
  Bekfontein; added when a farm needs one.
- Storing copies of ID documents, contracts or permits: a POPIA and disk liability for little gain in v1.

**Protecting the details** (POPIA: ID numbers and bank details are personal information, and Boord dropped them
for that reason in September; this module holds them because payroll is its job)
- ID and passport numbers, permit numbers, bank account numbers and tax numbers are **encrypted in the database**
  (Laravel's encrypted casts, keyed by `APP_KEY`). A database dump or backup alone does not reveal them.
- Only the owner and office users given **Payroll** can see or export them, and only they see pay runs,
  payslips and the bank and cash lists; everyone else sees the register
  without them. Every view of a worker's payroll details and every payroll export is written to `audit_log`.
- Phones never receive them.
- Each farm's `APP_KEY` is also kept off the server, in the owner's password manager (never in the repo): a backup
  restored without it has its encrypted columns unreadable.
- A worker who leaves keeps their record for the period payroll and tax law require (5 years), then the
  sensitive fields are cleared by a scheduled job; the farm sets the period.
- The privacy notice the farm gives its workers names Plaashek as operator; template in the order-form pack.

### 2.5 Next: Boord

Boord (R150 pm) is the third paid module, decided 24 October 2026. Its scope is written as its own section here
before any code, from the farm-PC Boord (Bekfontein's harvest and pack-house app today) and the suite's
`boord-reuse-audit.md` and `piecework-build-scope.md`. Already settled by this plan: pickers are Werkers workers,
identified by scanning their card at the scale, and their kilograms × rate become piece-work lines in the Werkers
pay run, so a harvest week needs no typing in payroll.

### 2.6 Later modules

Rebuilt on the same core, in an order still to agree (§11), each starting from its `plaashek-suite` build scope:
Veldnotas, Span (using Werkers and feeding its pay runs), Stoor, Water, Bespuitings, Werkswinkel, Oudit, Eienaar.
Cross-module
links the website promises (Bespuitings booking against Stoor stock and Water readings and the Weather data;
Oudit drawing on Boord, Kudde and Stoor) are plain queries, because they share a database. Eienaar reads one
farm only.

---

## 3. Hosting layout

Afrihost Linux hosting. **One database per farm**, whatever modules it runs: **Bronze Pro** (R135 pm, 20
databases, 100 subdomains) holds about 19 farms beside the demo farm; **Silver Pro** (100 databases) after that.

| What | Where |
|---|---|
| Landing page | `plaashek.co.za` → `public_html/` (the `Plaashek` repo's `index.html`, `en.html`, `photos/`) |
| A farm | `<farm>.plaashek.co.za` → `/home/<user>/plaashek-<farm>/public` |
| Office | `<farm>.plaashek.co.za/admin` |
| Field PWA | `<farm>.plaashek.co.za/` (start URL of the installed app) |
| Its database / user | `<prefix>_<farm>` (cPanel prefix), ALL PRIVILEGES on that database only |
| Code and `.env` | `plaashek-<farm>/`, outside `public_html`; only `public/` is web-reachable |
| PHP | 8.3 (ea-php83) per subdomain in MultiPHP Manager |
| HTTPS | AutoSSL per subdomain; Force HTTPS only once the certificate is real |
| Cron | One cPanel cron per farm: `php artisan schedule:run` every minute (weather backfill, backups, cleanup) |

Reserved, never a farm name: `www`, `demo` (the demo farm, for trying releases and showing farmers), `mail`,
`admin`, `hek`, `app`, `api`. Everything else follows
`bowlsbuddy-app/docs/DEPLOY-AFRIHOST.md`, copied as `docs/DEPLOY-AFRIHOST.md`.

---

## 4. Code and data layout

### 4.1 Code

```
app/                      core: users, devices, settings, camps, weather, audit, export, field sync
app/Modules/Kudde/        Models, Services (HerdState, AnimalClass, CampNames...), Http, Filament, Policies
app/Modules/Weer/         OpenMeteoProxy, ResponseCache, Filament page
app/Modules/Werkers/      Models, SaIdNumber, RegisterImport, Filament resources, card print view;
                          Payroll/ (PayRun, Paye, Uif, Payslip PDF, BankList, CashList, Emp201Totals) and rates/
app/Modules/<Next>/       same shape
database/migrations/      core migrations
app/Modules/*/migrations/ each module's migrations, loaded by its service provider
public/field/             the PWA shell (sign-in, change user, home tiles, outbox, sync)
public/field/kudde/       Kudde's field screens
public/field/werkers/     card scan and worker view (vendored QR scanner, as Boord Labour's door phone)
public/field/weer/        Weather Compare's js/ (chart, series, fields, openmeteo, data, cache, dates, insights),
                          one location only, and vendor/jspdf, html2canvas, shared with the office tab
lang/af, lang/en          strings, one file per module
```

- A module registers itself through one service provider: its migrations, routes, Filament resources, field
  screens, export sheets and the sync kinds it accepts. The core never names a module.
- **Every module's tables exist in every farm's database**, enabled or not, so every farm has the same schema
  and one migration run fits all. Enabling a module only shows it.
- A module may read the core and another module's models only through that module's service classes, never its
  tables directly, so a later split stays possible.

### 4.2 Data

One farm per database, so **no `farm_id` column**: the database is the farm. English names. Module tables are
prefixed with the module code.

| Table | What | Notes |
|---|---|---|
| `settings` | Farm details, GPS, language, season start month, frost threshold, gestation length | Key/value through `App\Support\Settings`, like BowlsBuddy |
| `modules` | code, enabled, licence status, grace until, free | One row per module the farm has ever had; `weer` is free and always enabled |
| `users` | Everyone who signs in | Role: owner, manager, viewer, field, plus `plaashek_staff`. Office roles: email + password, and a Payroll tick. Field users: username or cell + PIN hash, failed tries, locked until, disabled at. `modules` they may use (default all enabled); optional `werkers_worker_id` |
| `devices` | A signed-in phone: label, token hash (Sanctum), last user, last seen, disabled at | One per phone, not per person |
| `camps` | name, sort order, archived | Shared across modules; records keep the typed name as text, as old Kudde |
| `weather_days` | date, max, min, rain, ET0, wind max | The farm's position, filled nightly; what modules stamp from |
| `audit_log` | who, what, before/after, when | Office corrections and staff access |
| `kudde_animals` | As old Kudde, plus species, `sire_id`/`dam_id` beside the parent tags, `worker_id` | `tag` unique per species; status adds `reference` |
| `kudde_events` | As old Kudde, one table for all kinds | `client_uuid` unique (nullable for office entries), `user_id`, `device_id` |
| `kudde_species` | code, names (af/en), enabled, sort order, class names, young until, gestation, weigh every, birth word, young tagged at weaning | Kudde's settings; seeded cattle, sheep, goats, pigs |
| `kudde_litters` | dam, birth event, born alive, stillborn, fostered, weaned count and date | For species whose young are tagged at weaning (pigs) |
| `kudde_photos` | file under `storage/app/photos/kudde/<animal_id>/` | `client_uuid` unique |
| `kudde_herds` | name, sort order, archived | Same rules as camps |
| `kudde_breeding` | animal, kind (bull in / AI / pregnancy check), dates, bull tag, result, by whom | Expected calving derived, never stored |
| `workers` | number (unique), first names, surname, known as, cell, photo, active | Core, under Farm settings: what every module scans and names |
| `werkers_workers` | one row per worker: identity, contact, employment, payment method | Werkers' extension of `workers`; ID/passport/permit, bank account and tax numbers encrypted |
| `werkers_rates` | worker, basis, rate (cents), effective from, set by | Append-only; newest effective row applies |
| `werkers_teams` | name, sort order, archived | |
| `werkers_pay_runs` | frequency, period start and end, pay date, status (draft, finalised, paid), paid method and date | |
| `werkers_payslips` | run, worker, gross, deductions, net, employer UIF, finalised copy of the worker's details | Frozen on finalise, so a later edit to the worker never changes a past payslip |
| `werkers_payslip_lines` | payslip, kind, quantity, rate, amount (cents), source (manual, span, boord), override reason | |
| `werkers_loans` | worker, reason, amount, instalment, balance | |
| `weer_responses` | query hash, endpoint, body (JSON), fetched at, expires at | The proxy's cache; a finished year never expires |

Carry the old models' docblocks across: they say *why* (parentage by tag, withdrawal as days, location as
text, camp archive not delete).

---

## 5. Access

| Who | Signs in with | Can |
|---|---|---|
| Field user | Username or cell + 4-digit PIN, on the phone | Capture and read in the modules the office allows them; no settings, never payroll details |
| Owner | Email + password (Filament) | Everything in the farm, including users, devices and payroll details |
| Manager | Email + password | Every enabled module, field users and devices; not office users or settings; payroll details only if given Payroll |
| Viewer | Email + password | Read and export |
| Plaashek staff | Account created by `farm:create`, every sign-in written to `audit_log` | Support; switch modules and licences |

**Field users and workers:** a field user signs in; a worker (§2.4) is employed. A foreman can be both.

**Setting up a phone:** open `<farm>.plaashek.co.za`, Add to Home Screen, sign in once with signal. The server
issues a device token (Sanctum) kept on the phone; the phone now belongs to that farm and stays signed in. Each
sync renews it; with no successful sync for 21 days it goes read-only with "Gaan na die hek vir sein" / "Find
signal to sync". An office user can sign in on a phone too.

**Shared phones:** "Change user", then username or cell + PIN. Works offline: the phone keeps the PIN hashes
(bcrypt) of the field users who have signed in on it, refreshed at each sync, and checks locally. A field user never seen
on that phone needs signal the first time.

**Rules:**
- The office creates field users, chooses their modules and sets or resets the PIN (shown once); a field user can
  change it on the phone. A field user can be linked to their Werkers record.
- Five wrong PINs lock that person for 5 minutes, on the server and on the phone.
- Disabling a person or a phone takes effect at the phone's next sync: the token is refused and the phone's
  cached PIN hashes are cleared. Records already captured offline are still accepted (the work happened) and
  flagged for the office.
- The devices page shows each phone's label, last user and last sync.
- A phone holds one farm. Signing it into another farm is allowed only after its outbox is empty.

**Kudde's field app is opened by a worker's card scan alone** (owner's call, 25 October 2026), ahead of the
field users and PINs above. **Accepted risk:** the card's QR holds only the worker number, so anyone who can
print a QR of a valid number can capture on Kudde until midnight. Limits: every record names the worker, an
inactive worker's card is refused and their session ends at their next request, card scans are limited to 20 a
minute per address, and the field API has no corrections, camp management or exports.

**Accepted risk:** a lost phone that has not synced keeps working offline until it does (at most 21 days), and a
4-digit PIN is weak on its own. The lockout, per-farm subdomain and server-side disable make that adequate for
farm records.

---

## 6. Running many farms

No cross-farm console. Instead:

- `php artisan farm:create`: asks farm name, language, owner email, GPS and modules; creates the owner, staff
  account and settings. A first-run setup page asks the same when there is no terminal (BowlsBuddy has both).
- `php artisan farm:modules kudde,boord`: switch paid modules on or off (`weer` is always on).
- `php artisan farm:licence <module> active|grace|suspended|cancelled`: grace keeps capturing 14 days, then
  read-only.
- `scripts/build-afrihost.sh`: `plaashek.zip` (with `vendor/`) and, with `--install-sql`, `install.sql`.
- `scripts/update-all.sh` (built, 24 October 2026): run every minute by a cPanel cron job, because the account
  has no shell. Upload the zip to `~/plaashek-release/` and it updates each farm, demo first: maintenance page,
  fresh folder with `.env` and `storage/` moved across, migrations, sign-in page must answer. It stops at the
  first failure, leaving that farm on its maintenance page with its old code kept, and logs to
  `~/plaashek-release/update.log` (docs/DEPLOY-AFRIHOST.md, Updates).
- `docs/FARMS.md`: the farm table (subdomain, folder, database, language, modules, licence, live date, disk
  use), like BowlsBuddy's Clubs table. No passwords.

---

## 7. Field app (offline)

- One plain JS PWA, no Node on the server. The shell (sign-in, change user, home tiles, outbox, sync) is core;
  each module adds its screens. Start Kudde's from old Kudde's `frontend/field/`.
- The home screen shows a tile per module that is enabled for the farm **and** allowed for the signed-in user.
- One IndexedDB outbox for every module. Each record carries `kind` (`kudde.event`, `kudde.photo`...) and a
  phone-made `client_uuid`; the server upserts on it, a replay is harmless, a refused record stays on the phone
  marked failed. One module's error never holds another's records back.
- Cached lists (animals, camps, products, and later each module's own) so everything can be browsed offline.
- **Never bump the IndexedDB version to add a field**; put new data inside the record.
- `af`/`en` strings picked from the farm's settings at sign-in.
- Service worker cache name changes with every release (the build stamps it, so no manual bump).

---

## 8. Quality

Same checks as BowlsBuddy, in CI on every push:

- Pint, Larastan level 6, Pest (MariaDB), `composer audit`
- Playwright at phone and desktop widths: sign in a phone, switch user offline, capture offline, come back
  online, sync, check the office sees it
- **Isolation test:** two farm databases; a device token, session, PIN or photo URL from farm A gets nothing from
  farm B's install
- **Module test:** with Kudde switched off, no tab, no tile, and its API refuses; Weather stays on whatever the
  licence says
- **Proxy test:** `/weer/api` forwards only to allowlisted Open-Meteo hosts, never returns the API key, serves a
  finished year from the cache without a call, and refuses a signed-out request
- **Payroll test:** a manager without Payroll never sees encrypted fields in any page, API response or export;
  the database holds them encrypted; every payroll view and export writes `audit_log`; a phone's register sync
  carries no ID, bank or pay field
- SA ID numbers: check digit, date of birth and sex, with known good and bad numbers
- Card print: Playwright prints a sheet to PDF and checks each card is 58 × 78 mm and its QR decodes to the number
- **Payroll tests**: PAYE checked against SARS's own examples and calculator for each frequency and age band;
  UIF at and above the ceiling; overtime, Sunday and public holiday rates; a monthly worker's hourly rate; loans
  running down to nil; a finalised run cannot change; a payslip carries every BCEA section 33 item; bank list
  totals equal the run's net; the cash list's notes and coins add up
- Weather Compare's `node:test` unit tests (dates, request planning, aggregation, series, chart, insights) carried
  across and run in CI beside Pest
- The old Kudde's 130 pytest cases rewritten as Pest tests first; they are the spec for §2.2 (animals 14,
  events 22, migrations 17, weather 11, camps 10, security 10, dashboard 8, app 8, classes 6, treatments 6,
  photos 6, calvings 5, export 4, bulk treatment 3). `test_security` becomes the access tests in §5.

---

## 9. Backups

- Afrihost's Afrires keeps 14 days and only their support can restore.
- So the scheduler writes a nightly SQL dump plus photos per farm to `storage/backups/` (keep 14), and the
  owner can download one from the office (BowlsBuddy's Download backup).
- Weekly off-site copy of every farm's dump, kept 30 days or more.
- Restore drill quarterly against a copy of a real farm.
- Disk: 7.81 GB on Bronze Pro, shared by every farm. Photos now, voice notes later (Veldnotas) are the risk;
  resize photos on the phone (~200 KB) and track disk use per farm in `docs/FARMS.md`. Decide on a bigger
  package or outside file storage before Veldnotas.

---

## 10. Phases

| Phase | What | Done when | Estimate |
|---|---|---|---|
| 0 | Skeleton from BowlsBuddy (Laravel 12, Filament 4, CI, session hook, build script, deploy doc); Afrihost package (Bronze Pro on `kregg.aserv.co.za`), farm subdomains' DNS pointed at it, landing page | Demo farm live at `demo.plaashek.co.za` over HTTPS (done 24 October 2026) | ½ week |
| 1 | Minimal core: modules table and loading, `farm:create`, setup page, office login and roles, Farm settings, `af`/`en`, audit log | A farm can be created, its owner signs in and sets the farm's position | 1 week |
| 2 | Weather: proxy and cache, `weather_days`, Weather Compare's screens at `/weer` and in the office, rain record, frost, PDF, offline | Bekfontein's forecast, rain record and a year-against-year chart, offline after first load | 1½ weeks |
| 3 | Bekfontein live on Weather (free), `update-all.sh`, privacy text | Bekfontein uses it for a fortnight | ½ week + trial |
| 4 | Kudde domain: animals, events, calvings, derived herd state; Pest port of the old tests | Old Kudde's cases pass | 1½–2 weeks |
| 4b | Livestock types: species in Settings with their rules, tags per species, births with several young, pig litters tagged at weaning, species in the field app and export | A farm with cattle and sheep captures both, each counted in its own classes | 1½ weeks |
| 5 | Kudde office tab: dashboard, herd, animal page, corrections, export; licence commands | The office's whole day passes as a browser test | 1–1½ weeks |
| 6 | Field: field-user sign-in, PIN switch offline, devices page, PWA shell and home tiles (Weather moves in), outbox, disable, 21-day window, Kudde screens | Offline capture → sync → office sees it, in Playwright | 2 weeks |
| 7 | Photos, herds and breeding, lineage (pedigree, offspring, sire suggestion, inbreeding warning), backups, importer | A farm-PC Kudde imports cleanly | 1½ weeks |
| 8 | Bekfontein live on Kudde, order-form text | One real farm live on Kudde for a month | ½ week + trial |
| 9 | Werkers register: SA ID check, rates, encryption and the Payroll permission, photos, card print, field scan, register CSV, worker privacy notice | Bekfontein's workers registered (imported from Boord's register by number), cards printed and scanned | 2 weeks |
| 10 | Werkers payroll: settings, rate table, pay runs, UIF and PAYE, loans, payslips, bank and cash lists, SARS and UIF totals | One Bekfontein pay run done in Plaashek beside the manual book, matching to the cent, then a second without the book | 2½ weeks + one pay cycle |
| 11 | Boord scope written (§2.5) | Scope agreed | ½ week |

Roughly 16–18 weeks part-time with AI help, to Boord's scope. Werkers waits for Bekfontein to be live on Kudde +
Weather (§1.3). Bekfontein pays R150 per month (hosting) on Weather alone, R300 with Kudde, R450 with Werkers.

---

## 11. Open questions

Answered 24 October 2026: pilot is **Bekfontein**; the Afrihost package goes on the **same ClientZone login** as
BowlsBuddy; **pricing** in §1.4; **herds and breeding go into Kudde v1** (§2.2); **one Eienaar per farm**; the
website's privacy wording is noted for rewording; Bekfontein pays **by hand**, so Werkers gets a simple payroll;
cards stay **Boord's 58 × 78 mm**; **Boord** is the next module after Werkers.

Still open:
1. **Bekfontein's pay**: which workers are weekly, fortnightly or monthly, and who is paid by bank or cash; which
   bank (for the bulk-payment CSV layout).
2. **Accountant check**: have the farm's accountant or a payroll practitioner review the PAYE and UIF results and
   a payslip before the first real run. Plaashek states figures; the farm stays responsible for what it files.
3. **Module order after Boord.**
4. **Website changes** before the first paying farm:
   a. Privacy: replace "your data never leaves the farm … nothing is … shared with a third party" with wording
      true of hosted data and AI (stored in South Africa, belongs to the farm, never sold).
   b. Eienaar: drop "across farms".
   c. Add the Weather module, free with every farm, and the prices if they are to be public.
5. **Open-Meteo plan:** the free API until the first paying farm (decided). Then buy the commercial plan, set
   `OPEN_METEO_API_KEY` on every farm, and check its price is carried by the hosting fee.
