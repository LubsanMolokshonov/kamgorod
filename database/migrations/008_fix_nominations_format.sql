-- Migration: Fix Nominations Format and Add to All Competitions
-- Created: 2026-01-23
-- Description: Конвертирование nomination_options в JSON формат и добавление номинаций для всех конкурсов
-- Status: READY TO APPLY

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

-- ==================================================
-- Обновление существующих конкурсов с номинациями в правильном JSON формате
-- ==================================================

-- 1. Конкурсы по методическим разработкам для ДОУ
UPDATE competitions SET nomination_options = '["Методическая разработка занятия","Образовательный проект","Дидактические материалы","Сценарий мероприятия","Методическое пособие"]'
WHERE category = 'methodology' AND target_participants LIKE '%дошкольн%' AND (nomination_options IS NULL OR nomination_options = '');

-- 2. Конкурсы по методическим разработкам для школы
UPDATE competitions SET nomination_options = '["Методическая разработка урока","Образовательный проект","Внеурочная деятельность","Рабочая программа","Дидактические материалы","Сценарий мероприятия"]'
WHERE category = 'methodology' AND target_participants LIKE '%учител%' AND (nomination_options IS NULL OR nomination_options = '');

-- 3. Творческие конкурсы для педагогов
UPDATE competitions SET nomination_options = '["Изобразительное творчество","Декоративно-прикладное творчество","Музыкальное творчество","Литературное творчество","Фотография","Видеоработа"]'
WHERE category = 'creative' AND target_participants LIKE '%педагог%' AND (nomination_options IS NULL OR nomination_options = '');

-- 4. Творческие конкурсы для детей (ДОУ)
UPDATE competitions SET nomination_options = '["Рисунок","Поделка","Аппликация","Лепка","Коллективная работа"]'
WHERE category = 'student_projects' AND target_participants LIKE '%дошкольн%' AND (nomination_options IS NULL OR nomination_options = '');

-- 5. Творческие конкурсы для школьников
UPDATE competitions SET nomination_options = '["Рисунок","Поделка","Исследовательская работа","Проект","Литературное творчество","Фотография","Видеоработа"]'
WHERE category = 'student_projects' AND target_participants LIKE '%школьн%' AND (nomination_options IS NULL OR nomination_options = '');

-- 6. Конкурсы по внеурочной деятельности
UPDATE competitions SET nomination_options = '["Программа внеурочной деятельности","Сценарий мероприятия","Классный час","Игровая деятельность","Проектная деятельность"]'
WHERE category = 'extracurricular' AND (nomination_options IS NULL OR nomination_options = '');

-- ==================================================
-- Конвертирование существующих номинаций из текстового формата в JSON
-- ==================================================

-- Экологическое воспитание ДОУ
UPDATE competitions
SET nomination_options = '["Экологические проекты для малышей","Наблюдения за природой в детском саду","Опытно-экспериментальная деятельность","Экологические праздники и акции"]'
WHERE title = 'Юные экологи: природа глазами дошкольников';

-- Социально-коммуникативное развитие
UPDATE competitions
SET nomination_options = '["Игры на социализацию","Развитие эмоционального интеллекта","Работа с семьей","Адаптация детей в коллективе"]'
WHERE title = 'Мир общения: социализация дошкольников';

-- Литературное чтение
UPDATE competitions
SET nomination_options = '["Работа с детской книгой","Развитие читательской грамотности","Литературные игры и викторины","Творческие проекты по прочитанному"]'
WHERE title = 'Волшебный мир книги: литературное чтение';

-- Английский язык начальная школа
UPDATE competitions
SET nomination_options = '["Игровые методики обучения","Песни и рифмовки на английском","Драматизация на уроках","Раннее обучение иностранному языку"]'
WHERE title = 'First Steps in English: английский для малышей';

