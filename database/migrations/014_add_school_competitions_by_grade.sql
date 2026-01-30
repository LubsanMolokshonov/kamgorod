-- Migration: Add School Competitions by Grade
-- Created: 2026-01-28
-- Description: Добавление конкурсов для средней и старшей школы по предметам и классам

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ==================================================
-- КОНКУРСЫ ДЛЯ СРЕДНЕЙ ШКОЛЫ (5-9 КЛАССЫ)
-- ==================================================

-- ==================== РУССКИЙ ЯЗЫК ====================

-- Русский язык 5 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Русский язык 5 класс: первые шаги в синтаксисе', 'russkiy-yazyk-5-klass', 'Всероссийский конкурс методических разработок по русскому языку для 5 класса. Переход от начальной школы к среднему звену.', 'Учителя русского языка', 'учителей русского языка', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Повторение изученного в начальной школе
Синтаксис и пунктуация
Фонетика и орфоэпия
Лексика и фразеология
Морфемика и словообразование', 150, 1, 100);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 17);

-- Русский язык 6 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Русский язык 6 класс: морфология и орфография', 'russkiy-yazyk-6-klass', 'Конкурс методических разработок по русскому языку для 6 класса. Изучение частей речи и орфографических правил.', 'Учителя русского языка', 'учителей русского языка', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Имя существительное
Имя прилагательное
Имя числительное
Местоимение
Глагол', 150, 1, 101);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 17);

-- Русский язык 7 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Русский язык 7 класс: причастие и деепричастие', 'russkiy-yazyk-7-klass', 'Конкурс методических разработок по русскому языку для 7 класса. Сложные темы морфологии.', 'Учителя русского языка', 'учителей русского языка', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Причастие и причастный оборот
Деепричастие и деепричастный оборот
Наречие
Служебные части речи
Междометие', 150, 1, 102);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 17);

-- Русский язык 8 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Русский язык 8 класс: синтаксис простого предложения', 'russkiy-yazyk-8-klass', 'Конкурс методических разработок по русскому языку для 8 класса. Синтаксис и пунктуация простого предложения.', 'Учителя русского языка', 'учителей русского языка', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Словосочетание
Простое предложение
Двусоставные предложения
Односоставные предложения
Обособленные члены предложения', 150, 1, 103);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 17);

-- Русский язык 9 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Русский язык 9 класс: сложное предложение и подготовка к ОГЭ', 'russkiy-yazyk-9-klass', 'Конкурс методических разработок по русскому языку для 9 класса. Сложное предложение и подготовка к ОГЭ.', 'Учителя русского языка', 'учителей русского языка', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Сложносочиненное предложение
Сложноподчиненное предложение
Бессоюзное сложное предложение
Подготовка к ОГЭ: сочинение
Подготовка к ОГЭ: изложение', 150, 1, 104);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 17);

-- ==================== ЛИТЕРАТУРА ====================

-- Литература 5 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Литература 5 класс: введение в мир книг', 'literatura-5-klass', 'Конкурс методических разработок по литературе для 5 класса. Фольклор и литературные сказки.', 'Учителя литературы', 'учителей литературы', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Устное народное творчество
Литературные сказки
Басни И.А. Крылова
Стихотворения русских поэтов
Рассказы о детях и для детей', 150, 1, 105);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 17);

-- Литература 6 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Литература 6 класс: от мифа к реализму', 'literatura-6-klass', 'Конкурс методических разработок по литературе для 6 класса. Мифология и классическая литература.', 'Учителя литературы', 'учителей литературы', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Мифы народов мира
Древнерусская литература
А.С. Пушкин: проза и поэзия
М.Ю. Лермонтов
Н.В. Гоголь', 150, 1, 106);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 17);

-- Литература 7 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Литература 7 класс: герой и время', 'literatura-7-klass', 'Конкурс методических разработок по литературе для 7 класса. Образ героя в литературе.', 'Учителя литературы', 'учителей литературы', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Былины и древнерусская литература
А.С. Пушкин: поэмы и повести
М.Ю. Лермонтов: поэмы
Н.В. Гоголь: повести
Рассказы русских писателей XIX века', 150, 1, 107);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 17);

-- Литература 8 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Литература 8 класс: классика и современность', 'literatura-8-klass', 'Конкурс методических разработок по литературе для 8 класса. Русская классическая литература.', 'Учителя литературы', 'учителей литературы', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Фонвизин «Недоросль»
Пушкин «Капитанская дочка»
Лермонтов «Мцыри»
Гоголь «Ревизор»
Толстой, Чехов', 150, 1, 108);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 17);

