-- Понедельные рекламные расходы по продуктовым направлениям.
-- Long-формат: одна строка = (неделя × направление), расширяется новыми
-- направлениями без ALTER. week_start — понедельник ISO-недели.
CREATE TABLE IF NOT EXISTS direction_weekly_costs (
    week_start DATE NOT NULL COMMENT 'Понедельник ISO-недели',
    direction  VARCHAR(32) NOT NULL COMMENT 'olympiads|competitions|publications|webinars|courses|materials',
    cost       DECIMAL(10,2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (week_start, direction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Ручной ввод рекламных расходов по направлениям (понедельно)';
