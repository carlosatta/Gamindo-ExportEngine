# Export Engine

Microservizio backend (PHP 7.3 + Laravel) che riceve statistiche di gioco/campagne
e genera report XLSX configurabili in modo asincrono. Una singola versione può
contenere milioni di eventi; gli export vengono scritti su file in streaming, con
un uso di memoria costante.

## Stack

- PHP 7.3, Laravel
- MySQL 8
- Redis (driver della coda)
- OpenSpout (scrittura XLSX in streaming)
- PHPUnit
- Docker Compose (nginx, php-fpm app, worker della coda, mysql, redis, phpMyAdmin)

## Requisiti

- Docker + Docker Compose

## Setup

```bash
# 1. (opzionale) copia l'env di root per le variabili di Compose
#    (worker, DB, coda). Se lo salti non serve nessun file: ogni variabile
#    in docker-compose.yml ha un default (${VAR:-default}) che Compose
#    passa ai container in questo ambiente base. Il file serve solo per
#    sovrascrivere quei default.
cp .env.example .env

# 2. avvia lo stack (aggiungi --profile dev per phpMyAdmin)
docker compose --profile dev up -d --build

# 3. schema del database
docker compose exec app php artisan migrate

# 4. dati demo (vedi "Dati demo" più sotto)
docker compose exec app php artisan demo:seed --players=150 --events=20
```

Al primo avvio l'entrypoint del container `app` fa già da solo `composer install`,
copia `src/.env` da `src/.env.example` se manca e genera l'`APP_KEY` se è vuota.
Quindi la prima `up` ci mette un po': aspetta che `composer install` finisca prima
di lanciare `migrate`.

Ci sono due file env, non confonderli:

- `.env` nella root → variabili lette da **docker-compose** (`EXPORT_WORKER_CONCURRENCY`,
  `DB_*`, `QUEUE_*`)
