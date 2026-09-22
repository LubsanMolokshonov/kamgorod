-- Отложенная рекомендация одного релевантного курса в MAX через ChatPush.
-- UNIQUE(order_id) не даёт повторному webhook поставить дубль.

CREATE TABLE IF NOT EXISTS max_course_recommendations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    course_id INT UNSIGNED DEFAULT NULL,
    match_score DECIMAL(8,4) DEFAULT NULL,
    status ENUM('pending','processing','sent','skipped','failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL COMMENT 'Московское wall time; claim сравнивает с PHP Europe/Moscow',
    locked_at DATETIME DEFAULT NULL,
    send_started_at DATETIME DEFAULT NULL COMMENT 'UTC; начат вызов ChatPush, повтор запрещён',
    sent_at DATETIME DEFAULT NULL,
    provider_message_id VARCHAR(128) DEFAULT NULL,
    http_code SMALLINT DEFAULT NULL,
    skip_reason VARCHAR(100) DEFAULT NULL,
    error TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_max_course_recommendation_order (order_id),
    KEY idx_max_course_recommendation_queue (status, available_at, id),
    KEY idx_max_course_recommendation_user_sent (user_id, status, sent_at),
    KEY idx_max_course_recommendation_course (course_id),
    KEY idx_max_course_recommendation_attempts (send_started_at),
    CONSTRAINT fk_max_course_recommendation_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_max_course_recommendation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_max_course_recommendation_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Отдельная suppression-таблица для рекомендаций в MAX. Входящее «Стоп»
-- добавляет телефон сюда до запуска ИИ-ответа.
CREATE TABLE IF NOT EXISTS max_marketing_suppressions (
    phone VARCHAR(20) NOT NULL,
    reason VARCHAR(100) NOT NULL DEFAULT 'user_stop',
    provider_message_id VARCHAR(128) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
