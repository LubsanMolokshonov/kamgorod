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
 *   - portal  — конкурс/олимпиада/вебинар/публикация + материалы ФОП (токены)
 *
 * Портальная доля смешанных заказов считается пропорционально price позиций;
 * курсовая сумма всегда берётся из OPPORTUNITY сделки Bitrix24.
 *
 * Режимы атрибуции выручки/оплат ($basis):
 *   - 'paid'    (дефолт): курсы — по CLOSEDATE выигранной сделки Bitrix24,
 *     портал — по дате оплаты orders.paid_at;
 *   - 'created': курсы — по DATE_CREATE выигранной сделки Bitrix24,
 *     портал — когортно по orders.created_at.
 * Заявки и «создано заказов» в обоих режимах — по created_at, расходы — по дате расхода.
 *
 * Материалы ФОП (покупка токенов) идут мимо orders — отдельной веткой через
 * token_transactions (reason='purchase'). Их выручка/платежи доклеиваются в
 * направление portal по каналу из token_transactions.utm_source (миграция 140).
 * База времени — token_transactions.created_at (= момент успешной оплаты), поэтому
 * покупка токенов учитывается и как «создано», и как «оплачено» в одном периоде.
 */

class RNPAnalytics
{
    private Database $db;
    private \PDO $pdo;
    private ?Bitrix24Integration $bitrix;
    private bool $crmDealsLoaded = false;
    private ?array $crmDeals = null;
    private ?array $courseDealContext = null;

    public const CHANNELS = ['direct', 'vk', 'other'];
    public const SECTIONS = ['portal', 'course'];

    public const COST_FIELDS = [
        'direct_portal_cost',
        'vk_portal_cost',
        'direct_course_cost',
        'vk_course_cost',
        'other_portal_cost',
        'other_course_cost',
    ];

    public function __construct(\PDO $pdo, ?Bitrix24Integration $bitrix = null)
    {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
        $this->bitrix = $bitrix;
    }

