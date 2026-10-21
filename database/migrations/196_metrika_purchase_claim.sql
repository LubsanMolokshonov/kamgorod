-- Короткая аренда отправки предотвращает конкурентный replay двух вкладок.
SET @claim_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='orders' AND COLUMN_NAME='metrika_claim_token')=0,
    'ALTER TABLE orders ADD COLUMN metrika_claim_token VARCHAR(64) NULL', 'SELECT 1');
PREPARE claim_stmt FROM @claim_ddl;
EXECUTE claim_stmt;
DEALLOCATE PREPARE claim_stmt;
SET @claim_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='orders' AND COLUMN_NAME='metrika_claimed_at')=0,
    'ALTER TABLE orders ADD COLUMN metrika_claimed_at DATETIME NULL', 'SELECT 1');
PREPARE claim_stmt FROM @claim_ddl;
EXECUTE claim_stmt;
DEALLOCATE PREPARE claim_stmt;
