-- UTM-атрибуция для олимпиадных регистраций (first-click attribution)
ALTER TABLE olympiad_registrations
    ADD COLUMN utm_source VARCHAR(255) NULL,
    ADD COLUMN utm_medium VARCHAR(255) NULL,
    ADD COLUMN utm_campaign VARCHAR(255) NULL,
    ADD COLUMN utm_content VARCHAR(255) NULL,
    ADD COLUMN utm_term VARCHAR(255) NULL;
