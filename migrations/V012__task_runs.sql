-- =============================================================================
-- V012__task_runs.sql
-- Система запусков (runs) задач: одна задача — несколько запусков.
-- Статус задачи определяется статусом последнего запуска.
-- Логи привязываются к конкретному запуску через run_id.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Таблица task_runs: запуски задач
-- Каждый запуск фиксирует попытку выполнения задачи со своим статусом,
-- временем начала/завершения, количеством найденных элементов и ошибкой.
-- -----------------------------------------------------------------------------
CREATE TABLE task_runs (
    id              UUID            PRIMARY KEY DEFAULT gen_random_uuid(),
    task_id         UUID            NOT NULL REFERENCES parse_tasks(id) ON DELETE CASCADE,
    run_number      INTEGER         NOT NULL DEFAULT 1,
    status          VARCHAR(20)     NOT NULL DEFAULT 'pending',
    started_at      TIMESTAMPTZ,
    finished_at     TIMESTAMPTZ,
    parsed_items    INTEGER         NOT NULL DEFAULT 0,
    error           TEXT,
    identity_id     VARCHAR(255),
    created_at      TIMESTAMPTZ     NOT NULL DEFAULT NOW(),

    CONSTRAINT task_runs_status_check
        CHECK (status IN ('pending', 'running', 'completed_success', 'completed_empty',
                         'completed_partial', 'completed_skipped', 'failed', 'cancelled')),

    CONSTRAINT task_runs_unique_run UNIQUE (task_id, run_number)
);

CREATE INDEX idx_task_runs_task_id ON task_runs (task_id);
CREATE INDEX idx_task_runs_status ON task_runs (status);
CREATE INDEX idx_task_runs_created_at ON task_runs (created_at DESC);

-- -----------------------------------------------------------------------------
-- Добавляем run_id в parse_logs
-- -----------------------------------------------------------------------------
ALTER TABLE parse_logs ADD COLUMN run_id UUID REFERENCES task_runs(id) ON DELETE SET NULL;
CREATE INDEX idx_parse_logs_run_id ON parse_logs (run_id);

-- -----------------------------------------------------------------------------
-- Миграция существующих данных: создаём по одному run для каждой задачи
-- Статус и временные метки копируются из задачи.
-- -----------------------------------------------------------------------------
INSERT INTO task_runs (id, task_id, run_number, status, started_at, finished_at, parsed_items, error, created_at)
SELECT
    gen_random_uuid(),
    t.id,
    1,
    t.status,
    t.started_at,
    t.completed_at,
    COALESCE(t.parsed_items, 0),
    t.error_message,
    t.created_at
FROM parse_tasks t;

-- Привязываем существующие логи к созданным run-ам (через task_id)
UPDATE parse_logs l
SET run_id = r.id
FROM task_runs r
WHERE l.parse_task_id = r.task_id
  AND r.run_number = 1;
