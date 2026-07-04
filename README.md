# Export Engine

Backend microservice (PHP 7.3 + Laravel) that ingests game/campaign statistics
and generates configurable, asynchronous XLSX reports. A single version can hold
millions of events; exports stream to file with a constant memory footprint.

## Stack

- PHP 7.3, Laravel
- MySQL 8
- Redis (queue driver)
- OpenSpout (streaming XLSX writer)
- PHPUnit
- Docker Compose (nginx, php-fpm app, queue worker, mysql, redis, phpMyAdmin)

## Requirements

- Docker + Docker Compose

## Setup

```bash
# 1. copy env and start the stack (add --profile dev for phpMyAdmin)
cp src/.env.example src/.env        # if not already present
docker compose --profile dev up -d --build

# 2. app key (skip if APP_KEY is already set in the environment)
docker compose exec app php artisan key:generate

# 3. database schema
docker compose exec app php artisan migrate

# 4. demo data (see "Demo data" below)
docker compose exec app php artisan demo:seed --players=150 --events=20
```

### Services

| Service    | URL                        | Notes                          |
|------------|----------------------------|--------------------------------|
| API        | http://localhost:8000/api/v1 | REST API                     |
| phpMyAdmin | http://localhost:8080      | only with `--profile dev`      |

## Demo data

A parametric artisan command generates realistic demo data in a fixed January 2026
window (matching the example export):

```bash
# drop everything and seed 1 version, 1000 players, 500 events each (~500k events)
docker compose exec app php artisan demo:seed --fresh --players=1000 --events=500

# smaller dataset
docker compose exec app php artisan demo:seed --fresh --players=150 --events=20
```

Options: `--versions`, `--players`, `--events`, `--fresh`.

The classic seeders are also available: `TestSeeder` (small, 2 versions) and
`DemoSeeder` (1 version, 1000 players, 500 events). `MappingSeeder` loads the four
export templates and runs automatically.

## Tests

Tests run against a dedicated MySQL database (not SQLite, to keep the EAV/index
behaviour identical to production):

```bash
docker compose exec app php artisan test
```

## API

Base path: `/api/v1`.

### Versions

```bash
# create a version
curl -s -X POST http://localhost:8000/api/v1/versions \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Summer Campaign 2026"}'

# list / show
curl -s http://localhost:8000/api/v1/versions
curl -s http://localhost:8000/api/v1/versions/1
```

### Ingestion

Every endpoint accepts a single object **or** an array. The response is always
HTTP 200 with `status: success` or `status: partial_success` (valid records are
saved, invalid ones are returned with per-record detail). Player email is
required and is the global dedup key (`firstOrCreate`). The event `payload` is
free JSON, normalised into EAV (no JSON columns).

```bash
# players
curl -s -X POST http://localhost:8000/api/v1/versions/1/players \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '[{"external_player_id":"ext-1","email":"player1@example.test","language":"it","utm_source":"google","registered_at":"2026-01-15T10:00:00Z","status":"registered"}]'

# events (payload auto-registers new fields in EAV)
curl -s -X POST http://localhost:8000/api/v1/versions/1/events \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '[{"external_player_id":"ext-1","type":"game_completed","occurred_at":"2026-01-15T10:05:00Z","payload":{"score":100,"level":3,"language":"it"}}]'

# transactions / answers / rewards follow the same shape
```

### Export (asynchronous)

The request returns a job id immediately; generation runs on the Redis queue
worker. States: `pending → processing → completed | failed` (with automatic
retry in between).

