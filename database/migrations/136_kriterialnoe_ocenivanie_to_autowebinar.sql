-- 136: Превращение вебинара «Критериальное оценивание. 7 инструментов» (id=19) в автовебинар.
-- Эфир прошёл 21.05.2026 (213 регистраций). Запись разослана зарегистрированным 22.05.2026
-- (scripts/send_webinar_recording_to_registered.php, 195 отправлено): запись clck.ru/3Tm8iG,
-- презентация clck.ru/3Tm8kk. Теперь переводим в status='videolecture', чтобы запись была
-- доступна сразу после регистрации, заработала цепочка AutowebinarEmailChain и выдача сертификата.
-- Для сертификата автовебинару нужен тест — добавляем 5 вопросов по теме (idempotent через slug).

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- 1. Перевод в автовебинар + ссылки на запись и презентацию.
UPDATE webinars
SET
    status        = 'videolecture',
    video_url     = 'https://clck.ru/3Tm8iG',
    materials_url = 'https://clck.ru/3Tm8kk'
WHERE slug = 'kriterialnoe-ocenivanie-7-instrumentov';

-- 2. Тест из 5 вопросов. Чистим возможные прежние вопросы по этому вебинару, чтобы миграция была
--    идемпотентной и не плодила дубли при повторном накате.
DELETE q FROM webinar_quiz_questions q
JOIN webinars w ON w.id = q.webinar_id
WHERE w.slug = 'kriterialnoe-ocenivanie-7-instrumentov';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id, t.question_text, t.options, t.correct_option_index, t.display_order
FROM webinars w
JOIN (
    SELECT
        'В чём главный смысл критериального оценивания?' AS question_text,
        JSON_ARRAY(
            'Ставить оценки строже и реже',
            'Сделать оценку прозрачной, объективной и понятной ученику, учителю и родителю',
            'Заменить отметки словесной похвалой',
            'Оценивать только итоговые контрольные работы'
        ) AS options,
        1 AS correct_option_index,
        1 AS display_order
    UNION ALL SELECT
        'Что такое рубрикатор урока?',
        JSON_ARRAY(
            'Список тем для домашнего задания',
            'Журнал с отметками за четверть',
            'Описание уровней достижения результата по заранее заданным критериям',
            'Расписание звонков и перемен'
        ),
        2, 2
    UNION ALL SELECT
        'Зачем на уроке нужен чек-лист критериев?',
        JSON_ARRAY(
            'Чтобы за 30 секунд объяснить ученику, за что поставлена оценка',
            'Чтобы заменить им учебник',
            'Чтобы фиксировать опоздания учеников',
            'Чтобы вести учёт выданных учебников'
        ),
        0, 3
    UNION ALL SELECT
        'Что относится к приёмам самооценивания?',
        JSON_ARRAY(
            'Учитель выставляет отметку, не показывая критерии',
            'Ученик сам сверяет свою работу с критериями до выставления отметки учителем',
            'Родитель проверяет тетрадь дома',
            'Завуч проводит контрольный срез'
        ),
        1, 4
    UNION ALL SELECT
        'Как критериальное оценивание помогает снизить споры с родителями?',
        JSON_ARRAY(
            'Родителей не информируют об оценках',
            'Отметки выставляются только в конце года',
            'Критерии заданы заранее и одинаковы для всех — отметка перестаёт быть субъективной',
            'Все спорные работы автоматически пересдаются'
        ),
        2, 5
) AS t
WHERE w.slug = 'kriterialnoe-ocenivanie-7-instrumentov';
