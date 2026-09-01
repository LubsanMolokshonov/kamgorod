-- Единый автоответчик Telegram + отдельного MAX-бота.
-- По умолчанию ни один чат не разрешён: пилот включается через ai_messenger_chats.is_enabled.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

ALTER TABLE support_alerts
    MODIFY COLUMN source ENUM('ai_chat','email','manual','vk','max','telegram','max_bot') NOT NULL DEFAULT 'ai_chat',
    ADD COLUMN messenger_channel ENUM('telegram','max_bot') DEFAULT NULL AFTER max_phone,
    ADD COLUMN messenger_chat_id VARCHAR(128) DEFAULT NULL AFTER messenger_channel,
    ADD COLUMN messenger_user_id VARCHAR(128) DEFAULT NULL AFTER messenger_chat_id,
    ADD INDEX idx_messenger_source (messenger_channel, messenger_chat_id);

CREATE TABLE IF NOT EXISTS ai_messenger_chats (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel ENUM('telegram','max_bot') NOT NULL,
    chat_id VARCHAR(128) NOT NULL,
    title VARCHAR(255) DEFAULT NULL,
    chat_type ENUM('private','group','supergroup','channel','unknown') NOT NULL DEFAULT 'unknown',
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    reply_policy ENUM('questions','mentions','all') NOT NULL DEFAULT 'questions',
    last_active_at DATETIME DEFAULT NULL,
    next_send_at DATETIME(6) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_channel_chat (channel, chat_id),
    KEY idx_enabled (is_enabled, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_messenger_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel ENUM('telegram','max_bot') NOT NULL,
    provider_update_id VARCHAR(191) NOT NULL,
    provider_message_id VARCHAR(191) DEFAULT NULL,
    chat_id VARCHAR(128) NOT NULL,
    user_id VARCHAR(128) DEFAULT NULL,
    user_name VARCHAR(255) DEFAULT NULL,
    chat_type ENUM('private','group','supergroup','channel','unknown') NOT NULL DEFAULT 'unknown',
    message_text TEXT DEFAULT NULL,
    response_text TEXT DEFAULT NULL,
    reply_to_message_id VARCHAR(191) DEFAULT NULL,
    reply_to_bot TINYINT(1) NOT NULL DEFAULT 0,
    raw_payload JSON DEFAULT NULL,
    status ENUM('pending','processing','sent','skipped','failed','escalated') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME DEFAULT NULL,
    response_message_id VARCHAR(191) DEFAULT NULL,
    alert_id BIGINT UNSIGNED DEFAULT NULL,
    tokens_in INT UNSIGNED DEFAULT NULL,
    tokens_out INT UNSIGNED DEFAULT NULL,
    model VARCHAR(191) DEFAULT NULL,
    error TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_channel_update (channel, provider_update_id),
    KEY idx_queue (status, available_at, id),
    KEY idx_chat_created (channel, chat_id, created_at),
    KEY idx_alert (alert_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_knowledge_articles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(191) NOT NULL,
    topic VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    source_url VARCHAR(500) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_slug (slug),
    KEY idx_active_topic (is_active, topic),
    FULLTEXT KEY ft_ai_knowledge (title, content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ai_knowledge_articles (slug, topic, title, content, source_url) VALUES
('course-documents', 'courses', 'Документы после обучения', 'После успешного освоения программы повышения квалификации выдаётся удостоверение о повышении квалификации, после программы профессиональной переподготовки — диплом о профессиональной переподготовке. Сведения о документах о квалификации вносятся в ФИС ФРДО. Конкретный вид документа определяется типом выбранной программы.', '/oferta-kursy/'),
('course-enrollment', 'courses', 'Зачисление и доступ к курсу', 'Для зачисления на дополнительную профессиональную программу требуются документы об имеющемся или получаемом среднем профессиональном либо высшем образовании. Доступ предоставляется после проверки документов. В персональных случаях бот должен передать вопрос менеджеру.', '/oferta-kursy/'),
('course-installment', 'payment', 'Оплата курса в рассрочку', 'Для курсов может быть доступна рассрочка для физических лиц и безналичная оплата по счёту для организаций. Точные условия зависят от выбранного курса и оформляются менеджером.', '/oferta-kursy/'),
('webinar-certificate', 'webinars', 'Сертификат вебинара', 'Участие в вебинаре и оформление именного сертификата — разные действия. Актуальная стоимость и количество академических часов сертификата хранятся в карточке конкретного вебинара. Сертификат вебинара не является документом о квалификации.', '/vebinary/'),
('publication-process', 'publications', 'Публикация материала', 'Пользователь может отправить собственный материал для размещения в журнале. Статус, свидетельство и персональные вопросы по уже отправленному материалу требуют проверки конкретной заявки.', '/zhurnal/'),
('personal-data', 'support', 'Персональные вопросы', 'Нельзя раскрывать в групповом чате сведения о заказе, оплате, документах или доступе конкретного пользователя. Нужно предложить продолжить разговор в личном чате и при необходимости создать заявку менеджеру.', '/politika-konfidencialnosti/'),
('refund-support', 'payment', 'Возврат денежных средств', 'Бот не обещает и не выполняет возврат самостоятельно. Для возврата и спорной оплаты создаётся обращение менеджеру, который проверит заказ и применимые условия оферты.', '/oferta-kursy/');
