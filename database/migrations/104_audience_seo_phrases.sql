-- Migration 104: SEO-фразы со склонениями и предлогами для предметов и уровней аудитории
-- Date: 2026-05-12
-- Purpose: динамические H1/title/description каталогов конкурсов, олимпиад, вебинаров
--          собирают «{свойства с предлогами}» вида «для школьников по математике 1-4 класса».
--          В audience_specializations.seo_phrase храним готовый фрагмент С предлогом
--          («по математике», «для логопедов»). В audience_types.seo_phrase — фрагмент уровня
--          без предлога («1-4 класса», «начальной школы»).

ALTER TABLE audience_specializations
    ADD COLUMN seo_phrase VARCHAR(255) NULL AFTER name;

ALTER TABLE audience_types
    ADD COLUMN seo_phrase VARCHAR(255) NULL AFTER name;

-- Предметы (specialization_type = 'subject') → «по X»
UPDATE audience_specializations SET seo_phrase = 'по английскому языку'             WHERE slug='angliiskiy-yazyk';
UPDATE audience_specializations SET seo_phrase = 'по биологии'                       WHERE slug='biologiya';
UPDATE audience_specializations SET seo_phrase = 'по экологическому воспитанию'      WHERE slug='ekologiya';
UPDATE audience_specializations SET seo_phrase = 'для экономических специальностей'  WHERE slug='ekonomicheskie';
UPDATE audience_specializations SET seo_phrase = 'по естественнонаучному направлению' WHERE slug='estestvennonauchnoe';
UPDATE audience_specializations SET seo_phrase = 'по физическому развитию и здоровью' WHERE slug='fizicheskoe-razvitie';
UPDATE audience_specializations SET seo_phrase = 'по физике'                         WHERE slug='fizika';
UPDATE audience_specializations SET seo_phrase = 'по физической культуре'            WHERE slug='fizkultura';
UPDATE audience_specializations SET seo_phrase = 'по физической культуре и спорту'   WHERE slug='fizkultura-sport';
UPDATE audience_specializations SET seo_phrase = 'по географии'                      WHERE slug='geografiya';
UPDATE audience_specializations SET seo_phrase = 'для гуманитарных специальностей'   WHERE slug='gumanitarnye';
UPDATE audience_specializations SET seo_phrase = 'по химии'                          WHERE slug='himiya';
UPDATE audience_specializations SET seo_phrase = 'по хореографии и танцам'           WHERE slug='horeografiya-tantsy';
UPDATE audience_specializations SET seo_phrase = 'по информатике и ИКТ'              WHERE slug='informatika';
UPDATE audience_specializations SET seo_phrase = 'по иностранным языкам'             WHERE slug='inostrannye-yazyki';
UPDATE audience_specializations SET seo_phrase = 'по истории'                        WHERE slug='istoriya';
UPDATE audience_specializations SET seo_phrase = 'по IT и программированию'          WHERE slug='it-programmirovanie';
UPDATE audience_specializations SET seo_phrase = 'по изобразительному искусству'     WHERE slug='izo';
UPDATE audience_specializations SET seo_phrase = 'по ИЗО и декоративно-прикладному искусству' WHERE slug='izo-dpi';
UPDATE audience_specializations SET seo_phrase = 'по литературному чтению'           WHERE slug='literatura-chtenie';
UPDATE audience_specializations SET seo_phrase = 'по математике'                     WHERE slug='matematika';
UPDATE audience_specializations SET seo_phrase = 'по математике (алгебре, геометрии)' WHERE slug='matematika-algebra-geometriya';
UPDATE audience_specializations SET seo_phrase = 'по математике и логическому мышлению' WHERE slug='matematika-logika';
UPDATE audience_specializations SET seo_phrase = 'для медицинских специальностей'    WHERE slug='medicinskiye';
UPDATE audience_specializations SET seo_phrase = 'по музыке'                         WHERE slug='muzyka';
UPDATE audience_specializations SET seo_phrase = 'по музыке и МХК'                   WHERE slug='muzyka-mhk';
UPDATE audience_specializations SET seo_phrase = 'по музыке и танцам'                WHERE slug='muzyka-tanec';
UPDATE audience_specializations SET seo_phrase = 'по музыке и вокалу'                WHERE slug='muzyka-vokal';
UPDATE audience_specializations SET seo_phrase = 'по общеобразовательным дисциплинам' WHERE slug='obshcheobrazovatelnye';
UPDATE audience_specializations SET seo_phrase = 'по обществознанию'                 WHERE slug='obshchestvoznanie';
UPDATE audience_specializations SET seo_phrase = 'по ОБЖ'                            WHERE slug='obzh';
UPDATE audience_specializations SET seo_phrase = 'по окружающему миру'               WHERE slug='okruzhayushchiy-mir';
UPDATE audience_specializations SET seo_phrase = 'для педагогических специальностей' WHERE slug='pedagogicheskie';
UPDATE audience_specializations SET seo_phrase = 'по развитию речи и коммуникации'   WHERE slug='razvitie-rechi';
UPDATE audience_specializations SET seo_phrase = 'по робототехнике и IT'             WHERE slug='robototehnika-it';
UPDATE audience_specializations SET seo_phrase = 'по русскому языку'                 WHERE slug='russkiy-yazyk';
UPDATE audience_specializations SET seo_phrase = 'по русскому языку и литературе'    WHERE slug='russkiy-yazyk-literatura';
UPDATE audience_specializations SET seo_phrase = 'по социально-гуманитарному направлению' WHERE slug='socialno-gumanitarnoe';
UPDATE audience_specializations SET seo_phrase = 'по социально-коммуникативному развитию' WHERE slug='socialno-kommunikativnoe';
UPDATE audience_specializations SET seo_phrase = 'по спорту и физкультуре'           WHERE slug='sport-fizkultura-do';
UPDATE audience_specializations SET seo_phrase = 'по театральному искусству'         WHERE slug='teatralnoe-iskusstvo';
UPDATE audience_specializations SET seo_phrase = 'для технических специальностей'    WHERE slug='tehnicheskie';
UPDATE audience_specializations SET seo_phrase = 'по технологии'                     WHERE slug IN ('tehnologiya','tehnologiya-trud');
UPDATE audience_specializations SET seo_phrase = 'по туризму и краеведению'          WHERE slug='turizm-kraevedenie';
UPDATE audience_specializations SET seo_phrase = 'по художественному творчеству'     WHERE slug='tvorchestvo';

