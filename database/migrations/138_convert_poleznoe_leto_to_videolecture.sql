-- 138: Конвертация вебинара «Полезное лето. Особый ребёнок» в видеолекцию (автовебинар).
-- Вебинар прошёл 03.06.2026. Добавляем запись, презентацию, тест.
-- Отдельная рассылка по всей базе users с magic-link на claim-страницу
-- (pages/autowebinar-claim.php) даёт авто-регистрацию + авто-зачёт теста + оформление диплома.

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- 1) Статус → videolecture, ссылки на запись и презентацию.
--    description дополняем идемпотентно (повторный накат не задублирует блок).
UPDATE webinars
SET status = 'videolecture',
    video_url = 'https://clck.ru/3TxwFa',
    materials_url = 'https://clck.ru/3Txvx4',
    description = CASE
        WHEN description NOT LIKE '%clck.ru/3TxwFa%'
        THEN CONCAT(description, '\n\n<h3>Запись и материалы</h3>\n<p>Смотрите <a href=\"https://clck.ru/3TxwFa\" target=\"_blank\">запись вебинара</a> и скачайте <a href=\"https://clck.ru/3Txvx4\" target=\"_blank\">презентацию эксперта</a> для работы.</p>')
        ELSE description
    END
WHERE slug = 'poleznoe-leto-osobyj-rebenok';

-- 2) Тест: 5 вопросов (на случай, если участник пройдёт его честно из кабинета видеолекции).
DELETE q FROM webinar_quiz_questions q
JOIN webinars w ON q.webinar_id = w.id
WHERE w.slug = 'poleznoe-leto-osobyj-rebenok';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Почему лето — это одновременно и возможность, и риск для развития особого ребёнка?',
    JSON_ARRAY(
        'Летом ребёнок обязательно теряет все навыки, и повлиять на это нельзя',
        'Без привычного режима и поддержки наработанное за год легко растерять, но при совместном планировании семьи и специалиста каникулы становятся ресурсом развития',
        'Лето не влияет на развитие ребёнка с особыми образовательными потребностями',
        'Развитием летом должны заниматься только родители, специалист в этот период не нужен'
    ),
    1, 1
FROM webinars w WHERE w.slug = 'poleznoe-leto-osobyj-rebenok';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Как специалисту выстраивать планирование летнего периода вместе с семьёй?',
    JSON_ARRAY(
        'Дать семье общие рекомендации «отдыхайте» и не вникать в детали',
        'Составить совместный реалистичный план с опорой на сильные стороны ребёнка, режим и посильные развивающие активности',
        'Полностью переложить планирование на родителей без обсуждения',
        'Требовать от семьи строго повторять школьную программу всё лето'
    ),
    1, 2
FROM webinars w WHERE w.slug = 'poleznoe-leto-osobyj-rebenok';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Какой приём помогает вовлечь даже «трудных» родителей, избегающих контакта?',
    JSON_ARRAY(
        'Общаться только в формате замечаний о проблемах ребёнка',
        'Начинать диалог с конкретных успехов ребёнка, говорить на языке семьи и предлагать небольшие посильные шаги',
        'Передавать всю информацию через администрацию, минуя родителей',
        'Прекратить попытки контакта, если родитель сразу не откликнулся'
    ),
    1, 3
FROM webinars w WHERE w.slug = 'poleznoe-leto-osobyj-rebenok';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Почему важно учитывать тип семьи при выстраивании взаимодействия?',
    JSON_ARRAY(
        'Тип семьи не имеет значения, со всеми нужно говорить одинаково',
        'Разные семьи по-разному воспринимают информацию и поддержку, и подбор подхода под конкретную семью повышает доверие и результат',
        'Чтобы разделить семьи на «удобных» и «неудобных» и работать только с первыми',
        'Чтобы переложить ответственность за развитие ребёнка целиком на семью'
    ),
    1, 4
FROM webinars w WHERE w.slug = 'poleznoe-leto-osobyj-rebenok';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Что отличает по-настоящему полезное лето для особого ребёнка?',
    JSON_ARRAY(
        'Полный отказ от любых занятий и режима ради отдыха',
        'Максимальная учебная нагрузка без пауз, чтобы «не отстать»',
        'Баланс отдыха и дозированных развивающих активностей в партнёрстве семьи и специалиста, с опорой на интересы ребёнка',
        'Изоляция ребёнка от новых впечатлений и общения'
    ),
    2, 5
FROM webinars w WHERE w.slug = 'poleznoe-leto-osobyj-rebenok';

-- 3) Глушим автовебинарную email-цепочку для уже существующих регистраций (52 реальных участника):
-- по ним идёт отдельная контролируемая рассылка с материалами и magic-link, дубли от cron не нужны.
-- Заранее проставленные 'skipped' (registration_id + touchpoint_id уникальны) не дают
-- AutowebinarEmailChain запланировать письма для этих регистраций.
INSERT IGNORE INTO autowebinar_email_log
    (registration_id, user_id, touchpoint_id, email, status, scheduled_at)
SELECT wr.id, wr.user_id, t.id, wr.email, 'skipped', NOW()
FROM webinar_registrations wr
JOIN webinars w ON wr.webinar_id = w.id
CROSS JOIN autowebinar_email_touchpoints t
WHERE w.slug = 'poleznoe-leto-osobyj-rebenok';

-- 4) Лог разовой рассылки по всей базе (отдельно от webinar_invitation_log,
-- который уже занят июньским invite-кампейном этого же вебинара).
CREATE TABLE IF NOT EXISTS autowebinar_recording_invite_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    webinar_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    sent_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    error TEXT NULL,
    unisender_id VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_webinar_user (webinar_id, user_id),
    INDEX idx_status_webinar (status, webinar_id),
    INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
