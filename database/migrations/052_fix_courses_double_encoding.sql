-- Исправление двойной кодировки UTF-8 для курсов 64, 65, 66
-- Данные были вставлены с интерпретацией UTF-8 байтов как Latin1

UPDATE courses
SET title = CONVERT(CAST(CONVERT(title USING latin1) AS BINARY) USING utf8mb4),
    description = CONVERT(CAST(CONVERT(description USING latin1) AS BINARY) USING utf8mb4),
    target_audience_text = CONVERT(CAST(CONVERT(target_audience_text USING latin1) AS BINARY) USING utf8mb4),
    course_group = CONVERT(CAST(CONVERT(course_group USING latin1) AS BINARY) USING utf8mb4),
    modules_json = CAST(CONVERT(CAST(CONVERT(modules_json USING latin1) AS BINARY) USING utf8mb4) AS JSON),
    outcomes_json = CAST(CONVERT(CAST(CONVERT(outcomes_json USING latin1) AS BINARY) USING utf8mb4) AS JSON)
WHERE id IN (64, 65, 66);
