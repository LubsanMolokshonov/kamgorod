-- Обновление цен всех конкурсов на 149 рублей
UPDATE competitions SET price = 149.00;

-- Проверка результата
SELECT id, title, price FROM competitions;
