-- Расходы Яндекс.Директа по дням и кампаниям (синк из ai.h1pro.ru, cron/sync-direct-spend.php).
-- Строки перезаписываются кроном по окну дат (DELETE + INSERT) — повторный прогон безопасен.
CREATE TABLE IF NOT EXISTS direct_ad_spend (
    date DATE NOT NULL,
    campaign_id BIGINT UNSIGNED NOT NULL,
    campaign_name VARCHAR(255) NOT NULL,
    direction VARCHAR(32) NOT NULL COMMENT 'olympiads|competitions|publications|webinars|courses|materials|other',
    section ENUM('portal','course') NOT NULL DEFAULT 'portal',
    cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Рубли, с НДС',
    synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (date, campaign_id),
    KEY idx_direction_date (direction, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Расходы Директа день×кампания из ai.h1pro.ru';

-- Автозаполняемая кроном доля Директа в недельных расходах направлений.
-- cost остаётся ручным вводом прочих каналов; итог на дашборде = cost + direct_cost.
ALTER TABLE direction_weekly_costs
    ADD COLUMN direct_cost DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Директ, авто из direct_ad_spend' AFTER cost;
