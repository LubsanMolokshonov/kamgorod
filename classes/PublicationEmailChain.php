<?php
/**
 * PublicationEmailChain Class
 * Триггерные email-цепочки для публикаций в журнале.
 *
 * Цепочки:
 * 1. cert_reminder     — публикация одобрена, сертификат не оформлен (2ч, 24ч, 3д, 7д от published_at)
 * 2. payment_reminder  — сертификат оформлен, не оплачен (1ч, 24ч, 3д от updated_at)
 * 3. rejected_retry    — отклонено модерацией, нет других одобренных (24ч от moderated_at)
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class PublicationEmailChain {
    private $db;
    private $pdo;
    private const MAX_ATTEMPTS = 3;
    private const BATCH_SIZE = 50;
    private const MAX_AGE_DAYS = 30;

    // Дата запуска системы — обрабатывать только публикации начиная с этой даты
    private const LAUNCH_DATE = '2026-02-25';

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
    }

    // ─── PUBLIC API ────────────────────────────────────────────

    /**
     * Запланировать первое письмо при auto_approved (вызывается из save-publication.php)
     */
    public function scheduleInitialEmail($publicationId) {
        $pub = $this->getPublicationData($publicationId);
        if (!$pub) return false;

        if ($this->isUnsubscribed($pub['email'])) {
            $this->log("SKIP | {$pub['email']} | unsubscribed");
            return false;
        }

        // Запланировать первое письмо cert_reminder
        $touchpoint = $this->db->queryOne(
            "SELECT * FROM publication_email_touchpoints WHERE code = 'pub_cert_2h' AND is_active = 1"
        );
        if (!$touchpoint) return false;

        $this->scheduleEmail($publicationId, $pub['user_id'], $pub['email'], $touchpoint, $pub['published_at'] ?? $pub['created_at']);
        $this->log("SCHEDULE | Publication {$publicationId} | pub_cert_2h");
        return true;
    }

    /**
     * Запланировать письмо для отклонённой публикации (вызывается из save-publication.php)
     */
    public function scheduleRejectedEmail($publicationId) {
        $pub = $this->getPublicationData($publicationId);
        if (!$pub) return false;

        if ($this->isUnsubscribed($pub['email'])) {
            $this->log("SKIP | {$pub['email']} | unsubscribed");
            return false;
        }

        $touchpoint = $this->db->queryOne(
            "SELECT * FROM publication_email_touchpoints WHERE code = 'pub_rejected_24h' AND is_active = 1"
        );
        if (!$touchpoint) return false;

        $anchorTime = $pub['moderated_at'] ?? $pub['updated_at'] ?? $pub['created_at'];
        $this->scheduleEmail($publicationId, $pub['user_id'], $pub['email'], $touchpoint, $anchorTime);
        $this->log("SCHEDULE | Publication {$publicationId} | pub_rejected_24h");
        return true;
    }

    /**
     * Главный метод для cron: проверить состояния, запланировать и отправить письма
     */
    public function processPendingEmails() {
        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        // Шаг 1: Найти публикации с состоянием в одном запросе (batch)
        $publications = $this->db->query(
            "SELECT p.id as publication_id, p.user_id, p.status as pub_status,
                    p.published_at, p.moderated_at, p.moderation_comment,
                    p.created_at, p.updated_at,
                    u.email,
                    pc.template_id, pc.status as cert_status,
                    pc.created_at as cert_created_at
             FROM publications p
             JOIN users u ON p.user_id = u.id
             LEFT JOIN publication_certificates pc ON pc.publication_id = p.id
             LEFT JOIN email_unsubscribes eu ON eu.email = u.email
             WHERE p.status IN ('published', 'rejected')
               AND p.created_at >= ?
               AND p.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND eu.id IS NULL",
            [self::LAUNCH_DATE, self::MAX_AGE_DAYS]
        );

        // Загрузить touchpoints один раз
        $allTouchpoints = [];
        $tpRows = $this->db->query(
            "SELECT * FROM publication_email_touchpoints WHERE is_active = 1 ORDER BY delay_hours ASC"
        );
        foreach ($tpRows as $tp) {
            $allTouchpoints[$tp['chain_type']][] = $tp;
        }

        // Загрузить уже существующие log-записи одним запросом
        $existingLogs = [];
        $logRows = $this->db->query(
            "SELECT publication_id, touchpoint_id FROM publication_email_log"
        );
        foreach ($logRows as $lr) {
            $existingLogs[$lr['publication_id'] . '_' . $lr['touchpoint_id']] = true;
        }

        // Для rejected_retry: загрузить user_id'ы, у которых есть другая published публикация за 24ч
        $usersWithPublished = [];
        $pubUsers = $this->db->query(
            "SELECT DISTINCT user_id FROM publications
             WHERE status = 'published'
               AND published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        foreach ($pubUsers as $pu) {
            $usersWithPublished[$pu['user_id']] = true;
        }

        // Шаг 2: Для каждой публикации — определить состояние и собрать batch INSERT
        $toInsert = [];
        $completedPubIds = [];
        $chainSkips = []; // [publication_id => activeChain]

        foreach ($publications as $pub) {
            $state = $this->getStateFromRow($pub);
            $activeChain = $this->getActiveChainType($state);

            // Для rejected — проверить, есть ли другая published за 24ч
            if ($state === 'rejected' && isset($usersWithPublished[$pub['user_id']])) {
                $completedPubIds[] = $pub['publication_id'];
                continue;
            }

            if ($activeChain === null) {
                $completedPubIds[] = $pub['publication_id'];
                continue;
            }

            $chainSkips[$pub['publication_id']] = $activeChain;

            $anchorTime = $this->getAnchorFromRow($pub, $activeChain);
            if ($anchorTime && !empty($allTouchpoints[$activeChain])) {
                foreach ($allTouchpoints[$activeChain] as $tp) {
                    $key = $pub['publication_id'] . '_' . $tp['id'];
                    if (isset($existingLogs[$key])) continue;

                    $scheduledAt = date('Y-m-d H:i:s',
                        strtotime($anchorTime) + ($tp['delay_hours'] * 3600)
                    );
                    $toInsert[] = [
                        $pub['publication_id'], $pub['user_id'],
                        $tp['id'], $pub['email'], $scheduledAt
                    ];
                }
            }
        }

        // Batch skip для завершённых
        if (!empty($completedPubIds)) {
            $placeholders = implode(',', array_fill(0, count($completedPubIds), '?'));
            $this->db->execute(
                "UPDATE publication_email_log SET status = 'skipped', updated_at = NOW()
                 WHERE publication_id IN ({$placeholders}) AND status = 'pending'",
                $completedPubIds
            );
        }

        // Batch skip для устаревших цепочек (группируем по activeChain)
        $chainOrder = ['cert_reminder', 'payment_reminder'];
        $skipGroups = []; // activeChain => [pubIds]
        foreach ($chainSkips as $pubId => $activeChain) {
            if (in_array($activeChain, $chainOrder)) {
                $skipGroups[$activeChain][] = $pubId;
            }
        }
        foreach ($skipGroups as $activeChain => $pubIds) {
            $activeIndex = array_search($activeChain, $chainOrder);
            if ($activeIndex > 0) {
                $obsolete = array_slice($chainOrder, 0, $activeIndex);
                $obsPh = implode(',', array_fill(0, count($obsolete), '?'));
                foreach (array_chunk($pubIds, 500) as $chunk) {
                    $pubPh = implode(',', array_fill(0, count($chunk), '?'));
                    $this->db->execute(
                        "UPDATE publication_email_log pel
                         JOIN publication_email_touchpoints t ON pel.touchpoint_id = t.id
                         SET pel.status = 'skipped', pel.updated_at = NOW()
                         WHERE pel.publication_id IN ({$pubPh})
                           AND pel.status = 'pending'
                           AND t.chain_type IN ({$obsPh})",
                        array_merge($chunk, $obsolete)
                    );
                }
            }
        }

        // Batch INSERT IGNORE (chunks of 100)
        if (!empty($toInsert)) {
            $chunks = array_chunk($toInsert, 100);
            foreach ($chunks as $chunk) {
                $placeholders = [];
                $params = [];
                foreach ($chunk as $row) {
                    $placeholders[] = '(?, ?, ?, ?, \'pending\', ?)';
                    $params = array_merge($params, $row);
                }
                $sql = "INSERT IGNORE INTO publication_email_log
                        (publication_id, user_id, touchpoint_id, email, status, scheduled_at)
                        VALUES " . implode(', ', $placeholders);
                $this->pdo->prepare($sql)->execute($params);
            }
        }

        // Шаг 3: Обработать очередь отправки
        $pendingEmails = $this->db->query(
            "SELECT pel.*, t.email_subject, t.email_template, t.code as touchpoint_code, t.chain_type,
                    p.title as publication_title, p.slug as publication_slug,
                    p.status as pub_status, p.published_at, p.moderation_comment,
                    p.moderated_at,
                    u.full_name, u.email as user_email,
                    pc.template_id, pc.status as cert_status, pc.price as cert_price
             FROM publication_email_log pel
             JOIN publication_email_touchpoints t ON pel.touchpoint_id = t.id
             JOIN publications p ON pel.publication_id = p.id
             JOIN users u ON pel.user_id = u.id
             LEFT JOIN publication_certificates pc ON pc.publication_id = p.id
             WHERE pel.status = 'pending'
               AND pel.scheduled_at <= NOW()
               AND pel.attempts < ?
             ORDER BY pel.scheduled_at ASC
             LIMIT ?",
            [self::MAX_ATTEMPTS, self::BATCH_SIZE]
        );

        foreach ($pendingEmails as $emailData) {
            // Повторно проверить состояние (пользователь мог прогрессировать)
            $state = $this->getPublicationState($emailData['publication_id']);
            $activeChain = $this->getActiveChainType($state);

            // Для rejected — повторная проверка published за 24ч
            if ($state === 'rejected') {
                $hasPublished = $this->db->queryOne(
                    "SELECT id FROM publications
                     WHERE user_id = ? AND status = 'published'
                       AND published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                     LIMIT 1",
                    [$emailData['user_id']]
                );
                if ($hasPublished) {
                    $this->updateEmailStatus($emailData['id'], 'skipped', 'User has published publication');
                    $results['skipped']++;
                    continue;
                }
            }

            if ($activeChain !== $emailData['chain_type']) {
                $this->updateEmailStatus($emailData['id'], 'skipped', 'User progressed past this chain');
                $results['skipped']++;
                continue;
            }

            if ($this->isUnsubscribed($emailData['email'])) {
                $this->updateEmailStatus($emailData['id'], 'skipped', 'User unsubscribed');
                $results['skipped']++;
                continue;
            }

            $success = $this->sendEmail($emailData);

            if ($success) {
                $this->updateEmailStatus($emailData['id'], 'sent');
                $results['sent']++;
            } else {
                $this->incrementAttempts($emailData['id']);
                if ($emailData['attempts'] + 1 >= self::MAX_ATTEMPTS) {
                    $this->updateEmailStatus($emailData['id'], 'failed', 'Max attempts reached');
                }
                $results['failed']++;
            }
        }

        $this->log("PROCESS | Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}");
        return $results;
    }

    /**
     * Отменить все pending-письма для публикации (при оплате)
     */
    public function cancelForPublication($publicationId) {
        $result = $this->db->execute(
            "UPDATE publication_email_log
             SET status = 'skipped', updated_at = NOW()
             WHERE publication_id = ? AND status = 'pending'",
            [$publicationId]
        );

        $this->log("CANCEL | Publication {$publicationId} | Cancelled {$result} pending emails");
        return $result;
    }

    /**
     * Статистика для админки
     */
    public function getStats($days = 30) {
        $since = date('Y-m-d', strtotime("-{$days} days"));

        return [
            'total_sent' => $this->db->queryOne(
                "SELECT COUNT(*) as count FROM publication_email_log
                 WHERE status = 'sent' AND sent_at >= ?", [$since]
            )['count'],

            'total_pending' => $this->db->queryOne(
                "SELECT COUNT(*) as count FROM publication_email_log WHERE status = 'pending'"
            )['count'],

            'total_failed' => $this->db->queryOne(
                "SELECT COUNT(*) as count FROM publication_email_log
                 WHERE status = 'failed' AND created_at >= ?", [$since]
            )['count'],

            'by_touchpoint' => $this->db->query(
                "SELECT t.code, t.name, t.chain_type,
                        COUNT(CASE WHEN pel.status = 'sent' THEN 1 END) as sent,
                        COUNT(CASE WHEN pel.status = 'pending' THEN 1 END) as pending,
                        COUNT(CASE WHEN pel.status = 'failed' THEN 1 END) as failed,
                        COUNT(CASE WHEN pel.status = 'skipped' THEN 1 END) as skipped
                 FROM publication_email_touchpoints t
                 LEFT JOIN publication_email_log pel ON t.id = pel.touchpoint_id
                 GROUP BY t.id
                 ORDER BY t.display_order"
            )
        ];
    }

    // ─── STATE DETECTION ───────────────────────────────────────

    /**
     * Определить состояние из предзагруженной строки (batch-оптимизация)
     */
    private function getStateFromRow($row) {
        // Сертификат оплачен
        if (!empty($row['cert_status']) && in_array($row['cert_status'], ['paid', 'ready'])) {
            return 'cert_paid';
        }
        // Сертификат оформлен (template_id выбран), но не оплачен
        if (!empty($row['template_id']) && ($row['cert_status'] ?? '') === 'pending') {
            return 'cert_configured';
        }
        // Публикация одобрена, сертификат не оформлен
        if ($row['pub_status'] === 'published') {
            return 'published_no_cert';
        }
        // Публикация отклонена
        if ($row['pub_status'] === 'rejected') {
            return 'rejected';
        }
        return 'unknown';
    }

    /**
     * Получить якорное время из предзагруженной строки
     */
    private function getAnchorFromRow($row, $chainType) {
        return match ($chainType) {
            'cert_reminder'    => $row['published_at'] ?? $row['created_at'],
            'payment_reminder' => $row['cert_created_at'] ?? null,
            'rejected_retry'   => $row['moderated_at'] ?? $row['updated_at'] ?? $row['created_at'],
            default            => null,
        };
    }

    /**
     * Определить текущее состояние публикации (по ID, для re-check при отправке)
     */
    private function getPublicationState($publicationId) {
        $row = $this->db->queryOne(
            "SELECT p.status as pub_status,
                    pc.template_id, pc.status as cert_status
             FROM publications p
             LEFT JOIN publication_certificates pc ON pc.publication_id = p.id
             WHERE p.id = ?",
            [$publicationId]
        );

        if (!$row) return 'unknown';
        return $this->getStateFromRow($row);
    }

    /**
     * Определить активную цепочку по состоянию
     */
    private function getActiveChainType($state) {
        return match ($state) {
            'published_no_cert' => 'cert_reminder',
            'cert_configured'   => 'payment_reminder',
            'rejected'          => 'rejected_retry',
            'cert_paid'         => null,
            default             => null,
        };
    }

    // ─── CHAIN MANAGEMENT ──────────────────────────────────────

    /**
     * Запланировать одно письмо (INSERT IGNORE для идемпотентности)
     */
    private function scheduleEmail($publicationId, $userId, $email, $touchpoint, $anchorTime) {
        $scheduledAt = date('Y-m-d H:i:s',
            strtotime($anchorTime) + ($touchpoint['delay_hours'] * 3600)
        );

        try {
            $this->pdo->prepare(
                "INSERT IGNORE INTO publication_email_log
                 (publication_id, user_id, touchpoint_id, email, status, scheduled_at)
                 VALUES (?, ?, ?, ?, 'pending', ?)"
            )->execute([$publicationId, $userId, $touchpoint['id'], $email, $scheduledAt]);
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') === false) {
                throw $e;
            }
        }
    }

    // ─── EMAIL SENDING ─────────────────────────────────────────

    /**
     * Отправить одно письмо
     */
    private function sendEmail($emailData) {
        require_once BASE_PATH . '/vendor/autoload.php';

        try {
            $mail = new PHPMailer(true);

            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 10;
            $mail->SMTPKeepAlive = false;

            if (!empty(SMTP_USERNAME) && !empty(SMTP_PASSWORD)) {
                $mail->SMTPAuth = true;
                $mail->Username = SMTP_USERNAME;
                $mail->Password = SMTP_PASSWORD;

                if (SMTP_PORT == 465) {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } elseif (SMTP_PORT == 587) {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                }
            } else {
                $mail->SMTPAuth = false;
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($emailData['email'], $emailData['full_name']);

            // Unsubscribe
            $unsubscribeToken = $this->generateUnsubscribeToken($emailData['email']);
            $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . $unsubscribeToken;

            // Шаблонные данные
            $certificateUrl = generateMagicUrl(
                $emailData['user_id'],
                '/pages/publication-certificate.php?id=' . $emailData['publication_id']
            );

            $cabinetUrl = generateMagicUrl(
                $emailData['user_id'],
                '/pages/cabinet.php?tab=publications'
            );

            $submitUrl = generateMagicUrl(
                $emailData['user_id'],
                '/opublikovat'
            );

            $templateData = [
                'user_name'           => $emailData['full_name'],
                'user_email'          => $emailData['email'],
                'user_id'             => $emailData['user_id'],
                'publication_title'   => $emailData['publication_title'],
                'publication_slug'    => $emailData['publication_slug'] ?? '',
                'certificate_price'   => $emailData['cert_price'] ?? 299,
                'certificate_url'     => $certificateUrl,
                'cabinet_url'         => $cabinetUrl,
                'submit_url'          => $submitUrl,
                'publication_url'     => SITE_URL . '/zhurnal/' . ($emailData['publication_slug'] ?? ''),
                'moderation_comment'  => $emailData['moderation_comment'] ?? '',
                'unsubscribe_url'     => $unsubscribeUrl,
                'site_url'            => SITE_URL,
                'site_name'           => SITE_NAME ?? 'Каменный город',
                'touchpoint_code'     => $emailData['touchpoint_code'],
            ];

            $htmlBody = $this->renderTemplate($emailData['email_template'], $templateData);
            $textBody = $this->renderTextTemplate($emailData['touchpoint_code'], $templateData);

            $mail->isHTML(true);
            $mail->Subject = $this->interpolateSubject($emailData['email_subject'], $templateData);
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;

            $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
            $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            $mail->send();

            $this->log("SENT | {$emailData['email']} | {$emailData['touchpoint_code']} | Publication {$emailData['publication_id']}");
            return true;

        } catch (Exception $e) {
            $this->log("ERROR | {$emailData['email']} | {$emailData['touchpoint_code']} | " . $e->getMessage());
            $this->updateEmailStatus($emailData['id'], 'pending', $e->getMessage());
            return false;
        }
    }

    /**
     * Рендер HTML-шаблона
     */
    private function renderTemplate($templateName, $data) {
        $templatePath = BASE_PATH . '/includes/email-templates/' . $templateName . '.php';

        if (!file_exists($templatePath)) {
            throw new \Exception("Template not found: {$templateName}");
        }

        extract($data);

        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Plain-text версия письма
     */
    private function renderTextTemplate($touchpointCode, $data) {
        $text = "Здравствуйте, {$data['user_name']}!\n\n";

        $text .= match(true) {
            str_starts_with($touchpointCode, 'pub_cert') =>
                "Ваша публикация «{$data['publication_title']}» размещена в журнале.\n" .
                "Оформите свидетельство о публикации за " . number_format($data['certificate_price'], 0, ',', ' ') . " руб.\n\n" .
                "Оформить свидетельство: {$data['certificate_url']}\n\n" .
                "Акция «2+1»: при оплате двух участий третье бесплатно!\n\n",

            str_starts_with($touchpointCode, 'pub_pay') =>
                "Ваше свидетельство о публикации «{$data['publication_title']}» ожидает оплаты.\n" .
                "Стоимость: " . number_format($data['certificate_price'], 0, ',', ' ') . " руб.\n\n" .
                "Завершить оплату: {$data['cabinet_url']}\n\n" .
                "Акция «2+1»: при оплате двух участий третье бесплатно!\n\n",

            str_starts_with($touchpointCode, 'pub_rejected') =>
                "К сожалению, ваша публикация «{$data['publication_title']}» не прошла модерацию.\n" .
                (!empty($data['moderation_comment']) ? "Причина: {$data['moderation_comment']}\n\n" : "\n") .
                "Попробуйте отправить новый материал по педагогической тематике.\n\n" .
                "Отправить публикацию: {$data['submit_url']}\n\n",

            default => "Перейти: {$data['cabinet_url']}\n\n",
        };

        $text .= "---\n";
        $text .= "С уважением,\nКоманда проекта «Каменный город»\n\n";
        $text .= "Отписаться от рассылки: {$data['unsubscribe_url']}\n";

        return $text;
    }

    /**
     * Подстановка переменных в тему письма
     */
    private function interpolateSubject($subject, $data) {
        return str_replace(
            ['{publication_title}', '{user_name}', '{certificate_price}'],
            [$data['publication_title'], $data['user_name'], $data['certificate_price']],
            $subject
        );
    }

    // ─── UTILITIES ─────────────────────────────────────────────

    private function getPublicationData($publicationId) {
        return $this->db->queryOne(
            "SELECT p.*, u.email, u.full_name
             FROM publications p
             JOIN users u ON p.user_id = u.id
             WHERE p.id = ?",
            [$publicationId]
        );
    }

    private function updateEmailStatus($id, $status, $errorMessage = null) {
        $data = ['status' => $status];

        if ($status === 'sent') {
            $data['sent_at'] = date('Y-m-d H:i:s');
        }

        if ($errorMessage) {
            $data['error_message'] = $errorMessage;
        }

        return $this->db->update('publication_email_log', $data, 'id = ?', [$id]);
    }

    private function incrementAttempts($id) {
        return $this->db->execute(
            "UPDATE publication_email_log SET attempts = attempts + 1 WHERE id = ?",
            [$id]
        );
    }

    public function isUnsubscribed($email) {
        $result = $this->db->queryOne(
            "SELECT id FROM email_unsubscribes WHERE email = ?",
            [$email]
        );
        return !empty($result);
    }

    public function generateUnsubscribeToken($email) {
        $hash = substr(md5($email . SITE_URL), 0, 16);
        return base64_encode($email . ':' . $hash);
    }

    private function log($message) {
        $logFile = BASE_PATH . '/logs/publication-email-chain.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";

        $logDir = dirname($logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        error_log($logMessage, 3, $logFile);
    }
}
