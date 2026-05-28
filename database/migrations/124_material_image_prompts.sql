-- Миграция 124: добавляем поле image_prompt в шаблоны промптов типов материалов.
-- Модель возвращает краткое визуальное описание обложки → YandexArtService рисует картинку.
-- Идемпотентно: UPDATE по slug перезаписывает шаблон целиком.
-- Дата: 2026-05-28

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE material_types SET ai_prompt_template =
'Ты — методист, разрабатывающий технологические карты урока строго по ФГОС. Сгенерируй полную техкарту для предмета «{subject}», класс {class}, тема «{topic}», длительность {duration} минут. Особенности группы: {features}. Соответствие программе: {program}.\n\nВерни строго JSON со структурой: {"title": "...", "image_prompt": "краткое визуальное описание обложки по теме урока на русском, плоский векторный стиль, спокойная палитра, без текста на картинке", "goals": [...], "uud": {"personal": [...], "regulative": [...], "cognitive": [...], "communicative": [...]}, "stages": [{"name": "...", "duration_min": ..., "teacher_activity": "...", "student_activity": "...", "methods": [...]}], "homework": "...", "reflection": "..."}. Без markdown, только JSON.'
WHERE slug = 'tehkarta-uroka';

UPDATE material_types SET ai_prompt_template =
'Ты — учитель-методист. Составь подробный конспект урока по предмету «{subject}», класс {class}, тема «{topic}», длительность {duration} минут. Особенности группы: {features}.\n\nВерни JSON: {"title": "...", "image_prompt": "краткое визуальное описание обложки по теме урока на русском, плоский векторный стиль, спокойная палитра, без текста на картинке", "objectives": [...], "equipment": [...], "stages": [{"name": "...", "duration_min": ..., "narrative": "развёрнутый текст того, что говорит учитель и что делают ученики"}], "homework": "..."}. Без markdown.'
WHERE slug = 'konspekt-uroka';

UPDATE material_types SET ai_prompt_template =
'Создай рабочий лист для ученика. Предмет «{subject}», класс {class}, тема «{topic}». Особенности: {features}.\n\nВерни JSON: {"title": "...", "image_prompt": "краткое визуальное описание обложки по теме на русском, плоский векторный стиль, спокойная палитра, без текста на картинке", "intro": "краткое объяснение темы 2-3 предложения", "tasks": [{"number": 1, "type": "match|fill|choose|write|draw", "instruction": "...", "content": "..."}], "answer_key": [{"number": 1, "answer": "..."}]}. От 5 до 8 заданий разного типа. Без markdown.'
WHERE slug = 'rabochiy-list';

UPDATE material_types SET ai_prompt_template =
'Составь тест по предмету «{subject}», класс {class}, тема «{topic}». Количество вопросов: {questions_count}. Особенности: {features}.\n\nВерни JSON: {"title": "...", "image_prompt": "краткое визуальное описание обложки по теме на русском, плоский векторный стиль, спокойная палитра, без текста на картинке", "instructions": "...", "questions": [{"number": 1, "type": "single|multiple|open", "text": "...", "options": ["A", "B", "C", "D"], "correct": [0], "explanation": "..."}]}. Без markdown.'
WHERE slug = 'test-kontrolnaya';

UPDATE material_types SET ai_prompt_template =
'Сделай учебную презентацию. Предмет «{subject}», класс {class}, тема «{topic}». Количество слайдов: {slides_count} (10-20). Особенности: {features}.\n\nВерни JSON: {"title": "...", "image_prompt": "краткое визуальное описание обложки презентации по теме на русском, плоский векторный стиль, спокойная палитра, без текста на картинке", "slides": [{"number": 1, "title": "...", "bullets": ["...", "..."], "notes": "что говорит учитель на этом слайде"}]}. Первый слайд — титул, последний — итоги. Без markdown.'
WHERE slug = 'prezentatsiya';

UPDATE material_types SET ai_prompt_template =
'Разработай сценарий классного часа. Тема «{topic}», возраст/класс {class}, длительность {duration} минут. Особенности: {features}.\n\nВерни JSON: {"title": "...", "image_prompt": "краткое визуальное описание обложки по теме на русском, плоский векторный стиль, спокойная палитра, без текста на картинке", "goal": "...", "structure": [{"name": "...", "duration_min": ..., "narrative": "что делаем"}], "discussion_questions": [...], "reflection": "..."}. Без markdown.'
WHERE slug = 'klassnyy-chas';

UPDATE material_types SET ai_prompt_template =
'Составь фрагмент КТП. Предмет «{subject}», класс {class}, раздел/тема «{topic}», количество часов {hours}. Программа: {program}.\n\nВерни JSON: {"section": "...", "image_prompt": "краткое визуальное описание обложки по теме на русском, плоский векторный стиль, спокойная палитра, без текста на картинке", "rows": [{"lesson_num": 1, "topic": "...", "hours": 1, "uud": "...", "activity": "...", "control": "..."}]}. Без markdown.'
WHERE slug = 'ktp-fragment';
