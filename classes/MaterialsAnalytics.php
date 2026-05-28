<?php
/**
 * Аналитика раздела «Материалы ФОП».
 *
 * Воронка: Визиты → Регистрации → Сгенерировало → Токены → Оплаты → Выручка.
 * «Регистрации» — новые пользователи за период, у которых есть визит на лендинг материалов.
 * Покупки токенов идут мимо orders (вебхук кредитует через UserTokens::credit('purchase')),
 * поэтому выручка считается по token_packages.price_rub.
 */
class MaterialsAnalytics
{
    private Database $db;

    public function __construct($pdo)
    {
        $this->db = new Database($pdo);
    }

    /**
     * Сводные метрики за период.
     * Визиты, регистрации, генерации, траты токенов — по created_at.
     * Оплаты и выручка — по token_transactions.created_at (момент кредита = момент оплаты).
     */
    public function getTotals(string $startDate, string $endDate): array
    {
        $visits = (int)($this->db->queryOne(
            "SELECT COUNT(*) AS c FROM material_landing_visits WHERE created_at BETWEEN ? AND ?",
            [$startDate, $endDate]
        )['c'] ?? 0);

        $registered = (int)($this->db->queryOne(
            "SELECT COUNT(DISTINCT u.id) AS c FROM users u
             WHERE u.created_at BETWEEN ? AND ?
               AND EXISTS (SELECT 1 FROM material_landing_visits v WHERE v.user_id = u.id)",
            [$startDate, $endDate]
        )['c'] ?? 0);

        $generated = (int)($this->db->queryOne(
            "SELECT COUNT(DISTINCT user_id) AS c FROM material_generations
             WHERE status = 'done' AND created_at BETWEEN ? AND ?",
            [$startDate, $endDate]
        )['c'] ?? 0);

        $tokensSpent = (int)($this->db->queryOne(
            "SELECT COALESCE(SUM(ABS(delta)), 0) AS c FROM token_transactions
             WHERE delta < 0 AND reason IN ('generation','adaptation','download')
               AND created_at BETWEEN ? AND ?",
            [$startDate, $endDate]
        )['c'] ?? 0);

        $payments = $this->db->queryOne(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(tp.price_rub), 0) AS revenue
             FROM token_transactions tt
             JOIN token_packages tp ON tp.id = tt.package_id
             WHERE tt.reason = 'purchase' AND tt.created_at BETWEEN ? AND ?",
            [$startDate, $endDate]
        ) ?: [];

        $paid = (int)($payments['cnt'] ?? 0);
        $revenue = (float)($payments['revenue'] ?? 0);

