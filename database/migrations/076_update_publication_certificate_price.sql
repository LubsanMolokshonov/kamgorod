-- Обновление стоимости свидетельства о публикации: 169 → 299 ₽
ALTER TABLE publication_certificates ALTER COLUMN price SET DEFAULT 299.00;

-- Обновить цены у неоплаченных свидетельств
UPDATE publication_certificates SET price = 299.00 WHERE status = 'pending' AND price = 169.00;
