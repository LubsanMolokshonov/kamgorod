-- Migration: Add More Competitions
-- Created: 2026-01-28
-- Description: Дополнительные конкурсы: тематические, сезонные, специализированные

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ==================================================
-- ТЕМАТИЧЕСКИЕ И СЕЗОННЫЕ КОНКУРСЫ (creative)
-- ==================================================

-- 1. Новогодняя сказка
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Новогодняя сказка', 'novogodnyaya-skazka',
'Творческий конкурс, посвящённый Новому году и Рождеству: ёлочные игрушки, открытки, поделки, костюмы.',
'Дошкольники, учащиеся 1-11 классов',
'дошкольников, учащихся 1-11 классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'creative',
'Новогодняя открытка
Ёлочная игрушка своими руками
Новогодняя поделка
Рисунок «Зимняя сказка»
Новогодний костюм', 150, 1, 61);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3);

-- 2. Золотая осень
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Золотая осень: краски природы', 'zolotaya-osen-kraski',
'Творческий конкурс на осеннюю тематику: поделки из природных материалов, рисунки, фотографии осени.',
'Дошкольники, учащиеся 1-11 классов',
'дошкольников, учащихся 1-11 классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'creative',
'Поделка из природных материалов
Осенний букет
Рисунок «Золотая осень»
Фотография осенней природы
Гербарий', 150, 1, 62);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3);

-- 3. День космонавтики
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Космические дали: ко Дню космонавтики', 'kosmicheskie-dali',
'Творческий конкурс ко Дню космонавтики 12 апреля: рисунки, поделки, проекты о космосе.',
'Дошкольники, учащиеся 1-11 классов',
'дошкольников, учащихся 1-11 классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'creative',
'Рисунок о космосе
Поделка «Ракета»
Макет солнечной системы
Презентация о космонавтах
Космическая фантазия', 150, 1, 63);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3);

-- 4. Защитники Отечества
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Защитники Отечества', 'zashchitniki-otechestva',
'Патриотический творческий конкурс ко Дню защитника Отечества: открытки, рисунки, поделки военной тематики.',
'Дошкольники, учащиеся 1-11 классов',
'дошкольников, учащихся 1-11 классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'creative',
'Открытка к 23 февраля
Рисунок «Наша армия»
Поделка военной техники
Стихи о защитниках
Портрет героя', 150, 1, 64);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3);

-- 5. Широкая Масленица
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Широкая Масленица', 'shirokaya-maslenica',
'Творческий конкурс, посвящённый русскому народному празднику Масленица: рисунки, поделки, сценарии праздников.',
'Дошкольники, учащиеся 1-11 классов',
'дошкольников, учащихся 1-11 классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'creative',
'Рисунок «Масленица»
Кукла-масленица
Сценарий праздника
Народные игры
Блинный рецепт с иллюстрацией', 150, 1, 65);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3);

-- ==================================================
-- КОНКУРСЫ ДЛЯ СПЕЦИАЛИСТОВ (methodology)
-- ==================================================

-- 6. Классное руководство: мастерство и творчество
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Классное руководство: мастерство и творчество', 'klassnoe-rukovodstvo-masterstvo',
'Конкурс методических разработок для классных руководителей: классные часы, родительские собрания, работа с коллективом.',
'Классные руководители всех уровней образования',
'классных руководителей всех уровней образования',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Классный час
Родительское собрание
Диагностика классного коллектива
Работа с трудными подростками
Портфолио класса', 150, 1, 66);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2), (@last_comp_id, 3);

-- 7. Логопедическая мастерская
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Логопедическая мастерская', 'logopedicheskaya-masterskaya',
'Конкурс методических разработок для учителей-логопедов: коррекционные занятия, дидактические игры, консультации для родителей.',
'Учителя-логопеды, дефектологи',
'учителей-логопедов, дефектологов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Коррекционное занятие
Дидактическая игра
Артикуляционная гимнастика
Консультация для родителей
Логопедический тренажёр', 150, 1, 67);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2);

-- 8. Психологическая служба в образовании
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Психологическая служба в образовании', 'psihologicheskaya-sluzhba',
'Конкурс методических разработок для педагогов-психологов: тренинги, диагностики, коррекционные программы.',
'Педагоги-психологи образовательных организаций',
'педагогов-психологов образовательных организаций',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Психологический тренинг
Диагностическая методика
Коррекционно-развивающее занятие
Профилактика буллинга
Работа с тревожностью', 150, 1, 68);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3), (@last_comp_id, 4);

