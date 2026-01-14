-- Fix Competition Specializations
-- Исправление связей конкурсов со специализациями

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Очистка существующих связей конкурсов со специализациями
TRUNCATE TABLE competition_specializations;

-- ДОУ конкурсы
INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'razvitie-rechi'
WHERE c.slug = 'innovacii-razvitie-rechi-dou';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'tvorchestvo'
WHERE c.slug = 'malenkie-hudozhniki-dou';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'matematika-logika'
WHERE c.slug = 'matematika-dlya-malyshey-dou';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'fizicheskoe-razvitie'
WHERE c.slug = 'zdorovyy-doshkolnik';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'muzyka-tanec'
WHERE c.slug = 'muzykalnaya-palitra-dou';

-- Начальная школа конкурсы
INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'russkiy-yazyk'
WHERE c.slug = 'pervye-shagi-gramota';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'matematika'
WHERE c.slug = 'zanimatelnaya-matematika-nachalnaya';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'okruzhayushchiy-mir'
WHERE c.slug = 'mir-vokrug-nas-nachalnaya';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'angliiskiy-yazyk'
WHERE c.slug = 'kaleydoskop-vneurochnoy';

-- Средняя и старшая школа - по предметам
INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'russkiy-yazyk-literatura'
WHERE c.slug = 'masterskaya-slovesnika';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'matematika-algebra-geometriya'
WHERE c.slug = 'matematicheskaya-vertikal';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'informatika'
WHERE c.slug = 'cifrovaya-shkola-it';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'fizika'
WHERE c.slug = 'fizika-vokrug-nas';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'himiya'
WHERE c.slug = 'himiya-v-deystvii';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'biologiya'
WHERE c.slug = 'biologiya-issleduem';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'geografiya'
WHERE c.slug = 'geografiya-bez-granic';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'istoriya'
WHERE c.slug = 'uroki-istorii';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'obshchestvoznanie'
WHERE c.slug = 'obshchestvoznanie-aktualnye';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'inostrannye-yazyki'
WHERE c.slug = 'english-excellence';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'inostrannye-yazyki'
WHERE c.slug = 'mir-inostrannyh-yazykov';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'fizkultura-sport'
WHERE c.slug = 'sport-i-zdorove';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'tehnologiya-trud'
WHERE c.slug = 'tehnologiya-budushchego';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'obzh'
WHERE c.slug = 'osnovy-bezopasnosti';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'muzyka-mhk'
WHERE c.slug = 'mhk-v-shkole';

-- СПО конкурсы
INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'obshcheobrazovatelnye'
WHERE c.slug = 'obshcheobrazovatelnaya-podgotovka-spo';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'tehnicheskie'
WHERE c.slug = 'inzhenernaya-podgotovka-spo';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'it-programmirovanie'
WHERE c.slug = 'it-obrazovanie-spo';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'pedagogicheskie'
WHERE c.slug = 'pedagogicheskoe-masterstvo-spo';

INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT c.id, s.id
FROM competitions c
JOIN audience_specializations s ON s.slug = 'medicinskiye'
WHERE c.slug = 'medicinskoe-obrazovanie-spo';

SET FOREIGN_KEY_CHECKS = 1;

-- Проверка результатов
SELECT 'Связей конкурсов со специализациями:' as info, COUNT(*) as count
FROM competition_specializations;
