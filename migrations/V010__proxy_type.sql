-- Тип прокси: 'static' (фиксированный IP) или 'rotating' (IP меняется провайдером)
ALTER TABLE proxies ADD COLUMN type VARCHAR(20) NOT NULL DEFAULT 'static';
