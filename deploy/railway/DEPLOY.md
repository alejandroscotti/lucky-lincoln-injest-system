# Railway Deployment

**Production:** [https://luckylincoln.xyz/](https://luckylincoln.xyz/)

## Architecture on Railway

One app service (root `Dockerfile`) runs everything:

1. **nginx** on `$PORT` — Vue SPA, `/api` → PHP, `/app` → Reverb WebSocket (same origin)
2. **php artisan serve** on `127.0.0.1:8001` — Laravel API
3. **Reverb** on `127.0.0.1:8080` — live push (enabled automatically on Railway)
4. **migrate** — schema + `database/sql/reference_data.sql`
5. **schedule:work** — locations-feed daily + resubmit every 15m
6. Bootstrap `locations-feed:run --daily` on start

Live UI connects via `wss://luckylincoln.xyz/app/...` (nginx proxies to Reverb). Config comes from `GET /api/meta/reverb` — no manual Reverb env vars required; entrypoint + `railway.json` set defaults on deploy.

DB: entrypoint resolves `DATABASE_URL=${{MySQL.MYSQL_URL}}` or synthesizes from `MYSQLHOST` when that reference is empty.

## MySQL service variables

```
MYSQL_DATABASE=revenue_db
MYSQL_USER=revenue
MYSQL_PASSWORD=revenue_secret
MYSQL_ROOT_PASSWORD=rootsecret
```

## App service variables

```
PORT=3000
DATABASE_URL=${{MySQL.MYSQL_URL}}
APP_NAME=Revenue Reconciliation
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:+cIwfzR3IVs5CJlXZW4nO7FHE9IrZSkoTQSwKzKtbQw=
APP_URL=https://luckylincoln.xyz
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=database
SEED_RANDOM=42
LOCATIONS_FEED_DAILY_STAGGER_MS=3000
LOCATIONS_FEED_INVALID_RESUBMIT_RATE=0.1
```

(`QUEUE_CONNECTION` is overridden to `sync` at runtime on Railway — no jobs table.)

## Verify

```bash
curl https://luckylincoln.xyz/api/health?ready=1
curl https://luckylincoln.xyz/api/meta/reverb
curl https://luckylincoln.xyz/api/revenue/dashboard
```

Expect `/api/meta/reverb` → `"enabled": true`, health `ready: true`, no WebSocket errors to `localhost` in the browser console.
