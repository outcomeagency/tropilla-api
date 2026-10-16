#!/usr/bin/env bash
# Base separada para Pest (phpunit.xml apunta a ${POSTGRES_DB}_test), así los
# tests con RefreshDatabase nunca tocan la base de desarrollo.
# Corre solo la primera vez que se inicializa el volumen.
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
    CREATE DATABASE "${POSTGRES_DB}_test" OWNER "$POSTGRES_USER";
SQL

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "${POSTGRES_DB}_test" <<-SQL
    CREATE EXTENSION IF NOT EXISTS postgis;
    CREATE EXTENSION IF NOT EXISTS pgcrypto;
SQL
