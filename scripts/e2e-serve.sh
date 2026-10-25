#!/usr/bin/env bash
# Serves a fresh e2e farm for the browser tests (tests/e2e): the plaashek_e2e database wiped and seeded with
# OfficeDaySeeder, then `php artisan serve`. The environment overrides .env, so the farm's own database is never
# touched. Usage: scripts/e2e-serve.sh [port]
set -euo pipefail
cd "$(dirname "$0")/.."

export APP_ENV=e2e APP_DEBUG=true DB_DATABASE="${E2E_DB_DATABASE:-plaashek_e2e}"
export PORT="${1:-8123}" APP_URL="http://127.0.0.1:${1:-8123}"
# Files and sessions of their own, so a run leaves nothing in the farm's storage worth keeping.
export SESSION_DRIVER=file CACHE_STORE=array

php artisan migrate:fresh --force --seed --seeder='Database\Seeders\OfficeDaySeeder' >/dev/null
exec php artisan serve --host=127.0.0.1 --port="$PORT" --no-reload
