#!/bin/sh
set -e

is_valid_var() {
  [ -n "${1:-}" ] || return 1
  case "$1" in
    *'${{'*|*'}}'*) return 1 ;;
  esac
  return 0
}

sanitize_var() {
  if is_valid_var "${1:-}"; then
    printf '%s' "$1"
  fi
}

is_railway() {
  [ -n "${RAILWAY_ENVIRONMENT:-}" ] || [ -n "${RAILWAY_PROJECT_ID:-}" ] || [ -n "${RAILWAY_SERVICE_ID:-}" ]
}

# When DATABASE_URL=${{MySQL.MYSQL_URL}} resolves empty (MYSQL_URL missing on MySQL service),
# build the same URL Railway's plugin would from MYSQLHOST + credentials.
synthesize_railway_database_url() {
  [ -n "${DATABASE_URL:-}" ] && return 0
  is_railway || return 0

  _user="$(sanitize_var "${MYSQLUSER:-}${MYSQL_USER:-}")"
  _pass="$(sanitize_var "${MYSQLPASSWORD:-}${MYSQL_PASSWORD:-}")"
  _db="$(sanitize_var "${MYSQLDATABASE:-}${MYSQL_DATABASE:-}")"
  _host="$(sanitize_var "${MYSQLHOST:-}${MYSQL_TCP_HOST:-}")"
  _port="$(sanitize_var "${MYSQLPORT:-}${MYSQL_TCP_PORT:-3306}")"

  if [ -z "${_user:-}" ]; then _user="revenue"; fi
  if [ -z "${_pass:-}" ]; then _pass="revenue_secret"; fi
  if [ -z "${_db:-}" ]; then _db="revenue_db"; fi
  if [ -z "${_port:-}" ]; then _port="3306"; fi

  if [ -z "${_host:-}" ]; then
    _svc="${RAILWAY_MYSQL_SERVICE_NAME:-MySQL}"
    _lc="$(printf '%s' "$_svc" | tr '[:upper:]' '[:lower:]')"
    for candidate in "${_lc}.railway.internal" "mysql.railway.internal"; do
      if getent hosts "$candidate" >/dev/null 2>&1; then
        _host="$candidate"
        break
      fi
    done
    [ -z "${_host:-}" ] && _host="${_lc}.railway.internal"
  fi

  export MYSQLUSER="$_user"
  export MYSQLPASSWORD="$_pass"
  export MYSQLDATABASE="$_db"
  export MYSQLHOST="$_host"
  export MYSQLPORT="$_port"
  export DATABASE_URL="mysql://${_user}:${_pass}@${_host}:${_port}/${_db}"
  export DB_HOST="$_host"
  export DB_PORT="$_port"
  export DB_USERNAME="$_user"
  export DB_PASSWORD="$_pass"
  export DB_DATABASE="$_db"
  export DB_CONNECTION=mysql
  export DB_URL="$DATABASE_URL"

  echo "Railway: synthesized DATABASE_URL from MYSQLHOST/credentials (DATABASE_URL=\${{MySQL.MYSQL_URL}} was empty)"
}

configure_railway_defaults() {
  is_railway || return 0

  export BROADCAST_CONNECTION="${BROADCAST_CONNECTION:-reverb}"
  export QUEUE_CONNECTION=sync
  export REVERB_APP_ID="${REVERB_APP_ID:-production}"
  export REVERB_APP_KEY="${REVERB_APP_KEY:-production-reverb-key}"
  export REVERB_APP_SECRET="${REVERB_APP_SECRET:-production-reverb-secret}"
  export REVERB_HOST="${REVERB_HOST:-127.0.0.1}"
  export REVERB_PORT="${REVERB_PORT:-8080}"
  export REVERB_SCHEME="${REVERB_SCHEME:-http}"
  export REVERB_SERVER_HOST="${REVERB_SERVER_HOST:-0.0.0.0}"
  export REVERB_SERVER_PORT="${REVERB_SERVER_PORT:-8080}"
}

