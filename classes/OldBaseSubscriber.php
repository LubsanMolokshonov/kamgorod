<?php
/**
 * OldBaseSubscriber — управление импортированной «холодной» базой.
 *
 * Источник — CSV-выгрузка старых клиентов Педпортала (~31k email + ФИО).
 * Хранится в таблице old_base_subscribers, используется как пул получателей
 * для рассылок old_base_campaigns. Статус 'unsubscribed' синхронизирован
 * с email_unsubscribes — если пользователь отписался любой ссылкой из любого
 * нашего письма, он не получит писем по старой базе.
 */

require_once __DIR__ . '/Database.php';

class OldBaseSubscriber {
    private Database $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Импорт из CSV-файла. Формат: первая строка — заголовок (Email,ФИО);
     * далее строки данных. Идемпотентен (ON DUPLICATE KEY UPDATE).
     *
     * @return array{total:int,valid:int,invalid:int,inserted:int,updated:int,linked_to_users:int,already_unsubscribed:int}
     */
    public function importFromCsv(string $path, string $source = 'csv_2026_05'): array {
        if (!is_readable($path)) {
            throw new \RuntimeException("CSV file not readable: {$path}");
        }

        $stats = [
            'total' => 0, 'valid' => 0, 'invalid' => 0,
            'inserted' => 0, 'updated' => 0,
            'linked_to_users' => 0, 'already_unsubscribed' => 0,
        ];

        $fh = fopen($path, 'r');
        fgetcsv($fh); // skip header

        $batchEmails = [];
        $batch = [];
        $batchSize = 500;

        $flush = function() use (&$batch, &$batchEmails, $source, &$stats) {
            if (empty($batch)) return;

            // bulk find users with matching emails
            $placeholders = implode(',', array_fill(0, count($batchEmails), '?'));
            $userRows = $this->db->query(
                "SELECT id, email FROM users WHERE email IN ($placeholders)",
                $batchEmails
            );
            $userMap = [];
            foreach ($userRows as $u) {
                $userMap[mb_strtolower($u['email'])] = (int)$u['id'];
            }

            $unsubRows = $this->db->query(
                "SELECT DISTINCT email FROM email_unsubscribes WHERE email IN ($placeholders)",
                $batchEmails
            );
            $unsubSet = [];
            foreach ($unsubRows as $r) {
                $unsubSet[mb_strtolower($r['email'])] = true;
            }

            $pdo = $this->db->getPDO();
            $sql = "INSERT INTO old_base_subscribers
                    (email, full_name, status, user_id, source, imported_at)
                    VALUES " . implode(',', array_fill(0, count($batch), '(?,?,?,?,?,NOW())')) . "
                    ON DUPLICATE KEY UPDATE
                        full_name = COALESCE(VALUES(full_name), full_name),
                        user_id   = COALESCE(VALUES(user_id), user_id),
                        status    = IF(status='active', VALUES(status), status)";
            $values = [];
            foreach ($batch as $row) {
                $email = $row['email'];
                $userId = $userMap[$email] ?? null;
                $isUnsub = isset($unsubSet[$email]);
                $status = $isUnsub ? 'unsubscribed' : 'active';

                if ($userId) $stats['linked_to_users']++;
                if ($isUnsub) $stats['already_unsubscribed']++;

                array_push($values, $email, $row['full_name'], $status, $userId, $source);
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
            // rowCount после INSERT...ON DUPLICATE KEY UPDATE: 1=insert, 2=update.
            // Точно различить inserted/updated в bulk-режиме нельзя без отдельного SELECT;
            // оцениваем грубо: rowCount = 1*inserts + 2*updates.
            $affected = $stmt->rowCount();
            $estUpdated = max(0, $affected - count($batch));
            $estInserted = count($batch) - $estUpdated;
            $stats['inserted'] += $estInserted;
            $stats['updated']  += $estUpdated;

            $batch = [];
            $batchEmails = [];
        };

        $seen = [];
        while (($row = fgetcsv($fh)) !== false) {
            $stats['total']++;
            $rawEmail = $row[0] ?? '';
            $fullName = isset($row[1]) ? trim($row[1]) : null;

            $email = self::normalizeEmail($rawEmail);
            if ($email === null) {
                $stats['invalid']++;
                continue;
            }
            if (isset($seen[$email])) {
                continue; // dedupe within file
            }
            $seen[$email] = true;
            $stats['valid']++;

            $batch[] = ['email' => $email, 'full_name' => $fullName !== '' ? $fullName : null];
            $batchEmails[] = $email;

            if (count($batch) >= $batchSize) {
                $flush();
            }
        }
        $flush();
        fclose($fh);

        return $stats;
    }

    /**
     * Нормализация email: обрезать пробелы, привести к нижнему регистру,
     * срезать мусорные префиксы (`_`, `!`, `-`, `.`), валидировать.
     * Возвращает строку или null.
     */
    public static function normalizeEmail(string $raw): ?string {
        $email = mb_strtolower(trim($raw));
        if ($email === '') return null;
        // срезаем мусорные ведущие символы — пользователи иногда вводили "!!!foo@bar"
        $email = preg_replace('/^[^a-z0-9]+/', '', $email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        if (strlen($email) > 255) return null;
        return $email;
    }

    /**
     * Список подписчиков с пагинацией и фильтрами.
     * @param array{q?:string,status?:string} $filters
     */
    public function paginate(array $filters, int $page = 1, int $perPage = 50): array {
        $where = [];
        $params = [];

        if (!empty($filters['q'])) {
            $where[] = '(email LIKE ? OR full_name LIKE ?)';
            $params[] = '%' . $filters['q'] . '%';
            $params[] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['active','unsubscribed','bounced','complained','suppressed'], true)) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $total = (int)$this->db->queryOne(
            "SELECT COUNT(*) AS c FROM old_base_subscribers $whereSql",
            $params
        )['c'];

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->query(
            "SELECT * FROM old_base_subscribers
             $whereSql
             ORDER BY id ASC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int)ceil($total / $perPage)),
        ];
    }

    public function statusCounts(): array {
        $rows = $this->db->query(
            "SELECT status, COUNT(*) AS c FROM old_base_subscribers GROUP BY status"
        );
        $out = ['active'=>0,'unsubscribed'=>0,'bounced'=>0,'complained'=>0,'suppressed'=>0];
        foreach ($rows as $r) {
            $out[$r['status']] = (int)$r['c'];
        }
        $out['total'] = array_sum($out);
        return $out;
    }

    public function markBounced(int $subscriberId, string $reason): void {
        $this->db->execute(
            "UPDATE old_base_subscribers
             SET status='bounced', bounce_count=bounce_count+1,
                 last_bounce_at=NOW(), last_bounce_reason=?
             WHERE id=?",
            [mb_substr($reason, 0, 255), $subscriberId]
        );
    }

    public function markUnsubscribedByEmail(string $email): void {
        $this->db->execute(
            "UPDATE old_base_subscribers SET status='unsubscribed' WHERE email=? AND status='active'",
            [mb_strtolower(trim($email))]
        );
    }

    /**
     * Bounce по email. hard=true (адрес не существует) → сразу status='bounced'.
     * hard=false (soft bounce) → только инкремент счётчика; 'bounced' выставляется
     * лишь когда soft-bounce'ов накопилось ≥ 3.
     */
    public function markBouncedByEmail(string $email, string $reason, bool $hard): void {
        $email = mb_strtolower(trim($email));
        if ($hard) {
            $this->db->execute(
                "UPDATE old_base_subscribers
                 SET status = IF(status='active','bounced',status),
                     bounce_count = bounce_count + 1,
                     last_bounce_at = NOW(), last_bounce_reason = ?
                 WHERE email = ?",
                [mb_substr($reason, 0, 255), $email]
            );
        } else {
            $this->db->execute(
                "UPDATE old_base_subscribers
                 SET status = IF(status='active' AND bounce_count + 1 >= 3, 'bounced', status),
                     bounce_count = bounce_count + 1,
                     last_bounce_at = NOW(), last_bounce_reason = ?
                 WHERE email = ?",
                [mb_substr($reason, 0, 255), $email]
            );
        }
    }

    public function markComplainedByEmail(string $email): void {
        $this->db->execute(
            "UPDATE old_base_subscribers SET status='complained'
             WHERE email=? AND status IN ('active','bounced')",
            [mb_strtolower(trim($email))]
        );
    }

    public function incrementStats(int $subscriberId, array $deltas): void {
        $sets = [];
        $params = [];
        foreach (['total_sent','total_opened','total_clicked','total_converted'] as $col) {
            if (!empty($deltas[$col])) {
                $sets[] = "$col = $col + ?";
                $params[] = (int)$deltas[$col];
            }
        }
        if (!empty($deltas['last_sent_at'])) {
            $sets[] = "last_sent_at = NOW()";
        }
        if (!$sets) return;
        $params[] = $subscriberId;
        $this->db->execute(
            "UPDATE old_base_subscribers SET " . implode(',', $sets) . " WHERE id=?",
            $params
        );
    }
}
