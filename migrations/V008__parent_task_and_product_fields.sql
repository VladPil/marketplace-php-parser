-- V008: parent_task_id в parse_tasks + brand и description в products

ALTER TABLE parse_tasks ADD COLUMN IF NOT EXISTS parent_task_id UUID REFERENCES parse_tasks(id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_parse_tasks_parent_task_id ON parse_tasks (parent_task_id) WHERE parent_task_id IS NOT NULL;

ALTER TABLE products ADD COLUMN IF NOT EXISTS description TEXT;
ALTER TABLE products ADD COLUMN IF NOT EXISTS brand VARCHAR(500);