-- 9. Социальный педагог: защита детства
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Социальный педагог: защита детства', 'socialnyy-pedagog-zashchita',
'Конкурс методических разработок для социальных педагогов: работа с семьями, профилактика правонарушений, защита прав детей.',
'Социальные педагоги, специалисты по работе с семьёй',
'социальных педагогов, специалистов по работе с семьёй',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Работа с неблагополучной семьёй
Профилактика правонарушений
Защита прав ребёнка
Работа с детьми группы риска
Социальный проект', 150, 1, 69);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2), (@last_comp_id, 3), (@last_comp_id, 4);

-- 10. Библиотека — центр знаний
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Библиотека — центр знаний', 'biblioteka-centr-znaniy',
'Конкурс методических разработок для школьных библиотекарей: библиотечные уроки, выставки, продвижение чтения.',
'Педагоги-библиотекари, школьные библиотекари',
'педагогов-библиотекарей, школьных библиотекарей',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Библиотечный урок
Книжная выставка
Литературная игра
Продвижение чтения
Информационная грамотность', 150, 1, 70);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2), (@last_comp_id, 3);

-- 11. Педагог дополнительного образования
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Педагог дополнительного образования: творим вместе', 'pedagog-dopolnitelnogo-obrazovaniya',
'Конкурс методических разработок для педагогов дополнительного образования: программы, мастер-классы, открытые занятия.',
'Педагоги дополнительного образования, руководители кружков и секций',
'педагогов дополнительного образования, руководителей кружков и секций',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Дополнительная общеобразовательная программа
Мастер-класс
Открытое занятие
Творческий проект
Выставка работ', 150, 1, 71);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3);

-- ==================================================
-- АКТУАЛЬНЫЕ НАПРАВЛЕНИЯ (methodology)
-- ==================================================

-- 12. Инклюзивное образование
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Инклюзивное образование: равные возможности', 'inklyuzivnoe-obrazovanie-ravnye',
'Конкурс методических разработок по работе с детьми с ограниченными возможностями здоровья (ОВЗ).',
'Педагоги, работающие с детьми с ОВЗ, дефектологи, тьюторы',
'педагогов, работающих с детьми с ОВЗ, дефектологов, тьюторов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Адаптированная образовательная программа
Индивидуальный образовательный маршрут
Занятие для детей с ОВЗ
Сенсорная комната
Работа тьютора', 150, 1, 72);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3), (@last_comp_id, 4);

-- 13. Одарённые дети
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Одарённые дети: раскрываем таланты', 'odarennye-deti-raskryvaem',
'Конкурс методических разработок по работе с одарёнными и высокомотивированными детьми.',
'Педагоги, работающие с одарёнными детьми',
'педагогов, работающих с одарёнными детьми',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Программа работы с одарёнными детьми
Подготовка к олимпиадам
Исследовательская деятельность
Интеллектуальный марафон
Научное общество учащихся', 150, 1, 73);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2), (@last_comp_id, 3);

