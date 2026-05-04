-- 096: Конвертация вебинара «Перезагрузка отношений с родителями» в видеолекцию
-- Вебинар прошёл 23.04.2026. Добавляем запись, презентацию и тест.

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- Обновляем вебинар: статус → videolecture, ссылки на запись и материалы
UPDATE webinars
SET status = 'videolecture',
    video_url = 'https://clck.ru/3TF7jR',
    materials_url = 'https://clck.ru/3TEyCL',
    description = CONCAT(description, '\n\n<h3>Запись и материалы</h3>\n<p>Скачайте <a href=\"https://clck.ru/3TEyCL\" target=\"_blank\">презентацию и полезные материалы от эксперта</a> для использования в работе.</p>')
WHERE slug = 'perezagruzka-otnoshenij-s-roditelyami';

-- Тест: 5 вопросов

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Какая стратегия наиболее эффективна для предотвращения конфликтов с родителями учеников?',
    '["Свести общение с родителями к минимуму и взаимодействовать только через электронный дневник","Заранее выстраивать партнёрский диалог, выяснять ожидания родителей и регулярно давать конструктивную обратную связь","Решать все спорные вопросы только через администрацию школы","Отвечать на претензии родителей в том же эмоциональном тоне, чтобы показать свою позицию"]',
    1, 1
FROM webinars w WHERE w.slug = 'perezagruzka-otnoshenij-s-roditelyami';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'В чём суть техники активного слушания при общении с родителем?',
    '["Молча выслушать родителя и сразу перейти к своему ответу","Полностью соглашаться со всеми претензиями родителя, чтобы избежать конфликта","Перефразировать слова родителя, уточнять детали и отражать его чувства, демонстрируя понимание","Записывать сказанное родителем для последующего отчёта администрации"]',
    2, 2
FROM webinars w WHERE w.slug = 'perezagruzka-otnoshenij-s-roditelyami';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Какой ключевой принцип лежит в основе ненасильственного общения (ННО) с родителями?',
    '["Использование жёстких аргументов и ссылок на нормативные документы","Описание фактов и собственных чувств без обвинений и оценок личности собеседника","Избегание любых сложных тем в разговоре с родителями","Передача всей негативной информации только в письменном виде"]',
    1, 3
FROM webinars w WHERE w.slug = 'perezagruzka-otnoshenij-s-roditelyami';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Как правильно реагировать на гневное сообщение родителя в общем чате?',
    '["Ответить публично в эмоциональном тоне, чтобы другие родители видели вашу позицию","Удалить сообщение и заблокировать родителя в чате","Сделать паузу, перевести разговор в личный канал и предложить встречу для обсуждения по существу","Игнорировать сообщение в надежде, что ситуация разрешится сама"]',
    2, 4
FROM webinars w WHERE w.slug = 'perezagruzka-otnoshenij-s-roditelyami';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Что означает переход от позиции «против» к позиции «вместе» во взаимодействии с родителями?',
    '["Педагог уступает родителям во всех спорных вопросах ради сохранения отношений","Педагог и родители рассматриваются как единая команда, работающая на благо ребёнка с общими целями","Все решения по обучению ребёнка принимаются исключительно родителями","Педагог занимает нейтральную позицию и не вмешивается в воспитательные процессы семьи"]',
    1, 5
FROM webinars w WHERE w.slug = 'perezagruzka-otnoshenij-s-roditelyami';
