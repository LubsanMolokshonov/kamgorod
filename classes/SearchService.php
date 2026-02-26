<?php
/**
 * SearchService - Умный поиск конкурсов
 * Использует TNTSearch с fallback на MySQL FULLTEXT/LIKE
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TeamTNT\TNTSearch\TNTSearch;

class SearchService {
    private $db;
    private $pdo;
    private $tnt;
    private $indexPath;
    private $indexName = 'competitions.index';
    private $tntAvailable = false;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
        $this->indexPath = BASE_PATH . '/database/search/';

        // Убедиться что директория существует
        if (!file_exists($this->indexPath)) {
            mkdir($this->indexPath, 0755, true);
        }

        $this->initTNTSearch();
    }

    /**
     * Инициализация TNTSearch
     */
    private function initTNTSearch() {
        try {
            if (!class_exists('TeamTNT\TNTSearch\TNTSearch')) {
                return;
            }

            $this->tnt = new TNTSearch;

            $this->tnt->loadConfig([
                'driver'    => 'mysql',
                'host'      => DB_HOST,
                'database'  => DB_NAME,
                'username'  => DB_USER,
                'password'  => DB_PASS,
                'storage'   => $this->indexPath,
                'stemmer'   => \TeamTNT\TNTSearch\Stemmer\RussianStemmer::class
            ]);

            $this->tntAvailable = true;
        } catch (Exception $e) {
            // TNTSearch не доступен, будет использован fallback
            $this->tntAvailable = false;
        }
    }

    /**
     * Создать/обновить поисковый индекс
     */
    public function buildIndex() {
        if (!$this->tntAvailable) {
            throw new Exception('TNTSearch не доступен. Установите через: composer require teamtnt/tntsearch');
        }

        $indexer = $this->tnt->createIndex($this->indexName);
        $indexer->setPrimaryKey('id');

        // Индексировать title, description, target_participants
        $indexer->query('
            SELECT
                id,
                title,
                description,
                target_participants,
                slug
            FROM competitions
            WHERE is_active = 1
        ');

        $indexer->run();

        return true;
    }

    /**
     * Добавить конкурс в индекс
     */
    public function indexCompetition($competitionId) {
        if (!$this->tntAvailable || !file_exists($this->indexPath . $this->indexName)) {
            return false;
        }

        try {
            $this->tnt->selectIndex($this->indexName);
            $index = $this->tnt->getIndex();

            $competition = $this->db->queryOne(
                "SELECT id, title, description, target_participants, slug
                 FROM competitions WHERE id = ?",
                [$competitionId]
            );

            if ($competition) {
                $index->update($competitionId, $competition);
                return true;
            }
        } catch (Exception $e) {
            // Ошибка индексации
        }

        return false;
    }

    /**
     * Удалить конкурс из индекса
     */
    public function removeFromIndex($competitionId) {
        if (!$this->tntAvailable || !file_exists($this->indexPath . $this->indexName)) {
            return false;
        }

        try {
            $this->tnt->selectIndex($this->indexName);
            $index = $this->tnt->getIndex();
            $index->delete($competitionId);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Поиск конкурсов
     *
     * @param string $query Поисковый запрос
     * @param int $limit Лимит результатов
     * @return array Результаты поиска
     */
    public function search($query, $limit = 10) {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        // Попытка поиска через TNTSearch
        if ($this->tntAvailable && file_exists($this->indexPath . $this->indexName)) {
            try {
                return $this->searchWithTNT($query, $limit);
            } catch (Exception $e) {
                // Fallback на MySQL
            }
        }

        // Fallback на MySQL FULLTEXT/LIKE
        return $this->searchWithMySQL($query, $limit);
    }

    /**
     * Поиск через TNTSearch (с fuzzy matching)
     */
    private function searchWithTNT($query, $limit) {
        $this->tnt->selectIndex($this->indexName);

        // Включить fuzzy search для обработки опечаток
        $this->tnt->fuzziness = true;
        $this->tnt->fuzzy_prefix_length = 2;
        $this->tnt->fuzzy_max_expansions = 50;
        $this->tnt->fuzzy_distance = 2; // Допускаем 2 опечатки

        $results = $this->tnt->search($query, $limit);

        if (empty($results['ids'])) {
            return [];
        }

        // Получить полные данные конкурсов
        $ids = implode(',', array_map('intval', $results['ids']));

        $competitions = $this->db->query(
            "SELECT c.* FROM competitions c
             WHERE c.id IN ({$ids}) AND c.is_active = 1
             ORDER BY FIELD(c.id, {$ids})"
        );

        return $this->formatResults($competitions, $query);
    }

    /**
     * Fallback поиск через MySQL FULLTEXT/LIKE
     */
    private function searchWithMySQL($query, $limit) {
        // Сначала пробуем FULLTEXT (если индекс существует)
        $competitions = $this->tryFulltextSearch($query, $limit);

        // Если FULLTEXT не нашел результатов - используем LIKE
        if (empty($competitions)) {
            $competitions = $this->likeSearch($query, $limit);
        }

        return $this->formatResults($competitions, $query);
    }

    /**
     * Попытка FULLTEXT поиска
     */
    private function tryFulltextSearch($query, $limit) {
        try {
            // Экранируем спецсимволы для FULLTEXT
            $searchQuery = $this->prepareFulltextQuery($query);

            return $this->db->query(
                "SELECT c.*,
                        MATCH(title, description, target_participants)
                        AGAINST (? IN NATURAL LANGUAGE MODE) as relevance
                 FROM competitions c
                 WHERE c.is_active = 1
                   AND MATCH(title, description, target_participants)
                       AGAINST (? IN NATURAL LANGUAGE MODE)
                 ORDER BY relevance DESC
                 LIMIT ?",
                [$searchQuery, $searchQuery, $limit]
            );
        } catch (Exception $e) {
            // FULLTEXT индекс не существует
            return [];
        }
    }

    /**
     * LIKE поиск (самый простой fallback)
     */
    private function likeSearch($query, $limit) {
        $words = preg_split('/\s+/', $query);
        $likePatterns = [];
        $params = [];

        foreach ($words as $word) {
            if (mb_strlen($word) >= 2) {
                $pattern = '%' . $word . '%';
                $likePatterns[] = "(title LIKE ? OR description LIKE ? OR target_participants LIKE ?)";
                $params[] = $pattern;
                $params[] = $pattern;
                $params[] = $pattern;
            }
        }

        if (empty($likePatterns)) {
            $pattern = '%' . $query . '%';
            $likePatterns[] = "(title LIKE ? OR description LIKE ? OR target_participants LIKE ?)";
            $params[] = $pattern;
            $params[] = $pattern;
            $params[] = $pattern;
        }

        $whereClause = implode(' OR ', $likePatterns);

        return $this->db->query(
            "SELECT c.*,
                    CASE
                        WHEN title LIKE ? THEN 3
                        WHEN description LIKE ? THEN 2
                        WHEN target_participants LIKE ? THEN 1
                        ELSE 0
                    END as relevance
             FROM competitions c
             WHERE c.is_active = 1 AND ({$whereClause})
             ORDER BY
                CASE WHEN title LIKE ? THEN 0 ELSE 1 END,
                title ASC
             LIMIT ?",
            array_merge(
                ['%' . $query . '%', '%' . $query . '%', '%' . $query . '%'],
                $params,
                ['%' . $query . '%', $limit]
            )
        );
    }

    /**
     * Подготовить запрос для FULLTEXT
     */
    private function prepareFulltextQuery($query) {
        // Убираем спецсимволы
        $query = preg_replace('/[+\-><\(\)~*\"@]+/', ' ', $query);
        return trim($query);
    }

    /**
     * Форматировать результаты для frontend
     */
    private function formatResults($competitions, $query) {
        $results = [];

        foreach ($competitions as $comp) {
            $results[] = [
                'id' => (int)$comp['id'],
                'title' => $comp['title'],
                'slug' => $comp['slug'],
                'description' => $this->truncateText($comp['description'], 100),
                'price' => number_format((float)$comp['price'], 0, ',', ' ') . ' ₽',
                'category' => $comp['category'],
                'categoryLabel' => Competition::getCategoryLabel($comp['category']),
                'url' => '/konkursy/' . urlencode($comp['slug']),
                'highlight' => $this->highlightMatch($comp['title'], $query)
            ];
        }

        return $results;
    }

    /**
     * Обрезать текст с многоточием
     */
    private function truncateText($text, $length) {
        $text = strip_tags($text);
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length) . '...';
    }

    /**
     * Подсветить найденный текст
     */
    private function highlightMatch($text, $query) {
        $words = preg_split('/\s+/', $query);

        foreach ($words as $word) {
            if (mb_strlen($word) >= 2) {
                $pattern = '/(' . preg_quote($word, '/') . ')/iu';
                $text = preg_replace($pattern, '<mark>$1</mark>', $text);
            }
        }

        return $text;
    }

    /**
     * Проверить доступность TNTSearch
     */
    public function isTNTAvailable() {
        return $this->tntAvailable;
    }

    /**
     * Проверить существование индекса
     */
    public function indexExists() {
        return file_exists($this->indexPath . $this->indexName);
    }
}
