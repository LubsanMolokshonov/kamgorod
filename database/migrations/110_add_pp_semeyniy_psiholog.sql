-- Migration 110: добавление ПП-курса «Психолог-консультант в области семейных
-- и детско-родительских отношений с присвоением квалификации „Семейный психолог"».
-- Источник данных — Google-таблица «ПП для ФГОС Практикум — ПП», строка 26.
-- В таблице не указаны часы — взято 1180 ч по аналогии с курсом id 88
-- (та же группа «Психология», тот же тип ЦА). Поле «Для кого курс» в таблице
-- пустое — сформулировано на основе названия, оффера и ЦА.
--
-- Идемпотентна: INSERT IGNORE по уникальному slug + WHERE NOT EXISTS на связках.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. Новые эксперты (не было в БД, IGNORE по slug защищает от повторного запуска)
INSERT IGNORE INTO course_experts (full_name, slug, credentials, experience) VALUES
  ('Добромильский Виктор Викторович', 'dobromilskiy-viktor-viktorovich',
   'Бизнес-тренер, гештальт-терапевт, психолог.', NULL),
  ('Давыдова Мария Николаевна', 'davydova-mariya-nikolaevna',
   'Психолог, психодраматерапевт, член Ассоциации психодрамы России, региональный менеджер «Института психодрамы и психологического консультирования» г. Москва.', NULL);

-- 2. Курс
SELECT @start_order := COALESCE(MAX(display_order), 0) FROM courses;

INSERT IGNORE INTO courses
  (title, slug, description, target_audience_text, course_group, hours, program_type,
   learning_format, price, modules_json, outcomes_json, federal_registry_info,
   is_active, display_order)
VALUES (
  'Психолог-консультант в области семейных и детско-родительских отношений с присвоением квалификации «Семейный психолог»',
  'psiholog-konsultant-v-oblasti-semeynyh-i-detsko-roditelskih-otnosheniy-semeynyy-psiholog',
  'Курс для тех, кто желает работать психологом, уметь решать запросы, связанные с семейными, супружескими, детско-родительскими отношениями. Вы сможете правильно выявлять запрос клиента, определять мишени работы, выстраивать траекторию работы с клиентом, применять современные методики и направления психологического консультирования.',
  'Практикующий педагог-психолог\nРасширите профессиональные компетенции, освоите семейное и детско-родительское консультирование, методы работы с супружескими конфликтами и кризисами семьи. Сможете оказывать адресную психологическую помощь семьям с детьми разного возраста.\n\nСпециалист с непрофильным высшим образованием\nПолучите квалификацию «Семейный психолог» с правом ведения консультативной практики, освоите базовые психологические дисциплины и прикладные методики семейного и индивидуального консультирования.\n\nСотрудник центра социальной помощи семье и детям, школьный психолог, специалист кризисной службы\nОсвоите системный подход к работе с семьёй, методы диагностики детско-родительских и супружеских отношений, технологии кризисного и дистанционного консультирования. Сможете эффективнее сопровождать семьи обучающихся, находящиеся в трудной жизненной ситуации.',
  'Психология',
  1180,
  'pp',
  'Заочная с применением дистанционных образовательных технологий',
  45000.00,
  '[{"number": 1, "title": "Анатомия и физиология центральной нервной системы"}, {"number": 2, "title": "Общая психология"}, {"number": 3, "title": "Профессиональная этика в психолого-педагогической деятельности"}, {"number": 4, "title": "Психология личности"}, {"number": 5, "title": "Возрастная психология"}, {"number": 6, "title": "Основы социальной психологии"}, {"number": 7, "title": "Основы клинической психологии"}, {"number": 8, "title": "Психодиагностика"}, {"number": 9, "title": "Основы психотерапии и психокоррекции"}, {"number": 10, "title": "Кризисная психология"}, {"number": 11, "title": "Девиантология"}, {"number": 12, "title": "Психология экстремальных и критических ситуаций"}, {"number": 13, "title": "Конфликтология и медиация"}, {"number": 14, "title": "Психология семьи"}, {"number": 15, "title": "Методы диагностики в семейном консультировании и психотерапии"}, {"number": 16, "title": "Детско-родительские и сиблинговые отношения"}, {"number": 17, "title": "Супружеские отношения (супружеские конфликты, измены, семейные кризисы. Развод)"}, {"number": 18, "title": "Созависимые отношения и зависимости в семье (причины, психоконсультирование и терапия)"}, {"number": 19, "title": "Сексология, сексуальные дисфункции в семье"}, {"number": 20, "title": "Современные психотехнологии и направления работы семейного психолога. Применение психологических техник"}, {"number": 21, "title": "Дистанционное консультирование"}, {"number": 22, "title": "Профилактика профессионального выгорания"}, {"number": 23, "title": "Итоговая аттестация"}]',
  '{"knowledge": [], "skills": ["Работа психологом-консультантом в центрах социальной помощи семье и детям: вы будете оказывать психологическую поддержку семьям — помогать родителям и детям находить выход из сложных жизненных ситуаций, налаживать доверительные отношения в семье, решать семейные конфликты, консультировать родителей, воспитывающих детей с ограниченными возможностями здоровья. Работа включает проведение консультаций, тренингов и профилактических программ.\\n\\nВедение частной практики в качестве семейного психолога: вы сможете проводить индивидуальные и семейные консультации, парную терапию, групповые тренинги для родителей — по вопросам взаимопонимания, гармонизации отношений, воспитания детей, преодоления кризисов. Частная практика даёт возможность выстраивать индивидуальный подход к каждому клиенту, применять современные психологические методики и оказывать адресную помощь в сохранении эмоционального благополучия семьи."], "abilities": []}',
  NULL,
  1,
  @start_order + 1
);

