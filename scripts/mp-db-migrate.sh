#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

MIGRATIONS_DIR="$PROJECT_DIR/migrations"
COMPOSE_FILE="$PROJECT_DIR/.docker/compose/local.yml"
ENV_FILE="$PROJECT_DIR/.env"
DC="docker compose -f $COMPOSE_FILE --env-file $ENV_FILE"

DB_USER="${MP__DB__USER:-mp}"
DB_NAME="${MP__DB__NAME:-mp_parser}"

echo "=== Применение SQL-миграций ==="

# Создание таблицы отслеживания миграций
$DC exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -c "
CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(255) PRIMARY KEY,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);" 2>/dev/null

# Применение миграций в порядке версий
for migration in $(ls "$MIGRATIONS_DIR"/*.sql 2>/dev/null | sort); do
    filename=$(basename "$migration")
    version=$(echo "$filename" | sed 's/__.*$//')

    # Проверка, была ли миграция уже применена
    applied=$($DC exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -tAc \
        "SELECT COUNT(*) FROM schema_migrations WHERE version = '$version'")

    if [ "$applied" -eq 0 ]; then
        echo "Applying: $filename"
        $DC exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -f "/dev/stdin" < "$migration"
        $DC exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -c \
            "INSERT INTO schema_migrations (version) VALUES ('$version')"
        echo "  ✓ Applied"
    else
        echo "  · Skipped (already applied): $filename"
    fi
done

echo "=== Миграции применены ==="
