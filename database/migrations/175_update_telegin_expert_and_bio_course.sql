-- Migration 175: обновить регалии эксперта Телегина И. Г. + привязать его к курсу ПП Биология.
--   Только локально (пока). Идемпотентна.
--   Эксперт уже есть: course_experts.slug = 'telegin-ilya-grigorevich' (id 63).
--   Курс 35 (КПК «Технологический суверенитет») — Телегин уже привязан, только обновляются регалии.
--   Курс 104 (ПП «Биология ООО, СОО») — Телегина добавляем ПЕРВЫМ (display_order 0),
--   существующего эксперта Таринову сдвигаем на display_order 1.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. Обновить регалии эксперта (experience не трогаем — уже «17 лет»).
--    Отобразится на всех курсах эксперта (35 и 104).
UPDATE course_experts
SET credentials = 'Учитель биологии, педагог-методист, эксперт по применению ИИ в образовании. Абсолютный победитель конкурса «Учитель года Пермского края — 2010». Победитель и лауреат региональных и городских профессиональных конкурсов. Победитель районного этапа конкурса педагогических достижений Санкт-Петербурга (2025). Автор образовательных и исследовательских проектов, разработок по биологии, STEM и применению искусственного интеллекта в обучении. Спикер международных и региональных педагогических форумов, в том числе в Санкт-Петербурге. Автор методических материалов и публикаций по вопросам современной педагогики и образовательных технологий.',
    experience  = '17 лет'
WHERE slug = 'telegin-ilya-grigorevich';

-- 2. Сдвинуть текущего эксперта курса 104 (Таринова) на display_order 1.
--    Идемпотентно: только если он ещё на 0.
UPDATE course_expert_assignments
SET display_order = 1
WHERE course_id = 104
  AND expert_id = (SELECT id FROM course_experts WHERE slug = 'tarinova-natalya-vladimirovna')
  AND display_order = 0;

-- 3. Привязать Телегина к курсу 104 первым (display_order 0).
--    INSERT IGNORE идемпотентен: PRIMARY KEY (course_id, expert_id).
INSERT IGNORE INTO course_expert_assignments (course_id, expert_id, role, display_order)
SELECT c.id, e.id, 'instructor', 0
FROM courses c
JOIN course_experts e ON e.slug = 'telegin-ilya-grigorevich'
WHERE c.slug = 'pedagogicheskoe-obrazovanie-biologiya-v-usloviyah-realizatsii-fgos-ooo-soo';
