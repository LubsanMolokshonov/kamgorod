-- Migration 179: цены 5 курсов ПП «под вопросом» из таблицы 'ПП обновленные цены от 08.09.26.xlsx'.
--   Только локально. Пользователь подтвердил итоговые цены (меньший вариант из двух в таблице).
--   Цена из таблицы = ИТОГОВАЯ (после фикс-скидки ПП 10%). В БД пишем base = round(итог / 0.9),
--   тогда getAdjustedPrice(base,'D','pp') = round(base*0.9) = итог. Round-trip сверен PHP round().
--   Матч по УНИКАЛЬНОМУ slug + program_type='pp' + guard price=<текущий снимок> (идемпотентно).
--   Трогается ТОЛЬКО courses.price 5 строк. Ни связей, ни config, ни других курсов.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Музыкальное образование в ДОО (ФГОС ДО): итог 21465 -> 23850 (base 23850 -> 26500)
UPDATE courses SET price = 26500.00
  WHERE slug = 'muzykalnoe-obrazovanie-v-doshkolnoy-obrazovatelnoy-organizatsii-v-usloviyah-realizatsii-fgos-doshkolnogo-obrazovaniya'
    AND program_type = 'pp' AND price = 23850.00;

-- Педагогика и методика физической культуры и спорта. Тренер-преподаватель: итог 21465 -> 23850 (base 23850 -> 26500)
UPDATE courses SET price = 26500.00
  WHERE slug = 'pedagogika-i-metodika-fizicheskoy-kultury-i-sporta-trener-prepodavatel'
    AND program_type = 'pp' AND price = 23850.00;

-- Методика и содержание деятельности в сфере физической культуры. Физ. культура в ДОО (ФГОС): итог 21465 -> 23850 (base 23850 -> 26500)
UPDATE courses SET price = 26500.00
  WHERE slug = 'metodika-i-soderzhanie-deyatelnosti-v-sfere-fizicheskoy-kultury-fizicheskaya-kultura-v-doshkolnyh-obrazovatelnyh-organizatsiyah-v-usloviyah-realizatsii-fgos'
    AND program_type = 'pp' AND price = 23850.00;

-- Деятельность старшего воспитателя (ФГОС ДО): итог 22545 -> 25050 (base 25050 -> 27833)
UPDATE courses SET price = 27833.00
  WHERE slug = 'deyatelnost-starshego-vospitatelya-v-usloviyah-realizatsii-federalnogo-gosudarstvennogo-obrazovatelnogo-standarta-doshkolnogo-obrazovaniya'
    AND program_type = 'pp' AND price = 25050.00;

-- Дошкольная педагогика и психология. Мл. воспитатель ДОУ (ФГОС): итог 9315 -> 10350 (base 10350 -> 11500)
UPDATE courses SET price = 11500.00
  WHERE slug = 'doshkolnaya-pedagogika-i-psihologiya-psihologo-pedagogicheskaya-deyatelnost-mladshego-vospitatelya-dou-v-usloviyah-realizatsii-fgos'
    AND program_type = 'pp' AND price = 10350.00;
