<?php
/**
 * OldBaseCampaign — кампания рассылки по old_base_subscribers.
 *
 * Workflow:
 *   create() → previewAudience() → planRecipients() → launch() → (cron) → completed
 *   На любом этапе running → pause()/resume()/cancel().
 *
 * Аналитика тянется через JOIN с email_events (linked by message_id) и orders
 * (linked by orders.email_message_id, выставленным /api/email-track/click.php).
 *
 * Прогрев (ramp-up) — JSON-массив [{day:1,quota:15}, ...] на каждой кампании
 * редактируется per-day. Если получателей больше, чем суммарная квота — последний
 * день расписания держит quota постоянной до исчерпания базы.
 */

require_once __DIR__ . '/Database.php';

class OldBaseCampaign {
    private Database $db;
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
    }

    /**
     * Дефолтный прогрев на 30 дней.
     * @return array<array{day:int,quota:int}>
     */
    public static function defaultRampSchedule(): array {
        $quotas = [15, 20, 30, 50, 75, 100, 150, 200, 300, 400,
                   500, 600, 700, 800, 900, 1000, 1100, 1200, 1300, 1400,
                   1500, 1600, 1700, 1800, 1900, 2000, 2000, 2000, 2000, 2000];
        $out = [];
        foreach ($quotas as $i => $q) {
            $out[] = ['day' => $i + 1, 'quota' => $q];
        }
        return $out;
    }

    /**
     * Создать draft. Не планирует получателей.
     */
    public function create(array $data): int {
        $required = ['code', 'name', 'subject', 'html_body'];
        foreach ($required as $r) {
            if (empty($data[$r])) {
                throw new \InvalidArgumentException("OldBaseCampaign::create: '$r' required");
            }
        }
        $code = self::slugify($data['code']);
        if (!preg_match('/^[a-z0-9_-]{3,64}$/', $code)) {
            throw new \InvalidArgumentException("Invalid campaign code: $code");
        }

        $audienceFilter = $data['audience_filter'] ?? ['type' => 'all'];
        $rampSchedule = $data['ramp_schedule'] ?? self::defaultRampSchedule();

        return (int)$this->db->insert('old_base_campaigns', [
            'code'              => $code,
            'name'              => $data['name'],
            'subject'           => $data['subject'],
            'from_name'         => $data['from_name'] ?? null,
            'from_email'        => $data['from_email'] ?? null,
            'html_body'         => $data['html_body'],
            'plain_body'        => $data['plain_body'] ?? null,
            'cta_url'           => $data['cta_url'] ?? null,
            'auto_utm'          => !empty($data['auto_utm']) ? 1 : 0,
            'audience_filter'   => json_encode($audienceFilter, JSON_UNESCAPED_UNICODE),
            'status'            => 'draft',
            'start_date'        => $data['start_date'] ?? date('Y-m-d'),
            'send_window_start' => $data['send_window_start'] ?? '10:00:00',
            'send_window_end'   => $data['send_window_end'] ?? '18:00:00',
            'timezone'          => $data['timezone'] ?? 'Europe/Moscow',
            'ramp_schedule'     => json_encode($rampSchedule),
            'created_by'        => $data['created_by'] ?? null,
        ]);
    }

    public function update(int $id, array $data): void {
        $allowed = ['name','subject','from_name','from_email','html_body','plain_body',
                    'cta_url','auto_utm','start_date','send_window_start','send_window_end','timezone'];
        $patch = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) $patch[$k] = $data[$k];
        }
        if (isset($data['audience_filter'])) {
            $patch['audience_filter'] = json_encode($data['audience_filter'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($data['ramp_schedule'])) {
            $patch['ramp_schedule'] = json_encode($data['ramp_schedule']);
        }
        if (!$patch) return;
        $this->db->update('old_base_campaigns', $patch, 'id = ?', [$id]);
    }

    public function find(int $id): ?array {
        $row = $this->db->queryOne("SELECT * FROM old_base_campaigns WHERE id = ?", [$id]);
        return $row ?: null;
    }

    public function listAll(): array {
        return $this->db->query("SELECT * FROM old_base_campaigns ORDER BY id DESC");
    }

    /**
     * Посчитать число получателей под фильтр (без записи).
     */
    public function previewAudience(array $filter): int {
        [$sql, $params] = $this->audienceSql($filter, 'COUNT(DISTINCT s.id) AS c');
        $row = $this->db->queryOne($sql, $params);
        return $row ? (int)$row['c'] : 0;
    }

    /**
     * Сколько получателей под этот фильтр пересекаются с ещё не отправленными
     * (pending) письмами других активных кампаний (running/paused/scheduled).
     * Нужно, чтобы предупредить о двойной отправке одному человеку в один период.
     *
     * @param int $excludeCampaignId Не учитывать получателей этой кампании (при редактировании).
     */
    public function audienceOverlap(array $filter, int $excludeCampaignId = 0): int {
        [$audSql, $params] = $this->audienceSql($filter, 's.id AS subscriber_id');
        $params[] = $excludeCampaignId;
        $row = $this->db->queryOne(
            "SELECT COUNT(DISTINCT aud.subscriber_id) AS c
             FROM ($audSql) AS aud
             JOIN old_base_campaign_recipients r ON r.subscriber_id = aud.subscriber_id
             JOIN old_base_campaigns c ON c.id = r.campaign_id
             WHERE r.status = 'pending'
               AND c.status IN ('running','paused','scheduled')
               AND c.id <> ?",
            $params
        );
        return $row ? (int)$row['c'] : 0;
    }

    /**
     * Резолвит audience_filter в подзапрос по old_base_subscribers.
     * Поддерживаемые типы:
     *   - all
     *   - never_sent (active, у которых total_sent=0)
     *   - opened_in: { campaign_ids:[…] } — открывшие письма этих кампаний
     *   - clicked_in: { campaign_ids:[…] }
     *   - converted_in: { campaign_ids:[…] }
     *   - exclude_recipients_of: { campaign_ids:[…] } + base ('all'|'never_sent')
     *   - specific_emails: { emails:[…] } — для тестовых рассылок
     * Всегда отсеиваются status != 'active'.
     */
    private function audienceSql(array $filter, string $selectExpr): array {
        $type = $filter['type'] ?? 'all';
        $base = "FROM old_base_subscribers s WHERE s.status = 'active'";
        $params = [];

        switch ($type) {
            case 'all':
                // ничего
                break;

            case 'never_sent':
                $base .= " AND s.total_sent = 0";
                break;

            case 'specific_emails':
                $emails = array_values(array_filter(array_map('strval', $filter['emails'] ?? [])));
                if (!$emails) {
                    // вернём заведомо пустое
                    $base .= " AND 1=0";
                } else {
                    $ph = implode(',', array_fill(0, count($emails), '?'));
                    $base .= " AND s.email IN ($ph)";
                    $params = array_merge($params, $emails);
                }
                break;

            case 'opened_in':
            case 'clicked_in':
            case 'converted_in':
                $campIds = array_map('intval', $filter['campaign_ids'] ?? []);
                if (!$campIds) {
                    $base .= " AND 1=0";
                    break;
                }
                $ph = implode(',', array_fill(0, count($campIds), '?'));
                $col = $type === 'opened_in' ? 'opens_count' : ($type === 'clicked_in' ? 'clicks_count' : 'order_id');
                $op = $type === 'converted_in' ? 'IS NOT NULL' : '> 0';
                $base .= " AND s.id IN (
                    SELECT r.subscriber_id
                    FROM old_base_campaign_recipients r
                    JOIN email_events ev ON ev.message_id = r.message_id
                    WHERE r.campaign_id IN ($ph) AND ev.$col $op
                )";
                $params = array_merge($params, $campIds);
                break;

            case 'exclude_recipients_of':
                $campIds = array_map('intval', $filter['campaign_ids'] ?? []);
                $sub = $filter['base'] ?? 'all';
                if ($sub === 'never_sent') {
                    $base .= " AND s.total_sent = 0";
                }
                if ($campIds) {
                    $ph = implode(',', array_fill(0, count($campIds), '?'));
                    $base .= " AND s.id NOT IN (
                        SELECT r.subscriber_id FROM old_base_campaign_recipients r WHERE r.campaign_id IN ($ph)
                    )";
                    $params = array_merge($params, $campIds);
                }
                break;

            default:
                throw new \InvalidArgumentException("Unknown audience filter type: $type");
        }

        return ["SELECT $selectExpr $base", $params];
    }

    /**
     * Запланировать получателей: материализовать строки в old_base_campaign_recipients
     * со scheduled_at по ramp_schedule + send_window. Идемпотентен — повторный вызов
     * пропускает уже существующие строки (UNIQUE campaign_id, subscriber_id).
     *
     * @return int количество созданных recipient-строк
     */
    public function planRecipients(int $campaignId): int {
        $camp = $this->find($campaignId);
        if (!$camp) throw new \RuntimeException("Campaign #$campaignId not found");

        $filter = json_decode($camp['audience_filter'], true) ?: ['type' => 'all'];
        $ramp = json_decode($camp['ramp_schedule'], true) ?: self::defaultRampSchedule();

        [$sql, $params] = $this->audienceSql($filter, 's.id AS subscriber_id, s.email, s.user_id');
        $rows = $this->db->query($sql, $params);

        if (!$rows) return 0;

        $schedule = self::computeSchedule(
            $camp['start_date'],
            $camp['send_window_start'],
            $camp['send_window_end'],
            $ramp,
            count($rows)
        );

        $inserted = 0;
        $batch = [];
        $batchSize = 500;
        $pdo = $this->pdo;

        $flush = function() use (&$batch, $campaignId, $pdo, &$inserted) {
            if (!$batch) return;
            $sql = "INSERT IGNORE INTO old_base_campaign_recipients
                    (campaign_id, subscriber_id, user_id, email, scheduled_at, status)
                    VALUES " . implode(',', array_fill(0, count($batch), '(?,?,?,?,?,\'pending\')'));
            $values = [];
            foreach ($batch as $b) {
                array_push($values, $campaignId, $b['subscriber_id'], $b['user_id'], $b['email'], $b['scheduled_at']);
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
            $inserted += $stmt->rowCount();
            $batch = [];
        };

        foreach ($rows as $i => $r) {
            $batch[] = [
                'subscriber_id' => (int)$r['subscriber_id'],
                'user_id'       => $r['user_id'] !== null ? (int)$r['user_id'] : null,
                'email'         => $r['email'],
                'scheduled_at'  => $schedule[$i]->format('Y-m-d H:i:s'),
            ];
            if (count($batch) >= $batchSize) $flush();
        }
        $flush();

        $this->db->update('old_base_campaigns',
            ['recipient_count' => count($rows)],
            'id = ?', [$campaignId]);

        return $inserted;
    }

    /**
     * Распределить N получателей по ramp_schedule + send_window.
     * Каждый день получает quota получателей, scheduled_at равномерно
     * распределены внутри окна [windowStart, windowEnd] этого дня.
     * Если ramp заканчивается раньше, чем закончилась база — повторяем
     * последний quota.
     *
     * @return \DateTime[]
     */
    public static function computeSchedule(
        string $startDate,
        string $windowStart,
        string $windowEnd,
        array  $ramp,
        int    $total
    ): array {
        $out = [];
        if ($total <= 0) return $out;

        $sd = new \DateTime($startDate . ' ' . $windowStart);
        $wStartSec = self::timeToSec($windowStart);
        $wEndSec   = self::timeToSec($windowEnd);
        if ($wEndSec <= $wStartSec) {
            // защита от инверсии
            $wEndSec = $wStartSec + 3600;
        }
        $windowLenSec = $wEndSec - $wStartSec;

        $lastQuota = max(1, (int)($ramp[count($ramp) - 1]['quota'] ?? 1));
        $remaining = $total;
        $dayIdx = 0;

        while ($remaining > 0) {
            $quota = $dayIdx < count($ramp)
                ? max(1, (int)$ramp[$dayIdx]['quota'])
                : $lastQuota;
            $thisDay = min($quota, $remaining);

            $dayDate = (clone $sd)->modify("+{$dayIdx} day");
            // равномерное распределение внутри окна
            $step = $thisDay > 1 ? intdiv($windowLenSec, $thisDay - 1) : 0;
            for ($k = 0; $k < $thisDay; $k++) {
                $offset = $thisDay > 1 ? $k * $step : intdiv($windowLenSec, 2);
                $out[] = (clone $dayDate)->modify("+{$offset} second");
            }
            $remaining -= $thisDay;
            $dayIdx++;
        }
        return $out;
    }

    private static function timeToSec(string $hms): int {
        $p = explode(':', $hms);
        return ((int)($p[0] ?? 0)) * 3600 + ((int)($p[1] ?? 0)) * 60 + ((int)($p[2] ?? 0));
    }

    public function launch(int $campaignId): void {
        $camp = $this->find($campaignId);
        if (!$camp) throw new \RuntimeException("Campaign #$campaignId not found");
        if (!in_array($camp['status'], ['draft','scheduled','paused'], true)) {
            throw new \RuntimeException("Cannot launch from status: {$camp['status']}");
        }
        $this->db->update('old_base_campaigns',
            ['status' => 'running', 'started_at' => $camp['started_at'] ?: date('Y-m-d H:i:s')],
            'id = ?', [$campaignId]);
    }

    public function pause(int $campaignId): void {
        $this->db->update('old_base_campaigns', ['status' => 'paused'],
            'id = ? AND status = \'running\'', [$campaignId]);
    }

    public function cancel(int $campaignId): void {
        $this->db->update('old_base_campaigns',
            ['status' => 'cancelled', 'completed_at' => date('Y-m-d H:i:s')],
            'id = ?', [$campaignId]);
        $this->db->execute(
            "UPDATE old_base_campaign_recipients SET status='skipped'
             WHERE campaign_id=? AND status='pending'",
            [$campaignId]
        );
    }

    public function completeIfDone(int $campaignId): bool {
        $row = $this->db->queryOne(
            "SELECT
                SUM(status='pending') AS pending,
                COUNT(*) AS total
             FROM old_base_campaign_recipients WHERE campaign_id = ?",
            [$campaignId]
        );
        if ($row && (int)$row['total'] > 0 && (int)$row['pending'] === 0) {
            $this->db->update('old_base_campaigns',
                ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')],
                "id = ? AND status = 'running'", [$campaignId]);
            return true;
        }
        return false;
    }

    /**
     * Сводная статистика. См. план: sent/delivered/opened/clicked/applications/payments.
     */
    public function getStats(int $campaignId): array {
        $row = $this->db->queryOne(
            "SELECT
                COUNT(*) AS total_planned,
                SUM(r.status IN ('sent','bounced')) AS total_sent,
                SUM(r.delivered_at IS NOT NULL) AS delivered,
                SUM(r.status = 'pending') AS pending,
                SUM(r.status = 'failed')  AS failed,
                SUM(r.status = 'skipped') AS skipped,
                SUM(r.status = 'bounced') AS bounced,
                SUM(ev.opens_count > 0)  AS unique_opens,
                SUM(ev.clicks_count > 0) AS unique_clicks
             FROM old_base_campaign_recipients r
             LEFT JOIN email_events ev ON ev.message_id = r.message_id
             WHERE r.campaign_id = ?",
            [$campaignId]
        );

        $orderRow = $this->db->queryOne(
            "SELECT
                COUNT(DISTINCT o.id) AS applications,
                COUNT(DISTINCT CASE WHEN o.payment_status='succeeded' THEN o.id END) AS payments,
                COALESCE(SUM(CASE WHEN o.payment_status='succeeded' THEN o.final_amount ELSE 0 END), 0) AS revenue
             FROM old_base_campaign_recipients r
             JOIN orders o ON o.email_message_id = r.message_id
             WHERE r.campaign_id = ?",
            [$campaignId]
        );

        return [
            'total_planned'    => (int)($row['total_planned'] ?? 0),
            'total_sent'       => (int)($row['total_sent'] ?? 0),
            'delivered'        => (int)($row['delivered'] ?? 0),
            'pending'          => (int)($row['pending'] ?? 0),
            'failed'           => (int)($row['failed'] ?? 0),
            'skipped'          => (int)($row['skipped'] ?? 0),
            'bounced'          => (int)($row['bounced'] ?? 0),
            'unique_opens'     => (int)($row['unique_opens'] ?? 0),
            'unique_clicks'    => (int)($row['unique_clicks'] ?? 0),
            'applications'     => (int)($orderRow['applications'] ?? 0),
            'payments'         => (int)($orderRow['payments'] ?? 0),
            'revenue'          => (float)($orderRow['revenue'] ?? 0),
        ];
    }

    /**
     * Дневная разбивка для графика: запланировано / отправлено / открыто.
     */
    public function dailyBreakdown(int $campaignId): array {
        return $this->db->query(
            "SELECT
                DATE(r.scheduled_at) AS day,
                COUNT(*) AS planned,
                SUM(r.status IN ('sent','bounced')) AS sent,
                SUM(r.delivered_at IS NOT NULL) AS delivered,
                SUM(CASE WHEN ev.opens_count > 0 THEN 1 ELSE 0 END) AS opened,
                SUM(CASE WHEN ev.clicks_count > 0 THEN 1 ELSE 0 END) AS clicked
             FROM old_base_campaign_recipients r
             LEFT JOIN email_events ev ON ev.message_id = r.message_id
             WHERE r.campaign_id = ?
             GROUP BY DATE(r.scheduled_at)
             ORDER BY day",
            [$campaignId]
        );
    }

    /**
     * Список получателей кампании с пагинацией и фильтром по статусу.
     */
    public function recipients(int $campaignId, array $filters, int $page = 1, int $perPage = 50): array {
        $where = ['r.campaign_id = ?'];
        $params = [$campaignId];

        if (!empty($filters['status']) && in_array($filters['status'], ['pending','sent','failed','skipped','bounced'], true)) {
            $where[] = 'r.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['q'])) {
            $where[] = 'r.email LIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $total = (int)$this->db->queryOne(
            "SELECT COUNT(*) AS c FROM old_base_campaign_recipients r $whereSql",
            $params
        )['c'];

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->query(
            "SELECT r.*, ev.opens_count, ev.clicks_count, ev.opened_at, ev.first_clicked_at, ev.order_id, ev.revenue
             FROM old_base_campaign_recipients r
             LEFT JOIN email_events ev ON ev.message_id = r.message_id
             $whereSql
             ORDER BY r.scheduled_at ASC, r.id ASC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        return [
            'rows' => $rows, 'total' => $total,
            'page' => $page, 'per_page' => $perPage,
            'total_pages' => max(1, (int)ceil($total / $perPage)),
        ];
    }

    /**
     * Клонировать кампанию в draft с новым audience_filter:
     *   - winners: получатели source, у которых был open OR click
     *   - rest_of_base: вся active-база МИНУС получатели source
     */
    public function cloneToSegment(int $sourceId, string $segmentType, array $overrides): int {
        $src = $this->find($sourceId);
        if (!$src) throw new \RuntimeException("Source campaign #$sourceId not found");

        if ($segmentType === 'winners') {
            $filter = [
                'type' => 'opened_in',
                'campaign_ids' => [$sourceId],
            ];
        } elseif ($segmentType === 'rest_of_base') {
            $filter = [
                'type' => 'exclude_recipients_of',
                'campaign_ids' => [$sourceId],
                'base' => 'all',
            ];
        } else {
            throw new \InvalidArgumentException("Unknown segment: $segmentType");
        }

        $data = [
            'code'              => self::slugify(($overrides['code'] ?? ($src['code'] . '-' . $segmentType . '-' . date('mdHi')))),
            'name'              => $overrides['name'] ?? ($src['name'] . ' (' . $segmentType . ')'),
            'subject'           => $overrides['subject'] ?? $src['subject'],
            'from_name'         => $overrides['from_name'] ?? $src['from_name'],
            'from_email'        => $overrides['from_email'] ?? $src['from_email'],
            'html_body'         => $overrides['html_body'] ?? $src['html_body'],
            'plain_body'        => $overrides['plain_body'] ?? $src['plain_body'],
            'cta_url'           => $overrides['cta_url'] ?? $src['cta_url'],
            'auto_utm'          => $overrides['auto_utm'] ?? (bool)$src['auto_utm'],
            'audience_filter'   => $filter,
            'start_date'        => $overrides['start_date'] ?? date('Y-m-d'),
            'send_window_start' => $overrides['send_window_start'] ?? $src['send_window_start'],
            'send_window_end'   => $overrides['send_window_end'] ?? $src['send_window_end'],
            'timezone'          => $overrides['timezone'] ?? $src['timezone'],
            'ramp_schedule'     => $overrides['ramp_schedule'] ?? json_decode($src['ramp_schedule'], true),
            'created_by'        => $overrides['created_by'] ?? null,
        ];
        return $this->create($data);
    }

    /**
     * Вебхук Unisender: отметить доставку. Ставит delivered_at на самой свежей
     * отправленной строке этого email, у которой ещё нет delivered_at и нет
     * терминального bounce. Возвращает true, если строка найдена и обновлена.
     */
    public function markRecipientDelivered(string $email): bool {
        $email = mb_strtolower(trim($email));
        $row = $this->db->queryOne(
            "SELECT id FROM old_base_campaign_recipients
             WHERE email = ? AND status = 'sent' AND delivered_at IS NULL
             ORDER BY sent_at DESC, id DESC LIMIT 1",
            [$email]
        );
        if (!$row) return false;
        $this->db->update('old_base_campaign_recipients',
            ['delivered_at' => date('Y-m-d H:i:s')],
            'id = ?', [(int)$row['id']]);
        return true;
    }

    /**
     * Вебхук Unisender: отметить bounce. Переводит самую свежую отправленную
     * строку этого email в status='bounced'.
     */
    public function markRecipientBounced(string $email, string $reason = ''): bool {
        $email = mb_strtolower(trim($email));
        $row = $this->db->queryOne(
            "SELECT id FROM old_base_campaign_recipients
             WHERE email = ? AND status = 'sent'
             ORDER BY sent_at DESC, id DESC LIMIT 1",
            [$email]
        );
        if (!$row) return false;
        $this->db->update('old_base_campaign_recipients',
            ['status' => 'bounced', 'error_message' => mb_substr($reason, 0, 500)],
            'id = ?', [(int)$row['id']]);
        return true;
    }

    /**
     * Slugify — латиница/цифры + транслит из кириллицы.
     */
    public static function slugify(string $s): string {
        $s = trim($s);
        $tr = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'i',
            'к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f',
            'х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        ];
        $s = mb_strtolower($s);
        $s = strtr($s, $tr);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');
        return substr($s, 0, 64);
    }

    /**
     * Помогалка для приклеивания UTM к произвольной CTA-ссылке.
     */
    public static function appendUtm(string $url, string $campaignCode): string {
        if ($url === '') return $url;
        $utm = http_build_query([
            'utm_source'   => 'email',
            'utm_medium'   => 'old_base',
            'utm_campaign' => $campaignCode,
        ]);
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $sep . $utm;
    }
}
