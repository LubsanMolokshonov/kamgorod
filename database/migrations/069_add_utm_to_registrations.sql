-- Добавление UTM-полей в таблицу регистраций на конкурсы
ALTER TABLE registrations
    ADD COLUMN utm_source VARCHAR(255) NULL,
    ADD COLUMN utm_medium VARCHAR(255) NULL,
    ADD COLUMN utm_campaign VARCHAR(255) NULL,
    ADD COLUMN utm_content VARCHAR(255) NULL,
    ADD COLUMN utm_term VARCHAR(255) NULL;
