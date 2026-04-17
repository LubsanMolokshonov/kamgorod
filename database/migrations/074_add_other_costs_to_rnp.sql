-- Добавить колонки расходов "Другое" для портала и курсов
ALTER TABLE rnp_ad_costs
    ADD COLUMN other_portal_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER vk_course_cost,
    ADD COLUMN other_course_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER other_portal_cost;
