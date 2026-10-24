#!/bin/bash
#
# SessionStart hook for Claude Code on the web: PHP and npm dependencies, a local MariaDB with the
# databases the app, the tests and the browser tests use, and a .env. Idempotent; the container is
# cached after the first run, so later sessions only start MariaDB again.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
    exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(dirname "$0")/../..}"

export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_NO_INTERACTION=1

log() { echo "[session-start] $*" >&2; }

# --- MariaDB -------------------------------------------------------------------------------------
if ! command -v mariadbd >/dev/null 2>&1 && ! command -v mysqld >/dev/null 2>&1; then
    log "Installing MariaDB"
    apt-get update -qq >/dev/null
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mariadb-server >/dev/null
fi

if ! mysqladmin ping >/dev/null 2>&1; then
    log "Starting MariaDB"
    mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld 2>/dev/null || true
    (mysqld_safe --user=mysql >/tmp/mysqld_safe.log 2>&1 &)
    for _ in $(seq 1 60); do
        mysqladmin ping >/dev/null 2>&1 && break
        sleep 1
    done
fi

mysql -e "
    CREATE DATABASE IF NOT EXISTS plaashek;
    CREATE DATABASE IF NOT EXISTS plaashek_test;
    CREATE DATABASE IF NOT EXISTS plaashek_e2e;
    CREATE USER IF NOT EXISTS 'pk'@'127.0.0.1' IDENTIFIED BY 'pk';
    CREATE USER IF NOT EXISTS 'pk'@'localhost' IDENTIFIED BY 'pk';
    GRANT ALL ON *.* TO 'pk'@'127.0.0.1';
    GRANT ALL ON *.* TO 'pk'@'localhost';
    FLUSH PRIVILEGES;"

# --- Composer ------------------------------------------------------------------------------------
# The egress proxy refuses GitHub's zipball API (403), so packages are cloned from source instead.
# A package the lock file lists without a source (phpstan/phpstan) is cloned at its locked commit and
# zipped into Composer's download cache under the name Composer looks for.
if [ ! -f vendor/autoload.php ] || [ composer.lock -nt vendor/autoload.php ]; then
    cache_dir="$(composer config --global cache-files-dir 2>/dev/null || echo "$HOME/.cache/composer/files")"

    php -r '
        $lock = json_decode(file_get_contents("composer.lock"), true);
        foreach (array_merge($lock["packages"], $lock["packages-dev"] ?? []) as $p) {
            if (empty($p["source"]) && ($p["dist"]["type"] ?? "") === "zip"
                && preg_match("~^https://api\.github\.com/repos/([^/]+/[^/]+)/zipball/~", $p["dist"]["url"], $m)) {
                echo $p["name"], " ", $m[1], " ", $p["dist"]["reference"], " ", $p["dist"]["url"], "\n";
            }
        }' | while read -r name repo ref url; do
        target="$cache_dir/$name/$(php -r 'echo sha1($argv[1]);' "$url").zip"
        [ -f "$target" ] && continue
        log "Seeding Composer cache for $name"
        work="$(mktemp -d)"
        git clone -q --filter=blob:none "https://github.com/$repo.git" "$work/src"
        git -C "$work/src" checkout -q "$ref"
        rm -rf "$work/src/.git"
        mv "$work/src" "$work/${repo//\//-}-${ref:0:7}"
        mkdir -p "$(dirname "$target")"
        (cd "$work" && zip -qr "$target" "${repo//\//-}-${ref:0:7}")
        rm -rf "$work"
    done

    log "Installing PHP dependencies"
    composer install --prefer-source --no-progress >/tmp/composer-install.log 2>&1 \
        || { tail -30 /tmp/composer-install.log >&2; exit 1; }
fi

# --- npm (Playwright test runner; Chromium is preinstalled) --------------------------------------
if [ -f package.json ] && [ ! -d node_modules/@playwright/test ]; then
    log "Installing npm dependencies"
    npm install --no-audit --no-fund >/dev/null
fi

# --- .env ----------------------------------------------------------------------------------------
if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force >/dev/null
fi

php artisan migrate --force --no-interaction >/dev/null

if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
    echo 'export COMPOSER_ALLOW_SUPERUSER=1' >>"$CLAUDE_ENV_FILE"
    echo 'export E2E_CHROMIUM=/opt/pw-browsers/chromium' >>"$CLAUDE_ENV_FILE"
fi

log "Ready"
