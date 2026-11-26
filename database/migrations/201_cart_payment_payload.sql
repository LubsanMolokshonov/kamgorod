-- Сохраняем исходный запрос без повторного расчёта чека; старым попыткам оставляем их хеш.
SET @cart_payload_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='cart_payment_attempts' AND COLUMN_NAME='payload_json')=0,
    'ALTER TABLE cart_payment_attempts ADD COLUMN payload_json MEDIUMTEXT NULL',
    'SELECT 1');
PREPARE cart_payload_stmt FROM @cart_payload_ddl;
EXECUTE cart_payload_stmt;
DEALLOCATE PREPARE cart_payload_stmt;