    /**
     * Главный метод: возвращает массив строк отчёта.
     *
     * @param string $dateFrom 'YYYY-MM-DD'
     * @param string $dateTo   'YYYY-MM-DD'
     * @param string $granularity 'day' | 'week' | 'month'
     * @param string $basis 'paid' — CLOSEDATE для курсов | 'created' — DATE_CREATE для курсов
     * @return array{
     *     periods: array<int, array{
     *         key: string, label: string, start: string, end: string,
     *         rows: array<string, array<string, mixed>>,
     *         total: array<string, mixed>
     *     }>,
     *     grand_total: array<string, mixed>,
     *     grand_rows: array<string, array<string, mixed>>,
     *     course_crm: array{available: bool, count: int, revenue: float},
     *     offline_crm: array
     * }
     */
    public function getReport(string $dateFrom, string $dateTo, string $granularity = 'day', string $basis = 'paid'): array
    {
        $granularity = in_array($granularity, ['day', 'week', 'month'], true) ? $granularity : 'day';
        $basis = $basis === 'created' ? 'created' : 'paid';

        // Локальные оплаты определяют только портал. Курсовая выручка целиком
        // берётся из текущего набора выигранных сделок Bitrix24.
        $paidRows = $this->fetchOrderSplit($dateFrom, $dateTo, $basis === 'created' ? 'cohort' : 'paid_at', $granularity);
        $createdRows = $this->fetchOrderSplit($dateFrom, $dateTo, 'created_at', $granularity);
        $costs = $this->fetchCosts($dateFrom, $dateTo, $granularity);
        $leadRows = $this->fetchCourseLeads($dateFrom, $dateTo, $granularity);
        $tokenRows = $this->fetchTokenSplit($dateFrom, $dateTo, $granularity);
        $courseCrm = $this->fetchCrmCourseSplit($dateFrom, $dateTo, $granularity, $basis);
        $offline = $courseCrm['offline'];
        $periods = $this->buildPeriods($dateFrom, $dateTo, $granularity);

        // Индексы для быстрого слияния
        $paidIdx = [];
        foreach ($paidRows as $r) {
            $paidIdx[$r['period_key']][$r['channel']][$r['section']] = $r;
        }
        $courseCrmIdx = $courseCrm['periods'];
        $createdIdx = [];
        foreach ($createdRows as $r) {
            $createdIdx[$r['period_key']][$r['channel']][$r['section']] = $r;
        }
        $costsIdx = [];
        foreach ($costs as $c) {
            $costsIdx[$c['period_key']] = $c;
        }
        $leadsIdx = [];
        foreach ($leadRows as $l) {
            $leadsIdx[$l['period_key']][$l['channel']] = (float)$l['leads'];
        }
        // Материалы ФОП (токены) — доклеиваются в portal по каналу.
        $tokenIdx = [];
        foreach ($tokenRows as $t) {
            $tokenIdx[$t['period_key']][$t['channel']] = $t;
        }

        $report = [];
        $grandRows = $this->blankCellMatrix();
        $grandOffline = ['revenue' => 0.0, 'payments' => 0.0, 'created' => 0.0];

        foreach ($periods as $period) {
            $rows = $this->blankCellMatrix();

            foreach (self::CHANNELS as $channel) {
                foreach (self::SECTIONS as $section) {
                    $paid = $section === 'course'
                        ? ($courseCrmIdx[$period['key']][$channel] ?? null)
                        : ($paidIdx[$period['key']][$channel][$section] ?? null);
                    $created = $createdIdx[$period['key']][$channel][$section] ?? null;

                    $leadsVal = 0.0;
                    if ($section === 'course') {
                        $leadsVal = $leadsIdx[$period['key']][$channel] ?? 0.0;
                    }

                    // Материалы ФОП доклеиваются только в portal. Покупка токенов
                    // фиксируется по факту оплаты, поэтому одинаково идёт в created и paid.
                    $token = ($section === 'portal') ? ($tokenIdx[$period['key']][$channel] ?? null) : null;
                    $tokenRevenue  = $token ? (float)$token['revenue'] : 0.0;
                    $tokenPayments = $token ? (float)$token['payments'] : 0.0;

                    // «Создано» для оффлайн-сделок без заказа сохраняем отдельно:
                    // их нет в orders, но они должны участвовать в воронке РНП.
                    $off = ($section === 'course')
                        ? ($offline['periods'][$period['key']][$channel] ?? null)
                        : null;
                    $offCreated = $off ? (float)$off['created'] : 0.0;
                    $financialAvailable = $section !== 'course' || $courseCrm['available'];

                    $cell = [
                        'channel' => $channel,
                        'section' => $section,
                        'cost' => 0.0,
                        'revenue' => ($paid['revenue'] ?? 0.0) + $tokenRevenue,
                        'payments' => ($paid['payments'] ?? 0.0) + $tokenPayments,
                        'created_orders' => ($created['orders_count'] ?? 0.0) + $tokenPayments + $offCreated,
                        'paid_orders' => ($paid['orders_count'] ?? $paid['payments'] ?? 0.0) + $tokenPayments,
                        'leads' => $leadsVal,
                        'financial_available' => $financialAvailable,
                    ];
                    $rows[$channel][$section] = $cell;

                    // grand total: суммируем всё кроме cost
                    $grandRows[$channel][$section]['revenue'] += $cell['revenue'];
                    $grandRows[$channel][$section]['payments'] += $cell['payments'];
                    $grandRows[$channel][$section]['created_orders'] += $cell['created_orders'];
                    $grandRows[$channel][$section]['paid_orders'] += $cell['paid_orders'];
                    $grandRows[$channel][$section]['leads'] += $cell['leads'];
                    if (!$financialAvailable) {
                        $grandRows[$channel][$section]['financial_available'] = false;
                    }
                }
            }

            // Расходы — для direct/vk/other × portal/course
            $cost = $costsIdx[$period['key']] ?? null;
            if ($cost) {
                $rows['direct']['portal']['cost'] = (float)$cost['direct_portal_cost'];
                $rows['vk']['portal']['cost']     = (float)$cost['vk_portal_cost'];
                $rows['direct']['course']['cost'] = (float)$cost['direct_course_cost'];
                $rows['vk']['course']['cost']     = (float)$cost['vk_course_cost'];
                $rows['other']['portal']['cost']  = (float)$cost['other_portal_cost'];
                $rows['other']['course']['cost']  = (float)$cost['other_course_cost'];

                $grandRows['direct']['portal']['cost'] += (float)$cost['direct_portal_cost'];
                $grandRows['vk']['portal']['cost']     += (float)$cost['vk_portal_cost'];
                $grandRows['direct']['course']['cost'] += (float)$cost['direct_course_cost'];
                $grandRows['vk']['course']['cost']     += (float)$cost['vk_course_cost'];
                $grandRows['other']['portal']['cost']  += (float)$cost['other_portal_cost'];
                $grandRows['other']['course']['cost']  += (float)$cost['other_course_cost'];
            }

            // Расчёт метрик и Итого по периоду
            $rows = $this->computeMetrics($rows);
            // Информационная строка «в т.ч. Оффлайн CRM»: подмножество курсовой
            // CRM-выручки без успешного заказа. В суммы отчёта НЕ входит (канал 'crm'
            // не перечислен в CHANNELS, computeMetrics его не трогает).
            $offPeriod = $offline['periods'][$period['key']] ?? [];
            $offCell = $this->sumCrmChannels($offPeriod);
            $rows['cells']['crm']['course'] = $this->offlineCell($offCell, $courseCrm['available']);
            if ($offCell !== null) {
                $grandOffline['revenue']  += (float)$offCell['revenue'];
                $grandOffline['payments'] += (float)$offCell['payments'];
                $grandOffline['created']  += (float)$offCell['created'];
            }
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
        $grand['cells']['crm']['course'] = $this->offlineCell($grandOffline, $courseCrm['available']);

        return [
            'periods' => $report,
            'grand_total' => $grand['total'],
            'grand_rows' => $grand['cells'],
            'course_crm' => [
                'available' => $courseCrm['available'],
                'count' => $courseCrm['count'],
                'revenue' => $courseCrm['revenue'],
            ],
            // Диагностика подмножества CRM-сделок, у которых нет успешного заказа.
            'offline_crm' => $offline,
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
    public function getChartData(string $dateFrom, string $dateTo, string $basis = 'paid'): array
    {
        $report = $this->getReport($dateFrom, $dateTo, 'day', $basis);
        $labels = [];
        $revenue = [];
        $cost = [];
        $profit = [];
        $cpa = [];
        $available = $report['course_crm']['available'];

        foreach ($report['periods'] as $p) {
            $labels[] = $p['label'];
            $revenue[] = $available ? round($p['total']['revenue'], 2) : null;
            $cost[] = round($p['total']['cost'], 2);
            $profit[] = $available ? round($p['total']['profit'], 2) : null;
            $cpa[] = $available && $p['total']['payments'] > 0
                ? round($p['total']['cost'] / $p['total']['payments'], 2)
                : null;
        }

        return [
            'available' => $available,
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
     * @param string $dateColumn 'paid_at' | 'created_at' | 'cohort'
     */
    private function fetchOrderSplit(string $dateFrom, string $dateTo, string $dateColumn, string $granularity): array
    {
        $channelExpr = $this->channelExpr('o.utm_source');

        if ($dateColumn === 'cohort') {
            // Когортный режим («по дате создания»): только оплаченные заказы;
            // курсовая доля привязывается к дате создания заявки, портальная — заказа.
            // Верхней границы по created_at в SQL нет намеренно: оплата после date_to
            // за заявку внутри периода должна попасть в отчёт. Заявка всегда раньше
            // заказа, поэтому нижней границы достаточно; точный диапазон режется в PHP.
            $periodPortal = $this->periodExpr('o.created_at', $granularity);
            $periodCourse = $this->periodExpr('COALESCE(MIN(ce.created_at), o.created_at)', $granularity);
            $sql = "
                SELECT
                    {$periodPortal} AS period_portal,
                    DATE(o.created_at) AS date_portal,
                    {$periodCourse} AS period_course,
                    DATE(COALESCE(MIN(ce.created_at), o.created_at)) AS date_course,
                    {$channelExpr} AS channel,
                    o.id AS order_id,
                    o.final_amount AS final_amount,
                    1 AS is_paid,
                    COALESCE(SUM(CASE WHEN oi.course_enrollment_id IS NOT NULL THEN oi.price ELSE 0 END), 0) AS course_raw,
                    COALESCE(SUM(CASE WHEN oi.course_enrollment_id IS NULL THEN oi.price ELSE 0 END), 0) AS portal_raw
                FROM orders o
                LEFT JOIN order_items oi ON oi.order_id = o.id
                LEFT JOIN course_enrollments ce ON ce.id = oi.course_enrollment_id
                WHERE o.payment_status = 'succeeded' AND o.paid_at IS NOT NULL
                  AND DATE(o.created_at) >= ?
                GROUP BY o.id
            ";
            $params = [$dateFrom];
        } else {
            // Условие даты:
            // - для paid_at учитываем только успешно оплаченные
            // - для created_at — все заказы (как «создано»), плюс отдельно «оплачено» среди них
            if ($dateColumn === 'paid_at') {
                $whereDate = "o.payment_status = 'succeeded' AND o.paid_at IS NOT NULL
                              AND DATE(o.paid_at) BETWEEN ? AND ?";
            } else {
                $whereDate = "DATE(o.created_at) BETWEEN ? AND ?";
            }

            $periodExpr = $this->periodExpr("o.$dateColumn", $granularity);

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
            $params = [$dateFrom, $dateTo];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
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

            $channel = $o['channel'];

            foreach ([['course', $courseShare], ['portal', $portalShare]] as [$section, $share]) {
                if ($share <= 0) {
                    continue;
                }
                if ($dateColumn === 'cohort') {
                    // У каждой доли своя дата атрибуции; доли вне периода отбрасываем здесь.
                    $shareDate = $section === 'course' ? $o['date_course'] : $o['date_portal'];
                    if ($shareDate < $dateFrom || $shareDate > $dateTo) {
                        continue;
                    }
                    $key = $section === 'course' ? $o['period_course'] : $o['period_portal'];
                } else {
                    $key = $o['period_key'];
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
                // Для paid_at/cohort: revenue и payments — только ненулевые суммы (0₽ подписочные
                // выдачи не должны занижать CPA и средний чек). orders_count включает все
                // succeeded-заказы для правильного расчёта конверсии.
                if ($dateColumn !== 'created_at' && $isPaid) {
                    $agg[$key][$channel][$section]['revenue'] += $finalAmount * $share;
                    if ($finalAmount > 0) {
                        $agg[$key][$channel][$section]['payments'] += $share;
                    }
                }
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
                SUM(vk_course_cost)     AS vk_course_cost,
                SUM(other_portal_cost)  AS other_portal_cost,
                SUM(other_course_cost)  AS other_course_cost
            FROM rnp_ad_costs
            WHERE date BETWEEN ? AND ?
            GROUP BY period_key
        ";
        return $this->db->query($sql, [$dateFrom, $dateTo]);
    }

    /**
     * Заявки по курсам: регистрации на курс (course_enrollments) +
     * заявки на обратный звонок (course_consultations).
     * Дедупликация по нормализованному телефону в пределах (period × channel).
     */
    private function fetchCourseLeads(string $dateFrom, string $dateTo, string $granularity): array
    {
        $periodExprEnr = $this->periodExpr('created_at', $granularity);
        $channelExpr   = $this->channelExpr('utm_source');

        $sql = "
            SELECT period_key, channel, COUNT(DISTINCT phone_norm) AS leads
            FROM (
                SELECT
                    {$periodExprEnr} AS period_key,
                    {$channelExpr}   AS channel,
                    REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '') AS phone_norm
                FROM course_enrollments
                WHERE DATE(created_at) BETWEEN ? AND ?
                  AND phone IS NOT NULL AND phone <> ''
                UNION ALL
                SELECT
                    {$periodExprEnr} AS period_key,
                    {$channelExpr}   AS channel,
                    REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '') AS phone_norm
                FROM course_consultations
                WHERE DATE(created_at) BETWEEN ? AND ?
                  AND phone IS NOT NULL AND phone <> ''
            ) t
            WHERE phone_norm <> ''
            GROUP BY period_key, channel
        ";
        return $this->db->query($sql, [$dateFrom, $dateTo, $dateFrom, $dateTo]);
    }

    /**
     * Выручка от материалов ФОП (покупка токенов) по периодам и каналам.
     * Order на токены не создаётся — данные берём из token_transactions
     * (reason='purchase'). Канал — по utm_source транзакции (миграция 140).
     * revenue — фактически оплаченная сумма (amount_paid); для исторических строк
     * без неё — fallback на прайс пакета. База времени — created_at (момент оплаты).
     */
    private function fetchTokenSplit(string $dateFrom, string $dateTo, string $granularity): array
    {
        $periodExpr = $this->periodExpr('tt.created_at', $granularity);
        $channelExpr = $this->channelExpr('tt.utm_source');
        $sql = "
            SELECT
                {$periodExpr} AS period_key,
                {$channelExpr} AS channel,
                COALESCE(SUM(COALESCE(tt.amount_paid, tp.price_rub)), 0) AS revenue,
                COUNT(*) AS payments
            FROM token_transactions tt
            LEFT JOIN token_packages tp ON tp.id = tt.package_id
            WHERE tt.reason = 'purchase'
              AND DATE(tt.created_at) BETWEEN ? AND ?
            GROUP BY period_key, channel
        ";
        return $this->db->query($sql, [$dateFrom, $dateTo]);
    }

    /**
     * Полная курсовая выручка из текущего набора выигранных сделок Bitrix24.
     * Локальные orders используются только для определения канала и признака
     * «оффлайн»: их суммы и paid_at в расчёт курсовой выручки не входят.
     *
     * @return array{
     *   periods: array,
     *   available: bool,
     *   count: int,
     *   revenue: float,
     *   offline: array{periods: array, available: bool, count: int, revenue: float, deals: array}
     * }
     */
    private function fetchCrmCourseSplit(string $dateFrom, string $dateTo, string $granularity, string $basis): array
    {
        $unavailableOffline = [
            'periods' => [], 'available' => false, 'count' => 0, 'revenue' => 0.0, 'deals' => [],
        ];
        $deals = $this->loadWonCrmDeals();
        if ($deals === null) {
            return [
                'periods' => [], 'available' => false, 'count' => 0, 'revenue' => 0.0,
                'offline' => $unavailableOffline,
            ];
        }

        $context = $this->loadCourseDealContext();
        $periods = [];
        $offlinePeriods = [];
        $count = 0;
        $revenue = 0.0;
        $offlineCount = 0;
        $offlineRevenue = 0.0;
        $offlineDeals = [];
        $seen = [];

        foreach ($deals as $deal) {
            $dealId = (int)($deal['id'] ?? 0);
            if ($dealId <= 0 || isset($seen[$dealId])) {
                continue;
            }
            $seen[$dealId] = true;

            $closed = (string)($deal['closedate'] ?? '');
            $created = (string)($deal['created'] ?? '');
            $amount = (float)($deal['revenue'] ?? 0.0);
            $dealContext = $context[$dealId] ?? ['channel' => 'other', 'has_paid_order' => false];
            $channel = in_array($dealContext['channel'], self::CHANNELS, true)
                ? $dealContext['channel']
                : 'other';
            $isOffline = empty($dealContext['has_paid_order']);
            $revenueDate = $basis === 'created' ? $created : $closed;

            if ($revenueDate !== '' && $revenueDate >= $dateFrom && $revenueDate <= $dateTo) {
                $key = $this->periodKeyForDate($revenueDate, $granularity);
                $this->initCrmPeriod($periods, $key, $channel);
                $periods[$key][$channel]['revenue'] += $amount;
                if ($amount > 0) {
                    $periods[$key][$channel]['payments'] += 1;
                    $periods[$key][$channel]['orders_count'] += 1;
                }
                $count++;
                $revenue += $amount;

                if ($isOffline) {
                    $this->initCrmPeriod($offlinePeriods, $key, $channel);
                    $offlinePeriods[$key][$channel]['revenue'] += $amount;
                    if ($amount > 0) {
                        $offlinePeriods[$key][$channel]['payments'] += 1;
                        $offlinePeriods[$key][$channel]['orders_count'] += 1;
                    }
                    $offlineCount++;
                    $offlineRevenue += $amount;
                    $offlineDeals[] = $deal;
                }
            }

            // Для оффлайн-сделок «создано» всегда относится к DATE_CREATE,
            // независимо от выбранного режима выручки.
            if ($isOffline && $created !== '' && $created >= $dateFrom && $created <= $dateTo) {
                $key = $this->periodKeyForDate($created, $granularity);
                $this->initCrmPeriod($offlinePeriods, $key, $channel);
                $offlinePeriods[$key][$channel]['created'] += 1;
            }
        }

        return [
            'periods' => $periods,
            'available' => true,
            'count' => $count,
            'revenue' => $revenue,
            'offline' => [
                'periods' => $offlinePeriods,
                'available' => true,
                'count' => $offlineCount,
                'revenue' => $offlineRevenue,
                'deals' => $offlineDeals,
            ],
        ];
    }

    /** @return array<int,array>|null null означает недоступность Bitrix24. */
    private function loadWonCrmDeals(): ?array
    {
        if ($this->crmDealsLoaded) {
            return $this->crmDeals;
        }
        $this->crmDealsLoaded = true;

        try {
            if ($this->bitrix === null) {
                require_once __DIR__ . '/Bitrix24Integration.php';
                $this->bitrix = new Bitrix24Integration();
            }
            if (!$this->bitrix->isConfigured()) {
                return $this->crmDeals = null;
            }
            return $this->crmDeals = $this->bitrix->getFgosWonDeals();
        } catch (\Throwable $e) {
            error_log('[rnp] выигранные сделки CRM недоступны: ' . $e->getMessage());
            return $this->crmDeals = null;
        }
    }

    /**
     * Канал и наличие успешного заказа для каждой сделки, связанной с сайтом.
     * Приоритет источника: последний успешный заказ → заявка на курс → консультация.
     *
     * @return array<int,array{channel:string,has_paid_order:bool}>
     */
    private function loadCourseDealContext(): array
    {
        if ($this->courseDealContext !== null) {
            return $this->courseDealContext;
        }

        $context = [];
        $rows = $this->db->query(
            "SELECT ce.bitrix_lead_id AS deal_id,
                    ce.utm_source AS enrollment_utm_source,
                    o.id AS order_id,
                    o.utm_source AS order_utm_source
             FROM course_enrollments ce
             LEFT JOIN order_items oi ON oi.course_enrollment_id = ce.id
             LEFT JOIN orders o ON o.id = oi.order_id AND o.payment_status = 'succeeded'
             WHERE ce.bitrix_lead_id IS NOT NULL
             ORDER BY ce.bitrix_lead_id, o.paid_at DESC, o.id DESC"
        );
        foreach ($rows as $row) {
            $dealId = (int)$row['deal_id'];
            if ($dealId <= 0) {
                continue;
            }
            if (!isset($context[$dealId])) {
                $source = trim((string)($row['order_utm_source'] ?: $row['enrollment_utm_source']));
                $context[$dealId] = [
                    'channel' => $this->channelForSource($source),
                    'has_paid_order' => !empty($row['order_id']),
                ];
            } elseif (!empty($row['order_id'])) {
                $context[$dealId]['has_paid_order'] = true;
            }
        }

        // Синтетический заказ может пережить отвязку заявки. Маркер bitrix:<id>
        // остаётся надёжной связью и не даёт пометить такую сделку как оффлайн.
        $markedOrders = $this->db->query(
            "SELECT yookassa_payment_id, utm_source
             FROM orders
             WHERE payment_status = 'succeeded'
               AND yookassa_payment_id LIKE 'bitrix:%'"
        );
        foreach ($markedOrders as $row) {
            $dealId = (int)substr((string)$row['yookassa_payment_id'], 7);
            if ($dealId <= 0) {
                continue;
            }
            if (!isset($context[$dealId])) {
                $context[$dealId] = [
                    'channel' => $this->channelForSource((string)$row['utm_source']),
                    'has_paid_order' => true,
                ];
            } else {
                $context[$dealId]['has_paid_order'] = true;
            }
        }

        $consultations = $this->db->query(
            "SELECT bitrix_lead_id AS deal_id, utm_source
             FROM course_consultations
             WHERE bitrix_lead_id IS NOT NULL"
        );
        foreach ($consultations as $row) {
            $dealId = (int)$row['deal_id'];
            if ($dealId > 0 && !isset($context[$dealId])) {
                $context[$dealId] = [
                    'channel' => $this->channelForSource((string)$row['utm_source']),
                    'has_paid_order' => false,
                ];
            }
        }

        return $this->courseDealContext = $context;
    }

    private function channelForSource(string $source): string
    {
        $source = mb_strtolower(trim($source));
        if (str_starts_with($source, 'yandex') || str_starts_with($source, 'ya')) {
            return 'direct';
        }
        if (str_starts_with($source, 'vk')) {
            return 'vk';
        }
        return 'other';
    }

    private function initCrmPeriod(array &$periods, string $key, string $channel): void
    {
        if (!isset($periods[$key][$channel])) {
            $periods[$key][$channel] = [
                'revenue' => 0.0, 'payments' => 0.0, 'orders_count' => 0.0, 'created' => 0.0,
            ];
        }
    }

    /** @return array{revenue:float,payments:float,created:float}|null */
    private function sumCrmChannels(array $channels): ?array
    {
        if (!$channels) {
            return null;
        }
        $sum = ['revenue' => 0.0, 'payments' => 0.0, 'created' => 0.0];
        foreach ($channels as $cell) {
            $sum['revenue'] += (float)($cell['revenue'] ?? 0.0);
            $sum['payments'] += (float)($cell['payments'] ?? 0.0);
            $sum['created'] += (float)($cell['created'] ?? 0.0);
        }
        return $sum;
    }

    /**
     * Ключ периода для даты — тот же формат, что даёт periodExpr() в SQL.
     */
    private function periodKeyForDate(string $date, string $granularity): string
    {
        $d = new \DateTimeImmutable($date);
        return match ($granularity) {
            'month' => $d->format('Y-m'),
            // Соответствует DATE_FORMAT(col,'%x%v') из periodExpr() и ключу buildPeriods().
            'week'  => sprintf('%04d%02d', (int)$d->format('o'), (int)$d->format('W')),
            default => $d->format('Y-m-d'),
        };
    }

    /**
     * Расходы по конкретной дате (для inline-редактирования).
     */
    public function getCostsForDate(string $date): array
    {
        $row = $this->db->queryOne(
            'SELECT date, direct_portal_cost, vk_portal_cost, direct_course_cost, vk_course_cost,
                    other_portal_cost, other_course_cost
             FROM rnp_ad_costs WHERE date = ?',
            [$date]
        );
        return $row ?: [
            'date' => $date,
            'direct_portal_cost' => 0,
            'vk_portal_cost' => 0,
            'direct_course_cost' => 0,
            'vk_course_cost' => 0,
            'other_portal_cost' => 0,
            'other_course_cost' => 0,
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
            WHEN LOWER($col) LIKE 'yandex%' OR LOWER($col) LIKE 'ya%' THEN 'direct'
            WHEN LOWER($col) LIKE 'vk%' THEN 'vk'
            ELSE 'other'
        END";
    }

    /**
     * Ячейка информационной строки «в т.ч. Оффлайн CRM» (канал 'crm' × 'course').
     *
     * Дублирует часть курсовой выручки, поэтому ни в какие суммы не включается.
     *
     * @param array{revenue: float, payments: float, created: float}|null $off
     */
    private function offlineCell(?array $off, bool $available = true): array
    {
        $revenue  = $off ? (float)$off['revenue'] : 0.0;
        $payments = $off ? (float)$off['payments'] : 0.0;
        $created  = $off ? (float)$off['created'] : 0.0;

        return [
            'channel' => 'crm',
            'section' => 'course',
            'cost' => 0.0,
            'revenue' => $revenue,
            'payments' => $payments,
            'created_orders' => $created,
            'paid_orders' => $payments,
            'leads' => 0.0,
            'financial_available' => $available,
            'cpa' => null,          // расхода у оффлайн-сделок нет
            'avg_check' => $available && $payments > 0 ? $revenue / $payments : null,
            'profit' => $available ? $revenue : null,
            'romi' => null,
            'conversion' => $available && $created > 0 ? $payments / $created : null,
            'lead_cost' => null,
        ];
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
                    'leads' => 0.0,
                    'financial_available' => true,
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
            'created_orders' => 0.0, 'paid_orders' => 0.0, 'leads' => 0.0,
            'financial_available' => true,
        ];

        foreach (self::CHANNELS as $ch) {
            foreach (self::SECTIONS as $sec) {
                $cell = $matrix[$ch][$sec];
                $available = $cell['financial_available'] ?? true;
                $cell['cpa'] = $available && $cell['payments'] > 0 ? $cell['cost'] / $cell['payments'] : null;
                $cell['avg_check'] = $available && $cell['payments'] > 0 ? $cell['revenue'] / $cell['payments'] : null;
                $cell['profit'] = $available ? $cell['revenue'] - $cell['cost'] : null;
                $cell['romi'] = $available && $cell['cost'] > 0 ? ($cell['revenue'] - $cell['cost']) / $cell['cost'] : null;
                $cell['conversion'] = $available && $cell['created_orders'] > 0 ? $cell['paid_orders'] / $cell['created_orders'] : null;
                $cell['lead_cost'] = ($sec === 'course' && $cell['leads'] > 0) ? $cell['cost'] / $cell['leads'] : null;
                $matrix[$ch][$sec] = $cell;

                $total['cost']           += $cell['cost'];
                $total['created_orders'] += $cell['created_orders'];
                $total['leads']          += $cell['leads'];
                if ($available) {
                    $total['revenue'] += $cell['revenue'];
                    $total['payments'] += $cell['payments'];
                    $total['paid_orders'] += $cell['paid_orders'];
                } else {
                    $total['financial_available'] = false;
                }
            }
        }

        $available = $total['financial_available'];
        $total['cpa']        = $available && $total['payments'] > 0 ? $total['cost'] / $total['payments'] : null;
        $total['avg_check']  = $available && $total['payments'] > 0 ? $total['revenue'] / $total['payments'] : null;
        $total['profit']     = $available ? $total['revenue'] - $total['cost'] : null;
        $total['romi']       = $available && $total['cost'] > 0 ? ($total['revenue'] - $total['cost']) / $total['cost'] : null;
        $total['conversion'] = $available && $total['created_orders'] > 0 ? $total['paid_orders'] / $total['created_orders'] : null;
        $total['lead_cost']  = $total['leads'] > 0 ? $total['cost'] / $total['leads'] : null;

        return ['cells' => $matrix, 'total' => $total];
    }
}
