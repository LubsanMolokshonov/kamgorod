-- 039: Fix double-encoded UTF-8 in olympiad data
-- При импорте seed-данных без SET NAMES utf8mb4 русский текст получил
-- двойную кодировку (UTF-8 байты были интерпретированы как Latin1).
-- Этот скрипт конвертирует все текстовые поля обратно.
--
-- ВАЖНО: Запускать только ОДИН раз! Повторный запуск испортит данные.

SET NAMES utf8mb4;

-- Исправляем таблицу olympiads (все текстовые поля)
UPDATE olympiads SET
    title = CONVERT(CAST(CONVERT(title USING latin1) AS BINARY) USING utf8mb4),
    description = CONVERT(CAST(CONVERT(description USING latin1) AS BINARY) USING utf8mb4),
    seo_content = CONVERT(CAST(CONVERT(seo_content USING latin1) AS BINARY) USING utf8mb4),
    subject = CONVERT(CAST(CONVERT(subject USING latin1) AS BINARY) USING utf8mb4)
WHERE title LIKE '%Ð%';

-- Исправляем таблицу olympiad_questions (текст вопросов и JSON-варианты)
UPDATE olympiad_questions SET
    question_text = CONVERT(CAST(CONVERT(question_text USING latin1) AS BINARY) USING utf8mb4),
    options = CONVERT(CAST(CONVERT(options USING latin1) AS BINARY) USING utf8mb4)
WHERE question_text LIKE '%Ð%';