resolve_db_config() {
  DATABASE_URL="$(sanitize_var "${DATABASE_URL:-}")"
  MYSQL_URL="$(sanitize_var "${MYSQL_URL:-}")"
  MYSQLHOST="$(sanitize_var "${MYSQLHOST:-}")"
  MYSQLPORT="$(sanitize_var "${MYSQLPORT:-}")"
  MYSQLUSER="$(sanitize_var "${MYSQLUSER:-}${MYSQL_USER:-}")"
  MYSQLPASSWORD="$(sanitize_var "${MYSQLPASSWORD:-}${MYSQL_PASSWORD:-}")"
  MYSQLDATABASE="$(sanitize_var "${MYSQLDATABASE:-}${MYSQL_DATABASE:-}")"
  MYSQL_TCP_HOST="$(sanitize_var "${MYSQL_TCP_HOST:-}")"
  MYSQL_TCP_PORT="$(sanitize_var "${MYSQL_TCP_PORT:-}")"

  export DATABASE_URL MYSQL_URL MYSQLHOST MYSQLPORT MYSQLUSER MYSQLPASSWORD MYSQLDATABASE MYSQL_TCP_HOST MYSQL_TCP_PORT

  if [ -z "${MYSQLHOST:-}" ] && [ -n "${MYSQL_TCP_HOST:-}" ]; then
    export MYSQLHOST="$MYSQL_TCP_HOST"
    export MYSQLPORT="${MYSQL_TCP_PORT:-3306}"
  fi

  if [ -n "${MYSQL_URL:-}" ]; then
    export DATABASE_URL="$MYSQL_URL"
  elif [ -z "${DATABASE_URL:-}" ] && [ -n "${MYSQLHOST:-}" ]; then
    export MYSQLUSER="${MYSQLUSER:-root}"
    export MYSQLPASSWORD="${MYSQLPASSWORD:-${DB_PASSWORD:-}}"
    export MYSQLDATABASE="${MYSQLDATABASE:-${DB_DATABASE:-revenue_db}}"
    export DATABASE_URL="mysql://${MYSQLUSER}:${MYSQLPASSWORD}@${MYSQLHOST}:${MYSQLPORT:-3306}/${MYSQLDATABASE}"
  fi

  if [ -n "${MYSQLHOST:-}" ]; then
    export DB_HOST="$MYSQLHOST"
    export DB_PORT="${MYSQLPORT:-3306}"
    export DB_USERNAME="${MYSQLUSER:-${DB_USERNAME:-root}}"
    export DB_PASSWORD="${MYSQLPASSWORD:-${DB_PASSWORD:-}}"
    export DB_DATABASE="${MYSQLDATABASE:-${DB_DATABASE:-revenue_db}}"
  fi

  if [ -z "${DATABASE_URL:-}" ] && [ -n "${DB_HOST:-}" ] && [ -n "${DB_DATABASE:-}" ]; then
    export DB_USERNAME="${DB_USERNAME:-root}"
    export DB_PASSWORD="${DB_PASSWORD:-}"
    export DB_PORT="${DB_PORT:-3306}"
    export DATABASE_URL="mysql://${DB_USERNAME}:${DB_PASSWORD}@${DB_HOST}:${DB_PORT}/${DB_DATABASE}"
  fi

  synthesize_railway_database_url

  if [ -n "${DATABASE_URL:-}" ]; then
    export DB_CONNECTION=mysql
    export DB_URL="$DATABASE_URL"
  fi
}