-- 14. STEM-образование и робототехника
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('STEM-образование и робототехника', 'stem-obrazovanie-robototehnika',
'Конкурс методических разработок по STEM-образованию: робототехника, программирование, инженерное творчество.',
'Учителя информатики, технологии, педагоги дополнительного образования',
'учителей информатики, технологии, педагогов дополнительного образования',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Занятие по робототехнике
LEGO-конструирование
Программирование для детей
3D-моделирование и печать
Инженерный проект', 150, 1, 74);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 19), (@last_comp_id, 30);

-- 15. Финансовая грамотность
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Финансовая грамотность: учимся управлять деньгами', 'finansovaya-gramotnost-uchimsya',
'Конкурс методических разработок по финансовой грамотности для разных возрастных групп.',
'Учителя обществознания, экономики, воспитатели, учителя начальных классов',
'учителей обществознания, экономики, воспитателей, учителей начальных классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Основы финансовой грамотности
Семейный бюджет
Безопасность в финансовой сфере
Деловая игра по экономике
Предпринимательство для детей', 150, 1, 75);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3), (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 25), (@last_comp_id, 33);

-- 16. Медиаграмотность и цифровая безопасность
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Медиаграмотность и цифровая безопасность', 'mediagramotnost-cifrovaya-bezopasnost',
'Конкурс методических разработок по медиаграмотности, критическому мышлению и безопасности в интернете.',
'Учителя информатики, классные руководители, педагоги-библиотекари',
'учителей информатики, классных руководителей, педагогов-библиотекарей',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Безопасность в интернете
Критическое мышление
Фактчекинг
Цифровой этикет
Защита персональных данных', 150, 1, 76);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2), (@last_comp_id, 3), (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 19), (@last_comp_id, 37);

-- 17. Патриотическое воспитание
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Патриотическое воспитание: любовь к Родине', 'patrioticheskoe-vospitanie-lyubov',
'Конкурс методических разработок по патриотическому и гражданскому воспитанию.',
'Педагоги-организаторы, классные руководители, учителя истории и обществознания',
'педагогов-организаторов, классных руководителей, учителей истории и обществознания',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Урок мужества
Музейная педагогика
Военно-патриотическая игра
Встреча с ветеранами
Проект «Бессмертный полк»', 150, 1, 77);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2), (@last_comp_id, 3), (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 24), (@last_comp_id, 25);

-- ==================================================
-- КОНКУРСЫ ДЛЯ УЧАЩИХСЯ (student_projects, creative)
-- ==================================================

-- 18. Фотоконкурс «Мир глазами детей»
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Фотоконкурс «Мир глазами детей»', 'fotokonkurs-mir-glazami-detey',
'Конкурс детских фотографий: природа, люди, события, креативная фотография.',
'Учащиеся 1-11 классов, студенты СПО',
'учащихся 1-11 классов, студентов СПО',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'creative',
'Пейзаж
Портрет
Репортаж
Макрофотография
Креативная фотография', 150, 1, 78);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2), (@last_comp_id, 3), (@last_comp_id, 4);

-- 19. Конкурс чтецов «Живое слово»
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Конкурс чтецов «Живое слово»', 'konkurs-chtecov-zhivoe-slovo',
'Конкурс выразительного чтения стихов и прозы: классика, современная поэзия, авторские произведения.',
'Дошкольники, учащиеся 1-11 классов',
'дошкольников, учащихся 1-11 классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'creative',
'Классическая поэзия
Современная поэзия
Проза
Авторское стихотворение
Театрализованное чтение', 150, 1, 79);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 9), (@last_comp_id, 17);

-- 20. Олимпиадные задания и решения
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Олимпиадные задания и решения', 'olimpiadnye-zadaniya-resheniya',
'Конкурс авторских олимпиадных заданий и нестандартных решений по различным предметам.',
'Учащиеся 5-11 классов',
'учащихся 5-11 классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'student_projects',
'Олимпиадная задача по математике
Олимпиадная задача по физике
Олимпиадная задача по информатике
Нестандартное решение
Авторская задача', 150, 1, 80);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 18), (@last_comp_id, 19), (@last_comp_id, 20);

-- 21. Конкурс сочинений «Моё перо»
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Конкурс сочинений «Моё перо»', 'konkurs-sochineniy-moe-pero',
'Конкурс творческих письменных работ: сочинения, эссе, очерки, рецензии.',
'Учащиеся 5-11 классов, студенты СПО',
'учащихся 5-11 классов, студентов СПО',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'student_projects',
'Сочинение-рассуждение
Эссе
Очерк
Рецензия на книгу/фильм
Письмо литературному герою', 150, 1, 81);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3), (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 17);

-- 22. Юный художник
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Юный художник', 'yunyy-hudozhnik',
'Конкурс детского изобразительного творчества: живопись, графика, декоративно-прикладное искусство.',
'Дошкольники, учащиеся 1-11 классов',
'дошкольников, учащихся 1-11 классов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'creative',
'Живопись (акварель, гуашь)
Графика (карандаш, тушь)
Декоративно-прикладное искусство
Батик и роспись
Скульптура малых форм', 150, 1, 82);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 3), (@last_comp_id, 13);

-- ==================================================
-- УПРАВЛЕНИЕ ОБРАЗОВАНИЕМ (methodology)
-- ==================================================

-- 23. Рабочая программа педагога
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Рабочая программа педагога', 'rabochaya-programma-pedagoga',
'Конкурс авторских рабочих программ по учебным предметам и курсам внеурочной деятельности.',
'Учителя-предметники, педагоги дополнительного образования',
'учителей-предметников, педагогов дополнительного образования',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Рабочая программа по предмету
Программа элективного курса
Программа внеурочной деятельности
Программа факультатива
Адаптированная программа', 150, 1, 83);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2), (@last_comp_id, 3), (@last_comp_id, 4);

-- 24. Программа воспитания
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Программа воспитания: растим личность', 'programma-vospitaniya-rastim',
'Конкурс программ воспитания и календарных планов воспитательной работы образовательных организаций.',
'Заместители директоров по ВР, педагоги-организаторы, классные руководители',
'заместителей директоров по ВР, педагогов-организаторов, классных руководителей',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Рабочая программа воспитания
Календарный план воспитательной работы
Модуль программы воспитания
Воспитательное мероприятие
Система работы классного руководителя', 150, 1, 84);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3), (@last_comp_id, 4);

-- 25. Современный урок по ФГОС
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Современный урок по ФГОС', 'sovremennyy-urok-fgos',
'Конкурс технологических карт и сценариев уроков, соответствующих требованиям ФГОС.',
'Учителя всех предметов',
'учителей всех предметов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology',
'Технологическая карта урока
Урок открытия нового знания
Урок рефлексии
Урок-проект
Интегрированный урок', 150, 1, 85);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2), (@last_comp_id, 3);

-- ==================================================
-- ВНЕУРОЧНАЯ ДЕЯТЕЛЬНОСТЬ (extracurricular)
-- ==================================================

-- 26. День знаний и Последний звонок
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('День знаний и Последний звонок', 'den-znaniy-posledniy-zvonok',
'Конкурс сценариев торжественных линеек и праздников, посвящённых началу и окончанию учебного года.',
'Педагоги-организаторы, заместители директоров по ВР, классные руководители',
'педагогов-организаторов, заместителей директоров по ВР, классных руководителей',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'extracurricular',
'Торжественная линейка 1 сентября
Последний звонок
Выпускной вечер
Посвящение в первоклассники
День гимназиста/лицеиста', 150, 1, 86);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2), (@last_comp_id, 3);

-- 27. День учителя: праздник для педагогов
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('День учителя: праздник для педагогов', 'den-uchitelya-prazdnik',
'Конкурс сценариев мероприятий ко Дню учителя и Дню воспитателя.',
'Педагоги-организаторы, классные руководители, учащиеся',
'педагогов-организаторов, классных руководителей, учащихся',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'extracurricular',
'Концерт ко Дню учителя
Поздравительная открытка
Стенгазета
Флешмоб для учителей
Видеопоздравление', 150, 1, 87);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3);

-- 28. Экологические акции и проекты
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Экологические акции и проекты', 'ekologicheskie-akcii-proekty',
'Конкурс экологических мероприятий и акций: субботники, сбор макулатуры, посадка деревьев.',
'Педагоги-организаторы, учителя биологии, классные руководители',
'педагогов-организаторов, учителей биологии, классных руководителей',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'extracurricular',
'Экологический субботник
Акция «Сдай макулатуру — спаси дерево»
Посадка деревьев
День Земли
Раздельный сбор отходов', 150, 1, 88);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 6), (@last_comp_id, 22);

-- 29. Школьный театр
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Школьный театр: сцена для всех', 'shkolnyy-teatr-scena',
'Конкурс театральных постановок и сценариев для школьных театральных коллективов.',
'Руководители театральных кружков, учителя литературы, педагоги дополнительного образования',
'руководителей театральных кружков, учителей литературы, педагогов дополнительного образования',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'extracurricular',
'Драматический спектакль
Музыкальная постановка
Кукольный театр
Театр теней
Литературно-музыкальная композиция', 150, 1, 89);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1), (@last_comp_id, 2), (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 17), (@last_comp_id, 29);

-- 30. Здоровый образ жизни
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Здоровый образ жизни: выбираем здоровье', 'zdorovyy-obraz-zhizni-vybiraem',
'Конкурс мероприятий по пропаганде здорового образа жизни и профилактике вредных привычек.',
'Классные руководители, учителя физкультуры, педагоги-психологи',
'классных руководителей, учителей физкультуры, педагогов-психологов',
'Диплом I, II, III степени в электронном виде', '2025-2026', 'extracurricular',
'Классный час о ЗОЖ
Профилактика вредных привычек
Антинаркотическая акция
Утренняя зарядка
Неделя здоровья', 150, 1, 90);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2), (@last_comp_id, 3), (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 27), (@last_comp_id, 28);

SET FOREIGN_KEY_CHECKS = 1;

-- Итоговая статистика
SELECT
    category,
    COUNT(*) as count
FROM competitions
WHERE is_active = 1
GROUP BY category
ORDER BY count DESC;
