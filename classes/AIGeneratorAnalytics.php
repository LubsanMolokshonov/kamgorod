<?php
/**
 * Аналитика AI-генератора публикаций.
 *
 * Воронка: Визиты → Сессии генерации → Сгенерировано → Опубликовано → Оплачено.
 * «Оплачено индивидуально» — заказ содержит ровно один товар (свидетельство AI-публикации).
 * «Оплачено в составе заказа» — заказ содержит свидетельство AI-публикации + другие товары.
 */
class AIGeneratorAnalytics
{
    private Database $db;

    public function __construct($pdo)
    {
        $this->db = new Database($pdo);
    }

    /**
     * Сводные метрики за период.
     * Визиты, сессии, сгенерировано, опубликовано — по created_at.
     * Оплаты и выручка — по orders.paid_at.
     */
    public function getTotals(string $startDate, string $endDate): array
    {
        $visits = (int)($this->db->queryOne(
            "SELECT COUNT(*) AS c FROM ai_generator_visits WHERE created_at BETWEEN ? AND ?",
            [$startDate, $endDate]
        )['c'] ?? 0);

        $sessions = $this->db->queryOne(
            "SELECT
                COUNT(*) AS sessions_started,
                SUM(CASE WHEN generation_count > 0 THEN 1 ELSE 0 END) AS generated_cnt,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published_cnt
             FROM article_generation_sessions
             WHERE created_at BETWEEN ? AND ?",
            [$startDate, $endDate]
        ) ?: [];

        $sessionsStarted = (int)($sessions['sessions_started'] ?? 0);
        $generated = (int)($sessions['generated_cnt'] ?? 0);
        $published = (int)($sessions['published_cnt'] ?? 0);

        $payments = $this->db->queryOne(
            "SELECT
                COUNT(DISTINCT pc.id) AS paid_total,
                SUM(CASE WHEN cnt.item_count = 1 THEN 1 ELSE 0 END) AS paid_individual,
                SUM(CASE WHEN cnt.item_count > 1 THEN 1 ELSE 0 END) AS paid_in_order,
                COALESCE(SUM(CASE WHEN cnt.item_count = 1 THEN o.final_amount ELSE 0 END), 0) AS revenue_individual,
                COALESCE(SUM(CASE WHEN cnt.item_count > 1 THEN oi.price ELSE 0 END), 0) AS revenue_in_order
             FROM publication_certificates pc
             JOIN publications p ON p.id = pc.publication_id AND p.source = 'generator'
             JOIN order_items oi ON oi.certificate_id = pc.id
             JOIN orders o ON o.id = oi.order_id
             JOIN (SELECT order_id, COUNT(*) AS item_count FROM order_items GROUP BY order_id) cnt
               ON cnt.order_id = o.id
             WHERE o.payment_status = 'succeeded'
               AND o.paid_at BETWEEN ? AND ?",
            [$startDate, $endDate]
        ) ?: [];

        $paidTotal = (int)($payments['paid_total'] ?? 0);
        $paidIndividual = (int)($payments['paid_individual'] ?? 0);
        $paidInOrder = (int)($payments['paid_in_order'] ?? 0);
        $revenue = (float)($payments['revenue_individual'] ?? 0) + (float)($payments['revenue_in_order'] ?? 0);

        return [
            'visits' => $visits,
            'sessions_started' => $sessionsStarted,
            'generated' => $generated,
            'published' => $published,
            'paid_total' => $paidTotal,
            'paid_individual' => $paidIndividual,
            'paid_in_order' => $paidInOrder,
            'revenue' => $revenue,
            'revenue_individual' => (float)($payments['revenue_individual'] ?? 0),
            'revenue_in_order' => (float)($payments['revenue_in_order'] ?? 0),
            'conv_visit_to_session' => $visits > 0 ? round($sessionsStarted / $visits * 100, 1) : 0,
            'conv_session_to_generated' => $sessionsStarted > 0 ? round($generated / $sessionsStarted * 100, 1) : 0,
            'conv_generated_to_published' => $generated > 0 ? round($published / $generated * 100, 1) : 0,
            'conv_published_to_paid' => $published > 0 ? round($paidTotal / $published * 100, 1) : 0,
        ];
    }

