# Marketplace PHP Parser

Система парсинга маркетплейса Ozon с веб-админкой. PHP 8.4, Symfony, Swoole.

## Архитектура

```
marketplace-php-parser/
├── src/
│   ├── Module/
│   │   ├── Parser/    # Swoole-парсер (воркеры, очереди, API-клиент)
│   │   └── Admin/     # Symfony-админка (контроллеры, сервисы)
│   └── Shared/        # Общие сущности, репозитории, контракты
├── templates/         # Twig-шаблоны админки
├── migrations/        # SQL-миграции
├── config/            # Конфигурация Symfony
├── scripts/           # Скрипты инициализации и миграций
└── .docker/
    ├── dockerfiles/     # admin.Dockerfile, parser.Dockerfile
    ├── configs/admin/   # nginx.conf, supervisor.conf
    ├── compose/         # local.yml, infra.yml
    └── env/             # .env.example
```

## Сервисы

| Сервис   | Технологии               | Порт (хост) | Описание                     |
|----------|--------------------------|-------------|------------------------------|
| postgres | PostgreSQL 16 + pgvector | 5433        | Основная БД                  |
| redis    | Redis 7 Alpine           | 6380        | Очереди задач, прогресс, кеш |
| parser   | PHP 8.4 + Swoole         | 8203        | Движок парсинга (воркеры)    |
| admin    | PHP 8.4 + Nginx + FPM    | 8202        | Веб-админка (Symfony)        |

## Быстрый старт

### Требования

- Docker и Docker Compose
- Make

### Запуск

```bash
# 1. Скопировать конфигурацию
cp .docker/env/.env.example .env

# 2. Собрать и запустить
make build
make up

# 3. Дождаться запуска (30-60 секунд на первый раз)
make ps

# 4. Применить миграции
make migrate
```

Или одной командой:

```bash
make init
```

### Проверка работоспособности

```bash
# Все сервисы работают?
make ps

# Админка
curl http://localhost:8202/

# Parser health
curl http://localhost:8203/
```

## Админ-панель (localhost:8202)

Веб-интерфейс для управления парсингом:

- **Дашборд** (`/`) — обзор последних задач и товаров
- **Задачи** (`/tasks/`) — список задач парсинга, создание новых
- **Товары** (`/products/`) — спарсенные товары с ценами и рейтингами
- **Категории** (`/categories/`) — дерево категорий Ozon
- **Логи** (`/logs/`) — логи парсинга с фильтрацией по trace_id
- **Здоровье** (`/health/`) — статус всех сервисов

### Создание задачи парсинга

1. Перейти на `/tasks/create`
2. Выбрать тип задачи:
   - **Поиск товаров** — поиск по ключевому слову
   - **Парсинг товара** — детали конкретного товара (по external_id)
   - **Парсинг отзывов** — отзывы конкретного товара
3. Заполнить параметры и нажать «Создать задачу»

## Трассировка (trace_id)

Каждый запрос и задача помечаются уникальным `trace_id` (UUID v4):
- Передаётся через заголовок `X-Trace-Id` между сервисами
- Сохраняется в логах и в БД (таблица `parse_logs`)
- Позволяет отследить весь путь обработки задачи

## Прокси

Для работы через прокси-серверы настройте в `.env`:

```env
MP__PROXY__ENABLED=true
MP__PROXY__LIST=http://user:pass@proxy1:8080,socks5://user:pass@proxy2:1080
```

Поддерживаемые протоколы: `http`, `https`, `socks5`. Прокси ротируются случайным образом на каждый запрос.

## Solver-service

Парсер может работать с внешним solver-service для обхода анти-бот защиты Ozon. Подключение настраивается в `.env`:

```env
MP__SOLVER__HOST=host.docker.internal
MP__SOLVER__PORT=8204
MP__SOLVER__REQUEST_TIMEOUT=60
MP__SOLVER__CONNECT_TIMEOUT=10
```

## Makefile команды

```bash
make init           # Инициализация проекта (сборка + запуск + миграции)
make up             # Запуск контейнеров
make down           # Остановка
make restart        # Перезапуск
make ps             # Статус контейнеров
make build          # Пересборка образов
make logs           # Логи всех сервисов
make logs-parser    # Логи парсера
make logs-admin     # Логи админки
make migrate        # Применить SQL-миграции
make db-reset       # Сброс БД (с подтверждением)
make infra          # Только PostgreSQL + Redis (для локальной разработки)
make infra-down     # Остановить инфраструктуру
make shell-admin    # Bash в контейнер админки
make shell-parser   # Bash в контейнер парсера
make shell-postgres # psql к базе
make lint           # Проверка синтаксиса PHP
make phpstan        # Статический анализ (PHPStan)
make clean-all      # Удаление всех контейнеров и данных
```

## Структура БД

| Таблица           | Описание                           |
|-------------------|------------------------------------|
| products          | Товары с ценами и характеристиками |
| categories        | Дерево категорий                   |
| reviews           | Отзывы к товарам                   |
| review_summaries  | LLM-сводки по отзывам             |
| parse_tasks       | Задачи парсинга                    |
| parse_logs        | Логи с trace_id                    |
| schema_migrations | Отслеживание миграций              |

## Конфигурация

Вся конфигурация через переменные окружения в `.env` (см. `.docker/env/.env.example`):

| Группа              | Описание             |
|---------------------|----------------------|
| `MP__DOCKER__*`     | Порты контейнеров    |
| `MP__POSTGRES__*`   | Настройки PostgreSQL |
| `MP__DB__*`         | Подключение к БД     |
| `MP__REDIS__*`      | Подключение к Redis  |
| `MP__PARSER__*`     | Настройки парсера    |
| `MP__HTTP__*`       | HTTP-клиент          |
| `MP__PROXY__*`      | Прокси-серверы       |
| `MP__RETRY__*`      | Политика повторов    |
| `MP__SOLVER__*`     | Solver-service       |