-- Литература 9 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Литература 9 класс: золотой век русской литературы', 'literatura-9-klass', 'Конкурс методических разработок по литературе для 9 класса. Подготовка к ОГЭ.', 'Учителя литературы', 'учителей литературы', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Слово о полку Игореве
Грибоедов «Горе от ума»
Пушкин «Евгений Онегин»
Лермонтов «Герой нашего времени»
Гоголь «Мёртвые души»', 150, 1, 109);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 17);

-- ==================== МАТЕМАТИКА ====================

-- Математика 5 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Математика 5 класс: основы арифметики', 'matematika-5-klass', 'Конкурс методических разработок по математике для 5 класса. Натуральные числа и дроби.', 'Учителя математики', 'учителей математики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Натуральные числа и действия с ними
Площади и объёмы
Обыкновенные дроби
Десятичные дроби
Проценты', 150, 1, 110);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 18);

-- Математика 6 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Математика 6 класс: дроби и рациональные числа', 'matematika-6-klass', 'Конкурс методических разработок по математике для 6 класса. Дроби и отрицательные числа.', 'Учителя математики', 'учителей математики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Делимость чисел
Сложение и вычитание дробей
Умножение и деление дробей
Рациональные числа
Координаты на плоскости', 150, 1, 111);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 18);

-- Алгебра 7 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Алгебра 7 класс: введение в алгебру', 'algebra-7-klass', 'Конкурс методических разработок по алгебре для 7 класса. Алгебраические выражения и уравнения.', 'Учителя математики', 'учителей математики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Выражения и тождества
Уравнения с одной переменной
Функции и их графики
Степень с натуральным показателем
Многочлены', 150, 1, 112);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 18);

-- Алгебра 8 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Алгебра 8 класс: квадратные уравнения', 'algebra-8-klass', 'Конкурс методических разработок по алгебре для 8 класса. Квадратные корни и уравнения.', 'Учителя математики', 'учителей математики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Рациональные дроби
Квадратные корни
Квадратные уравнения
Неравенства
Степень с целым показателем', 150, 1, 113);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 18);

-- Алгебра 9 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Алгебра 9 класс: подготовка к ОГЭ', 'algebra-9-klass', 'Конкурс методических разработок по алгебре для 9 класса. Функции и подготовка к ОГЭ.', 'Учителя математики', 'учителей математики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Квадратичная функция
Уравнения и неравенства
Арифметическая прогрессия
Геометрическая прогрессия
Подготовка к ОГЭ', 150, 1, 114);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 18);

-- Геометрия 7 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Геометрия 7 класс: первые шаги', 'geometriya-7-klass', 'Конкурс методических разработок по геометрии для 7 класса. Основы планиметрии.', 'Учителя математики', 'учителей математики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Начальные геометрические сведения
Треугольники
Параллельные прямые
Соотношения в треугольнике
Построения циркулем и линейкой', 150, 1, 115);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 18);

-- Геометрия 8 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Геометрия 8 класс: четырёхугольники и площади', 'geometriya-8-klass', 'Конкурс методических разработок по геометрии для 8 класса. Четырёхугольники и площади фигур.', 'Учителя математики', 'учителей математики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Четырёхугольники
Площади фигур
Подобие треугольников
Окружность
Векторы', 150, 1, 116);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 18);

-- Геометрия 9 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Геометрия 9 класс: метод координат', 'geometriya-9-klass', 'Конкурс методических разработок по геометрии для 9 класса. Координаты и векторы.', 'Учителя математики', 'учителей математики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Метод координат
Соотношения в треугольнике
Скалярное произведение векторов
Длина окружности и площадь круга
Движения', 150, 1, 117);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 18);

-- ==================== ФИЗИКА ====================

-- Физика 7 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Физика 7 класс: первое знакомство', 'fizika-7-klass', 'Конкурс методических разработок по физике для 7 класса. Введение в физику.', 'Учителя физики', 'учителей физики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Введение в физику
Первоначальные сведения о строении вещества
Взаимодействие тел
Давление твёрдых тел, жидкостей и газов
Работа, мощность, энергия', 150, 1, 118);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 20);

-- Физика 8 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Физика 8 класс: тепловые и электрические явления', 'fizika-8-klass', 'Конкурс методических разработок по физике для 8 класса. Тепловые и электрические явления.', 'Учителя физики', 'учителей физики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Тепловые явления
Изменение агрегатных состояний вещества
Электрические явления
Электромагнитные явления
Световые явления', 150, 1, 119);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 20);

