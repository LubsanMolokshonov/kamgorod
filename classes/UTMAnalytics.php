<?php
/**
 * UTM-аналитика — агрегация данных по UTM-меткам
 * Иерархия: Source → Campaign → Content → Term
 */

class UTMAnalytics
{
    private Database $db;

    private const LEVELS = [
        'source'   => 'utm_source',
        'campaign' => 'utm_campaign',
        'content'  => 'utm_content',
        'term'     => 'utm_term',
    ];

    private const LEVEL_ORDER = ['source', 'campaign', 'content', 'term'];

    public function __construct($pdo)
    {
        $this->db = new Database($pdo);
    }

    /**
     * Получить данные отчёта для указанного уровня иерархии
     *
     * @param array $filters ['date_from', 'date_to', 'paid_from', 'paid_to', 'product_type']
     * @param string $groupLevel source|campaign|content|term
     * @param array $parentUtm ['utm_source' => '...', ...]
     * @return array
     */
    public function getReport(array $filters, string $groupLevel = 'source', array $parentUtm = []): array
    {
        $groupColumns = $this->getGroupColumns($groupLevel);
        $groupByCol = self::LEVELS[$groupLevel];

        // 1. Визиты
        $visits = $this->queryVisits($filters, $groupColumns, $parentUtm);

        // 2. Заявки на курсы
        $applications = $this->queryCourseApplications($filters, $groupColumns, $parentUtm);

        // 3. Заказы
        $orders = $this->queryOrders($filters, $groupColumns, $parentUtm);

        // Мержим по ключу группировки
        $results = $this->mergeResults($visits, $applications, $orders, $groupByCol);

        // На уровне source добавляем разбивку "(без UTM)" для записей без меток.
        // Вместо одной строки показываем источник, определённый по referrer визита
        // (органика Яндекс/Google, прямой заход, внутренний переход и т.д.) —
        // иначе ~20% трафика выглядят как загадочный «безутэмный» блок.
        if ($groupLevel === 'source' && empty($parentUtm)) {
            $results = array_merge($results, $this->buildNoUtmBreakdown($filters));
        }

        return $results;
    }

    /**
     * Итого — суммарные данные без группировки
     */
    public function getTotals(array $filters): array
    {
        $visits = $this->queryVisitsTotals($filters);
        $applications = $this->queryCourseApplicationsTotals($filters);
        $orders = $this->queryOrdersTotals($filters);

        return $this->buildRow('Итого и средние', $visits, $applications, $orders);
    }

    // ========================================
    // Визиты
    // ========================================

    private function queryVisits(array $filters, array $groupColumns, array $parentUtm): array
    {
        $groupBy = implode(', ', $groupColumns);
        $where = ['v.is_bot = 0'];
        $params = [];

        $this->addDateFilter($where, $params, 'v.started_at', $filters['date_from'] ?? '', $filters['date_to'] ?? '');
        $this->addParentUtmFilter($where, $params, $parentUtm, 'v');
        // Только визиты с utm_source
        $where[] = 'v.utm_source IS NOT NULL';

        $whereSql = implode(' AND ', $where);

        $rows = $this->db->query(
            "SELECT {$groupBy},
                    COUNT(*) as visits,
                    ROUND(AVG(v.duration_seconds)) as avg_duration
             FROM visits v
             WHERE {$whereSql}
             GROUP BY {$groupBy}
             ORDER BY visits DESC",
            $params
        );

        return $this->indexByLastColumn($rows, $groupColumns);
    }

    private function queryVisitsTotals(array $filters): array
    {
        $where = ['v.is_bot = 0'];
        $params = [];
        $this->addDateFilter($where, $params, 'v.started_at', $filters['date_from'] ?? '', $filters['date_to'] ?? '');

        $whereSql = implode(' AND ', $where);

        return $this->db->queryOne(
            "SELECT COUNT(*) as visits, ROUND(AVG(v.duration_seconds)) as avg_duration
             FROM visits v WHERE {$whereSql}",
            $params
        ) ?: ['visits' => 0, 'avg_duration' => 0];
    }

    // ========================================
    // Заявки на курсы
    // ========================================

