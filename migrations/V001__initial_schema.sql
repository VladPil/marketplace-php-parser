-- =============================================================================
-- V001__initial_schema.sql
-- Initial database schema for the Ozon Reviews Parser project.
-- Creates core tables: parse_tasks, categories, products, reviews,
-- review_summaries. Enables pgvector extension for embedding storage.
-- Adds auto-updating updated_at trigger where applicable.
-- =============================================================================

CREATE EXTENSION IF NOT EXISTS vector;

-- -----------------------------------------------------------------------------
-- Trigger function: auto-update updated_at on row modification
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- -----------------------------------------------------------------------------
-- Table: parse_tasks
-- Tracks parsing jobs (category scrape, product scrape, review scrape, etc.)
-- -----------------------------------------------------------------------------
CREATE TABLE parse_tasks (
    id              UUID            PRIMARY KEY DEFAULT gen_random_uuid(),
    type            VARCHAR(50)     NOT NULL,
    params          JSONB           NOT NULL DEFAULT '{}',
    status          VARCHAR(20)     NOT NULL DEFAULT 'pending',
    total_items     INTEGER         DEFAULT 0,
    parsed_items    INTEGER         DEFAULT 0,
    error_message   TEXT,
    resume_state    JSONB,
    created_at      TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
    started_at      TIMESTAMPTZ,
    completed_at    TIMESTAMPTZ,

    CONSTRAINT parse_tasks_status_check
        CHECK (status IN ('pending', 'running', 'completed', 'failed', 'paused'))
);

CREATE INDEX idx_parse_tasks_status     ON parse_tasks (status);
CREATE INDEX idx_parse_tasks_created_at ON parse_tasks (created_at DESC);

CREATE TRIGGER trg_parse_tasks_updated_at
    BEFORE UPDATE ON parse_tasks
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- -----------------------------------------------------------------------------
-- Table: categories
-- Ozon product category tree (supports nested hierarchy via parent_id)
-- -----------------------------------------------------------------------------
CREATE TABLE categories (
    id              BIGSERIAL       PRIMARY KEY,
    ozon_id         BIGINT          NOT NULL UNIQUE,
    name            VARCHAR(500)    NOT NULL,
    parent_id       BIGINT          REFERENCES categories(id) ON DELETE SET NULL,
    depth           INTEGER         NOT NULL DEFAULT 0,
    path            TEXT,
    product_count   INTEGER         DEFAULT 0,
    created_at      TIMESTAMPTZ     NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_categories_parent_id ON categories (parent_id);
CREATE INDEX idx_categories_path      ON categories (path);

-- -----------------------------------------------------------------------------
-- Table: products
-- Parsed product cards from Ozon
-- -----------------------------------------------------------------------------
CREATE TABLE products (
    id                  BIGSERIAL       PRIMARY KEY,
    ozon_id             BIGINT          NOT NULL UNIQUE,
    category_id         BIGINT          REFERENCES categories(id) ON DELETE SET NULL,
    parse_task_id       UUID            REFERENCES parse_tasks(id) ON DELETE SET NULL,
    title               VARCHAR(1000)   NOT NULL,
    url                 TEXT,
    price               NUMERIC(12,2),
    original_price      NUMERIC(12,2),
    rating              NUMERIC(3,2),
    review_count        INTEGER         DEFAULT 0,
    image_url           TEXT,
    characteristics     JSONB           DEFAULT '{}',
    created_at          TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ     NOT NULL DEFAULT NOW(),

    CONSTRAINT products_rating_check
        CHECK (rating BETWEEN 1 AND 5)
);

CREATE INDEX idx_products_category_id      ON products (category_id);
CREATE INDEX idx_products_rating           ON products (rating DESC);
CREATE INDEX idx_products_characteristics  ON products USING GIN (characteristics);

CREATE TRIGGER trg_products_updated_at
    BEFORE UPDATE ON products
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- -----------------------------------------------------------------------------
-- Table: reviews
-- Individual user reviews linked to products
-- -----------------------------------------------------------------------------
CREATE TABLE reviews (
    id                  BIGSERIAL       PRIMARY KEY,
    product_id          BIGINT          NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    ozon_review_id      BIGINT          NOT NULL UNIQUE,
    parse_task_id       UUID            REFERENCES parse_tasks(id) ON DELETE SET NULL,
    author              VARCHAR(255),
    rating              INTEGER         NOT NULL,
    text                TEXT,
    pros                TEXT,
    cons                TEXT,
    review_date         TIMESTAMPTZ,
    image_urls          JSONB           DEFAULT '[]',
    first_reply         TEXT,
    created_at          TIMESTAMPTZ     NOT NULL DEFAULT NOW(),

    CONSTRAINT reviews_rating_check
        CHECK (rating BETWEEN 1 AND 5)
);

CREATE INDEX idx_reviews_product_id  ON reviews (product_id);
CREATE INDEX idx_reviews_rating      ON reviews (rating);
CREATE INDEX idx_reviews_review_date ON reviews (review_date DESC);

-- -----------------------------------------------------------------------------
-- Table: review_summaries
-- LLM-generated summaries and vector embeddings per product
-- -----------------------------------------------------------------------------
CREATE TABLE review_summaries (
    id                  BIGSERIAL       PRIMARY KEY,
    product_id          BIGINT          NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    summary_text        TEXT,
    pros_summary        TEXT,
    cons_summary        TEXT,
    verdict             TEXT,
    llm_task_id         VARCHAR(255),
    llm_model           VARCHAR(100),
    llm_status          VARCHAR(20)     DEFAULT 'pending',
    embedding           vector(1024),
    review_count        INTEGER         DEFAULT 0,
    idempotency_key     VARCHAR(255)    UNIQUE,
    created_at          TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ     NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_review_summaries_embedding
    ON review_summaries USING hnsw (embedding vector_cosine_ops);

CREATE INDEX idx_review_summaries_product_id
    ON review_summaries (product_id);

CREATE TRIGGER trg_review_summaries_updated_at
    BEFORE UPDATE ON review_summaries
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();
