-- Migration: Add All Missing Competitions
-- Created: 2026-01-28
-- Description: Добавление 22 новых конкурсов для полного покрытия всех категорий и специализаций

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ==================================================
-- ВНЕУРОЧНАЯ ДЕЯТЕЛЬНОСТЬ (extracurricular) - 8 конкурсов
-- ==================================================

-- 1. Мир профессий: профориентационные игры
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Мир профессий: профориентационные игры', 'mir-professiy-proforientaciya',
'Конкурс методических разработок профориентационных мероприятий: классные часы, деловые игры, экскурсии на предприятия, встречи с представителями профессий.',
'Классные руководители, педагоги-психологи, педагоги-организаторы',
'классных руководителей, педагогов-психологов, педагогов-организаторов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'extracurricular',
'Профориентационный классный час
Деловая игра «Выбор профессии»
Экскурсия на предприятие
Встреча с профессионалом
Профессиональные пробы', 150, 1, 30);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2), (@last_comp_id, 3), (@last_comp_id, 4);

-- 2. Школьное самоуправление: лидеры будущего
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Школьное самоуправление: лидеры будущего', 'shkolnoe-samoupravlenie-lidery',
'Конкурс проектов и программ развития ученического самоуправления, школьных советов, лидерских объединений.',
'Заместители директоров по воспитательной работе, педагоги-организаторы, классные руководители',
'заместителей директоров по ВР, педагогов-организаторов, классных руководителей',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'extracurricular',
'Программа развития ученического самоуправления
Проект школьного совета
День самоуправления
Школьные выборы
Лидерский тренинг', 150, 1, 31);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);

-- 3. Каникулы с пользой
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Каникулы с пользой: лето в детском саду и школе', 'kanikuly-s-polzoy',
'Конкурс программ и разработок летнего отдыха и оздоровления детей в образовательных учреждениях.',
'Воспитатели, учителя начальных классов, педагоги дополнительного образования',
'воспитателей, учителей начальных классов, педагогов дополнительного образования',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'extracurricular',
'Программа летнего лагеря
Тематический день в лагере
Квест-игра на свежем воздухе
Экологическая тропа
Спортивный праздник на улице', 150, 1, 32);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2);

-- 4. Семейные ценности: школа и семья
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Семейные ценности: школа и семья', 'semeynye-cennosti-shkola-semya',
'Конкурс разработок мероприятий по работе с родителями: родительские собрания, семейные праздники, совместные проекты.',
'Классные руководители, воспитатели, педагоги-психологи, социальные педагоги',
'классных руководителей, воспитателей, педагогов-психологов, социальных педагогов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'extracurricular',
'Родительское собрание
Семейный праздник
День семьи
Совместный проект родителей и детей
Школа для родителей', 150, 1, 33);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 7);

-- 5. Праздничный калейдоскоп СПО
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Праздничный калейдоскоп СПО', 'prazdnichnyy-kaleydoskop-spo',
'Конкурс сценариев и разработок праздничных мероприятий для студентов колледжей и техникумов.',
'Кураторы групп, педагоги-организаторы СПО, заместители директоров по воспитательной работе',
'кураторов групп, педагогов-организаторов СПО, заместителей директоров по ВР',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'extracurricular',
'День студента
Посвящение в студенты
День открытых дверей
Профессиональный праздник
Выпускной вечер', 150, 1, 34);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 4);

-- 6. Правовое просвещение молодежи
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Правовое просвещение молодежи', 'pravovoe-prosveshchenie-molodezhi',
'Конкурс мероприятий по правовому воспитанию: классные часы о правах и обязанностях, профилактика правонарушений.',
'Учителя обществознания, классные руководители, социальные педагоги',
'учителей обществознания, классных руководителей, социальных педагогов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'extracurricular',
'Права ребенка
Конституция РФ
Профилактика правонарушений
Антикоррупционное воспитание
Правовая игра', 150, 1, 35);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3), (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 25);

-- 7. Волонтерское движение в образовании
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Волонтерское движение в образовании', 'volonterskoe-dvizhenie-obrazovanie',
'Конкурс проектов добровольческой деятельности: волонтерские отряды, социальные акции, помощь нуждающимся.',
'Педагоги-организаторы, классные руководители, кураторы волонтерских отрядов',
'педагогов-организаторов, классных руководителей, кураторов волонтерских отрядов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'extracurricular',
'Программа волонтерского отряда
Социальная акция
Помощь ветеранам
Экологическое волонтерство
Событийное волонтерство', 150, 1, 36);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2), (@last_comp_id, 3), (@last_comp_id, 4);