    /**
     * Источник «заявок по курсам» — UNION регистраций на курс и заявок на консультацию.
     * Используется как виртуальная таблица с алиасом ce.
     *
     * Тащим ym_uid: по нему восстанавливаем источник заявок без UTM
     * (join на visits.session_id = CONCAT('ym_', ce.ym_uid) — см. ниже).
     * Фильтр тестовых/мусорных заявок (testFilterSql) подставляется вызывающим кодом.
     */
    private const COURSE_LEADS_SOURCE = "(
        SELECT utm_source, utm_medium, utm_campaign, utm_content, utm_term, ym_uid, email, created_at
          FROM course_enrollments
        UNION ALL
        SELECT utm_source, utm_medium, utm_campaign, utm_content, utm_term, ym_uid, NULL AS email, created_at
          FROM course_consultations
    ) ce";

    /**
     * SQL-условие, отсеивающее тестовые/мусорные заявки по email.
     * У консультаций email нет (NULL) — их не трогаем (NULL проходит).
     * Записи в БД не удаляются, только не попадают в аналитику.
     */
    private function testLeadsFilterSql(string $alias = 'ce'): string
    {
        // Якоримся на явный мусор (^test@, клавиатурные наборы, фейк-домены),
        // чтобы не отсечь реальных учителей (напр. tester@school.ru проходит).
        return "({$alias}.email IS NULL OR (
            {$alias}.email LIKE '%@%.%'
            AND {$alias}.email NOT REGEXP '^(test|asdf|qwer|qwe|dsfsd|sdfsd|sdfsdf|ddfg)@'
            AND {$alias}.email NOT REGEXP '@(dsfsd|sdfsd|sdfsdf|test)\\\\.'
            AND {$alias}.email NOT IN ('lubsanmolokshonov@gmail.com', 'naya@gmail.com')
        ))";
    }

    private function queryCourseApplications(array $filters, array $groupColumns, array $parentUtm): array
    {
        $groupBy = implode(', ', array_map(fn($c) => str_replace('v.', 'ce.', $c), $groupColumns));
        $where = ['ce.utm_source IS NOT NULL', $this->testLeadsFilterSql('ce')];
        $params = [];

        $this->addDateFilter($where, $params, 'ce.created_at', $filters['date_from'] ?? '', $filters['date_to'] ?? '');
        $this->addParentUtmFilter($where, $params, $parentUtm, 'ce');

        $whereSql = implode(' AND ', $where);
        $source = self::COURSE_LEADS_SOURCE;

        $rows = $this->db->query(
            "SELECT {$groupBy},
                    COUNT(*) as course_applications
             FROM {$source}
             WHERE {$whereSql}
             GROUP BY {$groupBy}",
            $params
        );

        $lastCol = str_replace('v.', 'ce.', end($groupColumns));
        $result = $this->indexByKey($rows, $lastCol);

        // На уровне source (без drill-down) доклеиваем заявки без UTM, у которых
        // источник восстановлен по ym_uid → visits.utm_source. Так реклама Яндекса,
        // потерявшая UTM на форме, попадает в строку 'yandex', а не в «без источника».
        $isSourceLevel = (str_replace('v.', 'ce.', end($groupColumns)) === 'ce.utm_source');
        if ($isSourceLevel && empty($parentUtm)) {
            foreach ($this->recoverNoUtmAppsBySource($filters) as $src => $cnt) {
                if (!isset($result[$src])) {
                    $result[$src] = ['utm_source' => $src, 'course_applications' => 0];
                }
                $result[$src]['course_applications'] += $cnt;
            }
        }

        return $result;
    }

    /**
     * Заявки без utm_source, у которых по ym_uid в visits нашёлся визит С utm_source.
     * Возвращает [utm_source => count]. Используется для доклейки в source-строки отчёта.
     * Только такие заявки (с восстановленным реальным источником) — заявки, где визит
     * без UTM или не найден, остаются в «без UTM» разбивке (buildNoUtmBreakdown).
     */
    private function recoverNoUtmAppsBySource(array $filters): array
    {
        $where = ['ce.utm_source IS NULL', 'ce.ym_uid IS NOT NULL', $this->testLeadsFilterSql('ce')];
        $params = [];
        $this->addDateFilter($where, $params, 'ce.created_at', $filters['date_from'] ?? '', $filters['date_to'] ?? '');
        $whereSql = implode(' AND ', $where);
        $source = self::COURSE_LEADS_SOURCE;

        // Для каждой заявки берём самый ранний визит с utm_source по её ym_uid.
        // JOIN (не LEFT) намеренный: заявки, у которых визита с utm_source нет,
        // сюда не попадают — они остаются в no-UTM разбивке (по referrer-категории).
        // НЕ менять на LEFT JOIN — иначе сломается дедупликация и сумма != total.
        $rows = $this->db->query(
            "SELECT rec.utm_source AS src, COUNT(*) AS cnt
             FROM {$source}
             JOIN LATERAL (
                 SELECT v.utm_source
                 FROM visits v
                 WHERE v.session_id = CONCAT('ym_', ce.ym_uid)
                   AND v.utm_source IS NOT NULL
                 ORDER BY v.started_at
                 LIMIT 1
             ) rec
             WHERE {$whereSql}
             GROUP BY rec.utm_source",
            $params
        );

        $out = [];
        foreach ($rows as $r) {
            $out[$r['src']] = (int)$r['cnt'];
        }
        return $out;
    }

    private function queryCourseApplicationsTotals(array $filters): array
    {
        $where = [$this->testLeadsFilterSql('ce')];
        $params = [];
        $this->addDateFilter($where, $params, 'ce.created_at', $filters['date_from'] ?? '', $filters['date_to'] ?? '');

        $whereSql = implode(' AND ', $where);
        $source = self::COURSE_LEADS_SOURCE;

        return $this->db->queryOne(
            "SELECT COUNT(*) as course_applications FROM {$source} WHERE {$whereSql}",
            $params
        ) ?: ['course_applications' => 0];
    }

    // ========================================
    // Заказы
    // ========================================

    private function queryOrders(array $filters, array $groupColumns, array $parentUtm): array
    {
        $groupBy = implode(', ', array_map(fn($c) => str_replace('v.', 'o.', $c), $groupColumns));
        $where = ['o.utm_source IS NOT NULL'];
        $params = [];

        $this->addDateFilter($where, $params, 'o.created_at', $filters['date_from'] ?? '', $filters['date_to'] ?? '');
        $this->addParentUtmFilter($where, $params, $parentUtm, 'o');
        $this->addProductTypeFilter($where, $filters['product_type'] ?? 'all');

        // Фильтр по периоду оплаты для paid-метрик
        $paidWhere = [];
        $paidParams = [];
        if (!empty($filters['paid_from'])) {
            $paidWhere[] = 'o.paid_at >= ?';
            $paidParams[] = $filters['paid_from'] . ' 00:00:00';
        }
        if (!empty($filters['paid_to'])) {
            $paidWhere[] = 'o.paid_at <= ?';
            $paidParams[] = $filters['paid_to'] . ' 23:59:59';
        }
        $paidCondition = !empty($paidWhere)
            ? 'o.payment_status = \'succeeded\' AND ' . implode(' AND ', $paidWhere)
            : 'o.payment_status = \'succeeded\'';

        $whereSql = implode(' AND ', $where);
        $allParams = array_merge($params, $paidParams, $paidParams);

        $rows = $this->db->query(
            "SELECT {$groupBy},
                    COUNT(DISTINCT o.id) as created_orders,
                    COUNT(DISTINCT CASE WHEN {$paidCondition} THEN o.id END) as paid_orders,
                    COALESCE(SUM(CASE WHEN {$paidCondition} THEN o.final_amount ELSE 0 END), 0) as revenue
             FROM orders o
             WHERE {$whereSql}
             GROUP BY {$groupBy}
             ORDER BY created_orders DESC",
            $allParams
        );

        $lastCol = str_replace('v.', 'o.', end($groupColumns));
        return $this->indexByKey($rows, $lastCol);
    }

    private function queryOrdersTotals(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];
        $this->addDateFilter($where, $params, 'o.created_at', $filters['date_from'] ?? '', $filters['date_to'] ?? '');
        $this->addProductTypeFilter($where, $filters['product_type'] ?? 'all');

        $paidWhere = [];
        $paidParams = [];
        if (!empty($filters['paid_from'])) {
            $paidWhere[] = 'o.paid_at >= ?';
            $paidParams[] = $filters['paid_from'] . ' 00:00:00';
        }
        if (!empty($filters['paid_to'])) {
            $paidWhere[] = 'o.paid_at <= ?';
            $paidParams[] = $filters['paid_to'] . ' 23:59:59';
        }
        $paidCondition = !empty($paidWhere)
            ? 'o.payment_status = \'succeeded\' AND ' . implode(' AND ', $paidWhere)
            : 'o.payment_status = \'succeeded\'';

        $whereSql = implode(' AND ', $where);
        $allParams = array_merge($params, $paidParams, $paidParams);

        return $this->db->queryOne(
            "SELECT COUNT(DISTINCT o.id) as created_orders,
                    COUNT(DISTINCT CASE WHEN {$paidCondition} THEN o.id END) as paid_orders,
                    COALESCE(SUM(CASE WHEN {$paidCondition} THEN o.final_amount ELSE 0 END), 0) as revenue
             FROM orders o
             WHERE {$whereSql}",
            $allParams
        ) ?: ['created_orders' => 0, 'paid_orders' => 0, 'revenue' => 0];
    }

    // ========================================
    // Записи без UTM-меток
    // ========================================

    private function queryVisitsNoUtm(array $filters): array
    {
        $where = ['v.is_bot = 0', 'v.utm_source IS NULL'];
        $params = [];
        $this->addDateFilter($where, $params, 'v.started_at', $filters['date_from'] ?? '', $filters['date_to'] ?? '');
        $whereSql = implode(' AND ', $where);

        return $this->db->queryOne(
            "SELECT COUNT(*) as visits, ROUND(AVG(v.duration_seconds)) as avg_duration
             FROM visits v WHERE {$whereSql}",
            $params
        ) ?: ['visits' => 0, 'avg_duration' => 0];
    }

    /**
     * Заявки без UTM, разбитые по источнику из referrer визита (по ym_uid).
     * Заявки, у которых источник восстановлен до реального utm_source, сюда НЕ
     * попадают — они уже доклеены в source-строки (recoverNoUtmAppsBySource).
     * Возвращает [категория-referrer => count] + строку-fallback для остального.
     */
    private function queryCourseApplicationsNoUtmByCategory(array $filters): array
    {
        $where = ['ce.utm_source IS NULL', $this->testLeadsFilterSql('ce')];
        $params = [];
        $this->addDateFilter($where, $params, 'ce.created_at', $filters['date_from'] ?? '', $filters['date_to'] ?? '');
        $whereSql = implode(' AND ', $where);
        $source = self::COURSE_LEADS_SOURCE;
        $catSql = $this->referrerCategorySql('rec');
        $fallback = self::NO_UTM_FALLBACK_LABEL;

        // Исключаем заявки, у которых ХОТЬ ОДИН визит по ym_uid имеет utm_source —
        // они уже доклеены в source-строку (recoverNoUtmAppsBySource). Так гарантируем,
        // что сумма по всем строкам = total и нет двойного учёта.
        // rec — представительный визит для классификации по referrer (с непустым
        // referrer в приоритете). Если визита нет вовсе — строка-fallback.
        $rows = $this->db->query(
            "SELECT COALESCE(rec.cat, '{$fallback}') AS cat, COUNT(*) AS course_applications
             FROM {$source}
             LEFT JOIN LATERAL (
                 SELECT {$catSql} AS cat
                 FROM visits v
                 WHERE v.session_id = CONCAT('ym_', ce.ym_uid)
                 ORDER BY (v.referrer IS NULL OR v.referrer = ''), v.started_at
                 LIMIT 1
             ) rec ON TRUE
             WHERE {$whereSql}
               AND NOT EXISTS (
                   SELECT 1 FROM visits v2
                   WHERE v2.session_id = CONCAT('ym_', ce.ym_uid)
                     AND v2.utm_source IS NOT NULL
               )
             GROUP BY cat",
            $params
        );

        $out = [];
        foreach ($rows as $r) {
            $out[$r['cat']] = (int)$r['course_applications'];
        }
        return $out;
    }

    private function queryOrdersNoUtm(array $filters): array
    {
        $where = ['o.utm_source IS NULL'];
        $params = [];
        $this->addDateFilter($where, $params, 'o.created_at', $filters['date_from'] ?? '', $filters['date_to'] ?? '');
        $this->addProductTypeFilter($where, $filters['product_type'] ?? 'all');

        $paidWhere = [];
        $paidParams = [];
        if (!empty($filters['paid_from'])) {
            $paidWhere[] = 'o.paid_at >= ?';
            $paidParams[] = $filters['paid_from'] . ' 00:00:00';
        }
        if (!empty($filters['paid_to'])) {
            $paidWhere[] = 'o.paid_at <= ?';
            $paidParams[] = $filters['paid_to'] . ' 23:59:59';
        }
        $paidCondition = !empty($paidWhere)
            ? 'o.payment_status = \'succeeded\' AND ' . implode(' AND ', $paidWhere)
            : 'o.payment_status = \'succeeded\'';

        $whereSql = implode(' AND ', $where);
        $allParams = array_merge($params, $paidParams, $paidParams);

        return $this->db->queryOne(
            "SELECT COUNT(DISTINCT o.id) as created_orders,
                    COUNT(DISTINCT CASE WHEN {$paidCondition} THEN o.id END) as paid_orders,
                    COALESCE(SUM(CASE WHEN {$paidCondition} THEN o.final_amount ELSE 0 END), 0) as revenue
             FROM orders o
             WHERE {$whereSql}",
            $allParams
        ) ?: ['created_orders' => 0, 'paid_orders' => 0, 'revenue' => 0];
    }

    // ========================================
    // Разбивка трафика без UTM по источнику (referrer)
    // ========================================

    /**
     * SQL-выражение, классифицирующее визит по referrer в человекочитаемый источник.
     * Используется одинаково для визитов и заказов (через JOIN visits).
     */
    private function referrerCategorySql(string $alias = 'v'): string
    {
        return "CASE
            WHEN {$alias}.referrer IS NULL OR {$alias}.referrer = '' THEN '(без UTM · прямой / закладки)'
            WHEN {$alias}.referrer LIKE '%yandex.%' OR {$alias}.referrer LIKE '%ya.ru%' THEN '(без UTM · Яндекс-органика)'
            WHEN {$alias}.referrer LIKE '%google.%' OR {$alias}.referrer LIKE '%gmail%' THEN '(без UTM · Google/Gmail)'
            WHEN {$alias}.referrer LIKE '%mail.ru%' THEN '(без UTM · Mail.ru)'
            WHEN {$alias}.referrer LIKE '%vk.com%' OR {$alias}.referrer LIKE '%vk.ru%' THEN '(без UTM · VK)'
            WHEN {$alias}.referrer LIKE '%bing.%' OR {$alias}.referrer LIKE '%duckduckgo%' THEN '(без UTM · др. поисковики)'
            WHEN {$alias}.referrer LIKE '%yoomoney%' OR {$alias}.referrer LIKE '%yookassa%' THEN '(без UTM · возврат с оплаты)'
            WHEN {$alias}.referrer LIKE '%fgos.pro%' THEN '(без UTM · внутр. переход)'
            ELSE '(без UTM · внешние сайты)'
        END";
    }

    private const NO_UTM_FALLBACK_LABEL = '(без UTM · источник не определён)';

    /**
     * Строит несколько строк отчёта для трафика без UTM, сгруппированного по
     * источнику из referrer. Метрики визитов берём из visits, метрики заказов —
     * из orders с JOIN на их визит. Заказы/заявки без привязки к визиту
     * сводятся в одну строку-fallback.
     */
    private function buildNoUtmBreakdown(array $filters): array
    {
        $catSql = $this->referrerCategorySql('v');

        // 1. Визиты без UTM по категориям referrer
        $vWhere = ['v.is_bot = 0', 'v.utm_source IS NULL'];
        $vParams = [];
        $this->addDateFilter($vWhere, $vParams, 'v.started_at', $filters['date_from'] ?? '', $filters['date_to'] ?? '');
        $visitRows = $this->db->query(
            "SELECT {$catSql} AS cat, COUNT(*) AS visits, ROUND(AVG(v.duration_seconds)) AS avg_duration
             FROM visits v WHERE " . implode(' AND ', $vWhere) . " GROUP BY cat",
            $vParams
        );

        // 2. Заказы без UTM по категориям referrer их визита
        $oWhere = ['(o.utm_source IS NULL OR o.utm_source = \'\')'];
        $oParams = [];
        $this->addDateFilter($oWhere, $oParams, 'o.created_at', $filters['date_from'] ?? '', $filters['date_to'] ?? '');
        $this->addProductTypeFilter($oWhere, $filters['product_type'] ?? 'all');

        [$paidCondition, $paidParams] = $this->buildPaidCondition($filters);
        $fallbackLabel = self::NO_UTM_FALLBACK_LABEL;
        $orderCatSql = "COALESCE(NULLIF({$catSql}, ''), '{$fallbackLabel}')";
        // Заказы без визита (visit_id IS NULL) попадают в строку-fallback
        $orderCatExpr = "CASE WHEN v.id IS NULL THEN '{$fallbackLabel}' ELSE {$orderCatSql} END";

        $orderRows = $this->db->query(
            "SELECT {$orderCatExpr} AS cat,
                    COUNT(DISTINCT o.id) AS created_orders,
                    COUNT(DISTINCT CASE WHEN {$paidCondition} THEN o.id END) AS paid_orders,
                    COALESCE(SUM(CASE WHEN {$paidCondition} THEN o.final_amount ELSE 0 END), 0) AS revenue
             FROM orders o
             LEFT JOIN visits v ON v.id = o.visit_id
             WHERE " . implode(' AND ', $oWhere) . " GROUP BY cat",
            array_merge($oParams, $paidParams, $paidParams)
        );

        // 3. Заявки на курсы без UTM — разбиты по категории referrer визита (по ym_uid).
        // Заявки с восстановленным реальным utm_source сюда не входят (уже в source-строках).
        $appsNoUtmByCat = $this->queryCourseApplicationsNoUtmByCategory($filters);

        // Мержим по категории
        $byCat = [];
        foreach ($visitRows as $r) {
            $byCat[$r['cat']]['visits'] = (int)$r['visits'];
            $byCat[$r['cat']]['avg_duration'] = (int)$r['avg_duration'];
        }
        foreach ($orderRows as $r) {
            $byCat[$r['cat']]['created_orders'] = (int)$r['created_orders'];
            $byCat[$r['cat']]['paid_orders'] = (int)$r['paid_orders'];
            $byCat[$r['cat']]['revenue'] = (float)$r['revenue'];
        }
        foreach ($appsNoUtmByCat as $cat => $cnt) {
            $byCat[$cat]['course_applications'] = $cnt;
        }

        $rows = [];
        foreach ($byCat as $label => $m) {
            $row = $this->buildRow(
                $label,
                ['visits' => $m['visits'] ?? 0, 'avg_duration' => $m['avg_duration'] ?? 0],
                ['course_applications' => $m['course_applications'] ?? 0],
                [
                    'created_orders' => $m['created_orders'] ?? 0,
                    'paid_orders' => $m['paid_orders'] ?? 0,
                    'revenue' => $m['revenue'] ?? 0,
                ]
            );
            $row['is_no_utm'] = true; // фронт не показывает drill-down toggle
            if ($row['visits'] > 0 || $row['created_orders'] > 0 || $row['course_applications'] > 0) {
                $rows[] = $row;
            }
        }

        // Сортируем по визитам desc
        usort($rows, fn($a, $b) => $b['visits'] - $a['visits']);

        return $rows;
    }

    /**
     * Возвращает [SQL-условие оплаты, параметры] на основе фильтров paid_from/paid_to.
     */
    private function buildPaidCondition(array $filters): array
    {
        $paidWhere = [];
        $paidParams = [];
        if (!empty($filters['paid_from'])) {
            $paidWhere[] = 'o.paid_at >= ?';
            $paidParams[] = $filters['paid_from'] . ' 00:00:00';
        }
        if (!empty($filters['paid_to'])) {
            $paidWhere[] = 'o.paid_at <= ?';
            $paidParams[] = $filters['paid_to'] . ' 23:59:59';
        }
        $cond = !empty($paidWhere)
            ? "o.payment_status = 'succeeded' AND " . implode(' AND ', $paidWhere)
            : "o.payment_status = 'succeeded'";
        return [$cond, $paidParams];
    }

    // ========================================
    // Helpers
    // ========================================

    private function getGroupColumns(string $level): array
    {
        $columns = [];
        foreach (self::LEVEL_ORDER as $l) {
            $columns[] = 'v.' . self::LEVELS[$l];
            if ($l === $level) break;
        }
        return $columns;
    }

    private function addDateFilter(array &$where, array &$params, string $column, string $from, string $to): void
    {
        if (!empty($from)) {
            $where[] = "{$column} >= ?";
            $params[] = $from . ' 00:00:00';
        }
        if (!empty($to)) {
            $where[] = "{$column} <= ?";
            $params[] = $to . ' 23:59:59';
        }
    }

    private function addParentUtmFilter(array &$where, array &$params, array $parentUtm, string $alias): void
    {
        foreach (self::LEVEL_ORDER as $level) {
            $col = self::LEVELS[$level];
            if (isset($parentUtm[$col]) && $parentUtm[$col] !== '') {
                $where[] = "{$alias}.{$col} = ?";
                $params[] = $parentUtm[$col];
            }
        }
    }

    private function addProductTypeFilter(array &$where, string $productType): void
    {
        if ($productType === 'courses') {
            // Только заказы с курсами
            $where[] = 'EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.course_enrollment_id IS NOT NULL)';
        } elseif ($productType === 'pedportal') {
            // Только заказы без курсов (конкурсы, олимпиады, вебинары, публикации)
            $where[] = 'EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.course_enrollment_id IS NULL)';
        }
    }

    private function indexByLastColumn(array $rows, array $groupColumns): array
    {
        $lastCol = end($groupColumns);
        // Убираем алиас таблицы
        $key = str_contains($lastCol, '.') ? explode('.', $lastCol)[1] : $lastCol;
        $result = [];
        foreach ($rows as $row) {
            $k = $row[$key] ?? '(не задано)';
            $result[$k] = $row;
        }
        return $result;
    }

    private function indexByKey(array $rows, string $column): array
    {
        $key = str_contains($column, '.') ? explode('.', $column)[1] : $column;
        $result = [];
        foreach ($rows as $row) {
            $k = $row[$key] ?? '(не задано)';
            $result[$k] = $row;
        }
        return $result;
    }

    private function mergeResults(array $visits, array $applications, array $orders, string $groupByCol): array
    {
        $allKeys = array_unique(array_merge(
            array_keys($visits),
            array_keys($applications),
            array_keys($orders)
        ));

        $results = [];
        foreach ($allKeys as $key) {
            $v = $visits[$key] ?? [];
            $a = $applications[$key] ?? [];
            $o = $orders[$key] ?? [];

            $results[] = $this->buildRow(
                $key,
                [
                    'visits' => $v['visits'] ?? 0,
                    'avg_duration' => $v['avg_duration'] ?? 0,
                ],
                [
                    'course_applications' => $a['course_applications'] ?? 0,
                ],
                [
                    'created_orders' => $o['created_orders'] ?? 0,
                    'paid_orders' => $o['paid_orders'] ?? 0,
                    'revenue' => $o['revenue'] ?? 0,
                ]
            );
        }

        // Сортируем по визитам (desc)
        usort($results, fn($a, $b) => $b['visits'] - $a['visits']);

        return $results;
    }

    private function buildRow(string $label, array $visits, array $applications, array $orders): array
    {
        $visitCount = (int)($visits['visits'] ?? 0);
        $avgDuration = (int)($visits['avg_duration'] ?? 0);
        $courseApps = (int)($applications['course_applications'] ?? 0);
        $createdOrders = (int)($orders['created_orders'] ?? 0);
        $paidOrders = (int)($orders['paid_orders'] ?? 0);
        $revenue = (float)($orders['revenue'] ?? 0);

        return [
            'label' => $label,
            'visits' => $visitCount,
            'avg_duration' => $avgDuration,
            'avg_duration_formatted' => $this->formatDuration($avgDuration),
            'course_applications' => $courseApps,
            'conv_visit_to_app' => $visitCount > 0 ? round($courseApps / $visitCount * 100, 2) : 0,
            'created_orders' => $createdOrders,
            'conv_visit_to_order' => $visitCount > 0 ? round($createdOrders / $visitCount * 100, 2) : 0,
            'paid_orders' => $paidOrders,
            'conv_order_to_paid' => $createdOrders > 0 ? round($paidOrders / $createdOrders * 100, 2) : 0,
            'conv_visit_to_paid' => $visitCount > 0 ? round($paidOrders / $visitCount * 100, 2) : 0,
            'revenue' => $revenue,
            'revenue_formatted' => number_format($revenue, 0, ',', ' '),
            'avg_check' => $paidOrders > 0 ? round($revenue / $paidOrders, 0) : 0,
            'avg_check_formatted' => $paidOrders > 0 ? number_format(round($revenue / $paidOrders, 0), 0, ',', ' ') : '0',
        ];
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) return '0 с';
        $m = floor($seconds / 60);
        $s = $seconds % 60;
        if ($m > 0) {
            return $m . ' м ' . $s . ' с';
        }
        return $s . ' с';
    }
}