    /**
     * Разбивка по дням за период. Каждая строка — дата с метриками.
     * Объединяет визиты/сессии (по created_at) и оплаты (по paid_at) по дате.
     */
    public function getDailyBreakdown(string $startDate, string $endDate): array
    {
        $visitsByDay = [];
        foreach ($this->db->query(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c
             FROM ai_generator_visits WHERE created_at BETWEEN ? AND ?
             GROUP BY DATE(created_at)",
            [$startDate, $endDate]
        ) as $row) {
            $visitsByDay[$row['d']] = (int)$row['c'];
        }

        $sessionsByDay = [];
        foreach ($this->db->query(
            "SELECT DATE(created_at) AS d,
                    COUNT(*) AS sessions_started,
                    SUM(CASE WHEN generation_count > 0 THEN 1 ELSE 0 END) AS generated_cnt,
                    SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published_cnt
             FROM article_generation_sessions
             WHERE created_at BETWEEN ? AND ?
             GROUP BY DATE(created_at)",
            [$startDate, $endDate]
        ) as $row) {
            $sessionsByDay[$row['d']] = $row;
        }

        $paymentsByDay = [];
        foreach ($this->db->query(
            "SELECT DATE(o.paid_at) AS d,
                    COUNT(DISTINCT pc.id) AS paid_total,
                    SUM(CASE WHEN cnt.item_count = 1 THEN 1 ELSE 0 END) AS paid_individual,
                    SUM(CASE WHEN cnt.item_count > 1 THEN 1 ELSE 0 END) AS paid_in_order,
                    COALESCE(SUM(CASE WHEN cnt.item_count = 1 THEN o.final_amount ELSE 0 END), 0) AS revenue_individual,
                    COALESCE(SUM(CASE WHEN cnt.item_count > 1 THEN oi.price ELSE 0 END), 0) AS revenue_in_order
             FROM publication_certificates pc
             JOIN publications p ON p.id = pc.publication_id AND p.source = 'generator'
             JOIN order_items oi ON oi.certificate_id = pc.id
             JOIN orders o ON o.id = oi.order_id
             JOIN (SELECT order_id, COUNT(*) AS item_count FROM order_items GROUP BY order_id) cnt
               ON cnt.order_id = o.id
             WHERE o.payment_status = 'succeeded'
               AND o.paid_at BETWEEN ? AND ?
             GROUP BY DATE(o.paid_at)",
            [$startDate, $endDate]
        ) as $row) {
            $paymentsByDay[$row['d']] = $row;
        }

        $allDates = array_unique(array_merge(
            array_keys($visitsByDay),
            array_keys($sessionsByDay),
            array_keys($paymentsByDay)
        ));
        rsort($allDates);

        $result = [];
        foreach ($allDates as $date) {
            $s = $sessionsByDay[$date] ?? [];
            $p = $paymentsByDay[$date] ?? [];
            $revenue = (float)($p['revenue_individual'] ?? 0) + (float)($p['revenue_in_order'] ?? 0);
            $result[] = [
                'date' => $date,
                'visits' => $visitsByDay[$date] ?? 0,
                'sessions_started' => (int)($s['sessions_started'] ?? 0),
                'generated' => (int)($s['generated_cnt'] ?? 0),
                'published' => (int)($s['published_cnt'] ?? 0),
                'paid_total' => (int)($p['paid_total'] ?? 0),
                'paid_individual' => (int)($p['paid_individual'] ?? 0),
                'paid_in_order' => (int)($p['paid_in_order'] ?? 0),
                'revenue' => $revenue,
            ];
        }

        return $result;
    }
}
