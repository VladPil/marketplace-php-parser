-- =============================================================================
-- V002__normalize_and_optimize.sql
-- Normalizes the schema for multi-marketplace support by replacing
-- Ozon-specific column names with generic external_id / external_review_id
-- and adding a marketplace discriminator column to each relevant table.
-- Also relaxes rating constraints (allow 0), adds 'cancelled' task status,
-- and replaces single-column indexes with more efficient composite ones.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Products: rename ozon_id -> external_id, add marketplace column
-- -----------------------------------------------------------------------------
ALTER TABLE products RENAME COLUMN ozon_id TO external_id;
ALTER TABLE products ADD COLUMN marketplace VARCHAR(50) NOT NULL DEFAULT 'ozon';
ALTER TABLE products DROP CONSTRAINT products_ozon_id_key;
ALTER TABLE products ADD CONSTRAINT products_marketplace_external_id_key
    UNIQUE (marketplace, external_id);

-- -----------------------------------------------------------------------------
-- 2. Reviews: rename ozon_review_id -> external_review_id, add marketplace
-- -----------------------------------------------------------------------------
ALTER TABLE reviews RENAME COLUMN ozon_review_id TO external_review_id;
ALTER TABLE reviews ADD COLUMN marketplace VARCHAR(50) NOT NULL DEFAULT 'ozon';
ALTER TABLE reviews DROP CONSTRAINT reviews_ozon_review_id_key;
ALTER TABLE reviews ADD CONSTRAINT reviews_marketplace_external_review_id_key
    UNIQUE (marketplace, external_review_id);

-- -----------------------------------------------------------------------------
-- 3. Categories: rename ozon_id -> external_id, add marketplace
-- -----------------------------------------------------------------------------
ALTER TABLE categories RENAME COLUMN ozon_id TO external_id;
ALTER TABLE categories ADD COLUMN marketplace VARCHAR(50) NOT NULL DEFAULT 'ozon';
ALTER TABLE categories DROP CONSTRAINT categories_ozon_id_key;
ALTER TABLE categories ADD CONSTRAINT categories_marketplace_external_id_key
    UNIQUE (marketplace, external_id);

-- -----------------------------------------------------------------------------
-- 4. Parse tasks: add marketplace
-- -----------------------------------------------------------------------------
ALTER TABLE parse_tasks ADD COLUMN marketplace VARCHAR(50) NOT NULL DEFAULT 'ozon';

-- -----------------------------------------------------------------------------
-- 5. Fix constraints — relax rating to allow 0, add 'cancelled' status
-- -----------------------------------------------------------------------------
ALTER TABLE reviews DROP CONSTRAINT reviews_rating_check;
ALTER TABLE reviews ADD CONSTRAINT reviews_rating_check
    CHECK (rating BETWEEN 0 AND 5);

ALTER TABLE parse_tasks DROP CONSTRAINT parse_tasks_status_check;
ALTER TABLE parse_tasks ADD CONSTRAINT parse_tasks_status_check
    CHECK (status IN ('pending', 'running', 'completed', 'failed', 'paused', 'cancelled'));

ALTER TABLE products ALTER COLUMN rating TYPE NUMERIC(2,1);
ALTER TABLE products DROP CONSTRAINT products_rating_check;
ALTER TABLE products ADD CONSTRAINT products_rating_check
    CHECK (rating BETWEEN 0.0 AND 5.0);

-- -----------------------------------------------------------------------------
-- 6. Composite indexes (more efficient for common query patterns)
-- -----------------------------------------------------------------------------
CREATE INDEX idx_parse_tasks_status_created
    ON parse_tasks (status, created_at DESC);

CREATE INDEX idx_products_category_rating
    ON products (category_id, rating DESC);

CREATE INDEX idx_reviews_product_date
    ON reviews (product_id, review_date DESC);

CREATE INDEX idx_review_summaries_product_created
    ON review_summaries (product_id, created_at DESC);

CREATE INDEX idx_parse_tasks_marketplace
    ON parse_tasks (marketplace);

CREATE INDEX idx_products_marketplace
    ON products (marketplace);

-- -----------------------------------------------------------------------------
-- 7. Drop single-column indexes now covered by composites above
-- -----------------------------------------------------------------------------
DROP INDEX IF EXISTS idx_parse_tasks_status;
DROP INDEX IF EXISTS idx_parse_tasks_created_at;
DROP INDEX IF EXISTS idx_reviews_review_date;
