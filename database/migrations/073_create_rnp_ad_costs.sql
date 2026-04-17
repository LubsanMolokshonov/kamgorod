-- Миграция 073: Таблица расходов на рекламу для вкладки РНП (Рука на пульсе)
-- Хранит ежедневные расходы по 4 каналам: Direct/VK × Портал/Курсы.
-- Один день = одна строка. Ввод вручную через админку.

CREATE TABLE IF NOT EXISTS rnp_ad_costs (
    date DATE NOT NULL PRIMARY KEY,
    direct_portal_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Расход Яндекс.Директ — педпортал',
    vk_portal_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Расход VK — педпортал',
    direct_course_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Расход Яндекс.Директ — курсы',
    vk_course_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Расход VK — курсы',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ежедневные рекламные расходы для отчёта РНП';
