<?php
/**
 * AudienceCategory Class
 * Управление категориями аудитории (Level 0)
 * Педагогам, Школьникам, Дошкольникам, Студентам СПО
 */

class AudienceCategory {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Получить все активные категории
     */
    public function getAll($activeOnly = true) {
        $sql = "SELECT * FROM audience_categories";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY display_order ASC, name ASC";

        return $this->db->query($sql);
    }

    /**
     * Получить категорию по slug
     */
    public function getBySlug($slug) {
        return $this->db->queryOne(
            "SELECT * FROM audience_categories WHERE slug = ? AND is_active = 1",
            [$slug]
        );
    }

    /**
     * Получить категорию по ID
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT * FROM audience_categories WHERE id = ?",
            [$id]
        );
    }

    /**
     * Получить типы аудитории для категории
     */
    public function getAudienceTypes($categoryId, $activeOnly = true) {
        $sql = "SELECT * FROM audience_types WHERE category_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY display_order ASC, name ASC";

        return $this->db->query($sql, [$categoryId]);
    }

    /**
     * Получить типы аудитории для категории, у которых есть специализация с указанным slug.
     * Используется в новой URL-иерархии: после выбора специализации показать только применимые уровни.
     */
    public function getAudienceTypesWithSpec($categoryId, $specSlug, $activeOnly = true) {
        $hasV2 = false;
        try {
            $row = $this->db->queryOne("SHOW TABLES LIKE 'audience_type_specializations'");
            $hasV2 = !empty($row);
        } catch (Exception $e) {
            $hasV2 = false;
        }

        if ($hasV2) {
            $sql = "SELECT DISTINCT t.*
                    FROM audience_types t
                    JOIN audience_type_specializations ats ON t.id = ats.audience_type_id
                    JOIN audience_specializations s ON ats.specialization_id = s.id
                    WHERE t.category_id = ? AND s.slug = ?";
        } else {
            $sql = "SELECT DISTINCT t.*
                    FROM audience_types t
                    JOIN audience_specializations s ON s.audience_type_id = t.id
                    WHERE t.category_id = ? AND s.slug = ?";
        }
        if ($activeOnly) {
            $sql .= " AND t.is_active = 1 AND s.is_active = 1";
        }
        $sql .= " ORDER BY t.display_order ASC, t.name ASC";

        return $this->db->query($sql, [$categoryId, $specSlug]);
    }

    /**
     * Получить уникальные специализации (по slug) для категории — агрегация по всем типам.
     * Используется для URL-структуры ac/as/at, где специализация выбирается до уровня.
     *
     * @param int $categoryId
     * @return array Массив [{id, slug, name, specialization_type, icon}] — id наименьший среди дублей slug
     */
    public function getSpecializations($categoryId, $activeOnly = true) {
        // Проверим наличие junction-таблицы v2
        $hasV2 = false;
        try {
            $row = $this->db->queryOne("SHOW TABLES LIKE 'audience_type_specializations'");
            $hasV2 = !empty($row);
        } catch (Exception $e) {
            $hasV2 = false;
        }

        $hasSpecType = false;
        try {
            $col = $this->db->queryOne("SHOW COLUMNS FROM audience_specializations LIKE 'specialization_type'");
            $hasSpecType = !empty($col);
        } catch (Exception $e) {
            $hasSpecType = false;
        }

        $specTypeSelect = $hasSpecType ? "MIN(s.specialization_type) as specialization_type," : "";
        $iconSelect = $hasSpecType ? "MIN(s.icon) as icon," : "";
        $orderBy = $hasSpecType
            ? "ORDER BY MIN(s.specialization_type) ASC, MIN(s.display_order) ASC, s.slug ASC"
            : "ORDER BY MIN(s.display_order) ASC, s.slug ASC";

        if ($hasV2) {
            $sql = "SELECT MIN(s.id) as id, s.slug, MIN(s.name) as name, MIN(s.seo_phrase) as seo_phrase, {$specTypeSelect} {$iconSelect}
                           COUNT(DISTINCT t.id) as type_count
                    FROM audience_specializations s
                    JOIN audience_type_specializations ats ON s.id = ats.specialization_id
                    JOIN audience_types t ON ats.audience_type_id = t.id
                    WHERE t.category_id = ?";
            if ($activeOnly) {
                $sql .= " AND s.is_active = 1 AND t.is_active = 1";
            }
            $sql .= " GROUP BY s.slug {$orderBy}";
        } else {
            $sql = "SELECT MIN(s.id) as id, s.slug, MIN(s.name) as name, MIN(s.seo_phrase) as seo_phrase, {$specTypeSelect} {$iconSelect}
                           COUNT(DISTINCT t.id) as type_count
                    FROM audience_specializations s
                    JOIN audience_types t ON s.audience_type_id = t.id
                    WHERE t.category_id = ?";
            if ($activeOnly) {
                $sql .= " AND s.is_active = 1 AND t.is_active = 1";
            }
            $sql .= " GROUP BY s.slug {$orderBy}";
        }

        return $this->db->query($sql, [$categoryId]);
    }

