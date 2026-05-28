-- Снапшот ФИО участника на olympiad_registrations.
-- До этой миграции диплом олимпиады выписывался по users.full_name (см. OlympiadDiploma::generatePDF),
-- а ajax/save-olympiad-registration.php перезаписывал users.full_name при каждой подаче формы.
-- Итог: при нескольких заявках на один olympiad_result_id все PDF получали ПОСЛЕДНЕЕ введённое ФИО
-- (alert #90, май 2026: учитель оформил 3 диплома на 3 учеников — все три пришли с одинаковым ФИО).
--
-- Конкурсы (registrations.participant_name) уже снапшотят имя — здесь делаем по аналогии.

ALTER TABLE olympiad_registrations
    ADD COLUMN participant_name VARCHAR(55) NULL AFTER user_id;

-- Бэкфилл для исторических регистраций — берём текущее имя из users.
UPDATE olympiad_registrations r
    JOIN users u ON u.id = r.user_id
SET r.participant_name = u.full_name
WHERE r.participant_name IS NULL;
