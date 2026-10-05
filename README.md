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
| Send a test email | `docker compose exec app php artisan mail:test you@example.com` |
| Follow queue worker logs | `docker compose logs -f queue` |

## Services

| Service | URL / port | Notes |
| --- | --- | --- |
| App (nginx) | http://localhost:8081 | The site |
| Vite dev server | http://localhost:5173 | Hot reload for Vue / CSS |
| Mailpit | http://localhost:8025 | Catches all outgoing email |
| Queue worker | internal only | Sends queued jobs such as invitation emails |
| MySQL | `localhost:3307` | Database `gup`, user `gup_user`, password `secret` |
| Redis | internal only | Host `redis` inside the Docker network |

The passwords above are for local development only. Never reuse them on a server.

## Production: HTTPS and security headers

Locally the site runs on plain HTTP (http://localhost:8081). In production Nginx ends the TLS connection
(Technical Specification 5.2.1, 8.2):

- every request on port 80 is redirected to HTTPS (301)
- `Strict-Transport-Security` (HSTS), `X-Frame-Options` and `X-Content-Type-Options` are sent with every response
  (the last two also locally)
- only TLS 1.2 and 1.3 are allowed
- with `APP_ENV=production` the session cookie is `Secure`; set `APP_URL` to the `https://` address

```bash
# 1. Put the certificate chain and private key here (the folder is ignored by git)
#    docker/nginx/certs/fullchain.pem
#    docker/nginx/certs/privkey.pem

# 2. Start the web server with the production file on top of the normal one
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d webserver
```

## Troubleshooting

- **Page shows a Vite manifest error:** the `vite` container isn't running. Check it with `docker compose logs vite`.
- **Migrations fail with "connection refused":** MySQL is still starting. Wait a few seconds and try again.
- **Changed the Dockerfile:** rebuild with `docker compose up -d --build`.
- **Emails don't arrive in Mailpit:** check the worker with `docker compose logs queue`. After pulling new PHP code, restart it with `docker compose restart queue`, because the worker keeps the old code in memory.
- **Emails end up in `storage/logs/laravel.log` instead of Mailpit:** if `.env` has `MAIL_MAILER=log` or `MAIL_PORT=2525`, copy the `MAIL_` block from `.env.example` and run `docker compose restart app queue`.