        return [
            'visits' => $visits,
            'registered' => $registered,
            'generated' => $generated,
            'tokens_spent' => $tokensSpent,
            'paid' => $paid,
            'revenue' => $revenue,
            'conv_visit_to_reg' => $visits > 0 ? round($registered / $visits * 100, 1) : 0,
            'conv_reg_to_generated' => $registered > 0 ? round($generated / $registered * 100, 1) : 0,
            'conv_generated_to_paid' => $generated > 0 ? round($paid / $generated * 100, 1) : 0,
        ];
    }

    /**
     * Разбивка по дням за период. Каждая строка — дата с метриками.
     * Объединяет все метрики по дате (rsort по убыванию).
     */
    public function getDailyBreakdown(string $startDate, string $endDate): array
    {
        $visitsByDay = [];
        foreach ($this->db->query(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c
             FROM material_landing_visits WHERE created_at BETWEEN ? AND ?
             GROUP BY DATE(created_at)",
            [$startDate, $endDate]
        ) as $row) {
            $visitsByDay[$row['d']] = (int)$row['c'];
        }

        $regByDay = [];
        foreach ($this->db->query(
            "SELECT DATE(u.created_at) AS d, COUNT(DISTINCT u.id) AS c
             FROM users u
             WHERE u.created_at BETWEEN ? AND ?
               AND EXISTS (SELECT 1 FROM material_landing_visits v WHERE v.user_id = u.id)
             GROUP BY DATE(u.created_at)",
            [$startDate, $endDate]
        ) as $row) {
            $regByDay[$row['d']] = (int)$row['c'];
        }

        $generatedByDay = [];
        foreach ($this->db->query(
            "SELECT DATE(created_at) AS d, COUNT(DISTINCT user_id) AS c
             FROM material_generations
             WHERE status = 'done' AND created_at BETWEEN ? AND ?
             GROUP BY DATE(created_at)",
            [$startDate, $endDate]
        ) as $row) {
            $generatedByDay[$row['d']] = (int)$row['c'];
        }

        $tokensByDay = [];
        foreach ($this->db->query(
            "SELECT DATE(created_at) AS d, COALESCE(SUM(ABS(delta)), 0) AS c
             FROM token_transactions
             WHERE delta < 0 AND reason IN ('generation','adaptation','download')
               AND created_at BETWEEN ? AND ?
             GROUP BY DATE(created_at)",
            [$startDate, $endDate]
        ) as $row) {
            $tokensByDay[$row['d']] = (int)$row['c'];
        }

        $paymentsByDay = [];
        foreach ($this->db->query(
            "SELECT DATE(tt.created_at) AS d,
                    COUNT(*) AS cnt,
                    COALESCE(SUM(tp.price_rub), 0) AS revenue
             FROM token_transactions tt
             JOIN token_packages tp ON tp.id = tt.package_id
             WHERE tt.reason = 'purchase' AND tt.created_at BETWEEN ? AND ?
             GROUP BY DATE(tt.created_at)",
            [$startDate, $endDate]
        ) as $row) {
            $paymentsByDay[$row['d']] = $row;
        }

        $allDates = array_unique(array_merge(
            array_keys($visitsByDay),
            array_keys($regByDay),
            array_keys($generatedByDay),
            array_keys($tokensByDay),
            array_keys($paymentsByDay)
        ));
        rsort($allDates);

        $result = [];
        foreach ($allDates as $date) {
            $p = $paymentsByDay[$date] ?? [];
            $result[] = [
                'date' => $date,
                'visits' => $visitsByDay[$date] ?? 0,
                'registered' => $regByDay[$date] ?? 0,
                'generated' => $generatedByDay[$date] ?? 0,
                'tokens_spent' => $tokensByDay[$date] ?? 0,
                'paid' => (int)($p['cnt'] ?? 0),
                'revenue' => (float)($p['revenue'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Разбивка воронки по рекламной кампании (utm_campaign).
     * Визиты считаются по material_landing_visits, остальные стадии атрибутируются через
     * users.utm_campaign (проставляется при регистрации из воронки). Так оплаты привязываются
     * к кампании, на которую пришёл пользователь — для сверки с рекламным кабинетом.
     */
    public function getCampaignBreakdown(string $startDate, string $endDate): array
    {
        $none = '(без кампании)';

        $visitsByCampaign = [];
        foreach ($this->db->query(
            "SELECT COALESCE(NULLIF(utm_campaign, ''), ?) AS k, COUNT(*) AS c
             FROM material_landing_visits WHERE created_at BETWEEN ? AND ?
             GROUP BY k",
            [$none, $startDate, $endDate]
        ) as $row) {
            $visitsByCampaign[$row['k']] = (int)$row['c'];
        }

        $regByCampaign = [];
        foreach ($this->db->query(
            "SELECT COALESCE(NULLIF(u.utm_campaign, ''), ?) AS k, COUNT(DISTINCT u.id) AS c
             FROM users u
             WHERE u.created_at BETWEEN ? AND ?
               AND EXISTS (SELECT 1 FROM material_landing_visits v WHERE v.user_id = u.id)
             GROUP BY k",
            [$none, $startDate, $endDate]
        ) as $row) {
            $regByCampaign[$row['k']] = (int)$row['c'];
        }

        $genByCampaign = [];
        foreach ($this->db->query(
            "SELECT COALESCE(NULLIF(u.utm_campaign, ''), ?) AS k, COUNT(DISTINCT mg.user_id) AS c
             FROM material_generations mg
             JOIN users u ON u.id = mg.user_id
             WHERE mg.status = 'done' AND mg.created_at BETWEEN ? AND ?
             GROUP BY k",
            [$none, $startDate, $endDate]
        ) as $row) {
            $genByCampaign[$row['k']] = (int)$row['c'];
        }

        $payByCampaign = [];
        foreach ($this->db->query(
            "SELECT COALESCE(NULLIF(u.utm_campaign, ''), ?) AS k,
                    COUNT(*) AS cnt, COALESCE(SUM(tp.price_rub), 0) AS revenue
             FROM token_transactions tt
             JOIN users u ON u.id = tt.user_id
             JOIN token_packages tp ON tp.id = tt.package_id
             WHERE tt.reason = 'purchase' AND tt.created_at BETWEEN ? AND ?
             GROUP BY k",
            [$none, $startDate, $endDate]
        ) as $row) {
            $payByCampaign[$row['k']] = $row;
        }

        $campaigns = array_unique(array_merge(
            array_keys($visitsByCampaign),
            array_keys($regByCampaign),
            array_keys($genByCampaign),
            array_keys($payByCampaign)
        ));

        $result = [];
        foreach ($campaigns as $c) {
            $visits = $visitsByCampaign[$c] ?? 0;
            $paid = (int)($payByCampaign[$c]['cnt'] ?? 0);
            $generated = $genByCampaign[$c] ?? 0;
            $result[] = [
                'campaign'      => $c,
                'visits'        => $visits,
                'registered'    => $regByCampaign[$c] ?? 0,
                'generated'     => $generated,
                'paid'          => $paid,
                'revenue'       => (float)($payByCampaign[$c]['revenue'] ?? 0),
                'conv_visit_to_paid' => $visits > 0 ? round($paid / $visits * 100, 1) : 0,
            ];
        }

        usort($result, fn($a, $b) => $b['revenue'] <=> $a['revenue'] ?: $b['visits'] <=> $a['visits']);

        return $result;
    }
}