-- Физика 9 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Физика 9 класс: законы движения и подготовка к ОГЭ', 'fizika-9-klass', 'Конкурс методических разработок по физике для 9 класса. Механика и подготовка к ОГЭ.', 'Учителя физики', 'учителей физики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Законы движения
Механические колебания и волны
Электромагнитное поле
Строение атома и атомного ядра
Подготовка к ОГЭ по физике', 150, 1, 120);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 20);

-- ==================== ХИМИЯ ====================

-- Химия 8 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Химия 8 класс: первые открытия', 'himiya-8-klass', 'Конкурс методических разработок по химии для 8 класса. Введение в химию.', 'Учителя химии', 'учителей химии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Первоначальные химические понятия
Кислород. Горение
Водород
Вода. Растворы
Основные классы неорганических соединений', 150, 1, 121);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 21);

-- Химия 9 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Химия 9 класс: неорганическая химия и ОГЭ', 'himiya-9-klass', 'Конкурс методических разработок по химии для 9 класса. Неорганическая химия и подготовка к ОГЭ.', 'Учителя химии', 'учителей химии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Металлы
Неметаллы
Органические вещества
Химия и жизнь
Подготовка к ОГЭ по химии', 150, 1, 122);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 21);

-- ==================== БИОЛОГИЯ ====================

-- Биология 5 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Биология 5 класс: введение в биологию', 'biologiya-5-klass', 'Конкурс методических разработок по биологии для 5 класса. Живой организм.', 'Учителя биологии', 'учителей биологии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Биология — наука о живой природе
Клетка — основа строения
Многообразие организмов
Среды обитания
Человек на Земле', 150, 1, 123);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 22);

-- Биология 6 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Биология 6 класс: растения', 'biologiya-6-klass', 'Конкурс методических разработок по биологии для 6 класса. Ботаника.', 'Учителя биологии', 'учителей биологии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Строение и многообразие покрытосеменных растений
Жизнь растений
Классификация растений
Природные сообщества
Экологические факторы', 150, 1, 124);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 22);

-- Биология 7 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Биология 7 класс: животные', 'biologiya-7-klass', 'Конкурс методических разработок по биологии для 7 класса. Зоология.', 'Учителя биологии', 'учителей биологии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Простейшие
Беспозвоночные животные
Позвоночные животные
Эволюция животного мира
Экология животных', 150, 1, 125);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 22);

-- Биология 8 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Биология 8 класс: человек', 'biologiya-8-klass', 'Конкурс методических разработок по биологии для 8 класса. Анатомия и физиология человека.', 'Учителя биологии', 'учителей биологии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Организм человека
Опорно-двигательная система
Внутренняя среда организма
Нервная система
Органы чувств', 150, 1, 126);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 22);

-- Биология 9 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Биология 9 класс: общая биология и ОГЭ', 'biologiya-9-klass', 'Конкурс методических разработок по биологии для 9 класса. Общая биология.', 'Учителя биологии', 'учителей биологии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Молекулярный уровень
Клеточный уровень
Генетика
Эволюция
Подготовка к ОГЭ по биологии', 150, 1, 127);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 22);

-- ==================== ГЕОГРАФИЯ ====================

