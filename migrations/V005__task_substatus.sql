-- V005: Подстатусы задач + тип external_review_id для UUID отзывов

-- Расширяем CHECK constraint для статусов задач
ALTER TABLE parse_tasks DROP CONSTRAINT IF EXISTS parse_tasks_status_check;
ALTER TABLE parse_tasks ADD CONSTRAINT parse_tasks_status_check
  CHECK (status IN ('pending', 'running', 'completed_success', 'completed_empty', 'completed_partial', 'failed', 'paused', 'cancelled'));

-- Обновляем старые completed → completed_success (для совместимости)
UPDATE parse_tasks SET status = 'completed_success' WHERE status = 'completed';

-- UUID отзыва — строка (0198501d-9edb-7d6f-...), а не BIGINT
ALTER TABLE reviews ALTER COLUMN external_review_id TYPE VARCHAR(100);

-- Массив URL всех фото товара (JSON)
ALTER TABLE products ADD COLUMN IF NOT EXISTS image_urls JSONB DEFAULT '[]';