-- 8. Спортивные праздники и соревнования
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Спортивные праздники и соревнования', 'sportivnye-prazdniki-sorevnovaniya',
'Конкурс сценариев спортивных праздников, дней здоровья, веселых стартов и соревнований.',
'Учителя физической культуры, инструкторы по физкультуре, педагоги-организаторы',
'учителей физической культуры, инструкторов по физкультуре, педагогов-организаторов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'extracurricular',
'День здоровья
Весёлые старты
Спортивный праздник
Мама, папа, я - спортивная семья
Спортивная эстафета', 150, 1, 37);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 4), (@last_comp_id, 15), (@last_comp_id, 27);

-- ==================================================
-- ПРОЕКТЫ УЧАЩИХСЯ (student_projects) - 8 конкурсов
-- ==================================================

-- 9. Социальный проект: делаем мир лучше
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Социальный проект: делаем мир лучше', 'socialnyy-proekt-delaem-mir-luchshe',
'Конкурс социальных проектов учащихся, направленных на решение проблем местного сообщества.',
'Учащиеся 7-11 классов, студенты СПО',
'учащихся 7-11 классов, студентов СПО',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'student_projects',
'Помощь пожилым
Благоустройство территории
Помощь животным
Экологический проект
Социальная реклама', 150, 1, 38);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3), (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 25), (@last_comp_id, 34);

-- 10. Историческое исследование: открываем прошлое
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Историческое исследование: открываем прошлое', 'istoricheskoe-issledovanie-otkryvaem',
'Конкурс исследовательских работ по истории: краеведение, история семьи, история школы, локальная история.',
'Учащиеся 5-11 классов',
'учащихся 5-11 классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'student_projects',
'История моей семьи
История моей школы
Краеведческое исследование
Великая Отечественная война
Устная история', 150, 1, 39);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 24);

-- 11. Литературное творчество учащихся: первые строки
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Литературное творчество учащихся: первые строки', 'literaturnoe-tvorchestvo-pervye-stroki',
'Конкурс авторских литературных произведений учащихся: стихи, рассказы, сказки, эссе.',
'Учащиеся 1-11 классов',
'учащихся 1-11 классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'student_projects',
'Стихотворение
Рассказ
Сказка
Эссе
Басня', 150, 1, 40);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2), (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 9), (@last_comp_id, 17);

-- 12. Математический калейдоскоп: проекты по математике
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Математический калейдоскоп: проекты по математике', 'matematicheskiy-kaleydoskop-proekty',
'Конкурс проектных и исследовательских работ по математике: математическое моделирование, история математики, занимательная математика.',
'Учащиеся 1-11 классов',
'учащихся 1-11 классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'student_projects',
'Математическое моделирование
История математики
Занимательные задачи
Математика вокруг нас
Геометрия в архитектуре', 150, 1, 41);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2), (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 10), (@last_comp_id, 18);

-- 13. Мой первый бизнес-план
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Мой первый бизнес-план', 'moy-pervyy-biznes-plan',
'Конкурс предпринимательских проектов студентов СПО: бизнес-идеи, стартапы, экономические расчеты.',
'Студенты СПО экономических и гуманитарных специальностей',
'студентов СПО экономических и гуманитарных специальностей',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'student_projects',
'Бизнес-план стартапа
Маркетинговый проект
Социальное предпринимательство
Финансовый анализ
Инвестиционный проект', 150, 1, 42);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 33);

-- 14. Экологический мониторинг: исследуем окружающую среду
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Экологический мониторинг: исследуем окружающую среду', 'ekologicheskiy-monitoring-issleduem',
'Конкурс экологических исследований: мониторинг качества воды, воздуха, почвы, изучение экосистем.',
'Учащиеся 5-11 классов',
'учащихся 5-11 классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'student_projects',
'Мониторинг качества воды
Мониторинг качества воздуха
Изучение экосистемы
Биоиндикация
Экологическая тропа', 150, 1, 43);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 22), (@last_comp_id, 23);

-- 15. Лингвистический проект: языки мира
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Лингвистический проект: языки мира', 'lingvisticheskiy-proekt-yazyki-mira',
'Конкурс проектов по изучению иностранных языков: страноведение, лингвистические исследования, переводы.',
'Учащиеся 5-11 классов',
'учащихся 5-11 классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'student_projects',
'Страноведческий проект
Лингвистическое исследование
Художественный перевод
Сравнительный анализ языков
Путеводитель на иностранном языке', 150, 1, 44);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 26);

