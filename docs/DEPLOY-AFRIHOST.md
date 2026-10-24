# Deploying to Afrihost

Each farm gets its own subdomain, folder and database on Afrihost cPanel hosting, all running the same build.
It follows BowlsBuddy's `docs/DEPLOY-AFRIHOST.md`, which has the details on this kind of account (jailed SSH,
cPanel Terminal, AutoSSL, MultiPHP, Afrires backups). No passwords or keys are kept in this file.

## The account

A separate Afrihost Linux hosting package for Plaashek, on the same ClientZone login as BowlsBuddy.

| What | Value |
|---|---|
| Account | Active (ClientZone > Web Hosting > plaashek.co.za) |
| Server | `arno.aserv.co.za` |
| Domain | `plaashek.co.za`, expires 12 Sep 2027 |
| Package, cPanel user, database limit | not recorded yet |

Bronze Pro (20 databases) holds about 19 farms beside a test farm; Silver Pro (100 databases) after that.

**DNS (24 October 2026):** `www.plaashek.co.za` resolves to `185.199.111.153`, a GitHub Pages address: the
landing page is still served from the `Plaashek` repo, not from this server. That can stay. Each farm's
subdomain (`<farm>.plaashek.co.za`, and `test.`) needs its own A record pointing at `arno.aserv.co.za`'s IP,
added in cPanel > Zone Editor (or ClientZone DNS), before AutoSSL can issue its certificate.

**Weather data:** leave `OPEN_METEO_API_KEY` blank while Plaashek is in development and marketing (the free
API). Set it on every farm once farms pay.

`public_html` holds only the landing page (the `Plaashek` repo's `index.html`, `en.html`, `photos/`). Farm names
never used as subdomains: `www`, `test`, `mail`, `admin`, `hek`, `app`, `api`.

## What gets built

```bash
scripts/build-afrihost.sh        # build/afrihost/plaashek.zip
```

## New farm

1. **Subdomain:** cPanel > Domains > Create A New Domain: `<farm>.plaashek.co.za`, untick **Share document
   root**, document root `plaashek-<farm>/public`.
2. **PHP:** cPanel > MultiPHP Manager: PHP 8.3 (ea-php83) for the subdomain.
3. **Database:** cPanel > Database Wizard: database and user `<farm>`, ALL PRIVILEGES on that database only.
   That user must never be granted another farm's database.
4. **Files:** File Manager > `plaashek-<farm>/`: upload `plaashek.zip`, extract it, delete the zip.
5. **Settings:** create `.env` from `.env.afrihost.example`: `APP_URL`, the database, `APP_KEY`
   (`php artisan key:generate --show`), `APP_LOCALE`, `OPEN_METEO_API_KEY`. Put the `APP_KEY` in the owner's
   password manager too.
6. **Tables and farm:** cPanel > Terminal: `cd ~/plaashek-<farm> && php artisan migrate --force && php artisan
   farm:create`. Without a terminal, open the subdomain after migrating: the first-run setup page asks the same.
7. **Cron:** cPanel > Cron Jobs, every minute:
   `cd ~/plaashek-<farm> && php artisan schedule:run >> /dev/null 2>&1` (the nightly weather fill).
8. **HTTPS:** wait for AutoSSL's certificate, then Force HTTPS Redirect. Logins and "Use my location" need it.
9. Sign in at `/admin` as the owner, check Farm settings has the farm's position, open Weather. Add the farm
   to `docs/FARMS.md`.

## Updates

1. Build the zip.
2. In cPanel > Terminal, for each farm: unpack it over `plaashek-<farm>/` and run `php artisan migrate --force`.
   `.env` and `storage/` are kept (the zip has neither). `scripts/update-all.sh` will do every farm in one go
   (PLAN.md Phase 3).

## Support access

`php artisan farm:staff-password` gives Plaashek's support account a fresh password and writes it to the farm's
audit log, so the owner can see when support signed in.
