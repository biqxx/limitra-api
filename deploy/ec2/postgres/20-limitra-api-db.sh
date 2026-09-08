#!/bin/sh
set -eu

: "${LIMITRA_API_DB_NAME:?LIMITRA_API_DB_NAME is required}"
: "${LIMITRA_API_DB_USER:?LIMITRA_API_DB_USER is required}"
: "${LIMITRA_API_DB_PASSWORD:?LIMITRA_API_DB_PASSWORD is required}"

psql \
    --username "$POSTGRES_USER" \
    --dbname "$POSTGRES_DB" \
    --set=ON_ERROR_STOP=1 \
    --set=app_db="$LIMITRA_API_DB_NAME" \
    --set=app_user="$LIMITRA_API_DB_USER" \
    --set=app_password="$LIMITRA_API_DB_PASSWORD" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'app_user', :'app_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'app_user')\gexec

SELECT format('ALTER ROLE %I WITH LOGIN PASSWORD %L', :'app_user', :'app_password')\gexec

SELECT format('CREATE DATABASE %I OWNER %I', :'app_db', :'app_user')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'app_db')\gexec

SELECT format('ALTER DATABASE %I OWNER TO %I', :'app_db', :'app_user')\gexec
SQL
