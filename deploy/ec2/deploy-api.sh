#!/bin/sh
set -eu

APP_DIR="${APP_DIR:-/opt/apps/limitra-user-service}"
COMPOSE_FILE="${COMPOSE_FILE:-compose.production.yml}"
ENV_FILE="${ENV_FILE:-.env.production}"

cd "$APP_DIR"

export LIMITRA_API_ENV_FILE="$ENV_FILE"

docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" --profile tools pull
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" --profile tools run --rm --no-deps migrate
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" up -d --no-build --remove-orphans
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" ps
