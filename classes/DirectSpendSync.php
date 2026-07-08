<?php
/**
 * Синхронизация расходов Яндекс.Директа из ai.h1pro.ru (проект «AI аналитика для Директа»).
 *
 * Источник: GET {H1PRO_EXPORT_API_URL}/spend — строки день × кампания, рубли с НДС.
 * Директ пересчитывает статистику задним числом, поэтому окно синка (по умолчанию
 * последние 7 дней) каждый раз перезаписывается целиком: DELETE по диапазону + INSERT.
 *
 * После апсерта сырой таблицы direct_ad_spend пересчитываются агрегаты:
 *   - rnp_ad_costs.direct_portal_cost / direct_course_cost — по каждой дате окна;
 *   - direction_weekly_costs.direct_cost — по каждой ISO-неделе, пересекающей окно
 *     (ручная колонка cost — расходы прочих каналов — не трогается).
 *
 * Запуск: cron/sync-direct-spend.php (ежедневно) или вручную с --from/--to (бэкфилл).
 */

class DirectSpendSync
{
    private const RETRY_ATTEMPTS = 3;
    private const RETRY_PAUSE_SEC = 60;

    /**
     * Маппинг направления по подстроке названия кампании.
     * Порядок важен: «конкурс» — раньше «курс» (иначе «конКУРС» матчится на курсы).
     */
    private const DIRECTION_KEYWORDS = [
        'competitions' => ['конкурс'],
        'courses'      => ['кпк', 'кпп', 'курс', 'переподготов'],
        'olympiads'    => ['олимпиад'],
        'publications' => ['публикаци'],
        'webinars'     => ['вебинар'],
        'materials'    => ['материал', 'фоп'],
    ];

    private Database $db;
    private string $apiUrl;
    private string $apiKey;
    private string $projectId;
    /** @var callable|null function(string $level, string $msg): void */
    private $logger;

    public function __construct(\PDO $pdo, ?callable $logger = null)
    {
        $this->db = new Database($pdo);
        $this->apiUrl = rtrim(H1PRO_EXPORT_API_URL, '/');
        $this->apiKey = H1PRO_EXPORT_API_KEY;
        $this->projectId = H1PRO_PROJECT_ID;
        $this->logger = $logger;
    }

    /**
     * Полный цикл синка. Без дат API отдаёт окно последних 7 дней (по вчера, UTC) —
     * фактический диапазон берётся из meta ответа.
     *
     * @return array{date_from:string,date_to:string,rows:int,campaigns:int,unmapped:int,rnp_days:int,direction_weeks:int}
     */
    public function sync(?string $from = null, ?string $to = null): array
    {
        if ($this->apiKey === '' || $this->projectId === '') {
            throw new \RuntimeException('H1PRO_EXPORT_API_KEY / H1PRO_PROJECT_ID не заданы в .env');
        }

        $response = $this->fetchSpend($from, $to);
        $dateFrom = $response['meta']['date_from'] ?? $from;
        $dateTo   = $response['meta']['date_to'] ?? $to;
        if (!$dateFrom || !$dateTo) {
            throw new \RuntimeException('API не вернул meta.date_from/date_to');
        }

        $rows = [];
        $campaigns = [];
        $unmapped = [];
        foreach ($response['rows'] as $r) {
            $direction = self::mapDirection((string)$r['campaign_name']);
            if ($direction === 'other') {
                $unmapped[(int)$r['campaign_id']] = $r['campaign_name'];
            }
            $campaigns[(int)$r['campaign_id']] = true;
            $rows[] = [
                'date'          => $r['date'],
                'campaign_id'   => (int)$r['campaign_id'],
                'campaign_name' => (string)$r['campaign_name'],
                'direction'     => $direction,
                'section'       => $direction === 'courses' ? 'course' : 'portal',
                'cost'          => (float)$r['cost'],
            ];
        }

        $this->upsertRaw($rows, $dateFrom, $dateTo);
        $rnpDays = $this->recalcRnp($dateFrom, $dateTo);
        $dirWeeks = $this->recalcDirections($dateFrom, $dateTo);

        foreach ($unmapped as $id => $name) {
            $this->log('warning', "Кампания без направления (other): {$id} «{$name}»");
        }

        return [
            'date_from'       => $dateFrom,
            'date_to'         => $dateTo,
            'rows'            => count($rows),
            'campaigns'       => count($campaigns),
            'unmapped'        => count($unmapped),
            'rnp_days'        => $rnpDays,
            'direction_weeks' => $dirWeeks,
        ];
    }

