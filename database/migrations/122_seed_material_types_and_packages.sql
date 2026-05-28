-- Миграция 122: seed 7 типов материалов + 3 тарифных пакета
-- Дата: 2026-05-25
-- Промпты-шаблоны минимальные, редакция допилит через админку.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO material_types
    (name, slug, description, icon, output_format, token_cost_default, ai_model_key, ai_prompt_template, display_order)
VALUES
    (
        'Технологическая карта урока',
        'tehkarta-uroka',
        'Структурированная карта урока по требованиям ФГОС: этапы, цели, УУД, деятельность учителя и ученика.',
        'fa-table',
        'docx',
        20,
        'structured',
        'Ты — методист, разрабатывающий технологические карты урока строго по ФГОС. Сгенерируй полную техкарту для предмета «{subject}», класс {class}, тема «{topic}», длительность {duration} минут. Особенности группы: {features}. Соответствие программе: {program}.\n\nВерни строго JSON со структурой: {"title": "...", "goals": [...], "uud": {"personal": [...], "regulative": [...], "cognitive": [...], "communicative": [...]}, "stages": [{"name": "...", "duration_min": ..., "teacher_activity": "...", "student_activity": "...", "methods": [...]}], "homework": "...", "reflection": "..."}. Без markdown, только JSON.',
        10
    ),
    (
        'Конспект урока',
        'konspekt-uroka',
        'Подробный конспект урока с пошаговым планом и речью учителя.',
        'fa-file-lines',
        'docx',
        15,
        'default',
        'Ты — учитель-методист. Составь подробный конспект урока по предмету «{subject}», класс {class}, тема «{topic}», длительность {duration} минут. Особенности группы: {features}.\n\nВерни JSON: {"title": "...", "objectives": [...], "equipment": [...], "stages": [{"name": "...", "duration_min": ..., "narrative": "развёрнутый текст того, что говорит учитель и что делают ученики"}], "homework": "..."}. Без markdown.',
        20
    ),
    (
        'Рабочий лист',
        'rabochiy-list',
        'Готовый к печати рабочий лист A4 с заданиями для ученика.',
        'fa-clipboard-list',
        'pdf',
        10,
        'default',
        'Создай рабочий лист для ученика. Предмет «{subject}», класс {class}, тема «{topic}». Особенности: {features}.\n\nВерни JSON: {"title": "...", "intro": "краткое объяснение темы 2-3 предложения", "tasks": [{"number": 1, "type": "match|fill|choose|write|draw", "instruction": "...", "content": "..."}], "answer_key": [{"number": 1, "answer": "..."}]}. От 5 до 8 заданий разного типа. Без markdown.',
        30
    ),
    (
        'Тест или контрольная',
        'test-kontrolnaya',
        'Набор вопросов с вариантами ответов и ключами для проверки.',
        'fa-square-check',
        'docx',
        15,
        'structured',
        'Составь тест по предмету «{subject}», класс {class}, тема «{topic}». Количество вопросов: {questions_count}. Особенности: {features}.\n\nВерни JSON: {"title": "...", "instructions": "...", "questions": [{"number": 1, "type": "single|multiple|open", "text": "...", "options": ["A", "B", "C", "D"], "correct": [0], "explanation": "..."}]}. Без markdown.',
        40
    ),
    (
        'Презентация',
        'prezentatsiya',
        'Презентация PPTX 10–20 слайдов под тему урока.',
        'fa-display',
        'pptx',
        25,
        'structured',
        'Сделай учебную презентацию. Предмет «{subject}», класс {class}, тема «{topic}». Количество слайдов: {slides_count} (10-20). Особенности: {features}.\n\nВерни JSON: {"title": "...", "slides": [{"number": 1, "title": "...", "bullets": ["...", "..."], "notes": "что говорит учитель на этом слайде"}]}. Первый слайд — титул, последний — итоги. Без markdown.',
        50
    ),
    (
        'Классный час',
        'klassnyy-chas',
        'Сценарий классного часа с играми и обсуждением.',
        'fa-people-group',
        'docx',
        15,
        'default',
        'Разработай сценарий классного часа. Тема «{topic}», возраст/класс {class}, длительность {duration} минут. Особенности: {features}.\n\nВерни JSON: {"title": "...", "goal": "...", "structure": [{"name": "...", "duration_min": ..., "narrative": "что делаем"}], "discussion_questions": [...], "reflection": "..."}. Без markdown.',
        60
    ),
    (
        'Фрагмент КТП / рабочей программы',
        'ktp-fragment',
        'Фрагмент календарно-тематического планирования по разделу/теме.',
        'fa-calendar-week',
        'docx',
        20,
        'structured',
        'Составь фрагмент КТП. Предмет «{subject}», класс {class}, раздел/тема «{topic}», количество часов {hours}. Программа: {program}.\n\nВерни JSON: {"section": "...", "rows": [{"lesson_num": 1, "topic": "...", "hours": 1, "uud": "...", "activity": "...", "control": "..."}]}. Без markdown.',
        70
    );

-- Тарифные пакеты: 100/500/2000 токенов с возрастающим бонусом
INSERT INTO token_packages (name, description, tokens, bonus_tokens, price_rub, display_order) VALUES
    ('Стартовый',     'Попробовать генератор: 5-7 материалов в зависимости от типа.', 100,  0,   199.00, 10),
    ('Базовый',       'Месячная норма активного педагога. +50 бонусных токенов.',     500,  50,  899.00, 20),
    ('Профессионал',  'Для методистов и завучей. +300 бонусных токенов.',             2000, 300, 2990.00, 30);
