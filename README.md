# Revenue Reconciliation System

> This blockquote is handwritten by Alejandro, the rest of the file and repo is 100% AI-generated.
>
> ## Project Notes
>
> With the advancement of AI and the almost-instant code generation capabilities; I find myself in an advantageous reality; where I am able to focus all my time solely on implementing business-oriented solutions and features. Allowing me time to research deeply into Lucky Lincoln real business information and operations in order to understand core functionalities and creatively imagine possible hypothetical scenarios to work with.
>
> I maintained a balance between exceeding the Technical Assessment expectations and deploying a functional, bug-free Web App that meets production-grade architectural standards.
>
> ### Fully Deployed Web App
>
> - Access the Web App at [luckylincoln.xyz](https://luckylincoln.xyz)
> - Mermaid system diagrams at [System Diagrams](https://luckylincoln.xyz/diagrams)
>
> ### What To Improve
>
> With more time I would have developed a fully functional AI-powered Call Center to leverage additional API endpoints. This Call Center would have required an API Key to identify each Location's users and data. The Revenue Reconciliation system would have been connected to an LLM service along with a RAG & vector DB implementation. The Call Center features could have offered a Chat input where a user could ask to identify if a machine has not been reporting, perhaps it's reporting faulty data etc.
>
> Final thought: I once again thank you for the opportunity to complete this technical assessment and I hope that it exceeds your expectations.
>
> Best regards!
> Alejandro Scotti
>
> **Business Requirements Document:** [Business Requirements Document.md](Business%20Requirements%20Document.md)

## Quick start (Docker)

```bash
cp .env.example .env
./start.sh
```

Open **http://localhost:18430** — Laravel serves the Vue UI and `/api/*` on one port.

Services: `mysql`, `app` (API + Vue + locations-feed scheduler).

Full reset (wipe revenue and re-seed reference data):

```bash
docker compose down -v && ./start.sh
```

Laravel 12 + Vue 3 + MySQL prototype for nightly location revenue import, idempotency, reconciliation, and a live operator dashboard.

**Live (Railway):** [https://luckylincoln.xyz/](https://luckylincoln.xyz/)

## Architecture

Every persisted location submits revenue through the **same HTTP import API**. There is no in-process import bypass and no revenue in the database seeder.

```
Migration (reference_data.sql) → locations, machines, game_types, expected_totals (reference catalog only)
LocationsFeedCommand           → one POST /api/revenue/import per location (x-source = LOC-xxx)
RevenueImportController → envelope validation → ImportService → MySQL
Vue 3 SPA              → GET /api/* dashboards, submissions, reconcile, faults
```

### Container lifecycle (Docker / Railway)

Single `app` container runs:

| Step           | Command                            | Purpose                                             |
| -------------- | ---------------------------------- | --------------------------------------------------- |
| API + UI       | `php artisan serve`                | Serves Vue SPA and `/api/*`                         |
| Reference data | `migrate` (+ `reference_data.sql`) | ~245 locations + machines; **no** `revenue_records` |
| Scheduler      | `php artisan schedule:work`        | Daily **00:00 UTC** + resubmit **every 15 min**     |
| Bootstrap feed | `locations-feed:run --daily`       | Initial submission for all locations on start       |

Locations feed reads persisted locations via the API (`GET /api/locations/options`, `GET /api/locations/{id}/machines`), builds deterministic payloads (`FaultSimulation`, seeded RNG), and POSTs through `LocationsFeedApiClient` with headers `x-source`, `x-location-id`, `x-idempotency-key`, etc.

### Idempotency (summary)

- File key: `LOC-042-2026-06-21` = `location_id` + `report_date`
- Canonical row: `location_daily_files` per location-day
- Audit log: every attempt in `import_batches` (`source` = location id, e.g. `LOC-042`)
- Envelope required when `x-source` matches `LOC-*` (see `LocationSource`)

See **System Diagrams** in the UI or `GET /api/diagrams/mermaid` for full flowcharts.

## Stack

| Layer          | Technology                                                                   |
| -------------- | ---------------------------------------------------------------------------- |
| API            | **Laravel 12** (PHP 8.3) — `backend/`                                        |
| UI             | **Vue 3** (Composition API) — `packages/web/` → built into `backend/public/` |
| DB             | MySQL 8                                                                      |
| Locations feed | `locations-feed:run` + `schedule:work` in the same container                 |

## Environment variables

| Variable                               | Default                   | Purpose                                           |
| -------------------------------------- | ------------------------- | ------------------------------------------------- |
| `SEED_RANDOM`                          | `42`                      | Deterministic machine counts and feed payloads    |
| `LOCATIONS_FEED_DAILY_STAGGER_MS`      | `3000`                    | Delay between location POSTs in daily cycle       |
| `LOCATIONS_FEED_INVALID_RESUBMIT_RATE` | `0.1`                     | Fraction of resubmits with an invalid date (~10%) |
| `LOCATIONS_FEED_API_BASE_URL`          | `http://127.0.0.1:{PORT}` | In-container API base (usually unset)             |

Railway: see [deploy/railway/DEPLOY.md](deploy/railway/DEPLOY.md). Do **not** set `PORT` manually.

## Local development

**Laravel API** (PHP 8.3 + Composer + MySQL):

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
# separate terminal:
php artisan locations-feed:run --daily
```

**Vue UI** (dev server proxies `/api` to Laravel):

```bash
cd packages/web
npm install
npm run dev
```

Against Docker API on port 18430: `VITE_API_PROXY=http://localhost:18430 npm run dev`

## Key API endpoints

| Endpoint                          | Purpose                                                  |
| --------------------------------- | -------------------------------------------------------- |
| `POST /api/revenue/import`        | Idempotent location file import (single ingestion entry) |
| `GET /api/submissions`            | Per-location submission monitor (`source` = `LOC-xxx`)   |
| `GET /api/submissions/completion` | Location-day completion status                           |
| `GET /api/revenue/dashboard`      | Dashboard KPIs                                           |
| `GET /api/revenue/reconcile`      | Expected vs actual totals                                |
| `GET /api/diagrams/mermaid`       | Architecture diagrams (live schema ER when DB up)        |

## Locations feed commands

```bash
php artisan locations-feed:run --daily      # submit every persisted location for today (UTC)
php artisan locations-feed:run --resubmit   # one random valid or invalid-date resubmit
php artisan locations-feed:run              # both daily + resubmit (default when no flags)
```

Scheduled in `backend/routes/console.php`. Fault injection, partial batches, and retry-until-complete behaviour are unchanged from the original design — only the submitter identity changed from a single internal client to **each location id**.

## Tests

```bash
cd backend
php artisan test
```

## Railway deploy

Production: **https://luckylincoln.xyz/**

Set variables on Railway manually — [deploy/railway/DEPLOY.md](deploy/railway/DEPLOY.md). No secrets in the repo.

## Design reference

- `Technical Assignment.md` — original brief
- **System Diagrams** in the UI — authoritative idempotency and architecture diagrams
