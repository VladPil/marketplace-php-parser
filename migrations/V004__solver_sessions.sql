-- Таблица сессий solver для отслеживания полученных cookies и Client Hints
-- Каждая запись привязана к задаче парсинга и содержит все данные для воспроизведения запроса

CREATE TABLE solver_sessions (
    id              BIGSERIAL       PRIMARY KEY,
    parse_task_id   UUID            REFERENCES parse_tasks(id) ON DELETE SET NULL,
    cookies         JSONB           NOT NULL DEFAULT '[]',
    user_agent      TEXT            NOT NULL DEFAULT '',
    client_hints    JSONB           NOT NULL DEFAULT '{}',
    proxy           VARCHAR(255)    NOT NULL DEFAULT 'direct',
    status          VARCHAR(20)     NOT NULL DEFAULT 'success',
    error_message   TEXT,
    created_at      TIMESTAMPTZ     NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_solver_sessions_task ON solver_sessions(parse_task_id);
CREATE INDEX idx_solver_sessions_created ON solver_sessions(created_at DESC);
