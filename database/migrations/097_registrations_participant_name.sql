-- 097: Имя участника отдельно от пользователя-владельца аккаунта
-- Контекст: педагог/руководитель регистрирует нескольких учеников на один конкурс.
-- users.full_name теперь = имя владельца аккаунта (руководитель, если есть, иначе сам участник).
-- registrations.participant_name = имя конкретного участника (для диплома и кабинета).

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

ALTER TABLE registrations
    ADD COLUMN participant_name VARCHAR(55) NOT NULL DEFAULT '' AFTER user_id;

-- Бэкфилл: исторически в save-registration.php fio участника всегда писалось в users.full_name,
-- поэтому на момент миграции users.full_name корректно отражает имя последнего зарегистрированного
-- участника. Для записей одного user/competition это значит, что participant_name будет одинаков —
-- но это лучшее, что можно восстановить без формы.
UPDATE registrations r
JOIN users u ON u.id = r.user_id
SET r.participant_name = u.full_name
WHERE r.participant_name = '';

-- Снимаем уникальность (user_id, competition_id) — несколько участников у одного аккаунта.
-- Сначала добавляем отдельный индекс на user_id (нужен для FK registrations_ibfk_1),
-- иначе MySQL отказывается дропать unique_user_competition (1553 Cannot drop index).
ALTER TABLE registrations ADD KEY idx_user_id (user_id);
ALTER TABLE registrations DROP INDEX unique_user_competition;
