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
│   │       ├── HubSpotWebhookController.php      # Deal property change events
│   │       └── SmartSearchWebhookController.php  # Search result callbacks (status,
│   │                                             # subject notification, request date)
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
    │   ├── HubSpotService.php        # Deal property writes (ssids, status, request dates)
    │   └── HubSpotWebhookService.php # Signature check, event dispatch, searches, deal write-back
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

Searches are triggered by two checkbox properties on the deal, not by the deal stage.
Ticking either one runs that search; both can run on the same deal.

| Checkbox           | Runs                          | Writes back                                                        |
|--------------------|-------------------------------|--------------------------------------------------------------------|
| `ss_individual_uk` | UK individual AML search      | `smartsearch_uk_individual_ssid`, `uk_individual_request_date`      |
| `ss_smartdoc`      | SmartDoc document verification | `smartdoc_ssid`, `smartdoc_status`, `smartdoc_request_date`         |

1. `HubSpotWebhookController` verifies the signature and dispatches the event;
   `HubSpotWebhookService::handleDealPropertyChange()` ignores anything that is not one
   of the two checkboxes being set to `true`.
2. The deal is fetched and checked for that checkbox's own result properties. Any of
   them already holding a value means the search has run, and the event is skipped —
   HubSpot redelivers on its own schedule, so without this one deal searches repeatedly.
3. The deal's contacts and company are fetched. With no contacts on the deal, the
   company owner is used as the subject instead, using the company's own address.
4. **AML** (`ss_individual_uk`): one search per subject. The resulting ssids go onto the
   deal comma separated, and the `created_at` from the first search's meta is written as
   the request date.
5. **SmartDoc** (`ss_smartdoc`): one search per subject. Each is stored as a
   `webhook_details` row — `pending`, keyed by its SmartSearch id (`ssid`) — a result
   webhook is registered with SmartSearch for it, and the ssids and status go onto the deal.
6. When SmartSearch calls back, `SmartSearchWebhookController` matches the `ssid`, updates
   the row's status, and mirrors that status onto the deal. On `completed` it also sends
   the subject their verification link (email preferred, SMS otherwise, from the contact
   details stored on the detail) and writes the search's `created_at` as the request date.

Every step logs into the deal's original log group, so the whole deal reads as one thread
at `/logs/{token}`.

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

### HubSpot deal properties

The Deal object needs these custom properties. The two checkboxes trigger the searches;
the rest are what `HubSpotService` writes back. A property that does not exist has its
PATCH rejected with `PROPERTY_DOESNT_EXIST`, logged as a warning — the search itself is
unaffected, so the result lives on the webhook detail either way.

| Property                        | Type              | Written by                            |
|---------------------------------|-------------------|---------------------------------------|
| `ss_individual_uk`              | Checkbox          | — (trigger)                           |
| `ss_smartdoc`                   | Checkbox          | — (trigger)                           |
| `smartsearch_uk_individual_ssid`| Single-line text  | `updateSmartSearchUkIndividualSsid()` |
| `uk_individual_request_date`    | Single-line text  | `updateUkIndividualRequestDate()`     |
| `smartdoc_ssid`                 | Single-line text  | `updateSmartDocSsid()`                |
| `smartdoc_status`               | Single-line text  | `updateSmartDocStatus()`              |
| `smartdoc_request_date`         | Single-line text  | `updateSmartDocRequestDate()`         |

Request dates are written as `Y-m-d` in UTC, taken from the `created_at` SmartSearch
returns on the search rather than the time the write happens.
