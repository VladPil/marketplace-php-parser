-- V009: Таблица прокси-серверов, управляемых через админку

CREATE TABLE IF NOT EXISTS proxies (
    id BIGSERIAL PRIMARY KEY,
    address VARCHAR(500) NOT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'admin',
    is_enabled BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_proxies_address ON proxies (address);
CREATE INDEX IF NOT EXISTS idx_proxies_enabled ON proxies (is_enabled) WHERE is_enabled = true;