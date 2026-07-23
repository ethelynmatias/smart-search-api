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
│   └── LogType.php                   # webhook | api
│
├── Http/
│   ├── Controllers/
│   │   ├── LogController.php         # /logs/{token} viewer
│   │   ├── SmartSearchController.php # AML + SmartDoc endpoints
│   │   ├── Webhook/
│   │   │   └── HubSpotWebhookController.php
│   │   └── Webhooks/
│   │       └── SmartSearchWebhookController.php
│   └── Requests/
│       ├── AMLRequest.php            # AML validation
│       └── SmartDocRequest.php       # SmartDoc validation (+ notify_method sms/email)
│
├── Models/
│   ├── Log.php                       # logs table (type, message, payload, log_group_id)
│   └── SmartSearchSearch.php         # SmartSearch searches (search_id, type, status, result)
│
├── Repositories/
│   ├── Contracts/
│   │   └── LogRepositoryInterface.php
│   └── LogRepository.php
│
└── Services/
    ├── HubSpotWebhookService.php     # Signature check, event dispatch, deal/contact fetch
    ├── LogService.php                # Creates logs with a shared per-request log_group_id
    └── SmartSearch/
        ├── AMLService.php            # POST /v3/ukindividual (synchronous result)
        ├── AuthenticationService.php # Token fetch + 14-minute cache
        ├── SmartSearchClient.php     # Authenticated JSON:API HTTP client
        ├── SmartDocService.php       # POST /v3/smartdoc (+ SSID / subject-id extractors)
        ├── NotificationService.php   # Sends search link to end user
        ├── WebhookService.php        # Registers search webhooks + handles callbacks
        └── Exceptions/
            └── SmartSearchException.php
```

Supporting files: `routes/api.php` (HubSpot/SmartSearch endpoints), `routes/web.php` (welcome + logs), `config/services.php` (`hubspot`, `smartsearch` credentials), `config/logs.php` (logs page token), `public/css/logs.css`, `docker/nginx/default.conf`.

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/smartsearch/aml` | Run a UK individual AML check (synchronous) |
| POST | `/api/smartsearch/smartdoc` | Create a SmartDoc search, register its webhook, and send the link to the client (SMS default) |
| POST | `/api/smartsearch/event` | SmartSearch webhook receiver |
| POST | `/api/hubspot/event` | HubSpot webhook receiver |
| GET | `/logs/{token}` | Log viewer (token from `LOGS_ACCESS_TOKEN`) |

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
