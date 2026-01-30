-- Миграция: Добавление FULLTEXT индекса для поиска конкурсов
-- Дата: 2026-01-28
-- Описание: Создает FULLTEXT индекс на полях title, description, target_participants
--           для быстрого полнотекстового поиска (fallback для TNTSearch)

-- Проверяем существование индекса и создаем если нет
-- MySQL 8.0+ поддерживает FULLTEXT для InnoDB

-- Удаляем индекс если существует (для повторного запуска)
DROP INDEX IF EXISTS ft_competitions_search ON competitions;

-- Создаем FULLTEXT индекс
ALTER TABLE competitions
ADD FULLTEXT INDEX ft_competitions_search (title, description, target_participants);

-- Создаем директорию для хранения поисковых индексов TNTSearch
-- (выполняется через PHP, но записываем для документации)
-- Путь: /database/search/competitions.index
