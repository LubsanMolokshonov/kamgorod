-- 155_max_conversations.sql
-- Переписка в мессенджере «Макс» (через ChatPush): единый тред исходящих уведомлений,
-- входящих ответов пользователя, авто-ответов ИИ-менеджера и ручных ответов поддержки.
-- Плюс источник алерта 'max' (входящее сообщение, которое ИИ счёл обращением в поддержку).
--
-- max_notifications (миграция 154) остаётся транзакционным дедуп-журналом отправки на заказ;
-- здесь — полная лента диалога по телефону для дашборда /admin/max/.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

-- Источник алерта «Макс» + телефон отправителя (аналог vk_peer_id).
ALTER TABLE support_alerts
    MODIFY COLUMN source ENUM('ai_chat','email','manual','vk','max') NOT NULL DEFAULT 'ai_chat',
    ADD COLUMN max_phone VARCHAR(20) DEFAULT NULL AFTER vk_peer_id;

-- Лента сообщений «Макс» по телефону.
CREATE TABLE IF NOT EXISTS max_messages (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone               VARCHAR(20) NOT NULL,                 -- нормализованный 79XXXXXXXXX (ключ треда)
    user_id             INT UNSIGNED DEFAULT NULL,            -- если телефон сопоставлен с users
    direction           ENUM('out','in') NOT NULL,
    author              ENUM('system','ai','admin','user') NOT NULL,
    `text`              TEXT DEFAULT NULL,
    `status`            ENUM('pending','sent','failed') DEFAULT NULL,  -- для исходящих
    http_code           INT DEFAULT NULL,
    provider_response   TEXT DEFAULT NULL,                    -- сырой ответ ChatPush
    error               TEXT DEFAULT NULL,
    order_id            INT UNSIGNED DEFAULT NULL,            -- заказ-триггер (системное уведомление)
    alert_id            BIGINT UNSIGNED DEFAULT NULL,         -- если по входящему создан алерт
    provider_message_id VARCHAR(128) DEFAULT NULL,           -- id сообщения у ChatPush (дедуп входящих)
    sent_by_admin_id    INT UNSIGNED DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_phone_created (phone, created_at),
    KEY idx_direction (direction),
    KEY idx_alert (alert_id),
    UNIQUE KEY uniq_provider_message_id (provider_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
