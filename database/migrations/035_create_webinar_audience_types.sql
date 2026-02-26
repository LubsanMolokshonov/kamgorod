-- 035: Создать таблицу webinar_audience_types
-- Связь вебинаров с типами аудитории (аналогично competition_audience_types)
-- Необходима для работы рекомендаций в корзине (CartRecommendation)

CREATE TABLE IF NOT EXISTS webinar_audience_types (
    webinar_id INT UNSIGNED NOT NULL,
    audience_type_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (webinar_id, audience_type_id),
    FOREIGN KEY (webinar_id) REFERENCES webinars(id) ON DELETE CASCADE,
    FOREIGN KEY (audience_type_id) REFERENCES audience_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Заполнить данными: привязать все активные вебинары ко всем 4 типам аудитории
-- (ДОУ, Начальная школа, Средняя/старшая школа, СПО)
-- Администратор может потом уточнить связи через панель управления

INSERT IGNORE INTO webinar_audience_types (webinar_id, audience_type_id)
SELECT w.id, at.id
FROM webinars w
CROSS JOIN audience_types at
WHERE w.is_active = 1 AND at.is_active = 1;