- `src/.env` → configurazione **Laravel** (gestito in automatico dall'entrypoint)

### Servizi

| Servizio   | URL                          | Note                        |
|------------|------------------------------|-----------------------------|
| API        | http://localhost:8000/api/v1 | REST API                    |
| phpMyAdmin | http://localhost:8080        | solo con `--profile dev`    |

## Dati demo

Un comando artisan parametrico genera dati demo realistici in una finestra fissa
di gennaio 2026 (la stessa dell'export di esempio):

```bash
# svuota tutto e crea 1 versione, 1000 player, 500 eventi ciascuno (~500k eventi)
docker compose exec app php artisan demo:seed --fresh --players=1000 --events=500

# dataset più piccolo
docker compose exec app php artisan demo:seed --fresh --players=150 --events=20
```

Opzioni: `--versions`, `--players`, `--events`, `--fresh`.

Ci sono anche i seeder classici: `TestSeeder` (piccolo, 2 versioni) e `DemoSeeder`
(1 versione, 1000 player, 500 eventi). `MappingSeeder` carica i quattro template
di export e parte in automatico.

## Test

I test girano su un database MySQL dedicato (non SQLite, così il comportamento
EAV/indici resta identico alla produzione):

```bash
docker compose exec app php artisan test
```

## API

Base path: `/api/v1`.

### Versioni

```bash
# crea una versione
curl -s -X POST http://localhost:8000/api/v1/versions \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Summer Campaign 2026"}'

# lista / dettaglio
curl -s http://localhost:8000/api/v1/versions
curl -s http://localhost:8000/api/v1/versions/1
```

### Ingestione

Ogni endpoint accetta un singolo oggetto **oppure** un array. La risposta è sempre
HTTP 200 con `status: success` o `status: partial_success` (i record validi
vengono salvati, quelli invalidi tornano indietro con il dettaglio per record).
L'email del player è obbligatoria ed è la chiave di dedup globale (`firstOrCreate`).
Il `payload` dell'evento è JSON libero, normalizzato in EAV (niente colonne JSON).

```bash
# player
curl -s -X POST http://localhost:8000/api/v1/versions/1/players \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '[{"external_player_id":"ext-1","email":"player1@example.test","language":"it","utm_source":"google","registered_at":"2026-01-15T10:00:00Z","status":"registered"}]'

# eventi (il payload registra da solo i nuovi campi in EAV)
curl -s -X POST http://localhost:8000/api/v1/versions/1/events \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '[{"external_player_id":"ext-1","type":"game_completed","occurred_at":"2026-01-15T10:05:00Z","payload":{"score":100,"level":3,"language":"it"}}]'

# transactions / answers / rewards seguono la stessa forma
```

### Export (asincrono)

La richiesta torna subito un job id; la generazione gira sul worker della coda
Redis. Stati: `pending → processing → completed | failed` (con retry automatico
nel mezzo).

```bash
# crea un export -> HTTP 202 con il job id
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

# controlla lo stato (torna status, progress e download_url quando è completo)
curl -s http://localhost:8000/api/v1/exports/1

# scarica il file
curl -s -OJ http://localhost:8000/api/v1/exports/1/download

# elimina il job e il suo file
curl -s -X DELETE http://localhost:8000/api/v1/exports/1

# lista degli export (globale, o per versione)
curl -s http://localhost:8000/api/v1/exports
curl -s http://localhost:8000/api/v1/versions/1/exports
```

### Preview (sincrona)

Torna le prime 100 righe di ogni sheet richiesto come JSON, senza job e senza
file:

```bash
curl -s -X POST http://localhost:8000/api/v1/versions/1/exports/preview \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"sheets":[{"name":"players","columns":["player_id","email","total_score"]}]}'
```

## Come funziona l'export

Un **template** (riga in `export_templates`) descrive la *query base*: la tabella
base più un insieme di colonne (colonne proprie, join to-one, aggregati to-many
via join pre-aggregati, e colonne di payload EAV). Il **payload della richiesta**
applica le operazioni sopra a quel template, per ogni sheet:

- `columns`: quali colonne mostrare (se omesso = tutte le colonne del template)
- `filters`: `{campo: valore}` per uguaglianza; `payload.<code>` passa per l'EAV
- `sort`: `["campo:asc|desc"]`
- `group_by` + `metrics`: trasformano lo sheet in un aggregato (count, count_distinct,
  avg, sum, min, max, ratio, percentage)

Tabelle/colonne/payload code sconosciuti vengono ignorati (best effort, niente
crash).

I campi di payload EAV sono governati dai loro flag: `is_filterable`, `is_sortable`,
`is_aggregatable`. Un campo si può sempre selezionare/raggruppare per mostrarlo,
ma è usabile in filtri/sort/metriche solo se il flag corrispondente è true.

### Sheet XLSX

| Sheet                    | Tipo        |
|--------------------------|-------------|
| README                   | metadati dell'export |
| KPIs                     | aggregato (valori statici calcolati via SQL) |
| Configurazione_Richiesta | dump della richiesta |
| Players                  | dettaglio con colonne calcolate cross-table |
| Events_Summary           | aggregato (group by type + campi payload) |
| Answers                  | aggregato (percentuale calcolata dal backend) |
| Transactions             | dettaglio (join con version_players / players) |
| Data_Quality             | controlli sulle anomalie |

I quattro sheet speciali (README, KPIs, Configurazione_Richiesta, Data_Quality)
ci sono sempre, in posizione fissa; gli sheet di dati compaiono quando li chiedi.
Un output di esempio è committato in [`examples/export-example.xlsx`](examples/export-example.xlsx).

## Client Node e Postman

- `client/` è una simulazione Node dei flussi principali (ingestione bulk, export
  singolo e 30 export in parallelo con barre di progresso live e download). Si
  lancia con `cd client && npm install && npm start` (rigenera le fixture con
  `npm run generate`).
- `Export-Engine.postman_collection.json` copre tutti gli endpoint.

## Assunzioni

La traccia fornisce solo un **payload di esempio** e un **excel di esempio**, non le
formule esatte né l'architettura. Le scelte qui sotto sono quindi assunzioni.

**Modello di export**

- I fogli sono un **insieme fisso e noto**, non un motore generico "tutto da tutto".
  I quattro speciali (README, KPIs, Configurazione_Richiesta, Data_Quality) sono
  **sempre presenti e in posizione fissa**; i fogli dati compaiono solo se richiesti.
- Un **template** è la struttura della *query base* (tabella base + colonne), salvato
  nel DB e referenziato per `name`. Non è un export preconfezionato e **non ha endpoint
  REST**: si gestisce via seeder / riga `export_templates`.
- Grano del foglio: **senza `group_by` → detail** (1 riga per record), **con `group_by`
  → summary** (1 riga per gruppo).
- Relazioni: **to-one → JOIN**; **to-many → LEFT JOIN su tabella pre-aggregata**
  (aggregato calcolato una volta per gruppo, niente esplosione cartesiana né subquery
  per riga). `players` non è una base valida (manca `version_id`): la base del report
  players è `version_players`.
- Tolleranza best-effort: name/colonne/filtri/sort/metriche sconosciuti vengono
  ignorati, niente crash.

**Payload EAV**

- Il `payload` dell'evento è JSON libero, normalizzato in **EAV** (`payload_fields` +
  `payload_values`), niente colonne JSON. I nuovi campi si auto-registrano in ingestione.
- I flag `is_filterable` / `is_sortable` / `is_aggregatable` su `payload_fields`
  governano cosa è ammesso su un campo payload: un campo si può sempre mostrare/raggruppare,
  ma è usabile in filtri/sort/metriche solo se il flag corrispondente è true.
- Il payload **non viene validato** in ingestione; filtrare su campi con tipi misti può
  dare risultati incoerenti.

**Colonne calcolate (default, in attesa di conferma)**

- `total_score` = `SUM(payload.score)`, `levels_completed` = `COUNT(DISTINCT payload.level)`,
  `events_count` = `COUNT(eventi)`, `revenue` = `SUM(transactions.amount)`, `reward` =
  ultimo reward per `assigned_at` (`none` se assente).
- `language` / `utm_source` sugli eventi sono valori del payload e possono differire dai
  valori del player in `version_players`.

**Altro**

- **Nessuna autenticazione** in questa versione (scelta di progetto).
- I **KPI sono valori statici calcolati via SQL** (non formule Excel).
- Periodo canonico **gennaio 2026** ovunque (seeder, fixture del client, Postman, excel di
  esempio): dati e filtro devono stare nello stesso periodo, altrimenti l'export esce vuoto.
- Gli alias di colonna derivati dal payload sono sanitizzati (`[A-Za-z0-9_]`, max 64) prima
  di finire in SQL (anti-injection sugli alias).
- Dopo aver cambiato codice PHP, riavvia il worker della coda
  (`docker compose restart worker`); i worker Laravel non ricaricano il codice a caldo.

## Worker della coda e concorrenza

I job di export girano sui worker della coda Redis. Il numero massimo di export
processati in parallelo è il numero di repliche del worker, configurabile via env
di Compose (shell o un `.env` nella root del repo, vedi `.env.example`):

```bash
# lancia 4 worker in parallelo
EXPORT_WORKER_CONCURRENCY=4 docker compose up -d worker
```

`QUEUE_SLEEP`, `QUEUE_TIMEOUT` e `QUEUE_MAX_JOBS` regolano `queue:work` allo stesso modo.

## Architettura

Ci sono due flussi. L'**ingestione** è sincrona (i dati entrano e vengono salvati
subito). L'**export** è asincrono (la richiesta torna subito, il file lo genera un
worker in background).

