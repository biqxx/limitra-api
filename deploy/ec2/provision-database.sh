#!/bin/sh
set -eu

APP_DIR="${APP_DIR:-/opt/apps/limitra-user-service}"
PLATFORM_DIR="${PLATFORM_DIR:-/opt/platform}"
ENV_FILE="${ENV_FILE:-$APP_DIR/.env.production}"
INIT_SCRIPT="$APP_DIR/deploy/ec2/postgres/20-limitra-api-db.sh"

read_env_value() {
    key="$1"
    sed -n "s/^${key}=//p" "$ENV_FILE" | tail -n 1
}

database_name="$(read_env_value DB_DATABASE)"
database_user="$(read_env_value DB_USERNAME)"
database_password="$(read_env_value DB_PASSWORD)"

if [ -z "$database_name" ] || [ -z "$database_user" ] || [ -z "$database_password" ]; then
    echo "DB_DATABASE, DB_USERNAME, and DB_PASSWORD must be set in $ENV_FILE." >&2
    exit 1
fi

if [ "$database_password" = "CHANGE_ME" ]; then
    echo "Replace the example DB_PASSWORD before provisioning the database." >&2
    exit 1
fi

cd "$PLATFORM_DIR"

docker compose exec -T \
    -e LIMITRA_API_DB_NAME="$database_name" \
    -e LIMITRA_API_DB_USER="$database_user" \
    -e LIMITRA_API_DB_PASSWORD="$database_password" \
    postgres sh -s < "$INIT_SCRIPT"