-- ИЗО начальная школа
UPDATE competitions
SET nomination_options = '["Нетрадиционные техники рисования","Декоративно-прикладное творчество","Знакомство с народным искусством","Пленэрные занятия"]'
WHERE title = 'Краски детства: ИЗО в начальной школе';

-- Музыка начальная школа
UPDATE competitions
SET nomination_options = '["Хоровое пение","Музыкальные игры и упражнения","Слушание музыки","Музыкально-ритмические движения"]'
WHERE title = 'Музыкальная шкатулка: музыка в начальной школе';

-- Физкультура начальная школа
UPDATE competitions
SET nomination_options = '["Подвижные игры","Спортивные праздники","Физкультминутки","Формирование основ ЗОЖ"]'
WHERE title = 'Веселые старты: физкультура в начальной школе';

-- Технология начальная школа
UPDATE competitions
SET nomination_options = '["Работа с бумагой и картоном","Конструирование","Работа с природными материалами","Основы проектной деятельности"]'
WHERE title = 'Мастерилка: технология в начальной школе';

-- Экономика СПО
UPDATE competitions
SET nomination_options = '["Бухгалтерский учет","Банковское дело","Экономика предприятия","Деловые игры и кейсы"]'
WHERE title = 'Экономика и бизнес: подготовка специалистов СПО';

-- Гуманитарные специальности СПО
UPDATE competitions
SET nomination_options = '["Социальная работа","Право и юриспруденция","Документоведение","Туризм и сервис"]'
WHERE title = 'Гуманитарное образование в СПО';

-- ==================================================
-- Универсальные номинации для конкурсов без специфичных номинаций
-- ==================================================

-- Для конкретного конкурса ID=86 (если это специальный конкурс)
UPDATE competitions
SET nomination_options = '["Здоровый дошкольник: физкультура и здоровье","Физкультурно-оздоровительная работа в ДОУ","Нетрадиционные формы физического воспитания","Профилактика нарушений осанки и плоскостопия","Формирование основ здорового образа жизни"]'
WHERE id = 86;

-- ==================================================
-- Добавление номинаций для всех оставшихся конкурсов
-- ==================================================

-- Для всех методических конкурсов без номинаций
UPDATE competitions
SET nomination_options = '["Методическая разработка","Образовательная программа","Дидактические материалы","Инновационные технологии","Сценарий мероприятия"]'
WHERE category = 'methodology' AND (nomination_options IS NULL OR nomination_options = '' OR nomination_options = 'null');

-- Для всех творческих конкурсов без номинаций
UPDATE competitions
SET nomination_options = '["Изобразительное искусство","Декоративно-прикладное творчество","Музыкальное творчество","Литературное творчество","Фотография","Видеоработа"]'
WHERE category = 'creative' AND (nomination_options IS NULL OR nomination_options = '' OR nomination_options = 'null');

-- Для проектов учащихся без номинаций
UPDATE competitions
SET nomination_options = '["Исследовательский проект","Творческий проект","Социальный проект","Техническое творчество","Художественное творчество"]'
WHERE category = 'student_projects' AND (nomination_options IS NULL OR nomination_options = '' OR nomination_options = 'null');

-- Для внеурочной деятельности без номинаций
UPDATE competitions
SET nomination_options = '["Программа внеурочной деятельности","Классный час","Воспитательное мероприятие","Игровая программа","Экскурсионная деятельность"]'
WHERE category = 'extracurricular' AND (nomination_options IS NULL OR nomination_options = '' OR nomination_options = 'null');

-- ==================================================
-- Проверка результата
-- ==================================================

-- Вывести количество конкурсов с пустыми номинациями (должно быть 0)
SELECT COUNT(*) as 'Конкурсов без номинаций'
FROM competitions
WHERE is_active = 1 AND (nomination_options IS NULL OR nomination_options = '' OR nomination_options = 'null');

-- Показать примеры конкурсов с номинациями
SELECT id, title, nomination_options
FROM competitions
WHERE is_active = 1
LIMIT 10;
