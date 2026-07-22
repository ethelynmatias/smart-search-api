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
