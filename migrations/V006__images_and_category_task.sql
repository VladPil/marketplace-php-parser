-- Таблица изображений сущностей (товары, категории и т.д.)
CREATE TABLE IF NOT EXISTS images (
    id              BIGSERIAL PRIMARY KEY,
    entity_type     VARCHAR(50) NOT NULL,
    entity_id       BIGINT NOT NULL,
    url             TEXT NOT NULL,
    parse_task_id   UUID REFERENCES parse_tasks(id) ON DELETE SET NULL,
    sort_order      INT DEFAULT 0,
    created_at      TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX idx_images_entity ON images(entity_type, entity_id);
CREATE INDEX idx_images_task ON images(parse_task_id);

-- Привязка категорий к задачам парсинга
ALTER TABLE categories ADD COLUMN IF NOT EXISTS parse_task_id UUID REFERENCES parse_tasks(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_categories_task ON categories(parse_task_id);