write_runtime_env() {
  php <<'PHP'
<?php
$vars = [
    'DB_CONNECTION' => getenv('DB_CONNECTION') ?: 'mysql',
    'DATABASE_URL' => getenv('DATABASE_URL') ?: '',
    'DB_URL' => getenv('DB_URL') ?: getenv('DATABASE_URL') ?: '',
    'DB_HOST' => getenv('DB_HOST') ?: '',
    'DB_PORT' => getenv('DB_PORT') ?: '3306',
    'DB_DATABASE' => getenv('DB_DATABASE') ?: '',
    'DB_USERNAME' => getenv('DB_USERNAME') ?: '',
    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: '',
    'BROADCAST_CONNECTION' => getenv('BROADCAST_CONNECTION') ?: '',
    'QUEUE_CONNECTION' => getenv('QUEUE_CONNECTION') ?: '',
    'REVERB_APP_ID' => getenv('REVERB_APP_ID') ?: '',
    'REVERB_APP_KEY' => getenv('REVERB_APP_KEY') ?: '',
    'REVERB_APP_SECRET' => getenv('REVERB_APP_SECRET') ?: '',
    'REVERB_HOST' => getenv('REVERB_HOST') ?: '',
    'REVERB_PORT' => getenv('REVERB_PORT') ?: '',
    'REVERB_SCHEME' => getenv('REVERB_SCHEME') ?: '',
    'REVERB_SERVER_HOST' => getenv('REVERB_SERVER_HOST') ?: '',
    'REVERB_SERVER_PORT' => getenv('REVERB_SERVER_PORT') ?: '',
];
$always = ['DB_CONNECTION', 'DB_PORT', 'BROADCAST_CONNECTION', 'QUEUE_CONNECTION', 'REVERB_PORT', 'REVERB_SERVER_PORT'];
$lines = [];
foreach ($vars as $key => $value) {
    if ($value === '' && ! in_array($key, $always, true)) {
        continue;
    }
    if (preg_match('/[\s#="\']/', $value)) {
        $value = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
    $lines[] = $key . '=' . $value;
}
file_put_contents('/var/www/html/.env', implode("\n", $lines) . "\n");
PHP
}

configure_railway_defaults
resolve_db_config
write_runtime_env

if [ -n "${RAILWAY_PUBLIC_DOMAIN:-}" ] && [ -z "${APP_URL:-}" ]; then
  export APP_URL="https://${RAILWAY_PUBLIC_DOMAIN}"
fi

if is_railway && [ -z "${DATABASE_URL:-}" ]; then
  echo "FATAL: No DATABASE_URL after Railway synthesis." >&2
  echo "FATAL: DATABASE_URL=\${{MySQL.MYSQL_URL}} did not resolve; MYSQLHOST=${MYSQLHOST:-<empty>}" >&2
  exit 1
fi

rm -f bootstrap/cache/config.php bootstrap/cache/packages.php bootstrap/cache/services.php
php artisan package:discover --ansi 2>/dev/null || true

wait_for_db() {
  tries=0
  while [ "$tries" -lt 120 ]; do
    if php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::select("SELECT 1");
' 2>/dev/null; then
      return 0
    fi
    tries=$((tries + 1))
    sleep 2
  done
  echo "Database not reachable after 120 attempts (host=${DB_HOST:-unset} db=${DB_DATABASE:-unset})" >&2
  return 1
}

run_migrate_seed() {
  wait_for_db
  php artisan migrate --force
  php artisan expected-totals:sync --recompute
}

wait_for_api() {
  base="${LOCATIONS_FEED_API_BASE_URL:-http://127.0.0.1:${PORT}}"
  base="${base%/}"
  tries=0
  while [ "$tries" -lt 60 ]; do
    if curl -sf "${base}/api/health?ready=1" >/dev/null; then
      return 0
    fi
    tries=$((tries + 1))
    sleep 2
  done
  echo "API not reachable at ${base} after 60 attempts" >&2
  return 1
}

start_scheduler() {
  if ! wait_for_api; then
    echo "ERROR: locations-feed scheduler not started — API not ready" >&2
    return 1
  fi
  echo "Starting Laravel scheduler (locations-feed daily 00:00 UTC + resubmit every 15m)..."
  php artisan schedule:work &
  SCHEDULER_PID=$!
  echo "Scheduler running (pid ${SCHEDULER_PID})"
}

bootstrap_locations_feed() {
  if ! wait_for_api; then
    echo "WARNING: initial locations-feed skipped — API not ready" >&2
    return 1
  fi
  echo "Starting initial locations-feed (daily submission per persisted location)..."
  php artisan locations-feed:run --daily &
}

start_reverb() {
  if [ -z "${REVERB_APP_KEY:-}" ] || [ "${BROADCAST_CONNECTION:-null}" != "reverb" ]; then
    echo "Reverb disabled (BROADCAST_CONNECTION=${BROADCAST_CONNECTION:-unset})" >&2
    return 0
  fi
  echo "Starting Reverb WebSocket server on ${REVERB_SERVER_HOST:-0.0.0.0}:${REVERB_SERVER_PORT:-8080}..."
  php artisan reverb:start \
    --host="${REVERB_SERVER_HOST:-0.0.0.0}" \
    --port="${REVERB_SERVER_PORT:-8080}" &
  REVERB_PID=$!
  echo "Reverb running (pid ${REVERB_PID})"
}

start_nginx() {
  sed "s/__PORT__/${PORT}/" /etc/nginx/nginx.conf > /tmp/nginx.conf
  echo "Starting nginx on ${PORT} (API + WebSocket same origin)..."
  nginx -c /tmp/nginx.conf -g "daemon off;" &
  NGINX_PID=$!
  echo "nginx running (pid ${NGINX_PID})"
}

PORT="${PORT:-8000}"
export PORT
INTERNAL_SERVE_PORT=8001

run_migrate_seed

start_reverb

php artisan serve --host=127.0.0.1 --port="$INTERNAL_SERVE_PORT" --no-reload &
SERVE_PID=$!

start_nginx
start_scheduler
bootstrap_locations_feed

wait "$NGINX_PID"
