#!/usr/bin/env bash
#
# Builds the one file to upload to a farm's folder on Afrihost cPanel hosting (docs/DEPLOY-AFRIHOST.md):
#
#   plaashek.zip   the whole app, vendor/ and public/ included -> extract inside the farm's folder
#                  (e.g. plaashek-bekfontein/), whose subdomain has its document root set to its public/
#   update-all.sh  the updater: upload it with the zip to ~/plaashek-release/ and every farm is updated by
#                  its cron job (scripts/update-all.sh)
#
# A new farm then runs `php artisan migrate --force` and `php artisan farm:create` in cPanel > Terminal, or
# opens its subdomain and fills in the first-run setup page. Committed files only, once the repo has a
# commit; before that, the working tree without vendor/, .env and storage contents. Usage:
#
#   scripts/build-afrihost.sh [output-dir]

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$(mkdir -p "${1:-$ROOT/build/afrihost}" && cd "${1:-$ROOT/build/afrihost}" && pwd)"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

if git -C "$ROOT" rev-parse --verify -q HEAD >/dev/null 2>&1; then
    echo "Staging committed files..."
    git -C "$ROOT" archive HEAD | tar -x -C "$STAGE"
else
    echo "No commit yet: staging the working tree..."
    tar -C "$ROOT" --exclude=./vendor --exclude=./node_modules --exclude=./build --exclude=./.env \
        --exclude='./storage/logs/*.log' --exclude='./storage/framework/sessions/*' \
        --exclude='./storage/framework/views/*.php' --exclude='./storage/framework/cache/data/*' -cf - . | tar -x -C "$STAGE"
fi

echo "Installing production dependencies..."
(cd "$STAGE" && composer install --no-dev --no-interaction --no-progress --prefer-dist \
    --optimize-autoloader --classmap-authoritative --quiet)

# Drop package tests and docs that git-cloned packages bring along.
php "$ROOT/scripts/strip-export-ignored.php" "$STAGE/vendor"
(cd "$STAGE" && composer dump-autoload --no-dev --classmap-authoritative --no-interaction --quiet)

# Not needed on the server.
rm -rf "$STAGE"/{tests,.github,.claude,docs,scripts,phpunit.xml,phpstan.neon,.env.example,.editorconfig,.gitattributes,package.json}

# Every autoloaded class file must still be there after stripping.
(cd "$STAGE" && php -r '
    $missing = 0;
    foreach (require "vendor/composer/autoload_classmap.php" as $class => $file) {
        if (! is_file($file)) { fwrite(STDERR, "Missing: $file\n"); if (++$missing > 5) break; }
    }
    exit($missing ? 1 : 0);
') || { echo "BUILD FAILED: vendor files missing after stripping" >&2; exit 1; }

echo "Zipping..."
rm -f "$OUT/plaashek.zip"
# Composer falls back to git clones where GitHub zip downloads are blocked (as in Claude sessions), so keep
# .git history and package test suites out of the upload.
(cd "$STAGE" && zip -qr -9 "$OUT/plaashek.zip" . -x '*/.git/*' '*/.git' 'vendor/*/*/tests/*' 'vendor/*/*/.github/*')

echo "Checking..."
listing="$(unzip -l "$OUT/plaashek.zip")"
fail() { echo "BUILD FAILED: $1" >&2; exit 1; }
grep -q ' vendor/autoload\.php$' <<<"$listing" || fail "no vendor/autoload.php"
grep -q ' artisan$' <<<"$listing" || fail "no artisan"
grep -q ' public/index\.php$' <<<"$listing" || fail "no public/index.php"
grep -q ' public/\.htaccess$' <<<"$listing" || fail "no public/.htaccess"
grep -q ' public/css/filament/' <<<"$listing" || fail "no Filament assets"
grep -q ' public/modules/weer/js/app\.js$' <<<"$listing" || fail "no Weather screens"
grep -q '/\.git/' <<<"$listing" && fail "contains .git/ folders"
grep -q ' \.env$' <<<"$listing" && fail "contains a .env file"

cp "$ROOT/scripts/update-all.sh" "$OUT/update-all.sh"

echo "Done: $OUT"
ls -lh "$OUT"