-- География 5 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('География 5 класс: введение в географию', 'geografiya-5-klass', 'Конкурс методических разработок по географии для 5 класса. Начальный курс.', 'Учителя географии', 'учителей географии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'На какой Земле мы живём
Планета Земля
План и карта
Человек на Земле
Литосфера', 150, 1, 128);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 23);

-- География 6 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('География 6 класс: физическая география', 'geografiya-6-klass', 'Конкурс методических разработок по географии для 6 класса. Физическая география.', 'Учителя географии', 'учителей географии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Гидросфера
Атмосфера
Биосфера
Географическая оболочка
Население Земли', 150, 1, 129);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 23);

-- География 7 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('География 7 класс: материки и океаны', 'geografiya-7-klass', 'Конкурс методических разработок по географии для 7 класса. География материков и океанов.', 'Учителя географии', 'учителей географии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Главные особенности природы Земли
Африка
Австралия и Океания
Южная Америка
Северная Америка и Евразия', 150, 1, 130);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 23);

-- География 8 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('География 8 класс: география России (природа)', 'geografiya-8-klass', 'Конкурс методических разработок по географии для 8 класса. Природа России.', 'Учителя географии', 'учителей географии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Россия на карте мира
Рельеф и недра
Климат России
Внутренние воды
Природные зоны России', 150, 1, 131);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 23);

-- География 9 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('География 9 класс: география России (население и хозяйство)', 'geografiya-9-klass', 'Конкурс методических разработок по географии для 9 класса. Население и хозяйство России.', 'Учителя географии', 'учителей географии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Население России
Хозяйство России
Экономические районы
Регионы России
Подготовка к ОГЭ по географии', 150, 1, 132);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 23);

-- ==================== ИСТОРИЯ ====================

-- История 5 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('История 5 класс: Древний мир', 'istoriya-5-klass', 'Конкурс методических разработок по истории для 5 класса. История Древнего мира.', 'Учителя истории', 'учителей истории', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Жизнь первобытных людей
Древний Восток
Древняя Греция
Древний Рим
Культура древних цивилизаций', 150, 1, 133);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 24);

-- История 6 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('История 6 класс: Средние века и Древняя Русь', 'istoriya-6-klass', 'Конкурс методических разработок по истории для 6 класса. Средневековье и история России.', 'Учителя истории', 'учителей истории', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Раннее Средневековье
Расцвет Средневековья
Древняя Русь
Русь в XII-XV веках
Культура Средневековья', 150, 1, 134);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 24);

-- История 7 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('История 7 класс: Новое время и Россия XVI-XVII вв.', 'istoriya-7-klass', 'Конкурс методических разработок по истории для 7 класса. Начало Нового времени.', 'Учителя истории', 'учителей истории', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Великие географические открытия
Реформация и контрреформация
Россия при Иване Грозном
Смутное время
Россия в XVII веке', 150, 1, 135);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 24);

-- История 8 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('История 8 класс: XVIII-XIX века', 'istoriya-8-klass', 'Конкурс методических разработок по истории для 8 класса. Эпоха Просвещения и революций.', 'Учителя истории', 'учителей истории', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Эпоха Просвещения
Россия при Петре I
Россия в XVIII веке
Россия в первой половине XIX века
Промышленная революция', 150, 1, 136);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 24);

-- История 9 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('История 9 класс: XX век и подготовка к ОГЭ', 'istoriya-9-klass', 'Конкурс методических разработок по истории для 9 класса. Новейшая история.', 'Учителя истории', 'учителей истории', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Россия в начале XX века
Первая мировая война и революции
СССР в 1920-1930-е годы
Великая Отечественная война
Подготовка к ОГЭ по истории', 150, 1, 137);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 24);

-- ==================== ОБЩЕСТВОЗНАНИЕ ====================

-- Обществознание 6 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Обществознание 6 класс: человек и общество', 'obshchestvoznanie-6-klass', 'Конкурс методических разработок по обществознанию для 6 класса. Введение в обществознание.', 'Учителя обществознания', 'учителей обществознания', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Человек в социальном измерении
Человек среди людей
Нравственные основы жизни
Человек и его деятельность
Межличностные отношения', 150, 1, 138);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 25);

-- Обществознание 7 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Обществознание 7 класс: право и экономика', 'obshchestvoznanie-7-klass', 'Конкурс методических разработок по обществознанию для 7 класса. Основы права и экономики.', 'Учителя обществознания', 'учителей обществознания', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Регулирование поведения людей в обществе
Человек в экономических отношениях
Человек и природа
Права и обязанности граждан
Экономика семьи', 150, 1, 139);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 25);

-- Обществознание 8 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Обществознание 8 класс: личность и общество', 'obshchestvoznanie-8-klass', 'Конкурс методических разработок по обществознанию для 8 класса. Личность в обществе.', 'Учителя обществознания', 'учителей обществознания', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Личность и общество
Сфера духовной культуры
Социальная сфера
Экономическая сфера
Финансовая грамотность', 150, 1, 140);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 25);

-- Обществознание 9 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Обществознание 9 класс: политика и право. ОГЭ', 'obshchestvoznanie-9-klass', 'Конкурс методических разработок по обществознанию для 9 класса. Подготовка к ОГЭ.', 'Учителя обществознания', 'учителей обществознания', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Политическая сфера
Право
Конституция РФ
Права человека
Подготовка к ОГЭ по обществознанию', 150, 1, 141);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 25);

-- ==================== АНГЛИЙСКИЙ ЯЗЫК ====================

-- Английский язык 5 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Английский язык 5 класс: новый этап обучения', 'angliyskiy-yazyk-5-klass', 'Конкурс методических разработок по английскому языку для 5 класса.', 'Учителя английского языка', 'учителей английского языка', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Повторение изученного
Грамматика: времена Present
Расширение лексического запаса
Развитие навыков чтения
Письменная речь', 150, 1, 142);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 26);

-- Английский язык 6 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Английский язык 6 класс: развитие навыков', 'angliyskiy-yazyk-6-klass', 'Конкурс методических разработок по английскому языку для 6 класса.', 'Учителя английского языка', 'учителей английского языка', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Past Simple и Past Continuous
Степени сравнения прилагательных
Аудирование
Говорение: диалоги
Проектная деятельность', 150, 1, 143);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 26);

-- Английский язык 7 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Английский язык 7 класс: грамматика и лексика', 'angliyskiy-yazyk-7-klass', 'Конкурс методических разработок по английскому языку для 7 класса.', 'Учителя английского языка', 'учителей английского языка', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Present Perfect
Пассивный залог
Условные предложения
Модальные глаголы
Работа с текстом', 150, 1, 144);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 26);

-- Английский язык 8 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Английский язык 8 класс: углубление знаний', 'angliyskiy-yazyk-8-klass', 'Конкурс методических разработок по английскому языку для 8 класса.', 'Учителя английского языка', 'учителей английского языка', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Времена группы Perfect
Косвенная речь
Причастия и герундий
Чтение аутентичных текстов
Подготовка к ВПР', 150, 1, 145);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 26);

-- Английский язык 9 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Английский язык 9 класс: подготовка к ОГЭ', 'angliyskiy-yazyk-9-klass', 'Конкурс методических разработок по английскому языку для 9 класса. Подготовка к ОГЭ.', 'Учителя английского языка', 'учителей английского языка', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Подготовка к ОГЭ: аудирование
Подготовка к ОГЭ: чтение
Подготовка к ОГЭ: грамматика
Подготовка к ОГЭ: письмо
Подготовка к ОГЭ: говорение', 150, 1, 146);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 26);

-- ==================== ИНФОРМАТИКА ====================

-- Информатика 7 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Информатика 7 класс: основы', 'informatika-7-klass', 'Конкурс методических разработок по информатике для 7 класса. Введение в информатику.', 'Учителя информатики', 'учителей информатики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Информация и информационные процессы
Компьютер как устройство
Обработка графической информации
Обработка текстовой информации
Мультимедиа', 150, 1, 147);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 19);

-- Информатика 8 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Информатика 8 класс: программирование', 'informatika-8-klass', 'Конкурс методических разработок по информатике для 8 класса. Алгоритмизация и программирование.', 'Учителя информатики', 'учителей информатики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Математические основы информатики
Основы алгоритмизации
Начала программирования
Работа с массивами
Работа с электронными таблицами', 150, 1, 148);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 19);

-- Информатика 9 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Информатика 9 класс: подготовка к ОГЭ', 'informatika-9-klass', 'Конкурс методических разработок по информатике для 9 класса. Подготовка к ОГЭ.', 'Учителя информатики', 'учителей информатики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Моделирование и формализация
Алгоритмизация
Программирование на Python
Обработка числовой информации
Подготовка к ОГЭ по информатике', 150, 1, 149);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 19);

-- ==================== ФИЗКУЛЬТУРА ====================

-- Физкультура 5-6 классы
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Физическая культура 5-6 классы', 'fizkultura-5-6-klassy', 'Конкурс методических разработок по физической культуре для 5-6 классов.', 'Учителя физической культуры', 'учителей физической культуры', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Лёгкая атлетика
Спортивные игры
Гимнастика
Лыжная подготовка
Плавание', 150, 1, 150);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 27);

-- Физкультура 7-9 классы
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Физическая культура 7-9 классы', 'fizkultura-7-9-klassy', 'Конкурс методических разработок по физической культуре для 7-9 классов.', 'Учителя физической культуры', 'учителей физической культуры', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Баскетбол и волейбол
Футбол
Лёгкая атлетика
Подготовка к ГТО
Здоровьесбережение', 150, 1, 151);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 27);

-- ==================== ОБЖ ====================

-- ОБЖ 8 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('ОБЖ 8 класс: безопасность в повседневной жизни', 'obzh-8-klass', 'Конкурс методических разработок по ОБЖ для 8 класса.', 'Учителя ОБЖ', 'учителей ОБЖ', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Пожарная безопасность
Безопасность на дорогах
Безопасность на водоёмах
Экология и безопасность
Первая помощь', 150, 1, 152);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 28);

-- ОБЖ 9 класс
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('ОБЖ 9 класс: защита населения', 'obzh-9-klass', 'Конкурс методических разработок по ОБЖ для 9 класса.', 'Учителя ОБЖ', 'учителей ОБЖ', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Защита населения от ЧС
Гражданская оборона
Противодействие терроризму
Основы военной службы
Здоровый образ жизни', 150, 1, 153);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 28);

-- ==================== ТЕХНОЛОГИЯ ====================

-- Технология 5-6 классы
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Технология 5-6 классы: основы', 'tehnologiya-5-6-klassy', 'Конкурс методических разработок по технологии для 5-6 классов.', 'Учителя технологии', 'учителей технологии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Творческие проекты
Обработка древесины
Обработка металла
Кулинария
Рукоделие', 150, 1, 154);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 30);

-- Технология 7-8 классы
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Технология 7-8 классы: проектная деятельность', 'tehnologiya-7-8-klassy', 'Конкурс методических разработок по технологии для 7-8 классов.', 'Учителя технологии', 'учителей технологии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Проектная деятельность
Электротехника
Робототехника
3D-моделирование
Профориентация', 150, 1, 155);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 30);

-- ==================== МУЗЫКА И ИЗО ====================

-- Музыка 5-7 классы
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Музыка 5-7 классы: искусство звуков', 'muzyka-5-7-klassy', 'Конкурс методических разработок по музыке для 5-7 классов.', 'Учителя музыки', 'учителей музыки', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Вокально-хоровая работа
Музыкальная грамота
Слушание музыки
Музыкальные проекты
Интеграция искусств', 150, 1, 156);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 29);

-- ИЗО 5-7 классы
INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Изобразительное искусство 5-7 классы', 'izo-5-7-klassy', 'Конкурс методических разработок по изобразительному искусству для 5-7 классов.', 'Учителя ИЗО', 'учителей ИЗО', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Декоративно-прикладное искусство
Изображение фигуры человека
Дизайн и архитектура
Художественные техники
Проектная деятельность', 150, 1, 157);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 29);

-- ==================================================
-- КОНКУРСЫ ДЛЯ СТАРШЕЙ ШКОЛЫ (10-11 КЛАССЫ)
-- ==================================================

-- ==================== РУССКИЙ ЯЗЫК 10-11 ====================

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Русский язык 10 класс: систематизация знаний', 'russkiy-yazyk-10-klass', 'Конкурс методических разработок по русскому языку для 10 класса. Подготовка к ЕГЭ.', 'Учителя русского языка', 'учителей русского языка', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Фонетика и орфоэпия
Лексика и фразеология
Морфемика и словообразование
Орфография
Культура речи', 150, 1, 200);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 17);

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Русский язык 11 класс: подготовка к ЕГЭ', 'russkiy-yazyk-11-klass', 'Конкурс методических разработок по русскому языку для 11 класса. Интенсивная подготовка к ЕГЭ.', 'Учителя русского языка', 'учителей русского языка', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Подготовка к ЕГЭ: тестовая часть
Подготовка к ЕГЭ: сочинение
Анализ текста
Пунктуация
Стилистика', 150, 1, 201);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 17);

-- ==================== ЛИТЕРАТУРА 10-11 ====================

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Литература 10 класс: русская классика XIX века', 'literatura-10-klass', 'Конкурс методических разработок по литературе для 10 класса. Золотой век русской литературы.', 'Учителя литературы', 'учителей литературы', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'А.Н. Островский
И.А. Гончаров
И.С. Тургенев
Ф.М. Достоевский
Л.Н. Толстой', 150, 1, 202);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 17);

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Литература 11 класс: XX век и современность', 'literatura-11-klass', 'Конкурс методических разработок по литературе для 11 класса. Литература XX-XXI веков.', 'Учителя литературы', 'учителей литературы', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Серебряный век русской поэзии
М.А. Булгаков
М.А. Шолохов
Литература Великой Отечественной войны
Современная литература', 150, 1, 203);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 17);

-- ==================== МАТЕМАТИКА 10-11 ====================

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Алгебра и начала анализа 10 класс', 'algebra-10-klass', 'Конкурс методических разработок по алгебре для 10 класса. Начала математического анализа.', 'Учителя математики', 'учителей математики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Тригонометрические функции
Тригонометрические уравнения
Производная
Применение производной
Первообразная и интеграл', 150, 1, 204);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 18);

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Алгебра и начала анализа 11 класс: подготовка к ЕГЭ', 'algebra-11-klass', 'Конкурс методических разработок по алгебре для 11 класса. Подготовка к ЕГЭ.', 'Учителя математики', 'учителей математики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Показательная функция
Логарифмическая функция
Подготовка к ЕГЭ: профильный уровень
Подготовка к ЕГЭ: базовый уровень
Решение задач повышенной сложности', 150, 1, 205);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 18);

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Геометрия 10-11 классы: стереометрия', 'geometriya-10-11-klassy', 'Конкурс методических разработок по геометрии для 10-11 классов. Стереометрия.', 'Учителя математики', 'учителей математики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Параллельность в пространстве
Перпендикулярность в пространстве
Многогранники
Тела вращения
Объёмы тел', 150, 1, 206);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 18);

-- ==================== ФИЗИКА 10-11 ====================

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Физика 10 класс: механика и термодинамика', 'fizika-10-klass', 'Конкурс методических разработок по физике для 10 класса.', 'Учителя физики', 'учителей физики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Кинематика
Динамика
Законы сохранения
Молекулярная физика
Термодинамика', 150, 1, 207);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 20);

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Физика 11 класс: электродинамика и подготовка к ЕГЭ', 'fizika-11-klass', 'Конкурс методических разработок по физике для 11 класса. Подготовка к ЕГЭ.', 'Учителя физики', 'учителей физики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Электродинамика
Колебания и волны
Оптика
Квантовая физика
Подготовка к ЕГЭ по физике', 150, 1, 208);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 20);

-- ==================== ХИМИЯ 10-11 ====================

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Химия 10 класс: органическая химия', 'himiya-10-klass', 'Конкурс методических разработок по химии для 10 класса. Органическая химия.', 'Учителя химии', 'учителей химии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Углеводороды
Кислородсодержащие соединения
Азотсодержащие соединения
Биологически активные вещества
Синтетические полимеры', 150, 1, 209);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 21);

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Химия 11 класс: общая химия и подготовка к ЕГЭ', 'himiya-11-klass', 'Конкурс методических разработок по химии для 11 класса. Подготовка к ЕГЭ.', 'Учителя химии', 'учителей химии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Строение вещества
Химические реакции
Вещества и их свойства
Химия в жизни общества
Подготовка к ЕГЭ по химии', 150, 1, 210);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 21);

-- ==================== БИОЛОГИЯ 10-11 ====================

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Биология 10 класс: общая биология', 'biologiya-10-klass', 'Конкурс методических разработок по биологии для 10 класса. Общая биология.', 'Учителя биологии', 'учителей биологии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Биология как наука
Клетка
Размножение и развитие
Генетика
Селекция', 150, 1, 211);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 22);

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Биология 11 класс: эволюция и экология', 'biologiya-11-klass', 'Конкурс методических разработок по биологии для 11 класса. Подготовка к ЕГЭ.', 'Учителя биологии', 'учителей биологии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Эволюция
Происхождение жизни
Происхождение человека
Экология
Подготовка к ЕГЭ по биологии', 150, 1, 212);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 22);

-- ==================== ГЕОГРАФИЯ 10-11 ====================

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('География 10-11 классы: экономическая и социальная география мира', 'geografiya-10-11-klassy', 'Конкурс методических разработок по географии для 10-11 классов.', 'Учителя географии', 'учителей географии', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Современная политическая карта мира
Население мира
Мировое хозяйство
Регионы и страны мира
Глобальные проблемы человечества', 150, 1, 213);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 23);

-- ==================== ИСТОРИЯ 10-11 ====================

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('История 10 класс: история России и мира', 'istoriya-10-klass', 'Конкурс методических разработок по истории для 10 класса.', 'Учителя истории', 'учителей истории', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Древнейшая история
Средневековье
Новое время
История России XVI-XIX веков
Культура и быт', 150, 1, 214);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 24);

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('История 11 класс: XX-XXI века и подготовка к ЕГЭ', 'istoriya-11-klass', 'Конкурс методических разработок по истории для 11 класса. Подготовка к ЕГЭ.', 'Учителя истории', 'учителей истории', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Россия в начале XX века
СССР
Вторая мировая война
Современная Россия
Подготовка к ЕГЭ по истории', 150, 1, 215);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 24);

-- ==================== ОБЩЕСТВОЗНАНИЕ 10-11 ====================

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Обществознание 10 класс: общество и человек', 'obshchestvoznanie-10-klass', 'Конкурс методических разработок по обществознанию для 10 класса.', 'Учителя обществознания', 'учителей обществознания', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Человек и общество
Духовная культура
Экономическая жизнь общества
Социальная сфера
Политическая жизнь общества', 150, 1, 216);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 25);

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Обществознание 11 класс: право и подготовка к ЕГЭ', 'obshchestvoznanie-11-klass', 'Конкурс методических разработок по обществознанию для 11 класса. Подготовка к ЕГЭ.', 'Учителя обществознания', 'учителей обществознания', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Правовое регулирование
Конституционное право
Гражданское право
Трудовое и семейное право
Подготовка к ЕГЭ по обществознанию', 150, 1, 217);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 25);

-- ==================== АНГЛИЙСКИЙ ЯЗЫК 10-11 ====================

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Английский язык 10 класс: продвинутый уровень', 'angliyskiy-yazyk-10-klass', 'Конкурс методических разработок по английскому языку для 10 класса.', 'Учителя английского языка', 'учителей английского языка', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Грамматика высокого уровня
Академическое письмо
Подготовка к международным экзаменам
Дискуссии и дебаты
Работа с аутентичными текстами', 150, 1, 218);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 26);

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Английский язык 11 класс: подготовка к ЕГЭ', 'angliyskiy-yazyk-11-klass', 'Конкурс методических разработок по английскому языку для 11 класса. Подготовка к ЕГЭ.', 'Учителя английского языка', 'учителей английского языка', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Подготовка к ЕГЭ: аудирование
Подготовка к ЕГЭ: чтение
Подготовка к ЕГЭ: грамматика и лексика
Подготовка к ЕГЭ: письмо
Подготовка к ЕГЭ: говорение', 150, 1, 219);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 26);

-- ==================== ИНФОРМАТИКА 10-11 ====================

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Информатика 10 класс: информационные системы', 'informatika-10-klass', 'Конкурс методических разработок по информатике для 10 класса.', 'Учителя информатики', 'учителей информатики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Информационные системы
Базы данных
Компьютерные сети
Сайтостроение
Программирование', 150, 1, 220);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 19);

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Информатика 11 класс: подготовка к ЕГЭ', 'informatika-11-klass', 'Конкурс методических разработок по информатике для 11 класса. Подготовка к ЕГЭ.', 'Учителя информатики', 'учителей информатики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Логика и алгоритмы
Программирование на Python
Работа с файлами и данными
Теория информации
Подготовка к ЕГЭ по информатике', 150, 1, 221);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 19);

-- ==================== ФИЗКУЛЬТУРА 10-11 ====================

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Физическая культура 10-11 классы', 'fizkultura-10-11-klassy', 'Конкурс методических разработок по физической культуре для 10-11 классов.', 'Учителя физической культуры', 'учителей физической культуры', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Спортивные игры
Лёгкая атлетика
Гимнастика
Подготовка к ГТО
Здоровый образ жизни', 150, 1, 222);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 27);

-- ==================== ОБЖ 10-11 ====================

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('ОБЖ 10-11 классы: основы военной службы', 'obzh-10-11-klassy', 'Конкурс методических разработок по ОБЖ для 10-11 классов.', 'Учителя ОБЖ', 'учителей ОБЖ', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Основы военной службы
Начальная военная подготовка
Гражданская оборона
Первая помощь
Защита Отечества', 150, 1, 223);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 28);

-- ==================== МХК 10-11 ====================

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Мировая художественная культура 10-11 классы', 'mhk-10-11-klassy', 'Конкурс методических разработок по МХК для 10-11 классов.', 'Учителя МХК', 'учителей МХК', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Художественная культура древности
Культура Средневековья
Культура Нового времени
Культура XX века
Современное искусство', 150, 1, 224);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 29);

-- ==================== АСТРОНОМИЯ 10-11 ====================

INSERT INTO competitions (title, slug, description, target_participants, target_participants_genitive, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Астрономия 10-11 классы: тайны Вселенной', 'astronomiya-10-11-klassy', 'Конкурс методических разработок по астрономии для 10-11 классов.', 'Учителя астрономии и физики', 'учителей астрономии и физики', 'Диплом I, II, III степени в электронном виде', '2025-2026', 'methodology', 'Практическая астрономия
Солнечная система
Солнце и звёзды
Строение Вселенной
Космические технологии', 150, 1, 225);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 20);

SET FOREIGN_KEY_CHECKS = 1;

-- Проверка количества добавленных конкурсов
SELECT CONCAT('Добавлено конкурсов для средней и старшей школы: ', COUNT(*)) as result
FROM competitions
WHERE slug LIKE '%-5-klass' OR slug LIKE '%-6-klass' OR slug LIKE '%-7-klass'
   OR slug LIKE '%-8-klass' OR slug LIKE '%-9-klass' OR slug LIKE '%-10-klass'
   OR slug LIKE '%-11-klass' OR slug LIKE '%-5-6-%' OR slug LIKE '%-7-8-%'
   OR slug LIKE '%-7-9-%' OR slug LIKE '%-10-11-%' OR slug LIKE '%-5-7-%';
