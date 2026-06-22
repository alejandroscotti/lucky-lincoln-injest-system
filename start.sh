#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

if [ -n "${RAILWAY_ENVIRONMENT:-}" ] || [ -n "${RAILWAY_PROJECT_ID:-}" ] || [ -n "${RAILWAY_SERVICE_ID:-}" ]; then
  echo "ERROR: start.sh is for local Docker Compose only — not Railway."
  echo "Remove any custom Start Command from Railway. See deploy/railway/DEPLOY.md"
  exit 1
fi

if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env from .env.example"
fi

# shellcheck disable=SC1091
source .env 2>/dev/null || true
APP_PORT="${API_HOST_PORT:-18430}"

echo "Starting Revenue Reconciliation stack (Laravel + Vue)..."
docker compose up --build -d --remove-orphans

echo "Waiting for Laravel app..."
for i in $(seq 1 60); do
  if curl -sf "http://localhost:${APP_PORT}/api/health?ready=1" >/dev/null 2>&1; then
    echo "App ready."
    break
  fi
  if [ "$i" -eq 60 ]; then
    echo "App did not become ready in time. Check: docker compose logs app"
    exit 1
  fi
  sleep 3
done

URL="http://localhost:${APP_PORT}"
echo "Opening ${URL}"
if command -v xdg-open >/dev/null 2>&1; then
  xdg-open "$URL" || true
elif command -v open >/dev/null 2>&1; then
  open "$URL" || true
else
  echo "Open your browser to ${URL}"
fi

echo "Stack running at ${URL} (Laravel API + Vue UI + locations-feed scheduler)"
echo "Stop: docker compose down  |  Full reset: docker compose down -v"
