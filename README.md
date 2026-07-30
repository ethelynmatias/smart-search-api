# Smart Search API

A Laravel-based smart search API.

## Tech Stack

- **PHP** 8.4 (FPM)
- **Laravel** 13
- **MySQL** 8.4
- **Redis** (cache / queues)
- **Nginx**
- **Docker** & Docker Compose

## Requirements

- [Docker](https://docs.docker.com/get-docker/) and Docker Compose v2
- `make`

That's it — PHP, Composer, MySQL, and Redis all run inside containers.

## Installation (Development)

### Quick start

```bash
git clone <repository-url> smart-search-api
cd smart-search-api
make setup
```

`make setup` performs the full initial setup:

1. Creates `.env` from `.env.example` (if missing)
2. Builds the Docker images
3. Starts all containers
4. Waits for MySQL to be ready
5. Generates the application key
6. Runs database migrations

The API is then available at [http://localhost:8080](http://localhost:8080).

> **Note:** Docker Compose is fully driven by the `.env` file — database credentials, root password, and exposed ports (`APP_PORT`, `DB_PORT`, `REDIS_PORT`) are all read from it. `.env.example` ships with working defaults for the Docker services; adjust them before `make setup` if needed.

### Services

| Service | Container          | Port (host)          |
|---------|--------------------|----------------------|
| Nginx   | smart-search-nginx | `APP_PORT` (8080)    |
| PHP-FPM | smart-search-app   | —                    |
| MySQL   | smart-search-mysql | `DB_PORT` (3306)     |
| Redis   | smart-search-redis | `REDIS_PORT` (6379)  |

## Development

All common tasks are wrapped in the `Makefile`. Run `make help` to list every target.

| Command          | Description                                              |
|------------------|----------------------------------------------------------|
| `make setup`     | Full initial setup (env, build, up, key, migrate)        |
| `make up`        | Start the containers                                     |
| `make down`      | Stop the containers (keeps data)                         |
| `make restart`   | Restart the containers                                   |
| `make destroy`   | Stop containers and remove volumes (deletes all data)    |
| `make logs`      | Tail container logs                                      |
| `make shell`     | Open a bash shell in the app container                   |
| `make migrate`   | Run database migrations                                  |
| `make fresh`     | Drop all tables and re-run migrations                    |
| `make seed`      | Run database seeders                                     |
| `make test`      | Run the test suite                                       |
| `make pint`      | Fix code style with Pint                                 |
| `make pail`      | Tail application logs with Pail                          |
| `make cache-clear` | Clear all Laravel caches                               |

Arbitrary artisan/composer commands:

```bash
make artisan cmd="route:list"
make composer cmd="require vendor/package"
```

## File Structure

Application code specific to this project:

```
app/
├── DTOs/
│   └── SmartSearch/
│       ├── AMLData.php               # AML check payload (name, DoB, sex, country, ID types)
│       ├── AddressData.php           # Postcode-lookup address (flat, building, lines, town, region)
│       ├── NotificationData.php      # Search link notification (SMS/email)
│       ├── SmartDocData.php          # SmartDoc search payload
│       └── WebhookData.php           # Parsed incoming SmartSearch webhook
│
├── Enums/
│   ├── LogType.php                   # webhook | api
│   └── WebhookDetailStatus.php       # pending | processing | completed | failed | expired
│
├── Http/
│   ├── Controllers/
│   │   ├── LogController.php         # /logs/{token} viewer
│   │   └── Webhooks/
│   │       ├── HubSpotWebhookController.php      # Deal close events
│   │       └── SmartSearchWebhookController.php  # Search result callbacks
│   └── Requests/
│       ├── AMLRequest.php            # AML validation
│       └── SmartDocRequest.php       # SmartDoc validation (+ notify_method sms/email)
│
├── Models/
│   ├── Log.php                       # logs table (type, message, payload, log_group_id)
│   ├── SmartSearchSearch.php         # SmartSearch searches (search_id, type, status, result)
│   └── WebhookDetail.php             # Search awaiting a callback (ssid, deal_id, status)
│
├── Repositories/
│   ├── Contracts/
│   │   ├── LogRepositoryInterface.php
│   │   └── WebhookDetailRepositoryInterface.php
│   ├── LogRepository.php
│   └── WebhookDetailRepository.php
│
└── Services/
    ├── HubSpot/
    │   ├── HubSpotAuthService.php    # Access token + authenticated API client
    │   ├── HubSpotService.php        # Deal writes (smartdoc_ssid property)
    │   └── HubSpotWebhookService.php # Signature check, event dispatch, deal/contact fetch
    │
    ├── LogService.php                # Creates logs with a shared per-request log_group_id
    │
    └── SmartSearch/
        ├── AmlService.php            # AML searches
        ├── AuthenticationService.php # Cached app token
        ├── SmartDocService.php       # SmartDoc searches + result webhook registration
        ├── SmartSearchClient.php     # Authenticated JSON:API client
        └── Exceptions/
            └── SmartSearchException.php
```

Supporting files: `routes/api.php` (HubSpot + SmartSearch endpoints), `routes/web.php` (welcome + logs), `config/services.php` (`hubspot`, `smartsearch` credentials), `config/logs.php` (logs page token), `public/css/logs.css`, `docker/nginx/default.conf`.

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/hubspot/event` | HubSpot webhook receiver (signature-verified) |
| POST | `/api/smartsearch/search` | SmartSearch search result callback |
| GET | `/logs/{token}` | Log viewer (token from `LOGS_ACCESS_TOKEN`) |

## Search Flow

When a deal is closed (`closedwon` / `closedlost`) in HubSpot:

1. `HubSpotWebhookController` verifies the signature and dispatches the event.
2. `HubSpotWebhookService` fetches the deal, its contacts, and its company. With no
   contacts on the deal, the company owner is used as the subject instead.
3. An AML search and a SmartDoc search run per subject; each result is logged.
4. Every created SmartDoc search is stored as a `webhook_details` row — `pending`,
   keyed by its SmartSearch id (`ssid`) — and a result webhook is registered with
   SmartSearch for it.
5. When SmartSearch calls back, `SmartSearchWebhookController` matches the `ssid`,
   updates the row's status, and logs the response into the deal's original log
   group, so the whole deal reads as one thread at `/logs/{token}`.

Searches that cannot run (missing name, address, date of birth, or sex) are recorded
as skipped alongside the ones that did, so one incomplete contact never loses the rest.

## HubSpot Webhook

The HubSpot webhook endpoint is:

```
POST /api/hubspot/event
```

Incoming events are signature-verified (`X-HubSpot-Signature-v3`) and saved to the `logs` table — browse them at [http://localhost:8080/logs](http://localhost:8080/logs).

Set the client secret from your HubSpot app (Auth → Client secret) in `.env`, then restart the app container:

```dotenv
HUBSPOT_CLIENT_SECRET=your-secret-here
```

```bash
docker compose restart app
```

### Local testing with ngrok

HubSpot needs a publicly reachable HTTPS URL, so tunnel the local stack with [ngrok](https://ngrok.com):

```bash
ngrok http 8080
```

Then use the forwarding URL as the webhook target in your HubSpot app settings:

```
https://<your-subdomain>.ngrok-free.app/api/hubspot/event
```

Notes:

- The free-tier ngrok URL changes on every restart — update the URL in HubSpot each time, or reserve a static domain and run `ngrok http 8080 --domain=<your-domain>.ngrok-free.app`.
- Inspect incoming requests at ngrok's local dashboard: [http://127.0.0.1:4040](http://127.0.0.1:4040).

## SmartSearch

Credentials and the search result callback URL:

```dotenv
# Sandbox; production is https://api.app.smartsearch.com
SMARTSEARCH_BASE_URL="https://api.sandbox.app.smartsearch.com"
SMARTSEARCH_APP_ID=your-app-id
SMARTSEARCH_SECRET=your-secret
SMARTSEARCH_WEBHOOK_URL=
```

`SMARTSEARCH_WEBHOOK_URL` is where SmartSearch calls back when a search completes.
Leave it empty to use this app's own route (`/api/smartsearch/search`, built from
`APP_URL`); set it explicitly when the app is not publicly reachable at `APP_URL` —
locally, the same ngrok URL used for HubSpot:

```dotenv
SMARTSEARCH_WEBHOOK_URL=https://<your-subdomain>.ngrok-free.app/api/smartsearch/search
```

Run `php artisan config:clear` after editing, or a cached config will keep the old value.

The app token is fetched on demand and cached for 14 minutes; no token needs storing.

### HubSpot deal property

Writing the SmartDoc search id back onto a deal (`HubSpotService::updateSmartDocSsid()`)
requires a custom single-line-text property named `smartdoc_ssid` on the Deal object in
HubSpot. Without it, the PATCH is rejected and the failure is logged.
