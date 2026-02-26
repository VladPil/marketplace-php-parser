COMPOSE_LOCAL := .docker/compose/local.yml
COMPOSE_INFRA := .docker/compose/infra.yml
ENV_FILE := .env
DC := docker compose -f $(COMPOSE_LOCAL) --env-file $(ENV_FILE)
DC_INFRA := docker compose -f $(COMPOSE_INFRA) --env-file $(ENV_FILE)

.PHONY: init up down restart ps build logs logs-admin logs-parser \
        shell-admin shell-parser shell-postgres migrate db-reset \
        infra infra-down lint phpstan clean clean-all help


init: ## Инициализация проекта
	@scripts/mp-init-project.sh


up: ## Запустить контейнеры
	$(DC) up -d

down: ## Остановить контейнеры
	$(DC) down

restart: ## Перезапустить контейнеры
	$(DC) restart

ps: ## Статус контейнеров
	$(DC) ps

build: ## Собрать Docker-образы
	$(DC) build

logs: ## Показать логи всех контейнеров
	$(DC) logs -f

logs-admin: ## Показать логи админки
	$(DC) logs -f admin

logs-parser: ## Показать логи парсера
	$(DC) logs -f parser

shell-admin: ## Открыть shell в контейнере админки
	$(DC) exec admin bash

shell-parser: ## Открыть shell в контейнере парсера
	$(DC) exec parser bash

shell-postgres: ## Открыть psql-консоль PostgreSQL
	$(DC) exec postgres psql -U $${MP__POSTGRES__POSTGRES_USER:-mp} -d $${MP__POSTGRES__POSTGRES_DB:-mp_parser}

migrate: ## Применить миграции БД
	@scripts/mp-db-migrate.sh

db-reset: ## Пересоздать БД (все данные будут удалены!)
	@echo "ВНИМАНИЕ: Все данные будут удалены!"
	@read -p "Вы уверены? [y/N] " confirm && [ "$$confirm" = "y" ] && \
		$(DC) exec postgres psql -U $${MP__POSTGRES__POSTGRES_USER:-mp} -c "DROP DATABASE IF EXISTS $${MP__POSTGRES__POSTGRES_DB:-mp_parser}" && \
		$(DC) exec postgres psql -U $${MP__POSTGRES__POSTGRES_USER:-mp} -c "CREATE DATABASE $${MP__POSTGRES__POSTGRES_DB:-mp_parser}" && \
		make migrate || echo "Отменено."

infra: ## Запустить только инфраструктуру (для локальной разработки)
	$(DC_INFRA) up -d

infra-down: ## Остановить инфраструктуру
	$(DC_INFRA) down

lint: ## Проверить синтаксис PHP
	@echo "Проверка синтаксиса PHP..."
	@find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
	@echo "Проверка завершена."

phpstan: ## Статический анализ (PHPStan)
	vendor/bin/phpstan analyse -c phpstan.neon.dist

clean: ## Очистить временные файлы
	@find . -name ".DS_Store" -delete 2>/dev/null || true
	@find . -name "*.cache" -delete 2>/dev/null || true

clean-all: down clean ## Полная очистка (контейнеры + образы + volumes)
	$(DC) down --rmi all --volumes


help: ## Показать доступные команды
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

.DEFAULT_GOAL := help
