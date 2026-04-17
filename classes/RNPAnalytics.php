<?php
/**
 * РНП (Рука на пульсе) — маркетинговая аналитика по каналам и направлениям.
 *
 * Каналы (по orders.utm_source):
 *   - direct  — utm_source LIKE 'yandex%'
 *   - vk      — utm_source LIKE 'vk%'
 *   - other   — всё остальное и NULL
 *
 * Направления (по наличию order_items.course_enrollment_id):
 *   - course  — позиция курса
 *   - portal  — конкурс/олимпиада/вебинар/публикация
 *
 * Выручка смешанных заказов делится пропорционально сумме price позиций каждого направления.
 * База расчётов выручки — orders.paid_at. Созданные заказы — orders.created_at.
 */

class RNPAnalytics
{
    private Database $db;
    private \PDO $pdo;

    public const CHANNELS = ['direct', 'vk', 'other'];
    public const SECTIONS = ['portal', 'course'];

    public const COST_FIELDS = [
        'direct_portal_cost',
        'vk_portal_cost',
        'direct_course_cost',
        'vk_course_cost',
    ];

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
    }

    /**
     * Главный метод: возвращает массив строк отчёта.
     *
     * @param string $dateFrom 'YYYY-MM-DD'
     * @param string $dateTo   'YYYY-MM-DD'
     * @param string $granularity 'day' | 'week' | 'month'
     * @return array{
     *     periods: array<int, array{
     *         key: string, label: string, start: string, end: string,
     *         rows: array<string, array<string, mixed>>,
     *         total: array<string, mixed>
     *     }>,
     *     grand_total: array<string, mixed>
     * }
     */
    public function getReport(string $dateFrom, string $dateTo, string $granularity = 'day'): array
    {
        $granularity = in_array($granularity, ['day', 'week', 'month'], true) ? $granularity : 'day';

        $paidRows = $this->fetchOrderSplit($dateFrom, $dateTo, 'paid_at', $granularity);
        $createdRows = $this->fetchOrderSplit($dateFrom, $dateTo, 'created_at', $granularity);
        $costs = $this->fetchCosts($dateFrom, $dateTo, $granularity);
        $periods = $this->buildPeriods($dateFrom, $dateTo, $granularity);

        // Индексы для быстрого слияния
        $paidIdx = [];
        foreach ($paidRows as $r) {
            $paidIdx[$r['period_key']][$r['channel']][$r['section']] = $r;
        }
        $createdIdx = [];
        foreach ($createdRows as $r) {
            $createdIdx[$r['period_key']][$r['channel']][$r['section']] = $r;
        }
        $costsIdx = [];
        foreach ($costs as $c) {
            $costsIdx[$c['period_key']] = $c;
        }

        $report = [];
        $grandRows = $this->blankCellMatrix();

        foreach ($periods as $period) {
            $rows = $this->blankCellMatrix();

            foreach (self::CHANNELS as $channel) {
                foreach (self::SECTIONS as $section) {
                    $paid = $paidIdx[$period['key']][$channel][$section] ?? null;
                    $created = $createdIdx[$period['key']][$channel][$section] ?? null;

                    $cell = [
                        'channel' => $channel,
                        'section' => $section,
                        'cost' => 0.0,
                        'revenue' => $paid['revenue'] ?? 0.0,
                        'payments' => $paid['payments'] ?? 0.0,
                        'created_orders' => $created['orders_count'] ?? 0.0,
                        'paid_orders' => $paid['orders_count'] ?? 0.0,
                    ];
                    $rows[$channel][$section] = $cell;

                    // grand total: суммируем всё кроме cost
                    $grandRows[$channel][$section]['revenue'] += $cell['revenue'];
                    $grandRows[$channel][$section]['payments'] += $cell['payments'];
                    $grandRows[$channel][$section]['created_orders'] += $cell['created_orders'];
                    $grandRows[$channel][$section]['paid_orders'] += $cell['paid_orders'];
                }
            }

            // Расходы — только для direct/vk × portal/course
            $cost = $costsIdx[$period['key']] ?? null;
            if ($cost) {
                $rows['direct']['portal']['cost'] = (float)$cost['direct_portal_cost'];
                $rows['vk']['portal']['cost']     = (float)$cost['vk_portal_cost'];
                $rows['direct']['course']['cost'] = (float)$cost['direct_course_cost'];
                $rows['vk']['course']['cost']     = (float)$cost['vk_course_cost'];

                $grandRows['direct']['portal']['cost'] += (float)$cost['direct_portal_cost'];
                $grandRows['vk']['portal']['cost']     += (float)$cost['vk_portal_cost'];
                $grandRows['direct']['course']['cost'] += (float)$cost['direct_course_cost'];
                $grandRows['vk']['course']['cost']     += (float)$cost['vk_course_cost'];
            }

            // Расчёт метрик и Итого по периоду
            $rows = $this->computeMetrics($rows);
            $report[] = [
                'key' => $period['key'],
                'label' => $period['label'],
                'start' => $period['start'],
                'end' => $period['end'],
                'rows' => $rows['cells'],
                'total' => $rows['total'],
            ];
        }

        $grand = $this->computeMetrics($grandRows);

        return [
            'periods' => $report,
            'grand_total' => $grand['total'],
            'grand_rows' => $grand['cells'],
        ];
    }

    /**
     * Сохранить значение расхода (inline-редактирование).
     */
    public function saveCost(string $date, string $field, float $value): void
    {
        if (!in_array($field, self::COST_FIELDS, true)) {
            throw new \InvalidArgumentException('Недопустимое поле расхода');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('Некорректный формат даты');
        }
        if ($value < 0) {
            throw new \InvalidArgumentException('Расход не может быть отрицательным');
        }

        $sql = "INSERT INTO rnp_ad_costs (date, {$field}) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE {$field} = VALUES({$field})";
        $this->db->execute($sql, [$date, $value]);
    }

    /**
     * Данные для графиков (всегда по дням, для выбранного периода).
     */
    public function getChartData(string $dateFrom, string $dateTo): array
    {
        $report = $this->getReport($dateFrom, $dateTo, 'day');
        $labels = [];
        $revenue = [];
        $cost = [];
        $profit = [];
        $cpa = [];

        foreach ($report['periods'] as $p) {
            $labels[] = $p['label'];
            $revenue[] = round($p['total']['revenue'], 2);
            $cost[] = round($p['total']['cost'], 2);
            $profit[] = round($p['total']['profit'], 2);
            $cpa[] = $p['total']['payments'] > 0 ? round($p['total']['cost'] / $p['total']['payments'], 2) : null;
        }

        return [
            'labels' => $labels,
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $profit,
            'cpa' => $cpa,
        ];
    }

    // ============================================================
    // Внутренняя кухня
    // ============================================================

    /**
     * Достаёт агрегированные данные по заказам с пропорциональным разбиением
     * выручки между курсами и порталом.
     *
     * @param string $dateColumn 'paid_at' | 'created_at'
     */
    private function fetchOrderSplit(string $dateFrom, string $dateTo, string $dateColumn, string $granularity): array
    {
        $periodExpr = $this->periodExpr("o.$dateColumn", $granularity);
        $channelExpr = $this->channelExpr('o.utm_source');

        // Условие даты:
        // - для paid_at учитываем только успешно оплаченные
        // - для created_at — все заказы (как «создано»), плюс отдельно «оплачено» среди них
        if ($dateColumn === 'paid_at') {
            $whereDate = "o.payment_status = 'succeeded' AND o.paid_at IS NOT NULL
                          AND DATE(o.paid_at) BETWEEN ? AND ?";
        } else {
            $whereDate = "DATE(o.created_at) BETWEEN ? AND ?";
        }

        // Сначала собираем «сырые» суммы по каждому заказу с CASE по course_enrollment_id
        // LEFT JOIN: orphan-заказы (без order_items) тоже учитываются и считаются как portal.
        $sql = "
            SELECT
                {$periodExpr} AS period_key,
                {$channelExpr} AS channel,
                o.id AS order_id,
                o.final_amount AS final_amount,
                (o.payment_status = 'succeeded' AND o.paid_at IS NOT NULL) AS is_paid,
                COALESCE(SUM(CASE WHEN oi.course_enrollment_id IS NOT NULL THEN oi.price ELSE 0 END), 0) AS course_raw,
                COALESCE(SUM(CASE WHEN oi.course_enrollment_id IS NULL THEN oi.price ELSE 0 END), 0) AS portal_raw
            FROM orders o
            LEFT JOIN order_items oi ON oi.order_id = o.id
            WHERE {$whereDate}
            GROUP BY o.id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$dateFrom, $dateTo]);
        $orders = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Агрегация в PHP с пропорциональным делением
        $agg = []; // [period_key][channel][section] => [revenue, payments, orders_count]
        foreach ($orders as $o) {
            $courseRaw = (float)$o['course_raw'];
            $portalRaw = (float)$o['portal_raw'];
            $rawSum = $courseRaw + $portalRaw;
            $finalAmount = (float)$o['final_amount'];
            $isPaid = (int)$o['is_paid'] === 1;

            if ($rawSum <= 0) {
                // Странный случай — все позиции бесплатны. Считаем как portal.
                $courseShare = 0.0;
                $portalShare = 1.0;
            } else {
                $courseShare = $courseRaw / $rawSum;
                $portalShare = $portalRaw / $rawSum;
            }

            $key = $o['period_key'];
            $channel = $o['channel'];

            foreach ([['course', $courseShare], ['portal', $portalShare]] as [$section, $share]) {
                if ($share <= 0) {
                    continue;
                }
                if (!isset($agg[$key][$channel][$section])) {
                    $agg[$key][$channel][$section] = [
                        'period_key' => $key,
                        'channel' => $channel,
                        'section' => $section,
                        'revenue' => 0.0,
                        'payments' => 0.0,
                        'orders_count' => 0.0,
                    ];
                }
                // Для paid_at: revenue считается только если оплачен
                if ($dateColumn === 'paid_at' && $isPaid) {
                    $agg[$key][$channel][$section]['revenue'] += $finalAmount * $share;
                    $agg[$key][$channel][$section]['payments'] += $share;
                }
                // orders_count считается всегда (для created_at — это «создано», для paid_at — «оплачено»)
                $agg[$key][$channel][$section]['orders_count'] += $share;
            }
        }

        // Плоский массив
        $out = [];
        foreach ($agg as $byChannel) {
            foreach ($byChannel as $bySection) {
                foreach ($bySection as $row) {
                    $out[] = $row;
                }
            }
        }
        return $out;
    }

    /**
     * Достать расходы за период, сгруппированные по гранулярности.
     */
    private function fetchCosts(string $dateFrom, string $dateTo, string $granularity): array
    {
        $periodExpr = $this->periodExpr('date', $granularity);
        $sql = "
            SELECT
                {$periodExpr} AS period_key,
                SUM(direct_portal_cost) AS direct_portal_cost,
                SUM(vk_portal_cost)     AS vk_portal_cost,
                SUM(direct_course_cost) AS direct_course_cost,
                SUM(vk_course_cost)     AS vk_course_cost
            FROM rnp_ad_costs
            WHERE date BETWEEN ? AND ?
            GROUP BY period_key
        ";
        return $this->db->query($sql, [$dateFrom, $dateTo]);
    }

    /**
     * Расходы по конкретной дате (для inline-редактирования).
     */
    public function getCostsForDate(string $date): array
    {
        $row = $this->db->queryOne(
            'SELECT date, direct_portal_cost, vk_portal_cost, direct_course_cost, vk_course_cost
             FROM rnp_ad_costs WHERE date = ?',
            [$date]
        );
        return $row ?: [
            'date' => $date,
            'direct_portal_cost' => 0,
            'vk_portal_cost' => 0,
            'direct_course_cost' => 0,
            'vk_course_cost' => 0,
        ];
    }

    /**
     * Построить список периодов (label/start/end) для отображения колонок таблицы.
     */
    private function buildPeriods(string $dateFrom, string $dateTo, string $granularity): array
    {
        $start = new \DateTimeImmutable($dateFrom);
        $end = new \DateTimeImmutable($dateTo);
        $periods = [];

        if ($granularity === 'day') {
            $cur = $start;
            while ($cur <= $end) {
                $key = $cur->format('Y-m-d');
                $periods[] = [
                    'key' => $key,
                    'label' => $cur->format('d.m'),
                    'start' => $key,
                    'end' => $key,
                ];
                $cur = $cur->modify('+1 day');
            }
        } elseif ($granularity === 'week') {
            // ISO-неделя: ПН-ВС
            $cur = $start->modify('monday this week');
            if ($cur > $start) {
                $cur = $start->modify('-1 week monday this week');
            }
            while ($cur <= $end) {
                $weekEnd = $cur->modify('+6 days');
                $isoYear = (int)$cur->format('o');
                $isoWeek = (int)$cur->format('W');
                $key = sprintf('%04d%02d', $isoYear, $isoWeek);
                $periods[] = [
                    'key' => $key,
                    'label' => $cur->format('d.m') . '–' . $weekEnd->format('d.m'),
                    'start' => $cur->format('Y-m-d'),
                    'end' => $weekEnd->format('Y-m-d'),
                ];
                $cur = $cur->modify('+7 days');
            }
        } else { // month
            $cur = $start->modify('first day of this month');
            while ($cur <= $end) {
                $monthEnd = $cur->modify('last day of this month');
                $key = $cur->format('Y-m');
                $months = ['', 'Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];
                $periods[] = [
                    'key' => $key,
                    'label' => $months[(int)$cur->format('n')] . ' ' . $cur->format('Y'),
                    'start' => $cur->format('Y-m-d'),
                    'end' => $monthEnd->format('Y-m-d'),
                ];
                $cur = $cur->modify('+1 month');
            }
        }

        return $periods;
    }

    /**
     * SQL-выражение для группировки по периоду.
     */
    private function periodExpr(string $col, string $granularity): string
    {
        switch ($granularity) {
            case 'week':
                return "DATE_FORMAT($col, '%x%v')";
            case 'month':
                return "DATE_FORMAT($col, '%Y-%m')";
            case 'day':
            default:
                return "DATE($col)";
        }
    }

    /**
     * SQL-выражение для классификации канала по utm_source.
     */
    private function channelExpr(string $col): string
    {
        return "CASE
            WHEN LOWER($col) LIKE 'yandex%' THEN 'direct'
            WHEN LOWER($col) LIKE 'vk%' THEN 'vk'
            ELSE 'other'
        END";
    }

    /**
     * Пустая матрица 3 канала × 2 направления.
     */
    private function blankCellMatrix(): array
    {
        $matrix = [];
        foreach (self::CHANNELS as $ch) {
            foreach (self::SECTIONS as $sec) {
                $matrix[$ch][$sec] = [
                    'channel' => $ch,
                    'section' => $sec,
                    'cost' => 0.0,
                    'revenue' => 0.0,
                    'payments' => 0.0,
                    'created_orders' => 0.0,
                    'paid_orders' => 0.0,
                ];
            }
        }
        return $matrix;
    }

    /**
     * Расчёт производных метрик и строки «Итого» для матрицы.
     *
     * @return array{cells: array, total: array}
     */
    private function computeMetrics(array $matrix): array
    {
        $total = [
            'cost' => 0.0, 'revenue' => 0.0, 'payments' => 0.0,
            'created_orders' => 0.0, 'paid_orders' => 0.0,
        ];

        foreach (self::CHANNELS as $ch) {
            foreach (self::SECTIONS as $sec) {
                $cell = $matrix[$ch][$sec];
                $cell['cpa'] = $cell['payments'] > 0 ? $cell['cost'] / $cell['payments'] : null;
                $cell['avg_check'] = $cell['payments'] > 0 ? $cell['revenue'] / $cell['payments'] : null;
                $cell['profit'] = $cell['revenue'] - $cell['cost'];
                $cell['romi'] = $cell['cost'] > 0 ? ($cell['revenue'] - $cell['cost']) / $cell['cost'] : null;
                $cell['conversion'] = $cell['created_orders'] > 0 ? $cell['paid_orders'] / $cell['created_orders'] : null;
                $matrix[$ch][$sec] = $cell;

                $total['cost']           += $cell['cost'];
                $total['revenue']        += $cell['revenue'];
                $total['payments']       += $cell['payments'];
                $total['created_orders'] += $cell['created_orders'];
                $total['paid_orders']    += $cell['paid_orders'];
            }
        }

        $total['cpa']        = $total['payments'] > 0 ? $total['cost'] / $total['payments'] : null;
        $total['avg_check']  = $total['payments'] > 0 ? $total['revenue'] / $total['payments'] : null;
        $total['profit']     = $total['revenue'] - $total['cost'];
        $total['romi']       = $total['cost'] > 0 ? ($total['revenue'] - $total['cost']) / $total['cost'] : null;
        $total['conversion'] = $total['created_orders'] > 0 ? $total['paid_orders'] / $total['created_orders'] : null;

        return ['cells' => $matrix, 'total' => $total];
    }
}