    /**
     * Направление по названию кампании (регистронезависимо, порядок правил важен).
     */
    public static function mapDirection(string $campaignName): string
    {
        $name = mb_strtolower($campaignName, 'UTF-8');
        foreach (self::DIRECTION_KEYWORDS as $direction => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_strpos($name, $kw, 0, 'UTF-8') !== false) {
                    return $direction;
                }
            }
        }
        return 'other';
    }

    // ============================================================
    // Внутренняя кухня
    // ============================================================

    /**
     * GET /spend с ретраями при 5xx и сетевых ошибках; 4xx — сразу исключение.
     */
    private function fetchSpend(?string $from, ?string $to): array
    {
        $query = ['project_id' => $this->projectId];
        if ($from !== null) $query['date_from'] = $from;
        if ($to !== null)   $query['date_to'] = $to;

        $lastError = 'unknown';
        for ($attempt = 1; $attempt <= self::RETRY_ATTEMPTS; $attempt++) {
            $result = $this->call('/spend', $query);
            if ($result['ok']) {
                return $result['data'];
            }
            $lastError = $result['error'];
            // 4xx — ошибка конфигурации/параметров, ретрай бессмыслен
            if ($result['http_code'] >= 400 && $result['http_code'] < 500) {
                throw new \RuntimeException("h1pro API {$result['http_code']}: {$lastError}");
            }
            $this->log('warning', "Попытка {$attempt}/" . self::RETRY_ATTEMPTS . " не удалась: {$lastError}");
            if ($attempt < self::RETRY_ATTEMPTS) {
                sleep(self::RETRY_PAUSE_SEC);
            }
        }
        throw new \RuntimeException('h1pro API недоступен после ' . self::RETRY_ATTEMPTS . " попыток: {$lastError}");
    }

    /**
     * Низкоуровневый GET-запрос. Не бросает исключений — возвращает ['ok'=>bool, ...].
     */
    private function call(string $path, array $query): array
    {
        $url = $this->apiUrl . $path . '?' . http_build_query($query);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'X-API-Key: ' . $this->apiKey,
            ],
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log("DirectSpendSync: cURL error: {$curlErr}");
            return ['ok' => false, 'error' => 'cURL: ' . $curlErr, 'http_code' => 0];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            error_log("DirectSpendSync: invalid JSON (HTTP {$httpCode})");
            return ['ok' => false, 'error' => 'invalid JSON response', 'http_code' => $httpCode];
        }
        if ($httpCode !== 200) {
            $detail = is_string($data['detail'] ?? null) ? $data['detail'] : $raw;
            error_log("DirectSpendSync: API error HTTP {$httpCode}: {$detail}");
            return ['ok' => false, 'error' => $detail, 'http_code' => $httpCode];
        }
        return ['ok' => true, 'data' => $data, 'http_code' => $httpCode];
    }

    /**
     * Перезапись сырых строк окна: DELETE по диапазону + INSERT (в транзакции),
     * чтобы не оставались фантомные строки пересчитанных задним числом кампаний.
     */
    private function upsertRaw(array $rows, string $dateFrom, string $dateTo): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->execute(
                "DELETE FROM direct_ad_spend WHERE date BETWEEN ? AND ?",
                [$dateFrom, $dateTo]
            );
            foreach ($rows as $r) {
                $this->db->execute(
                    "INSERT INTO direct_ad_spend (date, campaign_id, campaign_name, direction, section, cost)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [$r['date'], $r['campaign_id'], $r['campaign_name'], $r['direction'], $r['section'], $r['cost']]
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Дневные суммы Директа портал/курсы в rnp_ad_costs. Проходит по каждой дате окна,
     * включая дни без расхода — чтобы обнулять пересчитанные задним числом значения.
     */
    private function recalcRnp(string $dateFrom, string $dateTo): int
    {
        $sums = $this->db->query(
            "SELECT date, section, SUM(cost) AS cost
             FROM direct_ad_spend
             WHERE date BETWEEN ? AND ?
             GROUP BY date, section",
            [$dateFrom, $dateTo]
        );
        $idx = [];
        foreach ($sums as $s) {
            $idx[$s['date']][$s['section']] = (float)$s['cost'];
        }

        $days = 0;
        $cur = new \DateTimeImmutable($dateFrom);
        $end = new \DateTimeImmutable($dateTo);
        while ($cur <= $end) {
            $date = $cur->format('Y-m-d');
            $this->db->execute(
                "INSERT INTO rnp_ad_costs (date, direct_portal_cost, direct_course_cost)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     direct_portal_cost = VALUES(direct_portal_cost),
                     direct_course_cost = VALUES(direct_course_cost)",
                [$date, $idx[$date]['portal'] ?? 0, $idx[$date]['course'] ?? 0]
            );
            $days++;
            $cur = $cur->modify('+1 day');
        }
        return $days;
    }

    /**
     * Недельные суммы Директа по направлениям в direction_weekly_costs.direct_cost.
     * Каждая ISO-неделя, пересекающая окно, пересчитывается целиком из сырой таблицы.
     */
    private function recalcDirections(string $dateFrom, string $dateTo): int
    {
        $directions = array_keys(self::DIRECTION_KEYWORDS);

        $monday = (new \DateTimeImmutable($dateFrom))->modify('monday this week');
        if ($monday->format('Y-m-d') > $dateFrom) {
            $monday = $monday->modify('-7 days');
        }
        $end = new \DateTimeImmutable($dateTo);

        $weeks = 0;
        while ($monday <= $end) {
            $weekStart = $monday->format('Y-m-d');
            $weekEnd = $monday->modify('+6 days')->format('Y-m-d');

            $sums = $this->db->query(
                "SELECT direction, SUM(cost) AS cost
                 FROM direct_ad_spend
                 WHERE date BETWEEN ? AND ? AND direction != 'other'
                 GROUP BY direction",
                [$weekStart, $weekEnd]
            );
            $idx = [];
            foreach ($sums as $s) {
                $idx[$s['direction']] = (float)$s['cost'];
            }

            foreach ($directions as $dir) {
                $this->db->execute(
                    "INSERT INTO direction_weekly_costs (week_start, direction, cost, direct_cost)
                     VALUES (?, ?, 0, ?)
                     ON DUPLICATE KEY UPDATE direct_cost = VALUES(direct_cost)",
                    [$weekStart, $dir, $idx[$dir] ?? 0]
                );
            }

            $weeks++;
            $monday = $monday->modify('+7 days');
        }
        return $weeks;
    }

    private function log(string $level, string $msg): void
    {
        if ($this->logger) {
            ($this->logger)($level, $msg);
        }
    }
}