-- 16. Технический проект: инженеры будущего
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Технический проект: инженеры будущего', 'tekhnicheskiy-proekt-inzhenery',
'Конкурс технических и инженерных проектов студентов СПО: конструирование, изобретательство, рационализация.',
'Студенты СПО технических специальностей',
'студентов СПО технических специальностей',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'student_projects',
'Техническое изобретение
Рационализаторское предложение
Конструкторский проект
3D-моделирование
Прототипирование', 150, 1, 45);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 32);

-- ==================================================
-- ТВОРЧЕСКИЕ КОНКУРСЫ (creative) - 5 конкурсов
-- ==================================================

-- 17. Весенняя капель: творчество о весне
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Весенняя капель: творчество о весне', 'vesennyaya-kapel-tvorchestvo',
'Творческий конкурс, посвященный весенней тематике: рисунки, поделки, стихи о весне, 8 Марта.',
'Дошкольники, учащиеся 1-4 классов',
'дошкольников, учащихся 1-4 классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'creative',
'Весенний рисунок
Поделка к 8 Марта
Открытка маме
Стихи о весне
Весенняя композиция', 150, 1, 46);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 3), (@last_comp_id, 13);

-- 18. День Победы глазами детей
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('День Победы глазами детей', 'den-pobedy-glazami-detey',
'Патриотический творческий конкурс, посвященный Великой Победе: рисунки, поделки, стихи, рассказы о войне и героях.',
'Дошкольники, учащиеся 1-11 классов, студенты СПО',
'дошкольников, учащихся 1-11 классов, студентов СПО',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'creative',
'Рисунок о войне
Открытка ветерану
Письмо солдату
Стихи о Победе
Георгиевская лента', 150, 1, 47);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3), (@last_comp_id, 4);

-- 19. Мой любимый питомец
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Мой любимый питомец', 'moy-lyubimyy-pitomec',
'Творческий конкурс о домашних животных: рисунки, фотографии, рассказы, поделки.',
'Дошкольники, учащиеся 1-4 классов',
'дошкольников, учащихся 1-4 классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'creative',
'Рисунок питомца
Фотография питомца
Рассказ о питомце
Поделка «Мой питомец»
Презентация о питомце', 150, 1, 48);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 3), (@last_comp_id, 13);

-- 20. Цифровое творчество: арт в цифре
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Цифровое творчество: арт в цифре', 'cifrovoe-tvorchestvo-art-v-cifre',
'Конкурс цифрового творчества: компьютерная графика, анимация, видеоролики, презентации.',
'Учащиеся 5-11 классов, студенты СПО',
'учащихся 5-11 классов, студентов СПО',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'creative',
'Компьютерный рисунок
Анимация
Видеоролик
Фотоколлаж
Инфографика', 150, 1, 49);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3), (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 19), (@last_comp_id, 37);

-- 21. Музыкальный талант
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Музыкальный талант', 'muzykalnyy-talant',
'Конкурс музыкального творчества: вокал, инструментальное исполнение, авторские песни.',
'Дошкольники, учащиеся 1-11 классов, студенты СПО',
'дошкольников, учащихся 1-11 классов, студентов СПО',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'creative',
'Сольное пение
Хоровое пение
Инструментальное исполнение
Авторская песня
Музыкальный дуэт', 150, 1, 50);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3), (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 5), (@last_comp_id, 14), (@last_comp_id, 29);

-- ==================================================
-- МЕТОДИЧЕСКИЕ РАЗРАБОТКИ (methodology) - 1 конкурс
-- ==================================================

-- 22. Русский язык в начальной школе: от буквы к тексту
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Русский язык в начальной школе: от буквы к тексту', 'russkiy-yazyk-nachalnaya-shkola',
'Конкурс методических разработок по русскому языку для 1-4 классов: орфография, развитие речи, работа с текстом.',
'Учителя начальных классов',
'учителей начальных классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Орфографический тренинг
Развитие письменной речи
Работа над ошибками
Словарная работа
Изложение и сочинение', 150, 1, 51);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 8);

-- ==================================================
-- ДОПОЛНИТЕЛЬНЫЕ КОНКУРСЫ ДЛЯ ПУСТЫХ СПЕЦИАЛИЗАЦИЙ
-- ==================================================

-- 23. Экологическое воспитание дошкольников (для специализации 6 - экология ДОУ)
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Экологическое воспитание дошкольников', 'ekologicheskoe-vospitanie-doshkolnikov',
'Конкурс методических разработок по экологическому образованию и воспитанию детей дошкольного возраста.',
'Воспитатели дошкольных образовательных учреждений',
'воспитателей дошкольных образовательных учреждений',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Экологические занятия
Наблюдения за природой
Эксперименты с природными материалами
Экологические праздники
Проект «Огород на окне»', 150, 1, 52);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 6);

-- 24. Социализация дошкольников (для специализации 7 - социально-коммуникативное ДОУ)
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Социализация дошкольников: первые шаги в общество', 'socializaciya-doshkolnikov',
'Конкурс методических разработок по социально-коммуникативному развитию детей дошкольного возраста.',
'Воспитатели, педагоги-психологи дошкольных учреждений',
'воспитателей, педагогов-психологов дошкольных учреждений',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Адаптация к детскому саду
Развитие эмоционального интеллекта
Игры на общение
Конфликтология для малышей
Занятия по этикету', 150, 1, 53);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 7);

-- 25. Английский язык в начальной школе (для специализации 12)
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Английский язык в начальной школе: учимся играя', 'angliyskiy-yazyk-nachalnaya-shkola',
'Конкурс методических разработок по обучению английскому языку в начальной школе.',
'Учителя английского языка начальных классов',
'учителей английского языка начальных классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Игровые методики обучения
Песни и рифмовки
Драматизация сказок
Фонетическая зарядка
Проектная деятельность', 150, 1, 54);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 12);

-- 26. ИЗО в начальной школе (для специализации 13)
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Изобразительное искусство в начальной школе', 'izo-nachalnaya-shkola',
'Конкурс методических разработок уроков и внеурочных занятий по изобразительному искусству для 1-4 классов.',
'Учителя ИЗО, учителя начальных классов',
'учителей ИЗО, учителей начальных классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Нетрадиционные техники рисования
Декоративно-прикладное искусство
Лепка и скульптура
Работа с бумагой
Коллективное творчество', 150, 1, 55);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 13);

-- 27. Музыка в начальной школе (для специализации 14)
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Музыка в начальной школе: волшебный мир звуков', 'muzyka-nachalnaya-shkola',
'Конкурс методических разработок уроков музыки и музыкальных занятий для 1-4 классов.',
'Учителя музыки начальных классов',
'учителей музыки начальных классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Урок-концерт
Музыкальная игра
Знакомство с музыкальными инструментами
Слушание музыки
Хоровое пение', 150, 1, 56);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 14);

-- 28. Физкультура в начальной школе (для специализации 15)
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Физкультура в начальной школе: здоровье с детства', 'fizkultura-nachalnaya-shkola',
'Конкурс методических разработок уроков физической культуры и спортивных мероприятий для 1-4 классов.',
'Учителя физической культуры начальных классов',
'учителей физической культуры начальных классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Подвижные игры
Гимнастика
Лёгкая атлетика
Спортивные эстафеты
Физкультминутки', 150, 1, 57);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 15);

-- 29. Технология в начальной школе (для специализации 16)
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Технология в начальной школе: мастерим вместе', 'tehnologiya-nachalnaya-shkola',
'Конкурс методических разработок уроков технологии для 1-4 классов: работа с различными материалами, конструирование.',
'Учителя начальных классов, учителя технологии',
'учителей начальных классов, учителей технологии',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Работа с бумагой и картоном
Работа с тканью
Работа с природными материалами
Конструирование из ЛЕГО
Оригами', 150, 1, 58);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 16);

-- 30. Гуманитарные дисциплины в СПО (для специализации 34)
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Гуманитарное образование в СПО', 'gumanitarnoe-obrazovanie-spo',
'Конкурс методических разработок по гуманитарным дисциплинам в колледжах и техникумах: история, философия, психология, социология.',
'Преподаватели гуманитарных дисциплин СПО',
'преподавателей гуманитарных дисциплин СПО',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'История и обществознание
Философия и культурология
Психология общения
Социология
Правоведение', 150, 1, 59);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 34);

-- 31. Экономическое образование в СПО (для специализации 33)
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Экономическое образование в СПО', 'ekonomicheskoe-obrazovanie-spo',
'Конкурс методических разработок по экономическим дисциплинам в колледжах и техникумах.',
'Преподаватели экономических дисциплин СПО',
'преподавателей экономических дисциплин СПО',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Экономика предприятия
Бухгалтерский учёт
Менеджмент и маркетинг
Финансовая грамотность
Деловое общение', 150, 1, 60);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 33);

SET FOREIGN_KEY_CHECKS = 1;

-- Проверка результатов
SELECT
    category,
    COUNT(*) as count
FROM competitions
WHERE is_active = 1
GROUP BY category
ORDER BY count DESC;
