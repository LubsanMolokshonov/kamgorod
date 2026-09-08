-- Migration 176: обновление цен 14 курсов ПП по таблице 'ПП обновленные цены от 08.09.26.xlsx'.
--   Замена нерабочей 173_update_pp_prices_20260908.sql (та матчила по числовому id — на проде
--   id другие из-за авто-инкремента, часть UPDATE'ов молча промахивалась).
--
--   Цена в таблице = ИТОГОВАЯ (что видит покупатель, после скидки ПП 10%).
--   В БД пишем base = round(итог / 0.9); getAdjustedPrice(base, ..., 'pp') = round(base*0.9) = итог.
--   PHP round() round-trip проверен на проде для всех 14 значений — сходится в обе стороны.
--
--   Матчинг: WHERE slug = '<уникальный slug>' AND program_type = 'pp' AND price = <текущая прод-цена>.
--   Тройное условие исключает попадание в чужой курс; guard по price делает миграцию идемпотентной
--   (после успешного прогона цена уже новая -> повторный UPDATE ничего не сматчит).
--   Трогается ТОЛЬКО courses.price у 14 строк. Ни DELETE, ни связи, ни config.
--
--   НЕ входят (вне scope этой правки):
--     id 68  pedagog-psiholog-psiholog-v-sfere-obrazovaniya    — старый курс, в таблице спорная цена;
--     id 83-87 (муз. образование ДОУ, тренер, физ-ра ДОУ, мл./ст. воспитатель) — в таблице две цены на курс.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Начальное общее образование: итог 21465 -> 18150
UPDATE courses SET price = 20167.00
  WHERE slug = 'pedagogicheskoe-obrazovanie-nachalnoe-obschee-obrazovanie-v-usloviyah-realizatsii-fgos'
    AND program_type = 'pp' AND price = 23850.00;

-- Музыка в условиях реализации ФГОС: итог 21465 -> 23850
UPDATE courses SET price = 26500.00
  WHERE slug = 'pedagogicheskoe-obrazovanie-muzyka-v-usloviyah-realizatsii-fgos'
    AND program_type = 'pp' AND price = 23850.00;

-- Советник директора по воспитанию: итог 14130 -> 15700
UPDATE courses SET price = 17444.00
  WHERE slug = 'pedagogicheskaya-deyatelnost-sovetnika-direktora-po-vospitaniyu-i-vzaimodeystviyu-s-detskimi-obschestvennymi-obedineniyami-v-obrazovatelnoy-organizatsii'
    AND program_type = 'pp' AND price = 15700.00;

-- Педагог предшкольной подготовки: итог 16335 -> 18150
UPDATE courses SET price = 20167.00
  WHERE slug = 'pedagog-predshkolnoy-podgotovki'
    AND program_type = 'pp' AND price = 18150.00;

-- ОБЗР ООО, СОО: итог 47700 -> 23850
UPDATE courses SET price = 26500.00
  WHERE slug = 'pedagogicheskoe-obrazovanie-osnovy-bezopasnosti-i-zaschity-rodiny-v-usloviyah-realizatsii-fgos-ooo-soo'
    AND program_type = 'pp' AND price = 53000.00;

-- Физика ООО, СОО: итог 47700 -> 23850
UPDATE courses SET price = 26500.00
  WHERE slug = 'pedagogicheskoe-obrazovanie-fizika-v-usloviyah-realizatsii-fgos-ooo-soo'
    AND program_type = 'pp' AND price = 53000.00;

-- Педагог-организатор: итог 36300 -> 18150
UPDATE courses SET price = 20167.00
  WHERE slug = 'pedagogicheskoe-obrazovanie-pedagog-organizator'
    AND program_type = 'pp' AND price = 40333.00;

-- Дефектолог. Дети с ОВЗ (1180 ч): итог 33100 -> 65000 (ДОРОЖАЕТ)
UPDATE courses SET price = 72222.00
  WHERE slug = 'spetsialnoe-defektologicheskoe-obrazovanie-rabota-uchitelya-defektologa-s-detmi-s-ovz-doshkolnogo-i-shkolnogo-vozrastov'
    AND program_type = 'pp' AND price = 36778.00;

-- Педагог-библиотекарь: итог 36300 -> 18150
UPDATE courses SET price = 20167.00
  WHERE slug = 'pedagogicheskoe-obrazovanie-pedagog-bibliotekar-v-sovremennom-obrazovatelnom-prostranstve'
    AND program_type = 'pp' AND price = 40333.00;

-- Химия ООО, СОО: итог 47700 -> 23850
UPDATE courses SET price = 26500.00
  WHERE slug = 'pedagogicheskoe-obrazovanie-himiya-v-usloviyah-realizatsii-fgos-ooo-soo'
    AND program_type = 'pp' AND price = 53000.00;

-- Информатика ООО, СОО: итог 47700 -> 23850
UPDATE courses SET price = 26500.00
  WHERE slug = 'pedagogicheskoe-obrazovanie-informatika-v-usloviyah-realizatsii-fgos-ooo-soo'
    AND program_type = 'pp' AND price = 53000.00;

-- Биология ООО, СОО: итог 47700 -> 23850
UPDATE courses SET price = 26500.00
  WHERE slug = 'pedagogicheskoe-obrazovanie-biologiya-v-usloviyah-realizatsii-fgos-ooo-soo'
    AND program_type = 'pp' AND price = 53000.00;

-- Физическая культура ООО, СОО: итог 47700 -> 23850
UPDATE courses SET price = 26500.00
  WHERE slug = 'pedagogicheskoe-obrazovanie-fizicheskaya-kultura-v-usloviyah-realizatsii-fgos-ooo-soo'
    AND program_type = 'pp' AND price = 53000.00;

-- Воспитатель группы продлённого дня: итог 36300 -> 18150
UPDATE courses SET price = 20167.00
  WHERE slug = 'pedagogicheskoe-obrazovanie-vospitatel-gruppy-prodlennogo-dnya'
    AND program_type = 'pp' AND price = 40333.00;
