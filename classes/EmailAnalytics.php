<?php
/**
 * EmailAnalytics — запросы агрегатов для дашборда «E-mail трекинг».
 *
 * Работает поверх email_events (одна строка на письмо) и email_click_events.
 * Поддерживает фильтрацию по периоду отправки, типу письма (email_type) и touchpoint.
 */

class EmailAnalytics {

    private const TYPE_LABELS = [
        'journey'      => 'Конкурсы (неоплаченные)',
        'webinar'      => 'Вебинары',
        'publication'  => 'Публикации',
        'autowebinar'  => 'Видеолекции',
        'olympiad'     => 'Олимпиады',
        'course'       => 'Курсы',
        'course_promo' => 'Промо курсов',
        'payment'      => 'Транзакционные (оплата)',
        'other'        => 'Прочие',
    ];

    private Database $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    public static function typeLabel(string $type): string {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    public static function allTypes(): array {
        return self::TYPE_LABELS;
    }

    /**
     * KPI итоги: sent/opened/clicked/paid/revenue + rate'ы.
     */
    public function getTotals(array $filters): array {
        [$where, $params] = $this->buildWhere($filters);

        $row = $this->db->queryOne(
            "SELECT
                COUNT(*)                                    AS sent,
                SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END)        AS opened,
                SUM(CASE WHEN first_clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
                SUM(CASE WHEN order_id IS NOT NULL THEN 1 ELSE 0 END)         AS paid,
                COALESCE(SUM(revenue), 0) AS revenue
             FROM email_events
             WHERE {$where}",
            $params
        ) ?: ['sent' => 0, 'opened' => 0, 'clicked' => 0, 'paid' => 0, 'revenue' => 0];

        return $this->withRates($row);
    }

    /**
     * Разрез по email_type.
     */
    public function getByType(array $filters): array {
        [$where, $params] = $this->buildWhere($filters);

        $rows = $this->db->query(
            "SELECT email_type,
                    COUNT(*)                                                      AS sent,
                    SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END)        AS opened,
                    SUM(CASE WHEN first_clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
                    SUM(CASE WHEN order_id IS NOT NULL THEN 1 ELSE 0 END)         AS paid,
                    COALESCE(SUM(revenue), 0) AS revenue
             FROM email_events
             WHERE {$where}
             GROUP BY email_type
             ORDER BY sent DESC",
            $params
        );

        foreach ($rows as &$row) {
            $row['type_label'] = self::typeLabel($row['email_type']);
            $row = $this->withRates($row);
        }
        return $rows;
    }

    /**
     * Разрез по touchpoint_code (с фильтром по типу, если задан).
     */
    public function getByTouchpoint(array $filters): array {
        [$where, $params] = $this->buildWhere($filters);

        $rows = $this->db->query(
            "SELECT email_type,
                    COALESCE(touchpoint_code, '(без кода)') AS touchpoint_code,
                    COUNT(*)                                                      AS sent,
                    SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END)        AS opened,
                    SUM(CASE WHEN first_clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
                    SUM(CASE WHEN order_id IS NOT NULL THEN 1 ELSE 0 END)         AS paid,
                    COALESCE(SUM(revenue), 0) AS revenue
             FROM email_events
             WHERE {$where}
             GROUP BY email_type, touchpoint_code
             ORDER BY sent DESC",
            $params
        );

        foreach ($rows as &$row) {
            $row['type_label'] = self::typeLabel($row['email_type']);
            $row = $this->withRates($row);
        }
        return $rows;
    }

    /**
     * Последние N писем для drill-down.
     */
    public function getRecent(array $filters, int $limit = 50): array {
        [$where, $params] = $this->buildWhere($filters);
        $limit = max(1, min(500, $limit));

        $rows = $this->db->query(
            "SELECT id, message_id, email_type, touchpoint_code, recipient_email,
                    subject, sent_at, opened_at, opens_count, first_clicked_at,
                    clicks_count, order_id, converted_at, revenue
             FROM email_events
             WHERE {$where}
             ORDER BY sent_at DESC
             LIMIT {$limit}",
            $params
        );

        foreach ($rows as &$row) {
            $row['type_label'] = self::typeLabel($row['email_type']);
        }
        return $rows;
    }

    /**
     * Собрать WHERE-часть запроса из фильтров.
     */
    private function buildWhere(array $filters): array {
        $where = ['1=1'];
        $params = [];

        $from = $filters['date_from'] ?? null;
        $to   = $filters['date_to']   ?? null;
        if ($from) { $where[] = 'sent_at >= ?'; $params[] = $from . ' 00:00:00'; }
        if ($to)   { $where[] = 'sent_at <= ?'; $params[] = $to   . ' 23:59:59'; }

        if (!empty($filters['email_type']) && $filters['email_type'] !== 'all') {
            $where[] = 'email_type = ?';
            $params[] = $filters['email_type'];
        }

        if (!empty($filters['touchpoint_code']) && $filters['touchpoint_code'] !== 'all') {
            $where[] = 'touchpoint_code = ?';
            $params[] = $filters['touchpoint_code'];
        }

        return [implode(' AND ', $where), $params];
    }

    private function withRates(array $row): array {
        $sent    = (int)($row['sent'] ?? 0);
        $opened  = (int)($row['opened'] ?? 0);
        $clicked = (int)($row['clicked'] ?? 0);
        $paid    = (int)($row['paid'] ?? 0);

        $row['open_rate']    = $sent    > 0 ? round($opened  * 100 / $sent,    2) : 0;
        $row['click_rate']   = $sent    > 0 ? round($clicked * 100 / $sent,    2) : 0;
        $row['ctor']         = $opened  > 0 ? round($clicked * 100 / $opened,  2) : 0; // Click-To-Open Rate
        $row['conv_rate']    = $clicked > 0 ? round($paid    * 100 / $clicked, 2) : 0;
        $row['overall_conv'] = $sent    > 0 ? round($paid    * 100 / $sent,    2) : 0;
        $row['revenue']      = (float)($row['revenue'] ?? 0);

        return $row;
    }
}
