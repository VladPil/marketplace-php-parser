#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

echo "=== Парсер маркетплейсов — Инициализация проекта ==="

# Копирование .env если не существует
if [ ! -f "$PROJECT_DIR/.env" ]; then
    cp "$PROJECT_DIR/.docker/env/.env.example" "$PROJECT_DIR/.env"
    echo "✓ Создан .env из .env.example"
else
    echo "· .env уже существует"
fi

# Подгрузить переменные для отображения портов
set -a
source "$PROJECT_DIR/.env"
set +a

# Сборка и запуск
COMPOSE_FILE="$PROJECT_DIR/.docker/compose/local.yml"

echo "=== Сборка образов ==="
docker compose -f "$COMPOSE_FILE" --env-file "$PROJECT_DIR/.env" build

echo "=== Запуск контейнеров ==="
docker compose -f "$COMPOSE_FILE" --env-file "$PROJECT_DIR/.env" up -d

echo "=== Ожидание готовности БД ==="
sleep 5

echo "=== Применение миграций ==="
"$PROJECT_DIR/scripts/mp-db-migrate.sh"

echo ""
echo "=== Проект готов! ==="
echo "Admin:  http://localhost:${MP__DOCKER__ADMIN_PORT:-8202}"
echo "Parser: http://localhost:${MP__DOCKER__PARSER_HEALTH_PORT:-8203}"
echo "DB:     psql -h localhost -p ${MP__DOCKER__DB_PORT:-5433} -U ${MP__DB__USER:-mp} -d ${MP__DB__NAME:-mp_parser}"
echo "Redis:  redis-cli -p ${MP__DOCKER__REDIS_PORT:-6380}"
