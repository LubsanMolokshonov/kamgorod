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
        return $this->mergeResults($visits, $applications, $orders, $groupByCol);
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
        $where = ['v.is_bot = 0', 'v.utm_source IS NOT NULL'];
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

    private function queryCourseApplications(array $filters, array $groupColumns, array $parentUtm): array
    {
        $groupBy = implode(', ', array_map(fn($c) => str_replace('v.', 'ce.', $c), $groupColumns));
        $where = ['ce.utm_source IS NOT NULL'];
        $params = [];

        $this->addDateFilter($where, $params, 'ce.created_at', $filters['date_from'] ?? '', $filters['date_to'] ?? '');
        $this->addParentUtmFilter($where, $params, $parentUtm, 'ce');

        $whereSql = implode(' AND ', $where);

        $rows = $this->db->query(
            "SELECT {$groupBy},
                    COUNT(*) as course_applications
             FROM course_enrollments ce
             WHERE {$whereSql}
             GROUP BY {$groupBy}",
            $params
        );

        $lastCol = str_replace('v.', 'ce.', end($groupColumns));
        return $this->indexByKey($rows, $lastCol);
    }

    private function queryCourseApplicationsTotals(array $filters): array
    {
        $where = ['ce.utm_source IS NOT NULL'];
        $params = [];
        $this->addDateFilter($where, $params, 'ce.created_at', $filters['date_from'] ?? '', $filters['date_to'] ?? '');

        $whereSql = implode(' AND ', $where);

        return $this->db->queryOne(
            "SELECT COUNT(*) as course_applications FROM course_enrollments ce WHERE {$whereSql}",
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
        $where = ['o.utm_source IS NOT NULL'];
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