### Flusso di ingestione (sincrono)

1. Il client chiama un endpoint di ingestione (`players`, `events`, ...); `nginx`
   inoltra a `php-fpm` (container `app`).
2. Il **Controller** valida la forma e passa ai servizi in `Services/Ingestion`:
   - `VersionPlayerResolver`: trova o crea il player (dedup globale per email) e lo
     lega alla versione.
   - `PayloadWriter`: prende il `payload` JSON libero e lo scrive in **EAV**
     (`payload_fields` per i campi, `payload_values` per i valori). I campi nuovi si
     registrano da soli.
3. Tutto va su **MySQL**. La risposta è immediata (HTTP 200).

### Flusso di export (asincrono)

1. Il client fa `POST .../exports`. L'`ExportController` crea una riga
   `ExportRequest` in stato `pending`, mette un job sulla **coda Redis** e risponde
   subito (HTTP 202 + id).
2. Un **worker** prende il job ed esegue `GenerateExportJob`, che per ogni foglio
   richiesto chiama il builder giusto:
   - `MappingSheetBuilder` per i fogli dati: parte dal **template** (query base) e ci
     applica sopra `columns/filters/sort/group_by/metrics`.
   - `SpecialSheetBuilder` per i quattro fogli speciali (readme, kpis,
     configurazione_richiesta, data_quality).
3. I fogli vengono scritti con **OpenSpout** in streaming (memoria costante anche con
   milioni di righe); gli autofilter sui fogli dati sono iniettati nello zip xlsx in
   post-processing. Durante la scrittura il job aggiorna il **progress su Redis**.
4. A fine lavoro lo stato passa a `completed` (file pronto) o `failed` (con retry e
   `backoff`). Il client fa **polling** su `GET /exports/{id}` e poi scarica.

### Dove sta cosa (`src/app`)

