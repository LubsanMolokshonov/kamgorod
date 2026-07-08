<?php
/**
 * Экономика по направлениям — понедельная аналитика по продуктам.
 *
 * Направления (по наличию FK в order_items):
 *   - olympiads     — order_items.olympiad_registration_id
 *   - competitions  — order_items.registration_id
 *   - publications  — order_items.certificate_id
 *   - webinars      — order_items.webinar_certificate_id
 *   - courses       — order_items.course_enrollment_id
 *   - materials     — отдельный поток: покупки токенов (token_transactions.reason='purchase'),
 *                     выручка = token_packages.price_rub (материалы + ФОП объединены).
 *
 * Выручка смешанных заказов делится пропорционально сумме price позиций каждого направления.
 *
 * Режимы атрибуции выручки/оплат ($basis):
 *   - 'paid'    (дефолт) — по дате оплаты orders.paid_at («по дате завершения»);
 *   - 'created' — когортный: доля courses привязывается к дате создания заявки
 *     (course_enrollments.created_at), остальные направления — к orders.created_at.
 * Материалы/ФОП всегда по token_transactions.created_at (= момент оплаты), расходы —
 * по неделе расхода. Гранулярность всегда недельная (ISO-неделя ПН–ВС).
 */

class DirectionAnalytics
{
    private Database $db;
    private \PDO $pdo;

