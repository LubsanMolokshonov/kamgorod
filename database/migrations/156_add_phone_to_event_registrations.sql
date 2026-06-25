-- Сбор телефона во всех формах регистрации на мероприятия (конкурс / олимпиада / публикация),
-- чтобы наполнить базу номерами и включить уведомления в мессенджер «Макс» (ChatPush).
-- Вебинары уже хранят phone в webinar_registrations — их не трогаем.
--
-- Основной потребитель телефона — users.phone (его читает MAX-хелпер и Bitrix);
-- обработчики пишут номер туда. На самой заявке храним снапшот номера на момент
-- регистрации — на случай, если пользователь позже сменит телефон в профиле.
--
-- migrate.php игнорирует "Duplicate column" / "already exists" — повторный прогон безопасен.

ALTER TABLE registrations
    ADD COLUMN phone VARCHAR(20) NULL DEFAULT NULL AFTER participant_name;

ALTER TABLE olympiad_registrations
    ADD COLUMN phone VARCHAR(20) NULL DEFAULT NULL AFTER participant_name;

ALTER TABLE publication_certificates
    ADD COLUMN phone VARCHAR(20) NULL DEFAULT NULL AFTER author_name;
