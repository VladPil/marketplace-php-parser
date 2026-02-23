-- Таблица логов парсинга для детального отслеживания
-- Каждая запись привязана к trace_id и опционально к parse_task_id

CREATE TABLE IF NOT EXISTS parse_logs (
    id BIGSERIAL PRIMARY KEY,
    trace_id VARCHAR(64) NOT NULL,
    parse_task_id UUID REFERENCES parse_tasks(id) ON DELETE SET NULL,
    level VARCHAR(10) NOT NULL DEFAULT 'info',
    channel VARCHAR(50) NOT NULL DEFAULT 'parser',
    message TEXT NOT NULL,
    context JSONB DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_parse_logs_trace_id ON parse_logs (trace_id);
CREATE INDEX idx_parse_logs_task_id ON parse_logs (parse_task_id);
CREATE INDEX idx_parse_logs_level ON parse_logs (level);
CREATE INDEX idx_parse_logs_created_at ON parse_logs (created_at DESC);