    /** Упорядоченный список направлений: key => label. Единственное место для расширения. */
    public const DIRECTIONS = [
        'olympiads'    => 'Олимпиады',
        'competitions' => 'Конкурсы',
        'publications' => 'Публикации',
        'webinars'     => 'Вебинары',
        'courses'      => 'Курсы',
        'materials'    => 'Материалы/ФОП',
    ];

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
    }

    /**
     * Главный метод: отчёт по неделям.
     *
     * @return array{
     *     periods: array<int, array{key:string,label:string,start:string,end:string,rows:array,total:array}>,
     *     grand_total: array,
     *     grand_rows: array
     * }
     */
    public function getReport(string $dateFrom, string $dateTo, string $basis = 'paid'): array
    {
        $basis = $basis === 'created' ? 'created' : 'paid';
        $orderRows = $this->fetchOrderSplit($dateFrom, $dateTo, $basis);
        $tokenRows = $this->fetchTokenRevenue($dateFrom, $dateTo);
        $costs     = $this->fetchCosts($dateFrom, $dateTo);
        $periods   = $this->buildPeriods($dateFrom, $dateTo);

        // Индексы: [period_key][direction] => ['revenue','payments']
        $revIdx = [];
        foreach ($orderRows as $r) {
            $revIdx[$r['period_key']][$r['direction']] = [
                'revenue'  => (float)$r['revenue'],
                'payments' => (float)$r['payments'],
            ];
        }
        foreach ($tokenRows as $r) {
            $revIdx[$r['period_key']]['materials'] = [
                'revenue'  => (float)$r['revenue'],
                'payments' => (float)$r['payments'],
            ];
        }
        $costIdx = [];
        foreach ($costs as $c) {
            $costIdx[$c['period_key']][$c['direction']] = [
                'manual' => (float)$c['cost_manual'],
                'direct' => (float)$c['cost_direct'],
            ];
        }

        $report = [];
        $grandRows = $this->blankMatrix();

        foreach ($periods as $period) {
            $rows = $this->blankMatrix();
            foreach (array_keys(self::DIRECTIONS) as $dir) {
                $rev  = $revIdx[$period['key']][$dir] ?? null;
                $cost = $costIdx[$period['key']][$dir] ?? ['manual' => 0.0, 'direct' => 0.0];

                $cell = [
                    'direction'   => $dir,
                    'cost'        => $cost['manual'] + $cost['direct'],
                    'cost_manual' => $cost['manual'],
                    'cost_direct' => $cost['direct'],
                    'revenue'     => $rev['revenue'] ?? 0.0,
                    'payments'    => $rev['payments'] ?? 0.0,
                ];
                $rows[$dir] = $cell;

                $grandRows[$dir]['cost']        += $cell['cost'];
                $grandRows[$dir]['cost_manual'] += $cell['cost_manual'];
                $grandRows[$dir]['cost_direct'] += $cell['cost_direct'];
                $grandRows[$dir]['revenue']     += $cell['revenue'];
                $grandRows[$dir]['payments']    += $cell['payments'];
            }

            $computed = $this->computeMetrics($rows);
            $report[] = [
                'key'   => $period['key'],
                'label' => $period['label'],
                'start' => $period['start'],
                'end'   => $period['end'],
                'rows'  => $computed['cells'],
                'total' => $computed['total'],
            ];
        }

        $grand = $this->computeMetrics($grandRows);

        return [
            'periods'     => $report,
            'grand_total' => $grand['total'],
            'grand_rows'  => $grand['cells'],
        ];
    }

    /**
     * Сохранить понедельный расход направления (inline-редактирование).
     */
    public function saveCost(string $weekStart, string $direction, float $value): void
    {
        if (!array_key_exists($direction, self::DIRECTIONS)) {
            throw new \InvalidArgumentException('Недопустимое направление');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
            throw new \InvalidArgumentException('Некорректный формат даты');
        }
        // week_start обязан быть понедельником
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $weekStart);
        if (!$dt || $dt->format('Y-m-d') !== $weekStart || (int)$dt->format('N') !== 1) {
            throw new \InvalidArgumentException('Дата недели должна быть понедельником');
        }
        if ($value < 0) {
            throw new \InvalidArgumentException('Расход не может быть отрицательным');
        }

        $this->db->execute(
            "INSERT INTO direction_weekly_costs (week_start, direction, cost) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE cost = VALUES(cost)",
            [$weekStart, $direction, $value]
        );
    }

    // ============================================================
    // Внутренняя кухня
    // ============================================================

    /**
     * Выручка/оплаты по 5 «orders»-направлениям с пропорциональным делением final_amount.
     *
     * @param string $basis 'paid' — по paid_at | 'created' — когортно, по дате заявки/заказа
     */
    private function fetchOrderSplit(string $dateFrom, string $dateTo, string $basis = 'paid'): array
    {
        $rawSelect = "
                COALESCE(SUM(CASE WHEN oi.olympiad_registration_id IS NOT NULL THEN oi.price ELSE 0 END), 0) AS olympiads_raw,
                COALESCE(SUM(CASE WHEN oi.registration_id          IS NOT NULL THEN oi.price ELSE 0 END), 0) AS competitions_raw,
                COALESCE(SUM(CASE WHEN oi.certificate_id           IS NOT NULL THEN oi.price ELSE 0 END), 0) AS publications_raw,
                COALESCE(SUM(CASE WHEN oi.webinar_certificate_id   IS NOT NULL THEN oi.price ELSE 0 END), 0) AS webinars_raw,
                COALESCE(SUM(CASE WHEN oi.course_enrollment_id     IS NOT NULL THEN oi.price ELSE 0 END), 0) AS courses_raw
        ";

        if ($basis === 'created') {
            // Когортный режим: доля courses — по дате создания заявки, остальные — заказа.
            // Верхней границы по created_at в SQL нет намеренно: оплата после date_to
            // за заявку внутри периода должна попасть в отчёт. Заявка всегда раньше
            // заказа, поэтому нижней границы достаточно; точный диапазон режется в PHP.
            $periodCommon = $this->periodExpr('o.created_at');
            $periodCourse = $this->periodExpr('COALESCE(MIN(ce.created_at), o.created_at)');
            $sql = "
                SELECT
                    {$periodCommon} AS period_common,
                    DATE(o.created_at) AS date_common,
                    {$periodCourse} AS period_course,
                    DATE(COALESCE(MIN(ce.created_at), o.created_at)) AS date_course,
                    o.id AS order_id,
                    o.final_amount AS final_amount,
                    {$rawSelect}
                FROM orders o
                LEFT JOIN order_items oi ON oi.order_id = o.id
                LEFT JOIN course_enrollments ce ON ce.id = oi.course_enrollment_id
                WHERE o.payment_status = 'succeeded' AND o.paid_at IS NOT NULL
                  AND DATE(o.created_at) >= ?
                GROUP BY o.id
            ";
            $params = [$dateFrom];
        } else {
            $periodExpr = $this->periodExpr('o.paid_at');
            $sql = "
                SELECT
                    {$periodExpr} AS period_key,
                    o.id AS order_id,
                    o.final_amount AS final_amount,
                    {$rawSelect}
                FROM orders o
                LEFT JOIN order_items oi ON oi.order_id = o.id
                WHERE o.payment_status = 'succeeded' AND o.paid_at IS NOT NULL
                  AND DATE(o.paid_at) BETWEEN ? AND ?
                GROUP BY o.id
            ";
            $params = [$dateFrom, $dateTo];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Карта: колонка_raw => ключ направления
        $rawMap = [
            'olympiads_raw'    => 'olympiads',
            'competitions_raw' => 'competitions',
            'publications_raw' => 'publications',
            'webinars_raw'     => 'webinars',
            'courses_raw'      => 'courses',
        ];

        $agg = []; // [period_key][direction] => ['revenue','payments']
        foreach ($orders as $o) {
            $raws = [];
            $rawSum = 0.0;
            foreach ($rawMap as $col => $dir) {
                $raws[$dir] = (float)$o[$col];
                $rawSum += $raws[$dir];
            }
            // Заказы без определяемых позиций (free-promo / orphan) — единичны, пропускаем.
            if ($rawSum <= 0) {
                continue;
            }

            $finalAmount = (float)$o['final_amount'];

            foreach ($raws as $dir => $raw) {
                if ($raw <= 0) {
                    continue;
                }
                if ($basis === 'created') {
                    // У каждой доли своя дата атрибуции; доли вне периода отбрасываем здесь.
                    $shareDate = $dir === 'courses' ? $o['date_course'] : $o['date_common'];
                    if ($shareDate < $dateFrom || $shareDate > $dateTo) {
                        continue;
                    }
                    $key = $dir === 'courses' ? $o['period_course'] : $o['period_common'];
                } else {
                    $key = $o['period_key'];
                }
                $share = $raw / $rawSum;
                if (!isset($agg[$key][$dir])) {
                    $agg[$key][$dir] = ['period_key' => $key, 'direction' => $dir, 'revenue' => 0.0, 'payments' => 0.0];
                }
                $agg[$key][$dir]['revenue']  += $finalAmount * $share;
                $agg[$key][$dir]['payments'] += $share;
            }
        }

        $out = [];
        foreach ($agg as $byDir) {
            foreach ($byDir as $row) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Выручка по материалам/ФОП — покупки токенов.
     */
    private function fetchTokenRevenue(string $dateFrom, string $dateTo): array
    {
        $periodExpr = $this->periodExpr('tt.created_at');
        $sql = "
            SELECT
                {$periodExpr} AS period_key,
                SUM(tp.price_rub) AS revenue,
                COUNT(*) AS payments
            FROM token_transactions tt
            JOIN token_packages tp ON tp.id = tt.package_id
            WHERE tt.reason = 'purchase'
              AND DATE(tt.created_at) BETWEEN ? AND ?
            GROUP BY period_key
        ";
        return $this->db->query($sql, [$dateFrom, $dateTo]);
    }

    /**
     * Понедельные расходы по направлениям: cost — ручной ввод прочих каналов,
     * direct_cost — авто из синка Директа (cron/sync-direct-spend.php).
     */
    private function fetchCosts(string $dateFrom, string $dateTo): array
    {
        $periodExpr = $this->periodExpr('week_start');
        $sql = "
            SELECT {$periodExpr} AS period_key, direction,
                   SUM(cost) AS cost_manual, SUM(direct_cost) AS cost_direct
            FROM direction_weekly_costs
            WHERE week_start BETWEEN ? AND ?
            GROUP BY period_key, direction
        ";
        return $this->db->query($sql, [$dateFrom, $dateTo]);
    }

    /**
     * Список недель (ISO ПН–ВС), покрывающих период.
     */
    private function buildPeriods(string $dateFrom, string $dateTo): array
    {
        $start = new \DateTimeImmutable($dateFrom);
        $end   = new \DateTimeImmutable($dateTo);
        $periods = [];

        $cur = $start->modify('monday this week');
        if ($cur > $start) {
            $cur = $start->modify('-1 week monday this week');
        }
        while ($cur <= $end) {
            $weekEnd = $cur->modify('+6 days');
            $isoYear = (int)$cur->format('o');
            $isoWeek = (int)$cur->format('W');
            $periods[] = [
                'key'   => sprintf('%04d%02d', $isoYear, $isoWeek),
                'label' => $cur->format('d.m') . '–' . $weekEnd->format('d.m'),
                'start' => $cur->format('Y-m-d'),
                'end'   => $weekEnd->format('Y-m-d'),
            ];
            $cur = $cur->modify('+7 days');
        }
        return $periods;
    }

    /**
     * SQL-выражение группировки по ISO-неделе.
     */
    private function periodExpr(string $col): string
    {
        return "DATE_FORMAT($col, '%x%v')";
    }

    /**
     * Пустая матрица по направлениям.
     */
    private function blankMatrix(): array
    {
        $matrix = [];
        foreach (array_keys(self::DIRECTIONS) as $dir) {
            $matrix[$dir] = [
                'direction'   => $dir,
                'cost'        => 0.0,
                'cost_manual' => 0.0,
                'cost_direct' => 0.0,
                'revenue'     => 0.0,
                'payments'    => 0.0,
            ];
        }
        return $matrix;
    }

    /**
     * Производные метрики на ячейку + строка «Итого».
     *
     * @return array{cells: array, total: array}
     */
    private function computeMetrics(array $matrix): array
    {
        $total = ['cost' => 0.0, 'revenue' => 0.0, 'payments' => 0.0];

        foreach (array_keys(self::DIRECTIONS) as $dir) {
            $cell = $matrix[$dir];
            $matrix[$dir] = $this->withDerived($cell);

            $total['cost']     += $cell['cost'];
            $total['revenue']  += $cell['revenue'];
            $total['payments'] += $cell['payments'];
        }

        $total = $this->withDerived($total);

        return ['cells' => $matrix, 'total' => $total];
    }

    /**
     * Дописать в ячейку производные метрики.
     */
    private function withDerived(array $cell): array
    {
        $cell['cpa']       = $cell['payments'] > 0 ? $cell['cost'] / $cell['payments'] : null;
        $cell['avg_check'] = $cell['payments'] > 0 ? $cell['revenue'] / $cell['payments'] : null;
        $cell['profit']    = $cell['revenue'] - $cell['cost'];
        $cell['romi']      = $cell['cost'] > 0 ? ($cell['revenue'] - $cell['cost']) / $cell['cost'] : null;
        $cell['drr']       = $cell['revenue'] > 0 ? $cell['cost'] / $cell['revenue'] : null;
        return $cell;
    }
}