```bash
# create an export -> HTTP 202 with the job id
curl -s -X POST http://localhost:8000/api/v1/versions/1/exports \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{
        "format":"xlsx",
        "date_from":"2026-01-01",
        "date_to":"2026-01-31",
        "sheets":[
          {"name":"readme"},
          {"name":"kpis"},
          {"name":"players","columns":["player_id","email","total_score","status","revenue"],"filters":{"language":"it"},"sort":["total_score:desc"]},
          {"name":"events_summary","group_by":["event_type","language","utm_source"],"metrics":[{"fn":"count","as":"events_count"},{"fn":"count_distinct","on":"player_id","as":"unique_players"},{"fn":"avg","on":"score","as":"avg_score"}]},
          {"name":"answers","group_by":["question_id","question","answer"],"metrics":[{"fn":"count","as":"answers_count"},{"fn":"percentage","partition":"question_id","as":"percentage"}]},
          {"name":"transactions"},
          {"name":"data_quality"}
        ]
      }'

# poll status (returns status, progress and download_url when completed)
curl -s http://localhost:8000/api/v1/exports/1

# download the file
curl -s -OJ http://localhost:8000/api/v1/exports/1/download

# delete the job and its file
curl -s -X DELETE http://localhost:8000/api/v1/exports/1

# list exports (global, or per version)
curl -s http://localhost:8000/api/v1/exports
curl -s http://localhost:8000/api/v1/versions/1/exports
```

### Preview (synchronous)

Returns the first 100 rows of each requested sheet as JSON, without a job or a
file:

```bash
curl -s -X POST http://localhost:8000/api/v1/versions/1/exports/preview \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"sheets":[{"name":"players","columns":["player_id","email","total_score"]}]}'
```

## How the export works

A **template** (`export_templates` row) describes the *base query*: the base table
plus a set of columns (own columns, to-one joins, to-many aggregates via
pre-aggregated joins, and EAV payload columns). The **request payload** applies
operations on top of that template per sheet:

- `columns`: which columns to output (omit = all template columns)
- `filters`: `{field: value}` equality; `payload.<code>` resolves through EAV
- `sort`: `["field:asc|desc"]`
- `group_by` + `metrics`: turn the sheet into an aggregate (count, count_distinct,
  avg, sum, min, max, ratio, percentage)

Unknown tables/columns/payload codes are ignored (best effort, no crash).

EAV payload fields are governed by their flags: `is_filterable`, `is_sortable`,
`is_aggregatable`. A field can be selected/grouped for display, but it is only
usable in filters/sort/metrics if the matching flag is true.

### XLSX sheets

| Sheet                    | Type        |
|--------------------------|-------------|
| README                   | export metadata |
| KPIs                     | aggregate (static values computed via SQL) |
| Configurazione_Richiesta | request dump |
| Players                  | detail with calculated cross-table columns |
| Events_Summary           | aggregate (group by type + payload fields) |
| Answers                  | aggregate (percentage computed by the backend) |
| Transactions             | detail (join with version_players / players) |
| Data_Quality             | anomaly checks |

The four special sheets (README, KPIs, Configurazione_Richiesta, Data_Quality)
are always included in a fixed position; the data sheets appear when requested.
An example output is committed at [`examples/export-example.xlsx`](examples/export-example.xlsx).

## Node client and Postman

- `client/` is a Node simulation of the main flows (bulk ingestion, single and 30
  parallel exports with live progress bars and downloads). Run it with
  `cd client && npm install && npm start` (regenerate fixtures with `npm run generate`).
- `Export-Engine.postman_collection.json` covers every endpoint.

## Notes and assumptions

- **No authentication** in this version.
- The event **payload is not validated** on ingestion; filtering on fields with
  mixed types can give inconsistent results.
- `language` and `utm_source` on events are payload values and may differ from the
  player values in `version_players`.
- KPIs are static values computed via SQL (not Excel formulas). The demo/example
  data uses a fixed **January 2026** period across the seeder, the client fixtures
  and the Postman examples.
- After changing PHP code, restart the queue worker (`docker compose restart worker`);
  Laravel workers do not hot-reload code.

## Queue workers and concurrency

Export jobs run on Redis queue workers. The maximum number of exports processed
in parallel is the number of worker replicas, configurable via Compose env
(shell or a repo-root `.env`, see `.env.example`):

```bash
# run 4 workers in parallel
EXPORT_WORKER_CONCURRENCY=4 docker compose up -d worker
```

`QUEUE_SLEEP`, `QUEUE_TIMEOUT` and `QUEUE_MAX_JOBS` tune `queue:work` the same way.
