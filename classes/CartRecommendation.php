<?php
/**
 * CartRecommendation Class
 * Smart cross-selling engine for the cart page.
 * Detects audience type from cart items and recommends relevant products.
 */

class CartRecommendation {
    private $db;

    /**
     * Static mapping: publication_tag direction slug => audience_type slugs
     * Based on seed data in migration 017_seed_publication_data.sql
     */
    private static $tagToAudience = [
        'preschool'         => ['dou'],
        'primary-school'    => ['nachalnaya-shkola'],
        'secondary-school'  => ['srednyaya-starshaya-shkola'],
        'high-school'       => ['srednyaya-starshaya-shkola'],
        'extra-education'   => ['dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'spo'],
        'special-education' => ['dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'spo'],
        'educational-work'  => ['dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'spo'],
        'psychology'        => ['dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'spo'],
        'innovations'       => ['dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'spo'],
        'health'            => ['dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'spo'],
    ];

    /**
     * Reverse mapping: audience_type slug => publication_tag direction slugs
     */
    private static $audienceToTags = [
        'dou' => ['preschool', 'extra-education', 'special-education', 'educational-work', 'psychology', 'innovations', 'health'],
        'nachalnaya-shkola' => ['primary-school', 'extra-education', 'special-education', 'educational-work', 'psychology', 'innovations', 'health'],
        'srednyaya-starshaya-shkola' => ['secondary-school', 'high-school', 'extra-education', 'special-education', 'educational-work', 'psychology', 'innovations', 'health'],
        'spo' => ['extra-education', 'special-education', 'educational-work', 'psychology', 'innovations', 'health'],
    ];

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Get personalized recommendations for the cart page.
     *
     * @param array $allItems Cart items (same format as in cart.php: type, id, raw_data, ...)
     * @param int|null $userId Current user ID (for webinar/publication lookups)
     * @param int $limit Max total recommendations
     * @return array Array of recommendation cards
     */
    public function getRecommendations(array $allItems, ?int $userId, int $limit = 6): array {
        $audienceSlugs = $this->detectAudienceTypes($allItems);

        // Collect IDs of items already in cart to exclude
        $excludeCompetitionIds = [];
        $excludeWebinarIds = [];
        $excludePublicationIds = [];

        foreach ($allItems as $item) {
            $raw = $item['raw_data'] ?? [];
            if ($item['type'] === 'registration') {
                $excludeCompetitionIds[] = (int)($raw['competition_id'] ?? 0);
            } elseif ($item['type'] === 'webinar_certificate') {
                $excludeWebinarIds[] = (int)($raw['webinar_id'] ?? 0);
            } elseif ($item['type'] === 'certificate') {
                $excludePublicationIds[] = (int)($raw['publication_id'] ?? 0);
            }
        }

        $perType = max(1, intval(ceil($limit / 3)));
        $recommendations = [];

        // 1. Competition recommendations (always available, no user required)
        try {
            $competitions = $this->getCompetitionRecommendations($audienceSlugs, $excludeCompetitionIds, $perType);
        } catch (\Throwable $e) {
            error_log("Cart recommendations (competitions) error: " . $e->getMessage());
            $competitions = [];
        }
        $recommendations = array_merge($recommendations, $competitions);

        // 2. Webinar certificate recommendations (require logged-in user)
        $webinars = [];
        if ($userId) {
            try {
                $webinars = $this->getWebinarRecommendations($audienceSlugs, $excludeWebinarIds, $userId, $perType);
            } catch (\Throwable $e) {
                error_log("Cart recommendations (webinars) error: " . $e->getMessage());
                $webinars = [];
            }
            $recommendations = array_merge($recommendations, $webinars);
        }

        // 3. Publication certificate recommendations (require logged-in user)
        $publications = [];
        if ($userId) {
            try {
                $publications = $this->getPublicationRecommendations($audienceSlugs, $excludePublicationIds, $userId, $perType);
            } catch (\Throwable $e) {
                error_log("Cart recommendations (publications) error: " . $e->getMessage());
                $publications = [];
            }
            $recommendations = array_merge($recommendations, $publications);
        }

        // Interleave: take 1 from each type in round-robin
        $recommendations = $this->interleave($competitions, $webinars, $publications);

        return array_slice($recommendations, 0, $limit);
    }

    /**
     * Get promotion hint text based on current item count.
     *
     * @param int $itemCount Current number of items in cart
     * @return string|null Hint text or null if promotion is fully applied
     */
    public function getPromotionHint(int $itemCount): ?string {
        if ($itemCount < 1) {
            return null;
        }

        $remaining = 3 - ($itemCount % 3);
        if ($remaining === 3) {
            $remaining = 0;
        }

        if ($remaining === 0) {
            // Already at a multiple of 3, suggest getting one more set
            return 'Добавьте ещё 2, чтобы получить ещё одно бесплатно!';
        }

        if ($remaining === 1) {
            return 'Добавьте ещё 1 мероприятие и получите его БЕСПЛАТНО!';
        }

        return 'Добавьте ещё 2 мероприятия — третье бесплатно!';
    }

    /**
     * Detect audience type slugs from cart items.
     *
     * @param array $allItems Cart items
     * @return array Unique audience_type slugs (e.g. ['dou', 'nachalnaya-shkola'])
     */
    private function detectAudienceTypes(array $allItems): array {
        $audienceSlugs = [];

        // Collect IDs by type
        $competitionIds = [];
        $webinarIds = [];
        $publicationIds = [];

        foreach ($allItems as $item) {
            $raw = $item['raw_data'] ?? [];
            if ($item['type'] === 'registration' && !empty($raw['competition_id'])) {
                $competitionIds[] = (int)$raw['competition_id'];
            } elseif ($item['type'] === 'webinar_certificate' && !empty($raw['webinar_id'])) {
                $webinarIds[] = (int)$raw['webinar_id'];
            } elseif ($item['type'] === 'certificate' && !empty($raw['publication_id'])) {
                $publicationIds[] = (int)$raw['publication_id'];
            }
        }

        // Competitions → audience_types via competition_audience_types
        if (!empty($competitionIds)) {
            $placeholders = implode(',', array_fill(0, count($competitionIds), '?'));
            $rows = $this->db->query(
                "SELECT DISTINCT at.slug
                 FROM audience_types at
                 JOIN competition_audience_types cat ON at.id = cat.audience_type_id
                 WHERE cat.competition_id IN ($placeholders) AND at.is_active = 1",
                $competitionIds
            );
            foreach ($rows as $row) {
                $audienceSlugs[] = $row['slug'];
            }
        }

        // Webinars → audience_types via webinar_audience_types
        if (!empty($webinarIds)) {
            $placeholders = implode(',', array_fill(0, count($webinarIds), '?'));
            $rows = $this->db->query(
                "SELECT DISTINCT at.slug
                 FROM audience_types at
                 JOIN webinar_audience_types wat ON at.id = wat.audience_type_id
                 WHERE wat.webinar_id IN ($placeholders) AND at.is_active = 1",
                $webinarIds
            );
            foreach ($rows as $row) {
                $audienceSlugs[] = $row['slug'];
            }
        }

        // Publications → publication_tags → static mapping to audience_types
        if (!empty($publicationIds)) {
            $placeholders = implode(',', array_fill(0, count($publicationIds), '?'));
            $rows = $this->db->query(
                "SELECT DISTINCT pt.slug
                 FROM publication_tags pt
                 JOIN publication_tag_relations ptr ON pt.id = ptr.tag_id
                 WHERE ptr.publication_id IN ($placeholders) AND pt.tag_type = 'direction'",
                $publicationIds
            );
            foreach ($rows as $row) {
                $mapped = self::$tagToAudience[$row['slug']] ?? [];
                $audienceSlugs = array_merge($audienceSlugs, $mapped);
            }
        }

        $audienceSlugs = array_unique($audienceSlugs);

        // Fallback: if nothing detected, return all 4 types
        if (empty($audienceSlugs)) {
            $audienceSlugs = ['dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'spo'];
        }

        return array_values($audienceSlugs);
    }

    /**
     * Get competition recommendations matching audience.
     */
    private function getCompetitionRecommendations(array $audienceSlugs, array $excludeIds, int $limit): array {
        if (empty($audienceSlugs)) {
            return [];
        }

        $limitSafe = intval($limit);
        $audiencePlaceholders = implode(',', array_fill(0, count($audienceSlugs), '?'));
        $params = $audienceSlugs;

        $excludeClause = '';
        if (!empty($excludeIds)) {
            $excludePlaceholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $excludeClause = "AND c.id NOT IN ($excludePlaceholders)";
            $params = array_merge($params, $excludeIds);
        }

        $rows = $this->db->query(
            "SELECT c.id, c.title, c.slug, c.price, c.category
             FROM competitions c
             JOIN competition_audience_types cat ON c.id = cat.competition_id
             JOIN audience_types at ON cat.audience_type_id = at.id
             WHERE c.is_active = 1
               AND at.slug IN ($audiencePlaceholders)
               $excludeClause
             GROUP BY c.id
             ORDER BY c.display_order ASC
             LIMIT $limitSafe",
            $params
        );

        return array_map(function ($row) {
            return [
                'type' => 'competition',
                'id' => (int)$row['id'],
                'title' => $row['title'],
                'slug' => $row['slug'],
                'price' => (float)$row['price'],
                'meta' => $this->getCategoryLabel($row['category']),
                'quick_add' => false,
                'add_data' => null,
            ];
        }, $rows);
    }

    /**
     * Get webinar certificate recommendations for logged-in user.
     * Finds webinars where user has a registration but no certificate yet.
     */
    private function getWebinarRecommendations(array $audienceSlugs, array $excludeWebinarIds, int $userId, int $limit): array {
        if (empty($audienceSlugs)) {
            return [];
        }

        $limitSafe = intval($limit);
        $audiencePlaceholders = implode(',', array_fill(0, count($audienceSlugs), '?'));
        $params = $audienceSlugs;
        $params[] = $userId;

        $excludeClause = '';
        if (!empty($excludeWebinarIds)) {
            $excludePlaceholders = implode(',', array_fill(0, count($excludeWebinarIds), '?'));
            $excludeClause = "AND w.id NOT IN ($excludePlaceholders)";
            $params = array_merge($params, $excludeWebinarIds);
        }

        $rows = $this->db->query(
            "SELECT w.id, w.title, w.slug, w.certificate_price,
                    wr.id as registration_id, wr.full_name,
                    wr.organization, wr.position, wr.city
             FROM webinars w
             JOIN webinar_registrations wr ON w.id = wr.webinar_id
             JOIN webinar_audience_types wat ON w.id = wat.webinar_id
             JOIN audience_types at ON wat.audience_type_id = at.id
             LEFT JOIN webinar_certificates wc ON wr.id = wc.registration_id
             WHERE w.is_active = 1
               AND at.slug IN ($audiencePlaceholders)
               AND wr.user_id = ?
               AND wr.status = 'registered'
               AND wc.id IS NULL
               AND (w.status = 'completed' OR w.status = 'videolecture')
               $excludeClause
             GROUP BY w.id, wr.id
             ORDER BY w.scheduled_at DESC
             LIMIT $limitSafe",
            $params
        );

        return array_map(function ($row) {
            return [
                'type' => 'webinar_certificate',
                'id' => (int)$row['id'],
                'title' => $row['title'],
                'slug' => $row['slug'],
                'price' => (float)($row['certificate_price'] ?? 200),
                'meta' => 'Сертификат участника',
                'quick_add' => true,
                'add_data' => [
                    'registration_id' => (int)$row['registration_id'],
                    'full_name' => $row['full_name'],
                    'organization' => $row['organization'] ?? '',
                    'position' => $row['position'] ?? '',
                    'city' => $row['city'] ?? '',
                ],
            ];
        }, $rows);
    }

    /**
     * Get publication certificate recommendations for logged-in user.
     * Finds user's published works without a certificate.
     */
    private function getPublicationRecommendations(array $audienceSlugs, array $excludePublicationIds, int $userId, int $limit): array {
        // Convert audience slugs to publication tag slugs
        $tagSlugs = [];
        foreach ($audienceSlugs as $slug) {
            $tags = self::$audienceToTags[$slug] ?? [];
            $tagSlugs = array_merge($tagSlugs, $tags);
        }
        $tagSlugs = array_unique($tagSlugs);

        if (empty($tagSlugs)) {
            return [];
        }

        $limitSafe = intval($limit);
        $tagPlaceholders = implode(',', array_fill(0, count($tagSlugs), '?'));
        $params = [];
        $params[] = $userId;
        $params = array_merge($params, $tagSlugs);

        $excludeClause = '';
        if (!empty($excludePublicationIds)) {
            $excludePlaceholders = implode(',', array_fill(0, count($excludePublicationIds), '?'));
            $excludeClause = "AND p.id NOT IN ($excludePlaceholders)";
            $params = array_merge($params, $excludePublicationIds);
        }

        $rows = $this->db->query(
            "SELECT p.id, p.title, p.slug, u.full_name as author_name,
                    u.organization, u.position, u.city
             FROM publications p
             JOIN users u ON p.user_id = u.id
             JOIN publication_tag_relations ptr ON p.id = ptr.publication_id
             JOIN publication_tags pt ON ptr.tag_id = pt.id
             LEFT JOIN publication_certificates pc ON p.id = pc.publication_id
             WHERE p.status = 'published'
               AND p.user_id = ?
               AND pc.id IS NULL
               AND pt.tag_type = 'direction'
               AND pt.slug IN ($tagPlaceholders)
               $excludeClause
             GROUP BY p.id
             ORDER BY p.published_at DESC
             LIMIT $limitSafe",
            $params
        );

        return array_map(function ($row) {
            return [
                'type' => 'publication_certificate',
                'id' => (int)$row['id'],
                'title' => $row['title'],
                'slug' => $row['slug'],
                'price' => 299.0,
                'meta' => 'Свидетельство о публикации',
                'quick_add' => true,
                'add_data' => [
                    'publication_id' => (int)$row['id'],
                    'author_name' => $row['author_name'] ?? '',
                    'organization' => $row['organization'] ?? '',
                    'position' => $row['position'] ?? '',
                    'city' => $row['city'] ?? '',
                ],
            ];
        }, $rows);
    }

    /**
     * Interleave recommendations from different sources in round-robin order.
     */
    private function interleave(array ...$sources): array {
        $result = [];
        $maxLen = 0;
        foreach ($sources as $source) {
            $maxLen = max($maxLen, count($source));
        }

        for ($i = 0; $i < $maxLen; $i++) {
            foreach ($sources as $source) {
                if (isset($source[$i])) {
                    $result[] = $source[$i];
                }
            }
        }

        return $result;
    }

    /**
     * Get human-readable category label for competition.
     */
    private function getCategoryLabel(string $category): string {
        $labels = [
            'methodology' => 'Методика',
            'extracurricular' => 'Внеурочная деятельность',
            'student_projects' => 'Проекты учащихся',
            'creative' => 'Творчество',
        ];
        return $labels[$category] ?? 'Конкурс';
    }
}
