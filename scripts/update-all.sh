#!/usr/bin/env bash
#
# Updates every farm on the Afrihost account to a new build (PLAN.md section 6). The account has no shell, so
# this runs from a cPanel cron job, every minute, and does nothing until there is something to do:
#
#   1. Upload plaashek.zip (from scripts/build-afrihost.sh) and this script to ~/plaashek-release/.
#   2. The next minute's run picks the zip up and updates each ~/plaashek-<farm>/ folder, demo first, so a
#      release that breaks shows itself on the demo farm before any real farm.
#   3. Read ~/plaashek-release/update.log.
#
# Cron line (cPanel > Cron Jobs, Once Per Minute):
#
#   /bin/bash ~/plaashek-release/update-all.sh >/dev/null 2>&1
#
# Per farm: maintenance mode on; the zip unpacked into a fresh folder beside it; .env and storage/ moved
# across (the zip has neither, and storage/ holds the farm's logs, sessions and, later, photos and backups);
# the folders swapped, so files a release removed are really gone; migrations run; maintenance mode off;
# the sign-in page must answer 200. The old code is deleted once the farm is up again.
#
# The first farm that fails stops the run: it is left in maintenance mode with its old code kept as
# plaashek-<farm>.previous, the farms after it are not touched, and the zip is renamed plaashek.zip.failed so
# the next minute doesn't try again. update.log says what failed. To put the old code back: rename the farm's
# folder to plaashek-<farm>.broken and plaashek-<farm>.previous to plaashek-<farm>, then move .env and
# storage/ back into it. Migrations are additive, so the old code runs on the new tables.
#
# Options, for running it by hand or from a one-off cron line:
#   FARMS="demo bekfontein"   only these farms, in this order
#   PHP=/path/to/php          default /usr/local/bin/ea-php83 (cPanel's PHP 8.3), else `php`

set -uo pipefail

RELEASE_DIR="${RELEASE_DIR:-$HOME/plaashek-release}"
ZIP="$RELEASE_DIR/plaashek.zip"
WORKING="$RELEASE_DIR/plaashek.zip.working"
LOG="$RELEASE_DIR/update.log"
PHP="${PHP:-/usr/local/bin/ea-php83}"
[[ -x "$PHP" ]] || PHP="$(command -v php || true)"

# Nothing uploaded: the every-minute run ends here, quietly.
[[ -f "$ZIP" ]] || exit 0

# A run already going (a big zip on a slow day can outlast a minute).
if [[ -f "$WORKING" ]]; then
    exit 0
fi
mv "$ZIP" "$WORKING" || exit 1

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >>"$LOG"; }

finish() {
    local outcome="$1"
    if [[ "$outcome" == ok ]]; then
        rm -f "$WORKING"
        log "Done: every farm updated."
    else
        mv -f "$WORKING" "$ZIP.failed"
        log "STOPPED. Fix the farm above, then upload the zip again (and delete plaashek.zip.failed)."
    fi
    log "----"
    exit 0
}

log "Update started: $(du -h "$WORKING" | cut -f1) zip, PHP $([[ -n "$PHP" ]] && "$PHP" -r 'echo PHP_VERSION;' 2>/dev/null)"

if [[ -z "$PHP" ]]; then
    log "No PHP found."
    finish failed
fi

# The zip must be a whole Plaashek build before anything is touched.
if ! "$PHP" -r '
    $z = new ZipArchive();
    if ($z->open($argv[1]) !== true) exit(1);
    foreach (["artisan", "vendor/autoload.php", "public/index.php"] as $f) if ($z->locateName($f) === false) exit(2);
    if ($z->locateName(".env") !== false) exit(3);
' "$WORKING"; then
    log "The zip is not a Plaashek build from scripts/build-afrihost.sh (or it holds a .env)."
    finish failed
fi

# The farms: every ~/plaashek-<name>/ with an artisan and a .env, demo first, or FARMS in its order.
farms=()
if [[ -n "${FARMS:-}" ]]; then
    read -r -a farms <<<"$FARMS"
