# Gamified University Engagement Platform

Laravel 13 + Inertia 3 + Vue 3 + Tailwind CSS 4, running in Docker.

## Requirements

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (includes Docker Compose)
- Git

You don't need PHP, Composer or Node installed on your machine. Everything runs inside the containers.

## First-time setup

Run these from the project folder:

```bash
# 1. Create your local environment file
cp .env.example .env

# 2. Build the images and start all containers
docker compose up -d --build

# 3. Install PHP dependencies
docker compose exec app composer install

# 4. Generate the application key
docker compose exec app php artisan key:generate

# 5. Create the database tables
docker compose exec app php artisan migrate
```

The `vite` container runs `npm install` on startup, so JavaScript dependencies install automatically.
The first start can take a minute or two.

On Windows PowerShell, use `copy .env.example .env` for step 1.

## Everyday use

| What | Command |
| --- | --- |
| Start everything | `docker compose up -d` |
| Stop everything | `docker compose down` |
| Run migrations | `docker compose exec app php artisan migrate` |
| Run tests | `docker compose exec app php artisan test` |
| Any artisan command | `docker compose exec app php artisan <command>` |
| Follow Vite logs | `docker compose logs -f vite` |

## Services

| Service | URL / port | Notes |
| --- | --- | --- |
| App (nginx) | http://localhost:8081 | The site |
| Vite dev server | http://localhost:5173 | Hot reload for Vue / CSS |
| Mailpit | http://localhost:8025 | Catches all outgoing email |
| MySQL | `localhost:3307` | Database `gup`, user `gup_user`, password `secret` |
| Redis | internal only | Host `redis` inside the Docker network |

The passwords above are for local development only. Never reuse them on a server.

## Troubleshooting

- **Page shows a Vite manifest error:** the `vite` container isn't running. Check it with `docker compose logs vite`.
- **Migrations fail with "connection refused":** MySQL is still starting. Wait a few seconds and try again.
- **Changed the Dockerfile:** rebuild with `docker compose up -d --build`.