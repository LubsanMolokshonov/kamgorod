-- 117: Конвертация вебинара «Особый ребёнок — не проблема: 10 шагов» в видеолекцию
-- Вебинар прошёл 13.05.2026. Добавляем запись, презентацию и тест.

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- Обновляем вебинар: статус → videolecture, ссылки на запись и материалы
UPDATE webinars
SET status = 'videolecture',
    video_url = 'https://clck.ru/3TdXMc',
    materials_url = 'https://clck.ru/3TdXRD',
    description = CONCAT(description, '\n\n<h3>Запись и материалы</h3>\n<p>Скачайте <a href=\"https://clck.ru/3TdXRD\" target=\"_blank\">презентацию с полезными материалами от эксперта</a> для использования в работе.</p>')
WHERE slug = 'osobyj-rebenok-10-shagov';

-- Тест: 5 вопросов

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Что в первую очередь важно сделать воспитателю, когда в группе появляется ребёнок с особыми образовательными потребностями?',
    '["Сразу настоять на переводе ребёнка в специализированное учреждение","Изучить рекомендации специалистов, понять особенности ребёнка и создать для него принимающую, безопасную среду","Не обращать особого внимания и работать с ним строго так же, как со всеми остальными детьми","Сообщить родителям, что группа не подходит для их ребёнка"]',
    1, 1
FROM webinars w WHERE w.slug = 'osobyj-rebenok-10-shagov';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'В чём суть принципа индивидуального подхода в работе с особым ребёнком?',
    '["Предъявлять к ребёнку точно такие же требования и темп, как ко всей группе","Освобождать ребёнка от любых заданий, чтобы не создавать нагрузку","Опираться на сильные стороны ребёнка, дозировать нагрузку и адаптировать задания под его актуальные возможности","Заниматься с ребёнком только отдельно от группы, исключая совместную деятельность"]',
    2, 2
FROM webinars w WHERE w.slug = 'osobyj-rebenok-10-shagov';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Как лучше всего выстраивать взаимодействие с родителями особого ребёнка?',
    '["Общаться только при возникновении проблем и нарушений поведения","Выстраивать партнёрский диалог: регулярно делиться наблюдениями, отмечать успехи и согласовывать единые подходы","Передавать всю информацию исключительно через администрацию детского сада","Избегать обсуждения трудностей, чтобы не расстраивать родителей"]',
    1, 3
FROM webinars w WHERE w.slug = 'osobyj-rebenok-10-shagov';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Какую роль играют специалисты сопровождения (психолог, логопед, дефектолог) в поддержке особого ребёнка?',
    '["Полностью заменяют воспитателя в работе с этим ребёнком","Работают с ребёнком изолированно, не взаимодействуя с воспитателем","Составляют рекомендации и вместе с воспитателем выстраивают единый маршрут психолого-педагогического сопровождения","Подключаются только после официального заключения о невозможности обучения ребёнка"]',
    2, 4
FROM webinars w WHERE w.slug = 'osobyj-rebenok-10-shagov';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Как помочь другим детям группы принять сверстника с особенностями развития?',
    '["Не объяснять ничего, считая, что дети сами разберутся","Формировать культуру принятия через личный пример, совместные игры и доброжелательное обсуждение различий между людьми","Постоянно подчёркивать, что этот ребёнок не такой, как все","Ограничить контакты особого ребёнка с группой, чтобы избежать вопросов"]',
    1, 5
FROM webinars w WHERE w.slug = 'osobyj-rebenok-10-shagov';

-- Глушим автовебинарную email-цепочку для 96 уже зарегистрированных участников:
-- им рассылка с записью вебинара уже была отправлена вручную.
-- Заранее проставленные записи 'skipped' (registration_id + touchpoint_id уникальны)
-- не дают AutowebinarEmailChain запланировать письма для этих регистраций.
-- Новые регистрации после конвертации проходят цепочку штатно.
INSERT IGNORE INTO autowebinar_email_log
    (registration_id, user_id, touchpoint_id, email, status, scheduled_at)
SELECT wr.id, wr.user_id, t.id, wr.email, 'skipped', NOW()
FROM webinar_registrations wr
JOIN webinars w ON wr.webinar_id = w.id
CROSS JOIN autowebinar_email_touchpoints t
WHERE w.slug = 'osobyj-rebenok-10-shagov';
