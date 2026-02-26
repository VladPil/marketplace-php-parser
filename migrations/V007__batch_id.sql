ALTER TABLE parse_tasks ADD COLUMN batch_id UUID;
CREATE INDEX idx_parse_tasks_batch_id ON parse_tasks (batch_id) WHERE batch_id IS NOT NULL;
