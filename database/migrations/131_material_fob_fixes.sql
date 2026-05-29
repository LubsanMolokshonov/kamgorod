-- Миграция 131: исправления генератора материалов ФОП (FOB).
-- Дата: 2026-05-29
--
-- 1. Колонка ai_output_json — сырой JSON-ответ ИИ. Нужна, чтобы скачивание могло
--    рендерить ИСТИННЫЙ формат (DOCX/PPTX/PDF) на лету, а не только PDF из content.
-- 2. Уточнённые шаблоны промптов: отдельные ключи учитель/ученик, осмысленные типы
--    заданий, разноплановые вопросы теста, конспект вместо плана, структура слайдов.
-- 3. Конспект/техкарта — материал для учителя: обложку не генерим (экономия токенов),
--    помечаем флагом needs_cover.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. Сырой JSON ответа ИИ для рендера любого формата на лету.
--    MySQL 8 не поддерживает ADD COLUMN IF NOT EXISTS — проверяем через information_schema.
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'materials' AND COLUMN_NAME = 'ai_output_json');
SET @q = IF(@c = 0, 'ALTER TABLE materials ADD COLUMN ai_output_json JSON NULL AFTER ai_params_json', 'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Флаг «нужна обложка» у типа материала (для ученика — да, для учителя — нет)
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_types' AND COLUMN_NAME = 'needs_cover');
SET @q = IF(@c = 0, 'ALTER TABLE material_types ADD COLUMN needs_cover TINYINT(1) NOT NULL DEFAULT 1 AFTER ai_model_key', 'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Учительские материалы — обложка не нужна (экономим токены YandexART).
UPDATE material_types SET needs_cover = 0
    WHERE slug IN ('tehkarta-uroka', 'konspekt-uroka', 'test-kontrolnaya', 'ktp-fragment', 'klassnyy-chas');
-- Ученические/презентационные — обложка уместна.
UPDATE material_types SET needs_cover = 1
    WHERE slug IN ('rabochiy-list', 'prezentatsiya');

-- 2a. Технологическая карта — добавляем тип урока и планируемые результаты, явный раздел оборудования.
UPDATE material_types SET ai_prompt_template =
'Ты — методист, разрабатывающий технологические карты урока строго по ФГОС. Сгенерируй полную техкарту для предмета «{subject}», класс {class}, тема «{topic}», длительность {duration} минут. Особенности группы: {features}. Соответствие программе: {program}.\n\nТребования: этапов 5–8 (мотивация, актуализация, изучение нового, первичное закрепление, самостоятельная работа, рефлексия, домашнее задание); для каждого этапа — конкретные действия учителя и учеников, не общие фразы; формулировки результатов проверяемые.\n\nВерни строго JSON: {"title": "...", "lesson_type": "тип урока (открытие нового знания / закрепление / контроль и т.п.)", "goals": ["..."], "planned_results": {"subject": ["предметные"], "metasubject": ["метапредметные"], "personal": ["личностные"]}, "uud": {"personal": ["..."], "regulative": ["..."], "cognitive": ["..."], "communicative": ["..."]}, "equipment": ["..."], "stages": [{"name": "...", "duration_min": 5, "teacher_activity": "...", "student_activity": "...", "methods": ["..."]}], "homework": "...", "reflection": "..."}. Без markdown, только JSON.'
WHERE slug = 'tehkarta-uroka';

-- 2b. Конспект — это именно конспект с развёрнутым ходом и репликами, а не план.
UPDATE material_types SET ai_prompt_template =
'Ты — учитель-методист. Составь ПОДРОБНЫЙ конспект урока (не план!) по предмету «{subject}», класс {class}, тема «{topic}», длительность {duration} минут. Особенности группы: {features}.\n\nТребования: для каждого этапа в поле narrative — развёрнутый текст 4–8 предложений: что именно говорит учитель (прямая речь, вопросы классу), что отвечают и делают ученики, какие примеры и задания разбираются. Это материал, по которому можно вести урок без подготовки. Этапов 5–7.\n\nВерни JSON: {"title": "...", "objectives": ["..."], "equipment": ["..."], "stages": [{"name": "...", "duration_min": 7, "narrative": "развёрнутый текст хода этапа с репликами учителя и реакцией учеников"}], "homework": "..."}. Без markdown.'
WHERE slug = 'konspekt-uroka';

-- 2c. Рабочий лист — ответы НЕ внутри заданий, отдельный answer_key; осмысленные типы;
--      content для match — два столбца (left/right); для write/draw — пусто (место даёт рендерер).
UPDATE material_types SET ai_prompt_template =
'Создай рабочий лист для САМОСТОЯТЕЛЬНОЙ работы ученика. Предмет «{subject}», класс {class}, тема «{topic}». Особенности: {features}.\n\nКРИТИЧЕСКИ ВАЖНО: это лист для ученика — он ещё НЕ решён. В поле content и instruction НЕ должно быть готовых ответов, решений или вписанного результата. Все ответы только в answer_key.\n\nТипы заданий (5–8 штук, разные):\n- "fill" — вставить пропущенное; в content текст с пропусками вида "____".\n- "choose" — выбрать вариант; в content варианты на отдельных строках.\n- "match" — сопоставление; content = {"left": ["A","Б","В"], "right": ["1","2","3"]} (ученик соединяет линиями).\n- "write" — развёрнутый письменный ответ; content оставь пустым ("") — рендерер добавит линии для письма.\n- "draw" — нарисовать; content оставь пустым ("") — рендерер добавит рамку для рисунка.\n\nВ instruction — только формулировка задания. Верни JSON: {"title": "...", "intro": "краткое объяснение темы 2-3 предложения для ученика", "tasks": [{"number": 1, "type": "fill|choose|match|write|draw", "instruction": "...", "content": "... | \"\" | {left:[],right:[]}"}], "answer_key": [{"number": 1, "answer": "правильный ответ для учителя"}]}. Без markdown.'
WHERE slug = 'rabochiy-list';

-- 2d. Тест — поддержка критериев (multiple/single/open), разноплановые вопросы,
--      правильные ответы и пояснения только в answer_key (вне вопросов).
UPDATE material_types SET ai_prompt_template =
'Составь тест/контрольную по предмету «{subject}», класс {class}, тема «{topic}». Количество вопросов: {questions_count}. Особенности: {features}. Тип проверки: {test_mode}.\n\nКРИТИЧЕСКИ ВАЖНО: это бланк для ученика — правильные ответы НЕ отмечай в самих вопросах (поле correct и пояснения только в answer_key, не дублируй в тексте). Сделай вопросы РАЗНОПЛАНОВЫМИ: часть single (один ответ), часть multiple (несколько правильных — если режим это допускает), часть open (открытый ответ без вариантов). Для open поле options не нужно.\n\nВерни JSON: {"title": "...", "instructions": "инструкция для ученика", "questions": [{"number": 1, "type": "single|multiple|open", "text": "...", "options": ["A","B","C","D"]}], "answer_key": [{"number": 1, "correct": [0], "explanation": "почему этот ответ верный — кратко и по делу"}]}. Поле correct — индексы правильных вариантов (для single один, для multiple несколько, для open — пропусти correct и дай образец ответа в explanation). Без markdown.'
WHERE slug = 'test-kontrolnaya';

-- 2e. Презентация — явная структура слайдов (титул, цели, 2–3 содержательных, закрепление, итоги),
--      3–5 буллетов на слайд, заметки докладчика.
UPDATE material_types SET ai_prompt_template =
'Сделай учебную презентацию к уроку. Предмет «{subject}», класс {class}, тема «{topic}». Количество слайдов: {slides_count} (10-20). Особенности: {features}.\n\nСтруктура: 1) титульный (тема, класс), 2) цели/задачи урока, 3) актуализация/проблемный вопрос, 4–N) содержательные слайды (один слайд = одна мысль, 3–5 коротких буллетов, без сплошного текста), предпоследний — закрепление/вопросы, последний — итоги/рефлексия. В notes — что учитель говорит на этом слайде.\n\nВерни JSON: {"title": "...", "slides": [{"number": 1, "title": "заголовок слайда", "bullets": ["короткий тезис", "..."], "notes": "речь учителя на слайде"}]}. Без markdown.'
WHERE slug = 'prezentatsiya';

-- 2f. КТП — без изменений структуры, но добавим планируемые результаты в строки уже покрыты uud.
-- (оставляем как есть — претензий по КТП в баг-репорте нет)
