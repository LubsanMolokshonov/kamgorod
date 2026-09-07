-- Migration 168: цены курсов «СКОЛКОВО» (ПП id 92-108, КПК id 109-134) —
--   привести к тому, чтобы ИТОГОВАЯ цена на сайте (со скидкой ПП 10% / КПК 55%)
--   равнялась цене из "ПП/КПК добавить на сайт.xlsx".
--   new_price(ПП)  = round(xlsx_price / 0.9);   round(new_price*0.9)  == xlsx_price
--   new_price(КПК) = round(xlsx_price / 0.45);  round(new_price*0.45) == xlsx_price
-- Идемпотентна: UPDATE только если price ещё равен старому (xlsx) значению.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- id 92 [pp] xlsx=25800 -> price=28667  (spetsialnoe-defektologicheskoe-obrazovanie-rabota-uchitelya-)
UPDATE courses SET price = 28667.00 WHERE slug = 'spetsialnoe-defektologicheskoe-obrazovanie-rabota-uchitelya-defektologa-s-detmi-s-zaderzhkoy-psihicheskogo-razvitiya-doshkolnogo-i-shkolnogo-vozrastov-profil-zaderzhka-psihicheskogo-razvitiy' AND program_type = 'pp' AND price = 25800.00;
-- id 93 [pp] xlsx=47700 -> price=53000  (pedagogicheskoe-obrazovanie-osnovy-bezopasnosti-i-zaschity-r)
UPDATE courses SET price = 53000.00 WHERE slug = 'pedagogicheskoe-obrazovanie-osnovy-bezopasnosti-i-zaschity-rodiny-v-usloviyah-realizatsii-fgos-ooo-soo' AND program_type = 'pp' AND price = 47700.00;
-- id 94 [pp] xlsx=47700 -> price=53000  (pedagogicheskoe-obrazovanie-fizika-v-usloviyah-realizatsii-f)
UPDATE courses SET price = 53000.00 WHERE slug = 'pedagogicheskoe-obrazovanie-fizika-v-usloviyah-realizatsii-fgos-ooo-soo' AND program_type = 'pp' AND price = 47700.00;
-- id 95 [pp] xlsx=23850 -> price=26500  (vospitatel-logopedicheskoy-gruppy-pedagogicheskaya-i-korrekt)
UPDATE courses SET price = 26500.00 WHERE slug = 'vospitatel-logopedicheskoy-gruppy-pedagogicheskaya-i-korrektsionno-razvivayuschaya-pomosch-detyam-s-narusheniyami-rechi-v-usloviyah-realizatsii-fgos-do' AND program_type = 'pp' AND price = 23850.00;
-- id 96 [pp] xlsx=36300 -> price=40333  (pedagogicheskoe-obrazovanie-pedagog-organizator)
UPDATE courses SET price = 40333.00 WHERE slug = 'pedagogicheskoe-obrazovanie-pedagog-organizator' AND program_type = 'pp' AND price = 36300.00;
-- id 97 [pp] xlsx=33100 -> price=36778  (spetsialnoe-defektologicheskoe-obrazovanie-rabota-uchitelya-)
UPDATE courses SET price = 36778.00 WHERE slug = 'spetsialnoe-defektologicheskoe-obrazovanie-rabota-uchitelya-defektologa-s-detmi-s-ovz-doshkolnogo-i-shkolnogo-vozrastov' AND program_type = 'pp' AND price = 33100.00;
-- id 98 [pp] xlsx=36300 -> price=40333  (pedagogicheskoe-obrazovanie-pedagog-bibliotekar-v-sovremenno)
UPDATE courses SET price = 40333.00 WHERE slug = 'pedagogicheskoe-obrazovanie-pedagog-bibliotekar-v-sovremennom-obrazovatelnom-prostranstve' AND program_type = 'pp' AND price = 36300.00;
-- id 99 [pp] xlsx=25800 -> price=28667  (spetsialnoe-defektologicheskoe-obrazovanie-rabota-uchitelya-)
UPDATE courses SET price = 28667.00 WHERE slug = 'spetsialnoe-defektologicheskoe-obrazovanie-rabota-uchitelya-defektologa-s-detmi-s-narusheniyami-sluha-doshkolnogo-i-shkolnogo-vozrastov-profil-narusheniya-sluha' AND program_type = 'pp' AND price = 25800.00;
-- id 100 [pp] xlsx=25800 -> price=28667  (spetsialnoe-defektologicheskoe-obrazovanie-rabota-uchitelya-)
UPDATE courses SET price = 28667.00 WHERE slug = 'spetsialnoe-defektologicheskoe-obrazovanie-rabota-uchitelya-defektologa-s-detmi-s-narusheniyami-oporno-dvigatelnogo-apparata-doshkolnogo-i-shkolnogo-vozrastov-profil-narusheniya-oporno-dviga' AND program_type = 'pp' AND price = 25800.00;
-- id 101 [pp] xlsx=47700 -> price=53000  (pedagogicheskoe-obrazovanie-himiya-v-usloviyah-realizatsii-f)
UPDATE courses SET price = 53000.00 WHERE slug = 'pedagogicheskoe-obrazovanie-himiya-v-usloviyah-realizatsii-fgos-ooo-soo' AND program_type = 'pp' AND price = 47700.00;
-- id 102 [pp] xlsx=47700 -> price=53000  (pedagogicheskoe-obrazovanie-informatika-v-usloviyah-realizat)
UPDATE courses SET price = 53000.00 WHERE slug = 'pedagogicheskoe-obrazovanie-informatika-v-usloviyah-realizatsii-fgos-ooo-soo' AND program_type = 'pp' AND price = 47700.00;
-- id 103 [pp] xlsx=25800 -> price=28667  (spetsialnoe-defektologicheskoe-obrazovanie-rabota-uchitelya-)
UPDATE courses SET price = 28667.00 WHERE slug = 'spetsialnoe-defektologicheskoe-obrazovanie-rabota-uchitelya-defektologa-s-detmi-s-narusheniyami-zreniya-doshkolnogo-i-shkolnogo-vozrastov-profil-narusheniya-zreniya' AND program_type = 'pp' AND price = 25800.00;
-- id 104 [pp] xlsx=47700 -> price=53000  (pedagogicheskoe-obrazovanie-biologiya-v-usloviyah-realizatsi)
UPDATE courses SET price = 53000.00 WHERE slug = 'pedagogicheskoe-obrazovanie-biologiya-v-usloviyah-realizatsii-fgos-ooo-soo' AND program_type = 'pp' AND price = 47700.00;
-- id 105 [pp] xlsx=47700 -> price=53000  (pedagogicheskoe-obrazovanie-fizicheskaya-kultura-v-usloviyah)
UPDATE courses SET price = 53000.00 WHERE slug = 'pedagogicheskoe-obrazovanie-fizicheskaya-kultura-v-usloviyah-realizatsii-fgos-ooo-soo' AND program_type = 'pp' AND price = 47700.00;
-- id 106 [pp] xlsx=36300 -> price=40333  (pedagogicheskoe-obrazovanie-vospitatel-gruppy-prodlennogo-dn)
UPDATE courses SET price = 40333.00 WHERE slug = 'pedagogicheskoe-obrazovanie-vospitatel-gruppy-prodlennogo-dnya' AND program_type = 'pp' AND price = 36300.00;
-- id 107 [pp] xlsx=128000 -> price=142222  (logopediya-rabota-s-obuchayuschimisya-s-narusheniyami-rechi-)
UPDATE courses SET price = 142222.00 WHERE slug = 'logopediya-rabota-s-obuchayuschimisya-s-narusheniyami-rechi-i-kommunikatsii-s-dopolnitelnoy-spetsializatsiey-v-oblasti-doshkolnoy-defektologii' AND program_type = 'pp' AND price = 128000.00;
-- id 108 [pp] xlsx=25800 -> price=28667  (spetsialnoe-defektologicheskoe-obrazovanie-rabota-uchitelya-)
UPDATE courses SET price = 28667.00 WHERE slug = 'spetsialnoe-defektologicheskoe-obrazovanie-rabota-uchitelya-defektologa-s-detmi-s-umstvennoy-otstalostyu-intellektualnymi-narusheniyami-s-tyazhelymi-i-mnozhestvennymi-narusheniyami-razvitiya' AND program_type = 'pp' AND price = 25800.00;
-- id 109 [kpk] xlsx=9360 -> price=20800  (defektologiya-rabota-s-obuchayuschimisya-s-zaderzhkoy-psihic)
UPDATE courses SET price = 20800.00 WHERE slug = 'defektologiya-rabota-s-obuchayuschimisya-s-zaderzhkoy-psihicheskogo-razvitiya' AND program_type = 'kpk' AND price = 9360.00;
-- id 110 [kpk] xlsx=9360 -> price=20800  (defektologiya-rabota-s-obuchayuschimisya-s-rasstroystvami-au)
UPDATE courses SET price = 20800.00 WHERE slug = 'defektologiya-rabota-s-obuchayuschimisya-s-rasstroystvami-autisticheskogo-spektra' AND program_type = 'kpk' AND price = 9360.00;
-- id 111 [kpk] xlsx=9360 -> price=20800  (defektologiya-rabota-s-obuchayuschimisya-s-narusheniyami-slu)
UPDATE courses SET price = 20800.00 WHERE slug = 'defektologiya-rabota-s-obuchayuschimisya-s-narusheniyami-sluha' AND program_type = 'kpk' AND price = 9360.00;
-- id 112 [kpk] xlsx=9360 -> price=20800  (defektologiya-rabota-s-obuchayuschimisya-s-narusheniyami-zre)
UPDATE courses SET price = 20800.00 WHERE slug = 'defektologiya-rabota-s-obuchayuschimisya-s-narusheniyami-zreniya' AND program_type = 'kpk' AND price = 9360.00;
-- id 113 [kpk] xlsx=7470 -> price=16600  (organizatsiya-obrazovatelnogo-protsessa-dlya-obuchayuschihsy)
UPDATE courses SET price = 16600.00 WHERE slug = 'organizatsiya-obrazovatelnogo-protsessa-dlya-obuchayuschihsya-s-ovz-v-sisteme-dopolnitelnogo-obrazovaniya-detey' AND program_type = 'kpk' AND price = 7470.00;
-- id 114 [kpk] xlsx=47880 -> price=106400  (logopedicheskiy-massazh-prakticheskoe-rukovodstvo-dlya-ispol)
UPDATE courses SET price = 106400.00 WHERE slug = 'logopedicheskiy-massazh-prakticheskoe-rukovodstvo-dlya-ispolzovaniya-v-korrektsii-rechi' AND program_type = 'kpk' AND price = 47880.00;
-- id 115 [kpk] xlsx=5400 -> price=12000  (obuchenie-shahmatam-kak-intellektualnoe-razvitie-rebenka)
UPDATE courses SET price = 12000.00 WHERE slug = 'obuchenie-shahmatam-kak-intellektualnoe-razvitie-rebenka' AND program_type = 'kpk' AND price = 5400.00;
-- id 116 [kpk] xlsx=6300 -> price=14000  (protsess-realizatsii-fgos-v-sovremennoy-obrazovatelnoy-organ)
UPDATE courses SET price = 14000.00 WHERE slug = 'protsess-realizatsii-fgos-v-sovremennoy-obrazovatelnoy-organizatsii-deyatelnost-rukovoditelya' AND program_type = 'kpk' AND price = 6300.00;
-- id 117 [kpk] xlsx=8100 -> price=18000  (spetsialnoe-i-inklyuzivnoe-obrazovanie-detey-s-ovz-v-usloviy)
UPDATE courses SET price = 18000.00 WHERE slug = 'spetsialnoe-i-inklyuzivnoe-obrazovanie-detey-s-ovz-v-usloviyah-realizatsii-fgos' AND program_type = 'kpk' AND price = 8100.00;
-- id 118 [kpk] xlsx=4140 -> price=9200  (profilaktika-deviantnogo-povedeniya-i-kriminalnoy-subkultury)
UPDATE courses SET price = 9200.00 WHERE slug = 'profilaktika-deviantnogo-povedeniya-i-kriminalnoy-subkultury-v-shkolnoy-srede' AND program_type = 'kpk' AND price = 4140.00;
-- id 119 [kpk] xlsx=2862 -> price=6360  (ekologicheskoe-vospitanie-shkolnikov-v-sootvetstvii-s-fgos)
UPDATE courses SET price = 6360.00 WHERE slug = 'ekologicheskoe-vospitanie-shkolnikov-v-sootvetstvii-s-fgos' AND program_type = 'kpk' AND price = 2862.00;
-- id 120 [kpk] xlsx=5760 -> price=12800  (proektnaya-deyatelnost-v-nachalnoy-shkole-v-usloviyah-realiz)
UPDATE courses SET price = 12800.00 WHERE slug = 'proektnaya-deyatelnost-v-nachalnoy-shkole-v-usloviyah-realizatsii-obnovlennogo-fgos-noo' AND program_type = 'kpk' AND price = 5760.00;
-- id 121 [kpk] xlsx=8640 -> price=19200  (prostaya-nauka-formirovanie-elementarnyh-matematicheskih-i-e)
UPDATE courses SET price = 19200.00 WHERE slug = 'prostaya-nauka-formirovanie-elementarnyh-matematicheskih-i-estestvenno-nauchnyh-predstavleniy-u-doshkolnikov-v-usloviyah-doo' AND program_type = 'kpk' AND price = 8640.00;
-- id 122 [kpk] xlsx=8640 -> price=19200  (mladshiy-vospitatel-klyuchevye-kompetentsii-dlya-raboty-v-do)
UPDATE courses SET price = 19200.00 WHERE slug = 'mladshiy-vospitatel-klyuchevye-kompetentsii-dlya-raboty-v-doo' AND program_type = 'kpk' AND price = 8640.00;
-- id 123 [kpk] xlsx=5760 -> price=12800  (dostupnaya-obrazovatelnaya-sreda-tehnicheskoe-soprovozhdenie)
UPDATE courses SET price = 12800.00 WHERE slug = 'dostupnaya-obrazovatelnaya-sreda-tehnicheskoe-soprovozhdenie-obuchayuschihsya-s-ovz-invalidnostyu-v-obrazovatelnoy-organizatsii' AND program_type = 'kpk' AND price = 5760.00;
-- id 124 [kpk] xlsx=7470 -> price=16600  (metodist-doshkolnogo-obrazovatelnogo-uchrezhdeniya)
UPDATE courses SET price = 16600.00 WHERE slug = 'metodist-doshkolnogo-obrazovatelnogo-uchrezhdeniya' AND program_type = 'kpk' AND price = 7470.00;
-- id 125 [kpk] xlsx=4500 -> price=10000  (individualizatsiya-obrazovaniya-tehnologii-tyutorskogo-sopro)
UPDATE courses SET price = 10000.00 WHERE slug = 'individualizatsiya-obrazovaniya-tehnologii-tyutorskogo-soprovozhdeniya-obuchayuschihsya-v-obrazovatelnom-protsesse' AND program_type = 'kpk' AND price = 4500.00;
-- id 126 [kpk] xlsx=7470 -> price=16600  (sovremennye-metody-organizatsii-vneurochnoy-deyatelnosti-v-o)
UPDATE courses SET price = 16600.00 WHERE slug = 'sovremennye-metody-organizatsii-vneurochnoy-deyatelnosti-v-obrazovatelnoy-organizatsii-v-sootvetstvii-s-trebovaniyami-fgos' AND program_type = 'kpk' AND price = 7470.00;
-- id 127 [kpk] xlsx=5760 -> price=12800  (finansovyy-menedzhment-v-upravlenii-doshkolnoy-obrazovatelno)
UPDATE courses SET price = 12800.00 WHERE slug = 'finansovyy-menedzhment-v-upravlenii-doshkolnoy-obrazovatelnoy-organizatsiey' AND program_type = 'kpk' AND price = 5760.00;
-- id 128 [kpk] xlsx=5760 -> price=12800  (finansovyy-menedzhment-v-upravlenii-obscheobrazovatelnoy-org)
UPDATE courses SET price = 12800.00 WHERE slug = 'finansovyy-menedzhment-v-upravlenii-obscheobrazovatelnoy-organizatsiey' AND program_type = 'kpk' AND price = 5760.00;
-- id 129 [kpk] xlsx=5760 -> price=12800  (metodika-prepodavaniya-kursa-istoriya-nashego-kraya-v-sootve)
UPDATE courses SET price = 12800.00 WHERE slug = 'metodika-prepodavaniya-kursa-istoriya-nashego-kraya-v-sootvetstvii-s-trebovaniyami-fgos-i-fop-ooo' AND program_type = 'kpk' AND price = 5760.00;
-- id 130 [kpk] xlsx=5760 -> price=12800  (razgovory-o-vazhnom-metodika-organizatsii-i-provedeniya-prob)
UPDATE courses SET price = 12800.00 WHERE slug = 'razgovory-o-vazhnom-metodika-organizatsii-i-provedeniya-problemno-tsennostnogo-obscheniya-s-detmi' AND program_type = 'kpk' AND price = 5760.00;
-- id 131 [kpk] xlsx=3840 -> price=8533  (voprosy-profilaktiki-vich-infektsii-sredi-obuchayuschihsya-v)
UPDATE courses SET price = 8533.00 WHERE slug = 'voprosy-profilaktiki-vich-infektsii-sredi-obuchayuschihsya-v-obrazovatelnyh-organizatsiyah' AND program_type = 'kpk' AND price = 3840.00;
-- id 132 [kpk] xlsx=8640 -> price=19200  (sozdanie-i-funktsionirovanie-tsentra-obrazovaniya-tochka-ros)
UPDATE courses SET price = 19200.00 WHERE slug = 'sozdanie-i-funktsionirovanie-tsentra-obrazovaniya-tochka-rosta-deyatelnost-rukovoditelya' AND program_type = 'kpk' AND price = 8640.00;
-- id 133 [kpk] xlsx=8640 -> price=19200  (pedagogicheskaya-deyatelnost-v-tsentre-obrazovaniya-estestve)
UPDATE courses SET price = 19200.00 WHERE slug = 'pedagogicheskaya-deyatelnost-v-tsentre-obrazovaniya-estestvenno-nauchnoy-i-tehnologicheskoy-napravlennosti-tochka-rosta' AND program_type = 'kpk' AND price = 8640.00;
-- id 134 [kpk] xlsx=4500 -> price=10000  (organizatsiya-protsessa-obucheniya-trudu-tehnologii-v-tsentr)
UPDATE courses SET price = 10000.00 WHERE slug = 'organizatsiya-protsessa-obucheniya-trudu-tehnologii-v-tsentrah-obrazovaniya-tsifrovogo-i-gumanitarnogo-profiley-tochka-rosta' AND program_type = 'kpk' AND price = 4500.00;
