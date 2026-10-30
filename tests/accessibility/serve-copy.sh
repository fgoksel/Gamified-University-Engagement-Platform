#!/usr/bin/env bash
# Task #61: serve a throwaway copy of the app for the accessibility check.
#
# The copy has its own SQLite database with the Sprint 2 demo data, so the check
# never touches the dev database, and the same data makes the results before and
# after the fixes comparable. It is served at http://127.0.0.1:8099.
#
#   tests/accessibility/serve-copy.sh [commit]   build and start a copy (default HEAD)
#   tests/accessibility/serve-copy.sh --stop     remove the copy
#
# Needs the dev images (project-app, project-vite), the project_node_modules
# volume that docker compose creates, and an installed vendor folder.

set -euo pipefail
# Git Bash on Windows would turn the container paths into Windows paths.
export MSYS_NO_PATHCONV=1

NAME=gup-a11y
VOLUME=gup-a11y-src
PORT=8099
ROOT="$(git rev-parse --show-toplevel)"

docker rm -f "$NAME" >/dev/null 2>&1 || true
docker volume rm "$VOLUME" >/dev/null 2>&1 || true

if [ "${1:-}" = "--stop" ]; then
    echo "The copy is removed."
    exit 0
fi

COMMIT="$(git -C "$ROOT" rev-parse --short "${1:-HEAD}")"
echo "Building a copy of $COMMIT ..."

docker volume create "$VOLUME" >/dev/null
git -C "$ROOT" archive "$COMMIT" | docker run --rm -i -v "$VOLUME":/app project-app tar -x -C /app

# vendor goes into the volume in one stream. Read through the Windows bind mount on
# every request instead, each page took 10 to 15 seconds.
tar -C "$ROOT/vendor" -cf - . | docker run --rm -i -v "$VOLUME":/app project-app sh -c 'mkdir -p /app/vendor && tar -x -C /app/vendor'

docker run --rm --network none -v "$VOLUME":/app -v project_node_modules:/app/node_modules:ro -w /app project-vite \
    npx vite build --configLoader runner >/dev/null

docker run --rm --network none -e A11Y_PORT="$PORT" -v "$VOLUME":/app -w /app project-app sh -ec '
    cp .env.example .env
    sed -i \
        -e "s#^APP_URL=.*#APP_URL=http://127.0.0.1:$A11Y_PORT#" \
        -e "s#^DB_CONNECTION=.*#DB_CONNECTION=sqlite#" \
        -e "s#^DB_DATABASE=.*#DB_DATABASE=/app/database/a11y.sqlite#" \
        -e "/^DB_HOST=/d" -e "/^DB_PORT=/d" -e "/^DB_USERNAME=/d" -e "/^DB_PASSWORD=/d" \
        -e "s#^SESSION_DRIVER=.*#SESSION_DRIVER=file#" \
        -e "s#^CACHE_STORE=.*#CACHE_STORE=file#" \
        -e "s#^QUEUE_CONNECTION=.*#QUEUE_CONNECTION=sync#" \
        -e "s#^MAIL_MAILER=.*#MAIL_MAILER=log#" .env
    touch database/a11y.sqlite
    php artisan key:generate --force -q
    php artisan migrate --force -q
    php artisan db:seed --class=Sprint2DemoSeeder --force -q'

docker run -d --name "$NAME" -p "127.0.0.1:$PORT:8000" -e PHP_CLI_SERVER_WORKERS=6 -v "$VOLUME":/app -w /app project-app sh -c '
    printf "opcache.enable_cli=1\nopcache.validate_timestamps=0\n" > /usr/local/etc/php/conf.d/zz-a11y.ini
    exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload' >/dev/null

for _ in $(seq 1 60); do
    # The shell discards the page: with MSYS_NO_PATHCONV, curl.exe cannot open /dev/null itself.
    if curl -fs "http://127.0.0.1:$PORT/login" >/dev/null; then
        echo "The copy of $COMMIT is at http://127.0.0.1:$PORT (demo accounts: password)."
        exit 0
    fi
    sleep 1
done

echo "The copy did not start. See: docker logs $NAME" >&2
exit 1
