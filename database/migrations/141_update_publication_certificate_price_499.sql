-- Обновление стоимости свидетельства о публикации: 299 → 499 ₽
ALTER TABLE publication_certificates ALTER COLUMN price SET DEFAULT 499.00;

-- Обновить цены у неоплаченных свидетельств (оплаченные не трогаем — сохраняем историческую цену)
UPDATE publication_certificates SET price = 499.00 WHERE status = 'pending' AND price = 299.00;