| Cartella | Ruolo |
|----------|-------|
| `Http/Controllers/Api/V1` | endpoint REST (versioni, ingestione, export) |
| `Http/Requests` | validazione delle richieste |
| `Services/Ingestion` | scrittura player + payload EAV |
| `Services/Export` | costruzione dei fogli (`MappingSheetBuilder`, `SpecialSheetBuilder`) e definizione tabelle/colonne base (`EntityRegistry`, `TemplateDefinitions`) |
| `Jobs/GenerateExportJob` | generazione XLSX sul worker |
| `Models` | dominio + export + EAV (`PayloadField`, `PayloadValue`) |

I **template** vivono nel DB (`export_templates`, caricati da `MappingSeeder`) e si
referenziano per `name`: non c'è un endpoint REST per gestirli.

## Struttura del progetto

Livello top:

```
docker-compose.yml            stack: nginx, app, worker, mysql, redis, phpmyadmin
.env.example                  variabili lette da Compose (worker, DB, coda)
docker/
  php/Dockerfile              immagine php 7.3-fpm + estensioni + composer
  php/entrypoint.sh           primo avvio: composer install, .env, key:generate
  nginx/default.conf          virtual host che punta a php-fpm
examples/export-example.xlsx  output di esempio committato
client/                       simulatore Node dei flussi (vedi sotto)
Export-Engine.postman_collection.json   collezione con tutti gli endpoint
src/                          applicazione Laravel
```

Applicazione (`src/`):

```
routes/api.php                tutte le rotte /api/v1
config/                       config Laravel (db, queue, ecc.)
database/
  migrations/                 schema: versions, players, version_players, events,
                              transactions, answers, rewards, payload_fields,
                              payload_values, export_requests, export_templates
  seeders/
    MappingSeeder.php         carica i 4 template di export nel DB
    DataSeeder.php            dati realistici (usato da Demo/Test seeder)
    DemoSeeder.php            1 versione, volume alto (bulk insert)
    TestSeeder.php            dataset piccolo per i test
    DatabaseSeeder.php        entrypoint dei seeder
  factories/                  factory per i test
tests/Feature/                test end-to-end su MySQL (ingestione, export, schema)
```

Cuore dell'app (`src/app`):

```
Http/Controllers/Api/V1/
  VersionController.php        crea / lista / mostra versioni
  ExportController.php         crea export (202), stato, download, delete, preview
  Ingestion/
    IngestionController.php    base astratta: valida, risolve player, scrive payload
    Concerns/HandlesRecords.php  gestione uno-o-array + risposta success/partial
    Player/Event/Transaction/Answer/RewardController.php   i cinque endpoint
Http/Requests/                 validazione (StoreExportRequest, StoreVersionRequest)
Http/Resources/                shape JSON in output (Export, Version)

Services/Ingestion/
  VersionPlayerResolver.php    dedup player per email, aggancio alla versione
  PayloadWriter.php            payload JSON → EAV (payload_fields / payload_values)

Services/Export/
  EntityRegistry.php           tabelle base e relazioni to-one / to-many
  TemplateDefinitions.php      i 4 template (base + colonne, incl. calcolate)
  MappingSheetBuilder.php      costruisce i fogli dati (query + colonne/filtri/...)
  SpecialSheetBuilder.php      i 4 fogli speciali (readme/kpis/config/data_quality)

Jobs/GenerateExportJob.php     genera l'XLSX sul worker (streaming + progress)
Console/Commands/DemoSeedCommand.php   comando artisan demo:seed
Models/                        Version, VersionPlayer, Player, Event, Transaction,
                              Answer, Reward, ExportRequest, ExportTemplate,
                              PayloadField, PayloadValue
```

Client Node (`client/`):

```
index.js                       entrypoint della simulazione
lib/api.js                     wrapper delle chiamate REST
lib/steps.js                   i passi del flusso (ingestione, export, download)
lib/util.js                    utility (barre di progresso, ecc.)
data/generate.js               genera le fixture (players/events/... json)
data/*.json                    fixture pronte all'uso
output/                        file xlsx scaricati dalle run
```

## Strumenti AI

Durante lo sviluppo mi sono avvalso di **Claude Code**, per l'imbastitura di parti
di codice standard, ripetitive e noiose (boilerplate, scaffolding, dati e stesura
dei test, ritocchi di formattazione) e per check di alto livello, sempre sotto mia
revisione. Le scelte di progettazione, l'architettura e le assunzioni restano mie.
