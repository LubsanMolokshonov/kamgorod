<?php
/**
 * Аналитика шеринга публикаций.
 *
 * Воронка: клик по кнопке «поделиться» (таблица publication_shares)
 * → переход по расшаренной ссылке (таблица visits, utm_campaign=publication_share).
 *
 * Клики и переходы связываются через маппинг NETWORK_TO_SOURCE: сеть кнопки
 * (publication_shares.network) → utm_source, который партиал share-publication.php
 * вешает на ссылку. CR может быть >100% — одна расшаренная ссылка даёт много
 * переходов; клики до релиза UTM-разметки переходов не дают.
 */
class PublicationShareAnalytics
{
    /** Сеть кнопки «поделиться» → utm_source в размеченной ссылке */
    public const NETWORK_TO_SOURCE = [
        'vk'       => 'vk',
        'telegram' => 'telegram',
        'whatsapp' => 'whatsapp',
        'ok'       => 'ok',
        'copy'     => 'share_copy',
        'native'   => 'share_native',
    ];

    private Database $db;

    public function __construct($pdo)
    {
        $this->db = new Database($pdo);
    }

    /**
     * Клики по кнопкам «поделиться» за период, по сетям.
     * Возвращает [network => clicks].
     */
    public function getClicksByNetwork(string $from, string $to): array
    {
        $rows = $this->db->query(
            "SELECT network, COUNT(*) AS clicks
             FROM publication_shares
             WHERE created_at BETWEEN ? AND ?
             GROUP BY network",
            [$from, $to]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['network']] = (int)$row['clicks'];
        }
        return $result;
    }

    /**
     * Сколько разных публикаций шерили за период.
     */
    public function getSharedPublicationsCount(string $from, string $to): int
    {
        $row = $this->db->queryOne(
            "SELECT COUNT(DISTINCT publication_id) AS pubs
             FROM publication_shares
             WHERE created_at BETWEEN ? AND ?",
            [$from, $to]
        );
        return (int)($row['pubs'] ?? 0);
    }

    /**
     * Топ публикаций по кликам «поделиться» с разбивкой по сетям.
     */
    public function getTopPublicationsByClicks(string $from, string $to, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->query(
            "SELECT ps.publication_id,
                    COALESCE(p.title, CONCAT('Публикация #', ps.publication_id)) AS title,
                    p.slug,
                    COUNT(*) AS clicks,
                    SUM(ps.network = 'vk') AS vk_clicks,
                    SUM(ps.network = 'telegram') AS tg_clicks,
                    SUM(ps.network = 'whatsapp') AS wa_clicks,
                    SUM(ps.network = 'ok') AS ok_clicks,
                    SUM(ps.network = 'copy') AS copy_clicks,
                    SUM(ps.network = 'native') AS native_clicks
             FROM publication_shares ps
             LEFT JOIN publications p ON p.id = ps.publication_id
             WHERE ps.created_at BETWEEN ? AND ?
             GROUP BY ps.publication_id, p.title, p.slug
             ORDER BY clicks DESC
             LIMIT {$limit}",
            [$from, $to]
        );
    }

    /**
     * Входящие переходы по расшаренным ссылкам за период, по utm_source.
     * Возвращает [utm_source => visits].
     */
    public function getVisitsBySource(string $from, string $to): array
    {
        $rows = $this->db->query(
            "SELECT utm_source, COUNT(*) AS visits
             FROM visits
             WHERE is_bot = 0
               AND utm_campaign = 'publication_share'
               AND utm_source IN ({$this->sourcePlaceholders()})
               AND started_at BETWEEN ? AND ?
             GROUP BY utm_source",
            array_merge($this->sourceValues(), [$from, $to])
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['utm_source']] = (int)$row['visits'];
        }
        return $result;
    }

    /**
     * Переходы по размещению виджета (utm_content: publication/cabinet/certificate/email).
     * В publication_shares размещение не пишется, поэтому разрез только по переходам.
     */
    public function getVisitsByPlacement(string $from, string $to): array
    {
        return $this->db->query(
            "SELECT COALESCE(NULLIF(utm_content, ''), '(не указано)') AS placement,
                    COUNT(*) AS visits
             FROM visits
             WHERE is_bot = 0
               AND utm_campaign = 'publication_share'
               AND utm_source IN ({$this->sourcePlaceholders()})
               AND started_at BETWEEN ? AND ?
             GROUP BY placement
             ORDER BY visits DESC",
            array_merge($this->sourceValues(), [$from, $to])
        );
    }

    /**
     * Переходы по публикациям: slug вынимается из first_page_url
     * (участок между '/publikaciya/' и следующим '/' или '?').
     * Возвращает [slug => visits].
     */
    public function getVisitsByPublication(string $from, string $to): array
    {
        $rows = $this->db->query(
            "SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(
                        SUBSTRING_INDEX(first_page_url, '/publikaciya/', -1), '/', 1), '?', 1) AS slug,
                    COUNT(*) AS visits
             FROM visits
             WHERE is_bot = 0
               AND utm_campaign = 'publication_share'
               AND utm_source IN ({$this->sourcePlaceholders()})
               AND started_at BETWEEN ? AND ?
               AND first_page_url LIKE '%/publikaciya/%'
             GROUP BY slug",
            array_merge($this->sourceValues(), [$from, $to])
        );

        $result = [];
        foreach ($rows as $row) {
            if ($row['slug'] !== '') {
                $result[$row['slug']] = (int)$row['visits'];
            }
        }
        return $result;
    }

    /**
     * Помесячная динамика воронки за последние $months месяцев (независимо от фильтра периода).
     * Возвращает строки ['ym' => 'YYYY-MM', 'clicks' => N, 'visits' => N] по возрастанию месяца.
     */
    public function getMonthlyDynamics(int $months = 12): array
    {
        $since = date('Y-m-01 00:00:00', strtotime('-' . (max(1, $months) - 1) . ' months'));

        $byMonth = [];

        $clickRows = $this->db->query(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS clicks
             FROM publication_shares
             WHERE created_at >= ?
             GROUP BY ym",
            [$since]
        );
        foreach ($clickRows as $row) {
            $byMonth[$row['ym']]['clicks'] = (int)$row['clicks'];
        }

        $visitRows = $this->db->query(
            "SELECT DATE_FORMAT(started_at, '%Y-%m') AS ym, COUNT(*) AS visits
             FROM visits
             WHERE is_bot = 0
               AND utm_campaign = 'publication_share'
               AND utm_source IN ({$this->sourcePlaceholders()})
               AND started_at >= ?
             GROUP BY ym",
            array_merge($this->sourceValues(), [$since])
        );
        foreach ($visitRows as $row) {
            $byMonth[$row['ym']]['visits'] = (int)$row['visits'];
        }

        ksort($byMonth);

        $result = [];
        foreach ($byMonth as $ym => $data) {
            $result[] = [
                'ym'     => $ym,
                'clicks' => $data['clicks'] ?? 0,
                'visits' => $data['visits'] ?? 0,
            ];
        }
        return $result;
    }

    /**
     * Данные публикаций по списку slug'ов (для строк, где есть переходы, но нет кликов).
     * Возвращает [slug => ['id' => int, 'title' => string]].
     */
    public function getPublicationsBySlugs(array $slugs): array
    {
        if (empty($slugs)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $rows = $this->db->query(
            "SELECT id, slug, title FROM publications WHERE slug IN ({$placeholders})",
            array_values($slugs)
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['slug']] = ['id' => (int)$row['id'], 'title' => $row['title']];
        }
        return $result;
    }

    /** Плейсхолдеры для IN по utm_source (?,?,...) */
    private function sourcePlaceholders(): string
    {
        return implode(',', array_fill(0, count(self::NETWORK_TO_SOURCE), '?'));
    }

    /** Значения utm_source для IN — из константы, не интерполируются в SQL */
    private function sourceValues(): array
    {
        return array_values(self::NETWORK_TO_SOURCE);
    }
}
