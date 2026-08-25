-- Миграция 164: раздел «Блог» — новое значение source='blog' в таблице publications
-- и системный автор для редакционных статей (отдельно от пользовательских публикаций).
--
-- Блог переиспользует существующую инфраструктуру журнала (classes/Publication.php,
-- publication_types, publication_tags, детальный шаблон со всей микроразметкой Article),
-- изолируясь через колонку source, которая уже различает 'upload' (загрузка пользователем)
-- и 'generator' (AI-генератор пользователя). 'blog' — статьи от редакции сайта.

ALTER TABLE publications
    MODIFY COLUMN source ENUM('upload', 'generator', 'blog') NOT NULL DEFAULT 'upload';

INSERT INTO users (email, full_name, organization)
SELECT 'blog@fgos.pro', 'Редакция ФГОС-Практикум', 'ФГОС-Практикум'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'blog@fgos.pro');
