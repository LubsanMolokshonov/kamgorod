<?php
/**
 * SearchService - Умный поиск конкурсов и олимпиад
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
    private $olympiadIndexName = 'olympiads.index';
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

    // ========================================
    // Индексация конкурсов
    // ========================================

    /**
     * Создать/обновить поисковый индекс конкурсов
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

    // ========================================
    // Индексация олимпиад
    // ========================================

    /**
     * Создать/обновить поисковый индекс олимпиад
     */
    public function buildOlympiadIndex() {
        if (!$this->tntAvailable) {
            throw new Exception('TNTSearch не доступен. Установите через: composer require teamtnt/tntsearch');
        }

        $indexer = $this->tnt->createIndex($this->olympiadIndexName);
        $indexer->setPrimaryKey('id');

        $indexer->query('
            SELECT
                id,
                title,
                description,
                subject,
                slug
            FROM olympiads
            WHERE is_active = 1
        ');

        $indexer->run();

        return true;
    }

    /**
     * Добавить олимпиаду в индекс
     */
    public function indexOlympiad($olympiadId) {
        if (!$this->tntAvailable || !file_exists($this->indexPath . $this->olympiadIndexName)) {
            return false;
        }

        try {
            $this->tnt->selectIndex($this->olympiadIndexName);
            $index = $this->tnt->getIndex();

            $olympiad = $this->db->queryOne(
                "SELECT id, title, description, subject, slug
                 FROM olympiads WHERE id = ?",
                [$olympiadId]
            );

            if ($olympiad) {
                $index->update($olympiadId, $olympiad);
                return true;
            }
        } catch (Exception $e) {
            // Ошибка индексации
        }

        return false;
    }

    /**
     * Удалить олимпиаду из индекса
     */
    public function removeOlympiadFromIndex($olympiadId) {
        if (!$this->tntAvailable || !file_exists($this->indexPath . $this->olympiadIndexName)) {
            return false;
        }

        try {
            $this->tnt->selectIndex($this->olympiadIndexName);
            $index = $this->tnt->getIndex();
            $index->delete($olympiadId);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // ========================================
    // Поиск конкурсов
    // ========================================

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

    // ========================================
    // Поиск олимпиад
    // ========================================

    /**
     * Поиск олимпиад
     *
     * @param string $query Поисковый запрос
     * @param int $limit Лимит результатов
     * @return array Результаты поиска
     */
    public function searchOlympiads($query, $limit = 10) {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        // Попытка поиска через TNTSearch
        if ($this->tntAvailable && file_exists($this->indexPath . $this->olympiadIndexName)) {
            try {
                return $this->searchOlympiadsWithTNT($query, $limit);
            } catch (Exception $e) {
                // Fallback на MySQL
            }
        }

        // Fallback на MySQL FULLTEXT/LIKE
        return $this->searchOlympiadsWithMySQL($query, $limit);
    }

    /**
     * Поиск олимпиад через TNTSearch
     */
    private function searchOlympiadsWithTNT($query, $limit) {
        $this->tnt->selectIndex($this->olympiadIndexName);

        $this->tnt->fuzziness = true;
        $this->tnt->fuzzy_prefix_length = 2;
        $this->tnt->fuzzy_max_expansions = 50;
        $this->tnt->fuzzy_distance = 2;

        $results = $this->tnt->search($query, $limit);

        if (empty($results['ids'])) {
            return [];
        }

        $ids = implode(',', array_map('intval', $results['ids']));

        $olympiads = $this->db->query(
            "SELECT o.* FROM olympiads o
             WHERE o.id IN ({$ids}) AND o.is_active = 1
             ORDER BY FIELD(o.id, {$ids})"
        );

        return $this->formatOlympiadResults($olympiads, $query);
    }

    /**
     * Fallback поиск олимпиад через MySQL
     */
    private function searchOlympiadsWithMySQL($query, $limit) {
        $olympiads = $this->tryOlympiadFulltextSearch($query, $limit);

        if (empty($olympiads)) {
            $olympiads = $this->olympiadLikeSearch($query, $limit);
        }

        return $this->formatOlympiadResults($olympiads, $query);
    }

    /**
     * FULLTEXT поиск олимпиад
     */
    private function tryOlympiadFulltextSearch($query, $limit) {
        try {
            $searchQuery = $this->prepareFulltextQuery($query);

            return $this->db->query(
                "SELECT o.*,
                        MATCH(o.title, o.description, o.subject)
                        AGAINST (? IN NATURAL LANGUAGE MODE) as relevance
                 FROM olympiads o
                 WHERE o.is_active = 1
                   AND MATCH(o.title, o.description, o.subject)
                       AGAINST (? IN NATURAL LANGUAGE MODE)
                 ORDER BY relevance DESC
                 LIMIT ?",
                [$searchQuery, $searchQuery, $limit]
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * LIKE поиск олимпиад
     */
    private function olympiadLikeSearch($query, $limit) {
        $words = preg_split('/\s+/', $query);
        $likePatterns = [];
        $params = [];

        foreach ($words as $word) {
            if (mb_strlen($word) >= 2) {
                $pattern = '%' . $word . '%';
                $likePatterns[] = "(o.title LIKE ? OR o.description LIKE ? OR o.subject LIKE ?)";
                $params[] = $pattern;
                $params[] = $pattern;
                $params[] = $pattern;
            }
        }

        if (empty($likePatterns)) {
            $pattern = '%' . $query . '%';
            $likePatterns[] = "(o.title LIKE ? OR o.description LIKE ? OR o.subject LIKE ?)";
            $params[] = $pattern;
            $params[] = $pattern;
            $params[] = $pattern;
        }

        $whereClause = implode(' OR ', $likePatterns);

        return $this->db->query(
            "SELECT o.*,
                    CASE
                        WHEN o.title LIKE ? THEN 3
                        WHEN o.description LIKE ? THEN 2
                        WHEN o.subject LIKE ? THEN 1
                        ELSE 0
                    END as relevance
             FROM olympiads o
             WHERE o.is_active = 1 AND ({$whereClause})
             ORDER BY
                CASE WHEN o.title LIKE ? THEN 0 ELSE 1 END,
                o.title ASC
             LIMIT ?",
            array_merge(
                ['%' . $query . '%', '%' . $query . '%', '%' . $query . '%'],
                $params,
                ['%' . $query . '%', $limit]
            )
        );
    }

    // ========================================
    // Поиск курсов
    // ========================================

    /**
     * Поиск курсов (MySQL LIKE)
     */
    public function searchCourses($query, $limit = 10) {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $courses = $this->courseLikeSearch($query, $limit);
        return $this->formatCourseResults($courses, $query);
    }

    /**
     * LIKE поиск курсов
     */
    private function courseLikeSearch($query, $limit) {
        $words = preg_split('/\s+/', $query);
        $likePatterns = [];
        $params = [];

        foreach ($words as $word) {
            if (mb_strlen($word) >= 2) {
                $pattern = '%' . $word . '%';
                $likePatterns[] = "(c.title LIKE ? OR c.description LIKE ? OR c.target_audience_text LIKE ? OR c.course_group LIKE ?)";
                $params[] = $pattern;
                $params[] = $pattern;
                $params[] = $pattern;
                $params[] = $pattern;
            }
        }

        if (empty($likePatterns)) {
            $pattern = '%' . $query . '%';
            $likePatterns[] = "(c.title LIKE ? OR c.description LIKE ? OR c.target_audience_text LIKE ? OR c.course_group LIKE ?)";
            $params[] = $pattern;
            $params[] = $pattern;
            $params[] = $pattern;
            $params[] = $pattern;
        }

        $whereClause = implode(' OR ', $likePatterns);

        return $this->db->query(
            "SELECT c.*,
                    CASE
                        WHEN c.title LIKE ? THEN 3
                        WHEN c.description LIKE ? THEN 2
                        WHEN c.course_group LIKE ? THEN 1
                        ELSE 0
                    END as relevance
             FROM courses c
             WHERE c.is_active = 1 AND ({$whereClause})
             ORDER BY
                CASE WHEN c.title LIKE ? THEN 0 ELSE 1 END,
                c.display_order ASC,
                c.title ASC
             LIMIT ?",
            array_merge(
                ['%' . $query . '%', '%' . $query . '%', '%' . $query . '%'],
                $params,
                ['%' . $query . '%', $limit]
            )
        );
    }

    /**
     * Форматировать результаты курсов для frontend
     */
    private function formatCourseResults($courses, $query) {
        $results = [];

        foreach ($courses as $course) {
            $programTypeLabel = defined('COURSE_PROGRAM_TYPES') && isset(COURSE_PROGRAM_TYPES[$course['program_type']])
                ? COURSE_PROGRAM_TYPES[$course['program_type']]
                : $course['program_type'];

            $results[] = [
                'id' => (int)$course['id'],
                'type' => 'course',
                'title' => $course['title'],
                'slug' => $course['slug'],
                'description' => $this->truncateText($course['description'], 100),
                'price' => number_format((float)$course['price'], 0, ',', ' ') . ' ₽',
                'hours' => (int)$course['hours'],
                'programType' => $course['program_type'],
                'programTypeLabel' => $programTypeLabel,
                'category' => $course['course_group'],
                'categoryLabel' => $programTypeLabel . ' · ' . $course['hours'] . ' ч.',
                'url' => '/kursy/' . urlencode($course['slug']),
                'highlight' => $this->highlightMatch($course['title'], $query)
            ];
        }

        return $results;
    }

    // ========================================
    // Единый поиск (конкурсы + олимпиады)
    // ========================================

    /**
     * Единый поиск по конкурсам и олимпиадам с приоритизацией
     *
     * @param string $query Поисковый запрос
     * @param int $limit Общий лимит результатов
     * @param string $context 'all' | 'competitions' | 'olympiads'
     * @return array Результаты с полем 'type'
     */
    public function searchUnified($query, $limit = 10, $context = 'all') {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $competitions = $this->search($query, $limit);
        $olympiads = $this->searchOlympiads($query, $limit);

        return $this->mergeResults($competitions, $olympiads, $limit, $context);
    }

    /**
     * Объединить и приоритизировать результаты
     */
    private function mergeResults($competitions, $olympiads, $limit, $context) {
        switch ($context) {
            case 'competitions':
                $merged = array_merge($competitions, $olympiads);
                break;
            case 'olympiads':
                $merged = array_merge($olympiads, $competitions);
                break;
            default: // 'all'
                $merged = [];
                $maxLen = max(count($competitions), count($olympiads));
                for ($i = 0; $i < $maxLen; $i++) {
                    if (isset($competitions[$i])) $merged[] = $competitions[$i];
                    if (isset($olympiads[$i]))     $merged[] = $olympiads[$i];
                }
                break;
        }

        return array_slice($merged, 0, $limit);
    }

    // ========================================
    // Форматирование результатов
    // ========================================

    /**
     * Форматировать результаты конкурсов для frontend
     */
    private function formatResults($competitions, $query) {
        $results = [];

        foreach ($competitions as $comp) {
            $results[] = [
                'id' => (int)$comp['id'],
                'type' => 'competition',
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
     * Форматировать результаты олимпиад для frontend
     */
    private function formatOlympiadResults($olympiads, $query) {
        $results = [];

        foreach ($olympiads as $oly) {
            $results[] = [
                'id' => (int)$oly['id'],
                'type' => 'olympiad',
                'title' => $oly['title'],
                'slug' => $oly['slug'],
                'description' => $this->truncateText($oly['description'], 100),
                'price' => 'Бесплатно',
                'category' => $oly['subject'] ?? '',
                'categoryLabel' => $oly['subject'] ?? 'Олимпиада',
                'url' => '/olimpiady/' . urlencode($oly['slug']),
                'highlight' => $this->highlightMatch($oly['title'], $query)
            ];
        }

        return $results;
    }

    // ========================================
    // Утилиты
    // ========================================

    /**
     * Подготовить запрос для FULLTEXT
     */
    private function prepareFulltextQuery($query) {
        // Убираем спецсимволы
        $query = preg_replace('/[+\-><\(\)~*\"@]+/', ' ', $query);
        return trim($query);
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
     * Проверить существование индекса конкурсов
     */
    public function indexExists() {
        return file_exists($this->indexPath . $this->indexName);
    }

    /**
     * Проверить существование индекса олимпиад
     */
    public function olympiadIndexExists() {
        return file_exists($this->indexPath . $this->olympiadIndexName);
    }
}
