-- URL для ротации IP у ротационных прокси (HTTP-запрос для смены IP)
ALTER TABLE proxies ADD COLUMN rotation_url VARCHAR(1000) DEFAULT NULL;