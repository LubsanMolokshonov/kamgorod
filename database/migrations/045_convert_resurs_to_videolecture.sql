-- 045: Конвертация вебинара «Как сохранить ресурс» в видеолекцию
-- Вебинар прошёл 05.03.2026. Добавляем запись, презентацию и тест.

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- Обновляем вебинар: статус → videolecture, ссылки на запись и материалы
UPDATE webinars
SET status = 'videolecture',
    video_url = 'https://clck.ru/3SM2uX',
    materials_url = 'https://clck.ru/3SGVsi',
    description = CONCAT(description, '\n\n<h3>Полезные материалы</h3>\n<p>Скачайте <a href=\"https://clck.ru/3SGVsi\" target=\"_blank\">презентацию и полезные материалы от эксперта</a> для использования в работе.</p>')
WHERE slug = 'kak-sokhranit-resurs';

-- Тест: 5 вопросов

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Что является основной причиной профессионального выгорания педагогов?',
    '["Недостаточная заработная плата","Постоянная перегрузка без восстановления ресурсов и отсутствие баланса между нагрузкой и возможностями","Работа с трудными учениками","Частая смена образовательных стандартов"]',
    1, 1
FROM webinars w WHERE w.slug = 'kak-sokhranit-resurs';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Какой метод наиболее эффективен для управления рабочим временем педагога?',
    '["Выполнять все задачи по мере их поступления","Приоритетизация задач и планирование с учётом личных ресурсов и сроков","Работать сверхурочно, чтобы успеть всё","Делегировать все задачи коллегам"]',
    1, 2
FROM webinars w WHERE w.slug = 'kak-sokhranit-resurs';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Какой подход помогает сохранить качество работы при росте требований?',
    '["Игнорировать новые требования и работать по-старому","Увеличивать количество рабочих часов до максимума","Оптимизация рабочих процессов и грамотное распределение задач с использованием доступных ресурсов","Снизить стандарты качества, чтобы успевать больше"]',
    2, 3
FROM webinars w WHERE w.slug = 'kak-sokhranit-resurs';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Для чего педагогу необходимы навыки саморефлексии?',
    '["Чтобы критиковать свою работу и работу коллег","Чтобы вовремя замечать признаки истощения ресурсов и корректировать рабочие подходы","Чтобы составлять отчёты для администрации","Чтобы сравнивать себя с другими педагогами"]',
    1, 4
FROM webinars w WHERE w.slug = 'kak-sokhranit-resurs';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Какую роль играет командная работа в сохранении профессионального ресурса педагога?',
    '["Командная работа только увеличивает нагрузку на педагога","Эффективное взаимодействие с коллегами позволяет распределить нагрузку и получить поддержку","Каждый педагог должен справляться с трудностями самостоятельно","Командная работа важна только для руководителей"]',
    1, 5
FROM webinars w WHERE w.slug = 'kak-sokhranit-resurs';