    /**
     * Получить категории, у которых есть товары указанного типа
     * @param string $productType — 'olympiad', 'competition', 'webinar', 'publication', 'course'
     */
    public function getAllWithProducts($productType) {
        $tableMap = [
            'competition'  => ['competition_audience_categories', 'competition_id', 'competitions', 'is_active = 1'],
            'olympiad'     => ['olympiad_audience_categories',    'olympiad_id',    'olympiads',    'is_active = 1'],
            'webinar'      => ['webinar_audience_categories',     'webinar_id',     'webinars',     'is_active = 1'],
            'publication'  => ['publication_audience_categories',  'publication_id', 'publications', "status = 'published'"],
            'course'       => ['course_audience_categories',       'course_id',      'courses',      'is_active = 1'],
        ];

        if (!isset($tableMap[$productType])) {
            return $this->getAll();
        }

        [$junctionTable, $productCol, $productTable, $productWhere] = $tableMap[$productType];

        $sql = "SELECT ac.* FROM audience_categories ac
                WHERE ac.is_active = 1
                  AND EXISTS (
                      SELECT 1 FROM {$junctionTable} jt
                      JOIN {$productTable} p ON jt.{$productCol} = p.id
                      WHERE jt.category_id = ac.id AND p.{$productWhere}
                  )
                ORDER BY ac.display_order ASC, ac.name ASC";

        return $this->db->query($sql);
    }

    /**
     * Подсчитать количество мероприятий для категории (все типы)
     */
    public function getEventCounts($categoryId) {
        $competitions = $this->db->queryOne(
            "SELECT COUNT(DISTINCT cac.competition_id) as total
             FROM competition_audience_categories cac
             JOIN competitions c ON cac.competition_id = c.id
             WHERE cac.category_id = ? AND c.is_active = 1",
            [$categoryId]
        );

        $olympiads = $this->db->queryOne(
            "SELECT COUNT(DISTINCT oac.olympiad_id) as total
             FROM olympiad_audience_categories oac
             JOIN olympiads o ON oac.olympiad_id = o.id
             WHERE oac.category_id = ? AND o.is_active = 1",
            [$categoryId]
        );

        $webinars = $this->db->queryOne(
            "SELECT COUNT(DISTINCT wac.webinar_id) as total
             FROM webinar_audience_categories wac
             JOIN webinars w ON wac.webinar_id = w.id
             WHERE wac.category_id = ? AND w.is_active = 1",
            [$categoryId]
        );

        $publications = $this->db->queryOne(
            "SELECT COUNT(DISTINCT pac.publication_id) as total
             FROM publication_audience_categories pac
             JOIN publications p ON pac.publication_id = p.id
             WHERE pac.category_id = ? AND p.status = 'published'",
            [$categoryId]
        );

        $courses = $this->db->queryOne(
            "SELECT COUNT(DISTINCT cac.course_id) as total
             FROM course_audience_categories cac
             JOIN courses c ON cac.course_id = c.id
             WHERE cac.category_id = ? AND c.is_active = 1",
            [$categoryId]
        );

        return [
            'competitions' => $competitions['total'] ?? 0,
            'olympiads' => $olympiads['total'] ?? 0,
            'webinars' => $webinars['total'] ?? 0,
            'publications' => $publications['total'] ?? 0,
            'courses' => $courses['total'] ?? 0
        ];
    }
}
