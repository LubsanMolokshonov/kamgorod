-- 034: Конвертация вебинара «Читательские марафоны» в видеолекцию
-- Вебинар прошёл 25.02.2026. Добавляем запись, презентацию и тест.

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- Добавляем колонку для ссылки на материалы/презентацию
ALTER TABLE webinars ADD COLUMN materials_url VARCHAR(500) DEFAULT NULL AFTER video_url;

-- Обновляем вебинар: статус → videolecture, ссылки на запись и материалы
UPDATE webinars
SET status = 'videolecture',
    video_url = 'https://clck.ru/3S4HUn',
    materials_url = 'https://clck.ru/3S4HKT',
    description = CONCAT(description, '\n\n<h3>Полезные материалы</h3>\n<p>Скачайте <a href=\"https://clck.ru/3S4HKT\" target=\"_blank\">презентацию с полезными материалами</a> для использования в работе.</p>')
WHERE slug = 'vzaimodeystvie-s-semyami-vospitannikov-cherez-chitatelskie-marafony';

-- Тест: 5 вопросов

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Что является главной целью читательского марафона в образовательном учреждении?',
    '["Увеличение количества прочитанных книг за учебный год","Вовлечение семей в совместную читательскую деятельность с детьми","Подготовка детей к олимпиадам по литературе","Замена традиционных уроков чтения новым форматом"]',
    1, 1
FROM webinars w WHERE w.slug = 'vzaimodeystvie-s-semyami-vospitannikov-cherez-chitatelskie-marafony';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Какой формат взаимодействия с семьями наиболее эффективен при организации читательского марафона?',
    '["Педагог составляет список книг, родители контролируют чтение дома","Родители самостоятельно выбирают книги без участия педагога","Совместные задания для родителей и детей с обратной связью от педагога","Дети читают только в группе/классе под руководством воспитателя"]',
    2, 2
FROM webinars w WHERE w.slug = 'vzaimodeystvie-s-semyami-vospitannikov-cherez-chitatelskie-marafony';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Какой из перечисленных этапов является ключевым при подготовке читательского марафона?',
    '["Подбор произведений с учётом возрастных особенностей и интересов детей","Закупка большого количества новых книг для библиотеки","Проведение родительского собрания с обязательной явкой","Составление строгого графика чтения на каждый день"]',
    0, 3
FROM webinars w WHERE w.slug = 'vzaimodeystvie-s-semyami-vospitannikov-cherez-chitatelskie-marafony';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Каким образом читательские марафоны способствуют развитию ребёнка?',
    '["Учат ребёнка читать быстрее и больше","Заменяют необходимость посещения библиотеки","Развивают речь, воображение и формируют устойчивый интерес к чтению через совместную деятельность с семьёй","Готовят ребёнка исключительно к школьным контрольным по чтению"]',
    2, 4
FROM webinars w WHERE w.slug = 'vzaimodeystvie-s-semyami-vospitannikov-cherez-chitatelskie-marafony';

INSERT INTO webinar_quiz_questions (webinar_id, question_text, options, correct_option_index, display_order)
SELECT w.id,
    'Что помогает поддерживать мотивацию семей на протяжении всего читательского марафона?',
    '["Штрафы за невыполнение заданий в срок","Промежуточные творческие задания и публичное признание достижений участников","Ежедневные отчёты родителей перед педагогом","Соревнование между семьями с выбыванием отстающих"]',
    1, 5
FROM webinars w WHERE w.slug = 'vzaimodeystvie-s-semyami-vospitannikov-cherez-chitatelskie-marafony';