else
    [[ -f "$HOME/plaashek-demo/artisan" ]] && farms+=(demo)
    for dir in "$HOME"/plaashek-*/; do
        name="$(basename "$dir")"
        name="${name#plaashek-}"
        [[ "$name" == demo || "$name" == release || "$name" == *.* ]] && continue
        [[ -f "$dir/artisan" && -f "$dir/.env" ]] && farms+=("$name")
    done
fi

if [[ ${#farms[@]} -eq 0 ]]; then
    log "No farm folders found under $HOME."
    finish failed
fi
log "Farms: ${farms[*]}"

update_farm() {
    local farm="$1"
    local current="$HOME/plaashek-$farm"
    local next="$current.next"
    local previous="$current.previous"
    local out

    if [[ ! -f "$current/artisan" || ! -f "$current/.env" ]]; then
        log "[$farm] no Plaashek install at $current"
        return 1
    fi

    rm -rf "$next"
    if ! out="$("$PHP" -r '
        $z = new ZipArchive();
        if ($z->open($argv[1]) !== true || ! $z->extractTo($argv[2])) { fwrite(STDERR, "cannot extract\n"); exit(1); }
    ' "$WORKING" "$next" 2>&1)"; then
        log "[$farm] unpacking failed: $out"
        rm -rf "$next"
        return 1
    fi

    # From here the farm shows its maintenance page instead of half-copied code.
    (cd "$current" && "$PHP" artisan down --retry=60 >/dev/null 2>&1)

    rm -rf "$next/storage"
    if ! mv "$current/.env" "$next/.env" || ! mv "$current/storage" "$next/storage"; then
        log "[$farm] could not move .env and storage/ into the new code; the farm is in maintenance mode at $current"
        return 1
    fi
    chmod 600 "$next/.env"

    rm -rf "$previous"
    if ! mv "$current" "$previous" || ! mv "$next" "$current"; then
        log "[$farm] could not swap the folders; check $current, $next and $previous"
        return 1
    fi

    if ! out="$(cd "$current" && "$PHP" artisan migrate --force --no-interaction 2>&1)"; then
        log "[$farm] migrate failed; the farm stays in maintenance mode. Old code in $previous."
        log "$out"
        return 1
    fi
    echo "$out" | grep -E 'DONE|FAIL' | sed 's/^ *//' | while read -r line; do log "[$farm] migrated: $line"; done

    (cd "$current" && "$PHP" artisan optimize:clear >/dev/null 2>&1)
    (cd "$current" && "$PHP" artisan up >/dev/null 2>&1)

    # The farm must answer before it counts as updated. The sign-in page, not Laravel's /up: /up answers 200
    # even in maintenance mode, while /admin/login needs the app, its database (sessions) and maintenance off.
    local url
    url="$(grep -E '^APP_URL=' "$current/.env" | head -1 | cut -d= -f2- | tr -d '"'"'"' ')"
    if [[ -n "$url" ]] && command -v curl >/dev/null 2>&1; then
        local code
        code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$url/admin/login" || true)"
        if [[ "$code" != 200 ]]; then
            (cd "$current" && "$PHP" artisan down --retry=60 >/dev/null 2>&1)
            log "[$farm] $url/admin/login answered '$code' after the update; put back in maintenance mode. Old code in $previous."
            return 1
        fi
        log "[$farm] up: $url/admin/login answered 200"
    elif ! out="$(cd "$current" && "$PHP" artisan --version 2>&1)"; then
        (cd "$current" && "$PHP" artisan down --retry=60 >/dev/null 2>&1)
        log "[$farm] the new code does not start: $out"
        return 1
    else
        log "[$farm] up (no curl to check $url/admin/login): $out"
    fi

    rm -rf "$previous"
    return 0
}

for farm in "${farms[@]}"; do
    log "[$farm] updating"
    update_farm "$farm" || finish failed
done

finish ok