-- 3. Связи с типами аудитории (по аналогии с курсом id 88 «Практическая психология»)
INSERT IGNORE INTO course_audience_types (course_id, audience_type_id)
SELECT c.id, at.id
FROM courses c
JOIN audience_types at
  ON at.slug IN ('dou','nachalnaya-shkola','srednyaya-starshaya-shkola','spo','dopolnitelnoe-obrazovanie')
WHERE c.slug = 'psiholog-konsultant-v-oblasti-semeynyh-i-detsko-roditelskih-otnosheniy-semeynyy-psiholog';

-- 4. Специализации
INSERT IGNORE INTO course_specializations (course_id, specialization_id)
SELECT c.id, asp.id
FROM courses c
JOIN audience_specializations asp ON asp.slug = 'pedagog-psiholog'
WHERE c.slug = 'psiholog-konsultant-v-oblasti-semeynyh-i-detsko-roditelskih-otnosheniy-semeynyy-psiholog';

-- 5. Назначение экспертов
INSERT IGNORE INTO course_expert_assignments (course_id, expert_id, role, display_order)
SELECT c.id, ce.id, 'instructor',
       CASE ce.slug
         WHEN 'galieva-svetlana-yurevna' THEN 0
         WHEN 'goryunova-lyudmila-vyacheslavovna' THEN 1
         WHEN 'ponomarenko-anastasiya-aleksandrovna' THEN 2
         WHEN 'dobromilskiy-viktor-viktorovich' THEN 3
         WHEN 'davydova-mariya-nikolaevna' THEN 4
       END
FROM courses c
JOIN course_experts ce
  ON ce.slug IN (
    'galieva-svetlana-yurevna',
    'goryunova-lyudmila-vyacheslavovna',
    'ponomarenko-anastasiya-aleksandrovna',
    'dobromilskiy-viktor-viktorovich',
    'davydova-mariya-nikolaevna'
  )
WHERE c.slug = 'psiholog-konsultant-v-oblasti-semeynyh-i-detsko-roditelskih-otnosheniy-semeynyy-psiholog';