-- Роли (specialization_type = 'role') → «для X в род. падеже мн. ч.»
UPDATE audience_specializations SET seo_phrase = 'для администрации и управленцев'   WHERE slug='administratsiya-upravlenie';
UPDATE audience_specializations SET seo_phrase = 'для библиотекарей'                 WHERE slug='bibliotekar';
UPDATE audience_specializations SET seo_phrase = 'для дефектологов'                  WHERE slug='defektologiya';
UPDATE audience_specializations SET seo_phrase = 'для инструкторов по физкультуре'   WHERE slug='instruktor-fizkultura';
UPDATE audience_specializations SET seo_phrase = 'по классному руководству'          WHERE slug='klassnoe-rukovodstvo';
UPDATE audience_specializations SET seo_phrase = 'для логопедов'                     WHERE slug='logopediya';
UPDATE audience_specializations SET seo_phrase = 'для методистов'                    WHERE slug='metodist';
UPDATE audience_specializations SET seo_phrase = 'для младших воспитателей'          WHERE slug='mladshiy-vospitatel';
UPDATE audience_specializations SET seo_phrase = 'для педагогов дополнительного образования' WHERE slug='pedagog-do';
UPDATE audience_specializations SET seo_phrase = 'для педагогов-организаторов'       WHERE slug='pedagog-organizator';
UPDATE audience_specializations SET seo_phrase = 'для педагогов-психологов'          WHERE slug='pedagog-psiholog';
UPDATE audience_specializations SET seo_phrase = 'по работе с детьми с ОВЗ'          WHERE slug='rabota-s-ovz';
UPDATE audience_specializations SET seo_phrase = 'по социальной педагогике'          WHERE slug='socialnaya-pedagogika';
UPDATE audience_specializations SET seo_phrase = 'для старших воспитателей'          WHERE slug='starshiy-vospitatel';
UPDATE audience_specializations SET seo_phrase = 'по тьюторству'                     WHERE slug='tyutorstvo';
UPDATE audience_specializations SET seo_phrase = 'для учителей'                      WHERE slug='uchitel';
UPDATE audience_specializations SET seo_phrase = 'для воспитателей'                  WHERE slug='vospitatel';
UPDATE audience_specializations SET seo_phrase = 'для воспитателей ГПД'              WHERE slug='vospitatel-gpd';

-- Уровни аудитории — короткий фрагмент без предлога, продолжающий фразу
UPDATE audience_types SET seo_phrase = '1-4 классов'                  WHERE slug='1-4-klassy';
UPDATE audience_types SET seo_phrase = '5-8 классов'                  WHERE slug='5-8-klassy';
UPDATE audience_types SET seo_phrase = '9-11 классов'                 WHERE slug='9-11-klassy';
UPDATE audience_types SET seo_phrase = 'в дополнительном образовании' WHERE slug='dopolnitelnoe-obrazovanie';
UPDATE audience_types SET seo_phrase = '3-7 лет'                      WHERE slug='doshkolniki';
UPDATE audience_types SET seo_phrase = 'в ДОУ'                        WHERE slug='dou';
UPDATE audience_types SET seo_phrase = 'начальной школы'              WHERE slug='nachalnaya-shkola';
UPDATE audience_types SET seo_phrase = 'СПО'                          WHERE slug='spo';
UPDATE audience_types SET seo_phrase = 'средней и старшей школы'      WHERE slug='srednyaya-starshaya-shkola';
UPDATE audience_types SET seo_phrase = 'СПО'                          WHERE slug='studenty-spo';
