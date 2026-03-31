<?php
/**
 * CartRecommendation Class
 * Smart cross-selling engine for the cart page.
 * Detects audience type from cart items and recommends relevant products.
 */

class CartRecommendation {
    private $db;
    private $pdo;
    private $v2Ready = null;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
        $this->pdo = $pdo;
    }

    /**
     * Проверить, применена ли миграция v2 (3-уровневая сегментация).
     */
    private function isV2(): bool {
        if ($this->v2Ready === null) {
            try {
                $this->db->queryOne("SELECT 1 FROM audience_type_specializations LIMIT 1");
                $this->v2Ready = true;
            } catch (\Exception $e) {
                $this->v2Ready = false;
            }
        }
        return $this->v2Ready;
    }

    /**
     * Get personalized recommendations for the cart page.
     * Uses priority-based slot allocation for maximum type diversity.
     *
     * Algorithm:
     * 1. Determine which product categories are in the cart
     * 2. Prioritize categories NOT in cart (cross-selling), then categories in cart
     * 3. Fill each slot with cascading fallbacks (quick-add cert → browse → CTA)
     *
     * @param array $allItems Cart items (same format as in cart.php: type, id, raw_data, ...)
     * @param int|null $userId Current user ID (for webinar/publication lookups)
     * @param int $limit Max total recommendations (default 3 for diverse cross-selling)
     * @return array Array of recommendation cards
     */
    public function getRecommendations(array $allItems, ?int $userId, int $limit = 3): array {
        $context = $this->detectAudienceContext($allItems, $userId);
        $audienceSlugs = $context['type_slugs'];

        // Collect IDs of items already in cart to exclude
        $excludeCompetitionIds = [];
        $excludeOlympiadIds = [];
        $excludeWebinarIds = [];
        $excludeCourseIds = [];

        // Detect which product categories are already in the cart
        $cartHasCompetition = false;
        $cartHasOlympiad = false;
        $cartHasWebinar = false;
        $cartHasCourse = false;

        foreach ($allItems as $item) {
            $raw = $item['raw_data'] ?? [];
            if ($item['type'] === 'registration') {
                $excludeCompetitionIds[] = (int)($raw['competition_id'] ?? 0);
                $cartHasCompetition = true;
            } elseif ($item['type'] === 'olympiad_registration') {
                $excludeOlympiadIds[] = (int)($raw['olympiad_id'] ?? 0);
                $cartHasOlympiad = true;
            } elseif ($item['type'] === 'webinar_certificate') {
                $excludeWebinarIds[] = (int)($raw['webinar_id'] ?? 0);
                $cartHasWebinar = true;
            } elseif ($item['type'] === 'course_enrollment') {
                $excludeCourseIds[] = (int)($raw['course_id'] ?? 0);
                $cartHasCourse = true;
            }
        }

        // Phase 1: Determine slot priorities (cross-sell categories first)
        $notInCart = [];
        $inCart = [];

        if (!$cartHasCompetition) $notInCart[] = 'competition'; else $inCart[] = 'competition';
        if (!$cartHasOlympiad)    $notInCart[] = 'olympiad';     else $inCart[] = 'olympiad';
        if (!$cartHasWebinar)     $notInCart[] = 'webinar';      else $inCart[] = 'webinar';
        if (!$cartHasCourse)      $notInCart[] = 'course';       else $inCart[] = 'course';

        $slotPriority = array_merge($notInCart, $inCart);

        // Build exactly $limit slots, cycling through priorities if needed
        $slots = [];
        for ($i = 0; $i < $limit; $i++) {
            $slots[] = $slotPriority[$i % count($slotPriority)];
        }

        // Phase 2: Fill each slot using cascading fallbacks
        $recommendations = [];
        $usedCompetitionIds = $excludeCompetitionIds;
        $usedOlympiadIds = $excludeOlympiadIds;
        $usedWebinarIds = $excludeWebinarIds;
        $usedCourseIds = $excludeCourseIds;

        foreach ($slots as $category) {
            $card = null;

            if ($category === 'competition') {
                $card = $this->fillCompetitionSlot($audienceSlugs, $usedCompetitionIds, $context);
                if ($card) {
                    $usedCompetitionIds[] = $card['id'];
                }
            } elseif ($category === 'olympiad') {
                $card = $this->fillOlympiadSlot($audienceSlugs, $usedOlympiadIds, $context);
                if ($card) {
                    $usedOlympiadIds[] = $card['id'];
                }
            } elseif ($category === 'webinar') {
                $card = $this->fillWebinarSlot($audienceSlugs, $usedWebinarIds, $userId, $context);
                if ($card && $card['id'] > 0) {
                    $usedWebinarIds[] = $card['id'];
                }
            } elseif ($category === 'course') {
                $card = $this->fillCourseSlot($audienceSlugs, $usedCourseIds, $context);
                if ($card) {
                    $usedCourseIds[] = $card['id'];
                }
            }

            if ($card) {
                $recommendations[] = $card;
            }
        }

        return $recommendations;
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
        $olympiadIds = [];
        $courseIds = [];

        foreach ($allItems as $item) {
            $raw = $item['raw_data'] ?? [];
            if ($item['type'] === 'registration' && !empty($raw['competition_id'])) {
                $competitionIds[] = (int)$raw['competition_id'];
            } elseif ($item['type'] === 'webinar_certificate' && !empty($raw['webinar_id'])) {
                $webinarIds[] = (int)$raw['webinar_id'];
            } elseif ($item['type'] === 'certificate' && !empty($raw['publication_id'])) {
                $publicationIds[] = (int)$raw['publication_id'];
            } elseif ($item['type'] === 'olympiad_registration' && !empty($raw['olympiad_id'])) {
                $olympiadIds[] = (int)$raw['olympiad_id'];
            } elseif ($item['type'] === 'course_enrollment' && !empty($raw['course_id'])) {
                $courseIds[] = (int)$raw['course_id'];
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

        // Publications → audience_types via publication_audience_types
        if (!empty($publicationIds)) {
            $placeholders = implode(',', array_fill(0, count($publicationIds), '?'));
            $rows = $this->db->query(
                "SELECT DISTINCT at.slug
                 FROM audience_types at
                 JOIN publication_audience_types pat ON at.id = pat.audience_type_id
                 WHERE pat.publication_id IN ($placeholders) AND at.is_active = 1",
                $publicationIds
            );
            foreach ($rows as $row) {
                $audienceSlugs[] = $row['slug'];
            }
        }

        // Olympiads → audience_types via olympiad_audience_types
        if (!empty($olympiadIds)) {
            $placeholders = implode(',', array_fill(0, count($olympiadIds), '?'));
            $rows = $this->db->query(
                "SELECT DISTINCT at.slug
                 FROM audience_types at
                 JOIN olympiad_audience_types oat ON at.id = oat.audience_type_id
                 WHERE oat.olympiad_id IN ($placeholders) AND at.is_active = 1",
                $olympiadIds
            );
            foreach ($rows as $row) {
                $audienceSlugs[] = $row['slug'];
            }
        }

        // Courses → audience_types via course_audience_types
        if (!empty($courseIds)) {
            $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
            $rows = $this->db->query(
                "SELECT DISTINCT at.slug
                 FROM audience_types at
                 JOIN course_audience_types cat ON at.id = cat.audience_type_id
                 WHERE cat.course_id IN ($placeholders) AND at.is_active = 1",
                $courseIds
            );
            foreach ($rows as $row) {
                $audienceSlugs[] = $row['slug'];
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
     * Обнаружить полный контекст аудитории из корзины и профиля пользователя.
     * Возвращает 3-уровневый контекст для ранжирования рекомендаций.
     *
     * @param array $allItems Товары в корзине
     * @param int|null $userId ID пользователя
     * @return array{type_slugs: string[], specialization_ids: int[], cart_specialization_ids: int[], user_specialization_ids: int[]}
     */
    private function detectAudienceContext(array $allItems, ?int $userId): array {
        $typeSlugs = $this->detectAudienceTypes($allItems);

        $context = [
            'type_slugs' => $typeSlugs,
            'specialization_ids' => [],
            'cart_specialization_ids' => [],
            'user_specialization_ids' => [],
        ];

        if (!$this->isV2()) {
            return $context;
        }

        // Извлечь специализации из товаров корзины (батч-запросы)
        $cartSpecIds = $this->detectSpecializationsFromCart($allItems);
        $context['cart_specialization_ids'] = $cartSpecIds;

        // Получить специализации из профиля пользователя
        $userSpecIds = [];
        if ($userId) {
            $userSpecIds = $this->getUserSpecializationIds($userId);

            // Если корзина не дала type_slugs (фоллбэк), попробовать из профиля
            if ($typeSlugs === ['dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'spo']) {
                $userTypeSlug = $this->getUserAudienceTypeSlug($userId);
                if ($userTypeSlug) {
                    $context['type_slugs'] = [$userTypeSlug];
                }
            }
        }
        $context['user_specialization_ids'] = array_values(array_diff($userSpecIds, $cartSpecIds));

        // Объединить все специализации
        $context['specialization_ids'] = array_values(array_unique(array_merge($cartSpecIds, $userSpecIds)));

        return $context;
    }

    /**
     * Извлечь ID специализаций из товаров в корзине (батч-запросы).
     */
    private function detectSpecializationsFromCart(array $allItems): array {
        $competitionIds = [];
        $webinarIds = [];
        $publicationIds = [];
        $olympiadIds = [];
        $courseIds = [];

        foreach ($allItems as $item) {
            $raw = $item['raw_data'] ?? [];
            if ($item['type'] === 'registration' && !empty($raw['competition_id'])) {
                $competitionIds[] = (int)$raw['competition_id'];
            } elseif ($item['type'] === 'webinar_certificate' && !empty($raw['webinar_id'])) {
                $webinarIds[] = (int)$raw['webinar_id'];
            } elseif ($item['type'] === 'certificate' && !empty($raw['publication_id'])) {
                $publicationIds[] = (int)$raw['publication_id'];
            } elseif ($item['type'] === 'olympiad_registration' && !empty($raw['olympiad_id'])) {
                $olympiadIds[] = (int)$raw['olympiad_id'];
            } elseif ($item['type'] === 'course_enrollment' && !empty($raw['course_id'])) {
                $courseIds[] = (int)$raw['course_id'];
            }
        }

        $specIds = [];

        if (!empty($competitionIds)) {
            $placeholders = implode(',', array_fill(0, count($competitionIds), '?'));
            $rows = $this->db->query(
                "SELECT DISTINCT specialization_id FROM competition_specializations WHERE competition_id IN ($placeholders)",
                $competitionIds
            );
            foreach ($rows as $row) {
                $specIds[] = (int)$row['specialization_id'];
            }
        }

        if (!empty($webinarIds)) {
            $placeholders = implode(',', array_fill(0, count($webinarIds), '?'));
            $rows = $this->db->query(
                "SELECT DISTINCT specialization_id FROM webinar_specializations WHERE webinar_id IN ($placeholders)",
                $webinarIds
            );
            foreach ($rows as $row) {
                $specIds[] = (int)$row['specialization_id'];
            }
        }

        if (!empty($publicationIds)) {
            $placeholders = implode(',', array_fill(0, count($publicationIds), '?'));
            $rows = $this->db->query(
                "SELECT DISTINCT specialization_id FROM publication_specializations WHERE publication_id IN ($placeholders)",
                $publicationIds
            );
            foreach ($rows as $row) {
                $specIds[] = (int)$row['specialization_id'];
            }
        }

        if (!empty($olympiadIds)) {
            $placeholders = implode(',', array_fill(0, count($olympiadIds), '?'));
            $rows = $this->db->query(
                "SELECT DISTINCT specialization_id FROM olympiad_specializations WHERE olympiad_id IN ($placeholders)",
                $olympiadIds
            );
            foreach ($rows as $row) {
                $specIds[] = (int)$row['specialization_id'];
            }
        }

        if (!empty($courseIds)) {
            $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
            $rows = $this->db->query(
                "SELECT DISTINCT specialization_id FROM course_specializations WHERE course_id IN ($placeholders)",
                $courseIds
            );
            foreach ($rows as $row) {
                $specIds[] = (int)$row['specialization_id'];
            }
        }

        return array_values(array_unique($specIds));
    }

    /**
     * Получить ID специализаций из профиля пользователя.
     */
    private function getUserSpecializationIds(?int $userId): array {
        if (!$userId) {
            return [];
        }

        try {
            $rows = $this->db->query(
                "SELECT specialization_id FROM user_specializations WHERE user_id = ?",
                [$userId]
            );
            return array_map(fn($r) => (int)$r['specialization_id'], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Получить слаг типа аудитории из профиля пользователя (фоллбэк).
     */
    private function getUserAudienceTypeSlug(?int $userId): ?string {
        if (!$userId) {
            return null;
        }

        try {
            $row = $this->db->queryOne(
                "SELECT at.slug
                 FROM users u
                 JOIN audience_types at ON u.institution_type_id = at.id
                 WHERE u.id = ? AND at.is_active = 1",
                [$userId]
            );
            return $row['slug'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Построить LEFT JOIN подзапрос для весового скоринга по специализациям.
     * Возвращает фрагменты SQL и параметры, или пустые значения если скоринг не нужен.
     *
     * @param string $junctionTable Таблица связи (competition_specializations, webinar_specializations, ...)
     * @param string $entityIdColumn Название FK-колонки (competition_id, webinar_id, ...)
     * @param string $entityAlias Алиас основной таблицы в запросе (c, w, p)
     * @param array $context Контекст аудитории из detectAudienceContext()
     * @return array{join_sql: string, join_params: int[], select_expr: string, order_expr: string}
     */
    private function buildSpecScoreJoin(string $junctionTable, string $entityIdColumn, string $entityAlias, array $context): array {
        $empty = ['join_sql' => '', 'join_params' => [], 'select_expr' => '', 'order_expr' => ''];

        if (empty($context['specialization_ids'])) {
            return $empty;
        }

        $cartIds = $context['cart_specialization_ids'] ?? [];
        $userIds = $context['user_specialization_ids'] ?? [];
        $allIds = $context['specialization_ids'];

        $allPlaceholders = implode(',', array_fill(0, count($allIds), '?'));

        // Построить CASE для взвешенного подсчёта
        $params = [];
        $caseParts = [];

        if (!empty($cartIds)) {
            $cartPlaceholders = implode(',', array_fill(0, count($cartIds), '?'));
            $caseParts[] = "WHEN jt.specialization_id IN ($cartPlaceholders) THEN 2";
            $params = array_merge($params, $cartIds);
        }
        if (!empty($userIds)) {
            $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
            $caseParts[] = "WHEN jt.specialization_id IN ($userPlaceholders) THEN 1";
            $params = array_merge($params, $userIds);
        }

        // Если нет разделения на cart/user — просто считаем совпадения
        $scoreExpr = empty($caseParts)
            ? 'COUNT(*)'
            : 'SUM(CASE ' . implode(' ', $caseParts) . ' ELSE 0 END)';

        $joinSql = "LEFT JOIN (
            SELECT jt.$entityIdColumn, $scoreExpr AS match_score
            FROM $junctionTable jt
            WHERE jt.specialization_id IN ($allPlaceholders)
            GROUP BY jt.$entityIdColumn
        ) spec_match ON $entityAlias.id = spec_match.$entityIdColumn";

        // Параметры: сначала CASE-значения, потом WHERE IN
        $joinParams = array_merge($params, $allIds);

        return [
            'join_sql' => $joinSql,
            'join_params' => $joinParams,
            'select_expr' => ', COALESCE(spec_match.match_score, 0) AS spec_score',
            'order_expr' => 'spec_score DESC, ',
        ];
    }

    /**
     * Get competition recommendations matching audience.
     */
    private function getCompetitionRecommendations(array $audienceSlugs, array $excludeIds, int $limit, array $context = []): array {
        if (empty($audienceSlugs)) {
            return [];
        }

        $limitSafe = intval($limit);
        $audiencePlaceholders = implode(',', array_fill(0, count($audienceSlugs), '?'));

        // Скоринг по специализациям (v2)
        $score = $this->buildSpecScoreJoin('competition_specializations', 'competition_id', 'c', $context);

        $params = array_merge($score['join_params'], $audienceSlugs);

        $excludeClause = '';
        if (!empty($excludeIds)) {
            $excludePlaceholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $excludeClause = "AND c.id NOT IN ($excludePlaceholders)";
            $params = array_merge($params, $excludeIds);
        }

        $rows = $this->db->query(
            "SELECT c.id, c.title, c.slug, c.price, c.category
                    {$score['select_expr']}
             FROM competitions c
             {$score['join_sql']}
             JOIN competition_audience_types cat ON c.id = cat.competition_id
             JOIN audience_types at ON cat.audience_type_id = at.id
             WHERE c.is_active = 1
               AND at.slug IN ($audiencePlaceholders)
               $excludeClause
             GROUP BY c.id
             ORDER BY {$score['order_expr']}c.display_order ASC
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
    private function getWebinarRecommendations(array $audienceSlugs, array $excludeWebinarIds, int $userId, int $limit, array $context = []): array {
        if (empty($audienceSlugs)) {
            return [];
        }

        $limitSafe = intval($limit);
        $audiencePlaceholders = implode(',', array_fill(0, count($audienceSlugs), '?'));

        // Скоринг по специализациям (v2)
        $score = $this->buildSpecScoreJoin('webinar_specializations', 'webinar_id', 'w', $context);

        $params = array_merge($score['join_params'], $audienceSlugs);
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
                    {$score['select_expr']}
             FROM webinars w
             {$score['join_sql']}
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
             ORDER BY {$score['order_expr']}w.scheduled_at DESC
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
    private function getPublicationRecommendations(array $audienceSlugs, array $excludePublicationIds, int $userId, int $limit, array $context = []): array {
        if (empty($audienceSlugs)) {
            return [];
        }

        $limitSafe = intval($limit);
        $audiencePlaceholders = implode(',', array_fill(0, count($audienceSlugs), '?'));

        // Скоринг по специализациям (v2)
        $score = $this->buildSpecScoreJoin('publication_specializations', 'publication_id', 'p', $context);

        $params = array_merge($score['join_params'], $audienceSlugs);
        $params[] = $userId;

        $excludeClause = '';
        if (!empty($excludePublicationIds)) {
            $excludePlaceholders = implode(',', array_fill(0, count($excludePublicationIds), '?'));
            $excludeClause = "AND p.id NOT IN ($excludePlaceholders)";
            $params = array_merge($params, $excludePublicationIds);
        }

        $rows = $this->db->query(
            "SELECT p.id, p.title, p.slug, u.full_name as author_name,
                    u.organization, u.position, u.city
                    {$score['select_expr']}
             FROM publications p
             {$score['join_sql']}
             JOIN users u ON p.user_id = u.id
             JOIN publication_audience_types pat ON p.id = pat.publication_id
             JOIN audience_types at ON pat.audience_type_id = at.id
             LEFT JOIN publication_certificates pc ON p.id = pc.publication_id
             WHERE p.status = 'published'
               AND at.slug IN ($audiencePlaceholders)
               AND p.user_id = ?
               AND pc.id IS NULL
               $excludeClause
             GROUP BY p.id
             ORDER BY {$score['order_expr']}p.published_at DESC
             LIMIT $limitSafe",
            $params
        );

        return array_map(function ($row) {
            return [
                'type' => 'publication_certificate',
                'id' => (int)$row['id'],
                'title' => $row['title'],
                'slug' => $row['slug'],
                'price' => 169.0,
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

    // ── Slot-filling helpers with cascading fallbacks ──

    /**
     * Fill a competition slot. Competitions are always available (browse).
     */
    private function fillCompetitionSlot(array $audienceSlugs, array $excludeIds, array $context = []): ?array {
        try {
            $results = $this->getCompetitionRecommendations($audienceSlugs, $excludeIds, 1, $context);
            return $results[0] ?? null;
        } catch (\Throwable $e) {
            error_log("Cart rec (competition slot) error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fill an olympiad slot. Olympiads are always available (browse).
     */
    private function fillOlympiadSlot(array $audienceSlugs, array $excludeIds, array $context = []): ?array {
        try {
            $results = $this->getOlympiadRecommendations($audienceSlugs, $excludeIds, 1, $context);
            return $results[0] ?? null;
        } catch (\Throwable $e) {
            error_log("Cart rec (olympiad slot) error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get olympiad recommendations matching audience.
     */
    private function getOlympiadRecommendations(array $audienceSlugs, array $excludeIds, int $limit, array $context = []): array {
        if (empty($audienceSlugs)) {
            return [];
        }

        $limitSafe = intval($limit);
        $audiencePlaceholders = implode(',', array_fill(0, count($audienceSlugs), '?'));

        // Скоринг по специализациям (v2)
        $score = $this->buildSpecScoreJoin('olympiad_specializations', 'olympiad_id', 'o', $context);

        $params = array_merge($score['join_params'], $audienceSlugs);

        $excludeClause = '';
        if (!empty($excludeIds)) {
            $excludePlaceholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $excludeClause = "AND o.id NOT IN ($excludePlaceholders)";
            $params = array_merge($params, $excludeIds);
        }

        $rows = $this->db->query(
            "SELECT o.id, o.title, o.slug, o.diploma_price, o.target_audience
                    {$score['select_expr']}
             FROM olympiads o
             {$score['join_sql']}
             JOIN olympiad_audience_types oat ON o.id = oat.olympiad_id
             JOIN audience_types at ON oat.audience_type_id = at.id
             WHERE o.is_active = 1
               AND at.slug IN ($audiencePlaceholders)
               $excludeClause
             GROUP BY o.id
             ORDER BY {$score['order_expr']}o.display_order ASC
             LIMIT $limitSafe",
            $params
        );

        return array_map(function ($row) {
            return [
                'type' => 'olympiad',
                'id' => (int)$row['id'],
                'title' => $row['title'],
                'slug' => $row['slug'],
                'price' => (float)($row['diploma_price'] ?? 169),
                'meta' => $this->getOlympiadAudienceLabel($row['target_audience'] ?? ''),
                'quick_add' => false,
                'add_data' => null,
            ];
        }, $rows);
    }

    /**
     * Get human-readable audience label for olympiad.
     */
    private function getOlympiadAudienceLabel(string $audience): string {
        $labels = [
            'pedagogues_dou' => 'Для педагогов ДОУ',
            'pedagogues_school' => 'Для педагогов школ',
            'pedagogues_ovz' => 'Для педагогов ОВЗ',
            'students' => 'Для школьников',
            'preschoolers' => 'Для дошкольников',
            'logopedists' => 'Для логопедов',
        ];
        return $labels[$audience] ?? 'Олимпиада';
    }

    /**
     * Fill a webinar slot with cascading fallback:
     *   1. Webinar certificate quick-add (if user logged in and has registrations)
     *   2. Webinar browse matching audience
     *   3. Webinar browse without audience filter (any videolecture)
     *   4. Static webinar listing CTA (last resort)
     */
    private function fillWebinarSlot(array $audienceSlugs, array $excludeIds, ?int $userId, array $context = []): ?array {
        // Priority 1: Quick-add certificate (с учётом специализаций)
        if ($userId) {
            try {
                $certs = $this->getWebinarRecommendations($audienceSlugs, $excludeIds, $userId, 1, $context);
                if (!empty($certs)) {
                    return $certs[0];
                }
            } catch (\Throwable $e) {
                error_log("Cart rec (webinar cert slot) error: " . $e->getMessage());
            }
        }

        // Priority 2: Webinar browse matching audience (с учётом специализаций)
        try {
            $browse = $this->getWebinarBrowseRecommendations($audienceSlugs, $excludeIds, 1, $context);
            if (!empty($browse)) {
                return $browse[0];
            }
        } catch (\Throwable $e) {
            error_log("Cart rec (webinar browse slot) error: " . $e->getMessage());
        }

        // Priority 3: Webinar browse without audience filter (без скоринга)
        try {
            $allAudience = ['dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'spo'];
            $browse = $this->getWebinarBrowseRecommendations($allAudience, $excludeIds, 1);
            if (!empty($browse)) {
                return $browse[0];
            }
        } catch (\Throwable $e) {
            error_log("Cart rec (webinar browse fallback) error: " . $e->getMessage());
        }

        // Priority 4: Static CTA to webinar listing (last resort)
        $cta = $this->getWebinarListingCTA();
        return $cta[0];
    }

    /**
     * Fill a publication slot with cascading fallback:
     *   1. Publication certificate quick-add (if user has published works)
     *   2. Static "publish your work" CTA
     */
    private function fillPublicationSlot(array $audienceSlugs, array $excludeIds, ?int $userId, array $context = []): ?array {
        // Priority 1: Quick-add certificate (с учётом специализаций)
        if ($userId) {
            try {
                $certs = $this->getPublicationRecommendations($audienceSlugs, $excludeIds, $userId, 1, $context);
                if (!empty($certs)) {
                    return $certs[0];
                }
            } catch (\Throwable $e) {
                error_log("Cart rec (publication cert slot) error: " . $e->getMessage());
            }
        }

        // Priority 2: Static CTA
        $cta = $this->getPublicationCTA();
        return $cta[0];
    }

    // ── New recommendation sources ──

    /**
     * Get webinar browse recommendations (available for ALL users).
     * Returns webinars matching audience type for browsing, not certificate purchase.
     */
    private function getWebinarBrowseRecommendations(array $audienceSlugs, array $excludeWebinarIds, int $limit, array $context = []): array {
        if (empty($audienceSlugs)) {
            return [];
        }

        $limitSafe = intval($limit);
        $audiencePlaceholders = implode(',', array_fill(0, count($audienceSlugs), '?'));

        // Скоринг по специализациям (v2)
        $score = $this->buildSpecScoreJoin('webinar_specializations', 'webinar_id', 'w', $context);

        $params = array_merge($score['join_params'], $audienceSlugs);

        $excludeClause = '';
        if (!empty($excludeWebinarIds)) {
            $excludePlaceholders = implode(',', array_fill(0, count($excludeWebinarIds), '?'));
            $excludeClause = "AND w.id NOT IN ($excludePlaceholders)";
            $params = array_merge($params, $excludeWebinarIds);
        }

        $rows = $this->db->query(
            "SELECT w.id, w.title, w.slug, w.certificate_price, w.status,
                    w.certificate_hours
                    {$score['select_expr']}
             FROM webinars w
             {$score['join_sql']}
             JOIN webinar_audience_types wat ON w.id = wat.webinar_id
             JOIN audience_types at ON wat.audience_type_id = at.id
             WHERE w.is_active = 1
               AND at.slug IN ($audiencePlaceholders)
               AND w.status IN ('completed', 'videolecture')
               $excludeClause
             GROUP BY w.id
             ORDER BY {$score['order_expr']}
               CASE w.status
                 WHEN 'videolecture' THEN 1
                 WHEN 'completed' THEN 2
               END,
               w.scheduled_at DESC
             LIMIT $limitSafe",
            $params
        );

        return array_map(function ($row) {
            $meta = 'Видеолекция • ' . ($row['certificate_hours'] ?? 2) . ' ч.';

            return [
                'type' => 'webinar_browse',
                'id' => (int)$row['id'],
                'title' => $row['title'],
                'slug' => $row['slug'],
                'price' => (float)($row['certificate_price'] ?? 200),
                'meta' => $meta,
                'quick_add' => false,
                'add_data' => null,
            ];
        }, $rows);
    }

    /**
     * Get a static "publish your work" CTA card.
     */
    private function getPublicationCTA(): array {
        return [[
            'type' => 'publication_cta',
            'id' => 0,
            'title' => 'Опубликуйте свою работу',
            'slug' => '',
            'price' => 169.0,
            'meta' => 'Бесплатная публикация + свидетельство за 169 ₽',
            'quick_add' => false,
            'add_data' => null,
        ]];
    }

    /**
     * Get a static "browse webinars" CTA card.
     */
    private function getWebinarListingCTA(): array {
        return [[
            'type' => 'webinar_listing_cta',
            'id' => 0,
            'title' => 'Примите участие в вебинаре',
            'slug' => '',
            'price' => 200.0,
            'meta' => 'Бесплатное участие + сертификат за 200 ₽',
            'quick_add' => false,
            'add_data' => null,
        ]];
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

    // ── Course recommendations ──

    /**
     * Fill a course slot. Courses are always available (browse).
     *   1. Course browse matching audience
     *   2. Static "browse courses" CTA (last resort)
     */
    private function fillCourseSlot(array $audienceSlugs, array $excludeIds, array $context = []): ?array {
        try {
            $results = $this->getCourseRecommendations($audienceSlugs, $excludeIds, 1, $context);
            if (!empty($results)) {
                return $results[0];
            }
        } catch (\Throwable $e) {
            error_log("Cart rec (course slot) error: " . $e->getMessage());
        }

        // Fallback: static CTA
        return $this->getCourseListingCTA()[0];
    }

    /**
     * Get course recommendations matching audience.
     */
    private function getCourseRecommendations(array $audienceSlugs, array $excludeIds, int $limit, array $context = []): array {
        if (empty($audienceSlugs)) {
            return [];
        }

        $limitSafe = intval($limit);
        $audiencePlaceholders = implode(',', array_fill(0, count($audienceSlugs), '?'));

        $score = $this->buildSpecScoreJoin('course_specializations', 'course_id', 'cr', $context);

        $params = array_merge($score['join_params'], $audienceSlugs);

        $excludeClause = '';
        if (!empty($excludeIds)) {
            $excludePlaceholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $excludeClause = "AND cr.id NOT IN ($excludePlaceholders)";
            $params = array_merge($params, $excludeIds);
        }

        $rows = $this->db->query(
            "SELECT cr.id, cr.title, cr.slug, cr.price, cr.program_type, cr.hours
                    {$score['select_expr']}
             FROM courses cr
             {$score['join_sql']}
             JOIN course_audience_types cat ON cr.id = cat.course_id
             JOIN audience_types at ON cat.audience_type_id = at.id
             WHERE cr.is_active = 1
               AND at.slug IN ($audiencePlaceholders)
               $excludeClause
             GROUP BY cr.id
             ORDER BY {$score['order_expr']}cr.display_order ASC
             LIMIT $limitSafe",
            $params
        );

        return array_map(function ($row) {
            $typeLabel = $row['program_type'] === 'pp' ? 'Переподготовка' : 'Повышение квалификации';
            $meta = $typeLabel . ' • ' . $row['hours'] . ' ч.';

            return [
                'type' => 'course',
                'id' => (int)$row['id'],
                'title' => $row['title'],
                'slug' => $row['slug'],
                'price' => (float)$row['price'],
                'meta' => $meta,
                'quick_add' => false,
                'add_data' => null,
            ];
        }, $rows);
    }

    /**
     * Get a static "browse courses" CTA card.
     */
    private function getCourseListingCTA(): array {
        return [[
            'type' => 'course_cta',
            'id' => 0,
            'title' => 'Курсы повышения квалификации',
            'slug' => '',
            'price' => 2900.0,
            'meta' => 'КПК и переподготовка • от 36 ч. • с удостоверением',
            'quick_add' => false,
            'add_data' => null,
        ]];
    }
}
