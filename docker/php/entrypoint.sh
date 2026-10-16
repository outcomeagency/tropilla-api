#!/usr/bin/env bash
# Entrypoint idempotente de tropilla-api.
#
# CONTAINER_ROLE=app       → bootstrap completo (composer, .env, key, espera DB,
#                            migrate, seed) y después php-fpm.
# CONTAINER_ROLE=queue|scheduler → no toca la DB: compose los arranca recién
#                            cuando `app` está healthy (migraciones ya corridas).
#
# Se puede reiniciar cuantas veces se quiera: cada paso chequea antes de actuar.
set -euo pipefail

cd /var/www/html

ROLE="${CONTAINER_ROLE:-app}"
READY_FILE=/tmp/tropilla-ready
log() { echo "[entrypoint:${ROLE}] $*"; }

if [ "$ROLE" != "app" ]; then
    until [ -f vendor/autoload.php ] && grep -q '^APP_KEY=base64:' .env 2>/dev/null; do
        log "esperando a que app termine el bootstrap..."
        sleep 2
    done
    exec "$@"
fi

rm -f "$READY_FILE"

mkdir -p storage/framework/{cache/data,sessions,views,testing} storage/logs storage/app/public bootstrap/cache

# Dependencias: si falta vendor o composer.lock cambió (git pull con deps nuevas).
if [ ! -f vendor/autoload.php ] || [ composer.lock -nt vendor/autoload.php ]; then
    log "composer install..."
    composer install --no-interaction --prefer-dist --no-progress
    touch vendor/autoload.php
else
    # Clases nuevas de un git pull sin cambios de deps.
    composer dump-autoload --optimize --no-interaction --quiet
fi

# .env + APP_KEY (solo si faltan).
if [ ! -f .env ]; then
    log "creando .env desde .env.example"
    cp .env.example .env
fi
if ! grep -q '^APP_KEY=base64:' .env; then
    log "generando APP_KEY"
    php artisan key:generate --force --no-interaction
fi

# Espera a PostgreSQL (máx. ~2 min).
env_or_dotenv() { local v="${!1:-}"; [ -n "$v" ] && echo "$v" || { grep -E "^$1=" .env | tail -1 | cut -d= -f2- || true; }; }

DB_HOST="$(env_or_dotenv DB_HOST)"
DB_PORT="$(env_or_dotenv DB_PORT)"
DB_PORT="${DB_PORT:-5432}"
DB_USERNAME="$(env_or_dotenv DB_USERNAME)"
DB_DATABASE="$(env_or_dotenv DB_DATABASE)"
log "esperando a la base ${DB_HOST}:${DB_PORT}..."
for i in $(seq 1 60); do
    # -U/-d explícitos: con un UID sin entrada en /etc/passwd libpq no infiere el usuario.
    if pg_isready -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" -q; then
        break
    fi
    if [ "$i" -eq 60 ]; then
        log "la base no respondió a tiempo"
        exit 1
    fi
    sleep 2
done

php artisan config:clear --no-interaction --quiet
php artisan migrate --force --no-interaction

# Seeders idempotentes (updateOrCreate). SEED_ON_BOOT=false en entornos compartidos.
SEED_ON_BOOT="$(env_or_dotenv SEED_ON_BOOT)"
if [ "${SEED_ON_BOOT:-true}" != "false" ]; then
    php artisan db:seed --force --no-interaction
fi

php artisan storage:link --no-interaction --quiet 2>/dev/null || true

touch "$READY_FILE"
log "listo"

exec "$@"
