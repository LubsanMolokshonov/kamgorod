-- 023: Создание таблиц для теста автовебинара + seed вопросов
-- Таблица вопросов теста

CREATE TABLE IF NOT EXISTS webinar_quiz_questions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    webinar_id INT UNSIGNED NOT NULL,
    question_text VARCHAR(500) NOT NULL COMMENT 'Текст вопроса',
    options JSON NOT NULL COMMENT '["вариант1","вариант2","вариант3","вариант4"]',
    correct_option_index TINYINT UNSIGNED NOT NULL COMMENT '0-based индекс правильного ответа',
    display_order TINYINT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_webinar (webinar_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица результатов прохождения теста

CREATE TABLE IF NOT EXISTS webinar_quiz_results (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    webinar_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    registration_id INT UNSIGNED NOT NULL,
    score TINYINT UNSIGNED NOT NULL COMMENT 'Количество правильных ответов',
    total_questions TINYINT UNSIGNED NOT NULL DEFAULT 5,
    passed TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 если score >= 4',
    answers JSON NOT NULL COMMENT '{"question_id": selected_index, ...}',
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_webinar_user (webinar_id, user_id),
    INDEX idx_registration (registration_id),
    INDEX idx_passed (passed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Обновляем существующий вебинар: статус автовебинар + ссылка на запись

UPDATE webinars SET status = 'autowebinar', video_url = 'https://clck.ru/3RmQ2D'
WHERE slug = 'razgovory-o-vazhnom-bez-zevoty';

-- Seed: 5 вопросов для автовебинара "Разговоры о важном без зевоты"

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Какой основной подход помогает сделать классные часы «Разговоры о важном» интересными для учеников?',
    '["Строго зачитывать текст из методических рекомендаций","Использовать интерактивные формы работы и связь с реальной жизнью учеников","Показывать только видеоролики без обсуждения","Проводить занятие в форме контрольной работы"]',
    1, 1
FROM webinars w WHERE w.slug = 'razgovory-o-vazhnom-bez-zevoty';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Какой формат проведения «Разговоров о важном» наиболее эффективен?',
    '["Монолог учителя в течение 45 минут","Письменный тест по теме занятия","Дискуссия и диалог с учениками","Просмотр документального фильма без обсуждения"]',
    2, 2
FROM webinars w WHERE w.slug = 'razgovory-o-vazhnom-bez-zevoty';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Что является ключевым элементом успешного классного часа?',
    '["Строгое соблюдение тайминга без вопросов","Использование только печатных материалов","Оценивание учеников за активность","Учёт возрастных особенностей и интересов учеников"]',
    3, 3
FROM webinars w WHERE w.slug = 'razgovory-o-vazhnom-bez-zevoty';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Какой сертификат можно получить после прохождения автовебинара?',
    '["Диплом о переподготовке","Аттестат о повышении квалификации","Сертификат участника вебинара на 2 часа","Удостоверение о присвоении категории"]',
    2, 4
FROM webinars w WHERE w.slug = 'razgovory-o-vazhnom-bez-zevoty';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Как поддержать интерес учеников к тематике «Разговоров о важном» на протяжении всего учебного года?',
    '["Повторять одни и те же темы каждую неделю","Пропускать занятия, если тема кажется неинтересной","Заменять классные часы на дополнительные уроки","Варьировать формы работы и привлекать учеников к планированию"]',
    3, 5
FROM webinars w WHERE w.slug = 'razgovory-o-vazhnom-bez-zevoty';
