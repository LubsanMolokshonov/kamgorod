<?php
/**
 * AutowebinarEmailChain Class
 * Триггерные email-цепочки для автовебинаров.
 *
 * Цепочки:
 * 1. welcome        — сразу при регистрации
 * 2. quiz_reminder   — если тест не пройден (24ч, 3д, 7д от регистрации)
 * 3. cert_reminder   — если тест пройден, но сертификат не заказан (2ч, 24ч, 3д от теста)
 * 4. payment_reminder — если сертификат заказан, но не оплачен (1ч, 24ч, 3д от заказа)
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class AutowebinarEmailChain {
    private $db;
    private $pdo;
    private const MAX_ATTEMPTS = 3;
    private const BATCH_SIZE = 50;
    private const MAX_AGE_DAYS = 30;

    // Дата запуска системы — обрабатывать только регистрации начиная с этой даты
    private const LAUNCH_DATE = '2026-02-18';

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
    }

    // ─── PUBLIC API ────────────────────────────────────────────

    /**
     * Запланировать welcome-письмо (вызывается из register-webinar.php)
     */
    public function scheduleWelcomeEmail($registrationId) {
        $reg = $this->getRegistrationData($registrationId);
        if (!$reg) return false;

        if ($this->isUnsubscribed($reg['email'])) {
            $this->log("SKIP | {$reg['email']} | unsubscribed");
            return false;
        }

        $touchpoint = $this->db->queryOne(
            "SELECT * FROM autowebinar_email_touchpoints WHERE code = 'aw_welcome' AND is_active = 1"
        );
        if (!$touchpoint) return false;

        $this->scheduleEmail($registrationId, $reg['user_id'], $reg['email'], $touchpoint, $reg['created_at']);
        $this->log("SCHEDULE | Registration {$registrationId} | aw_welcome");
        return true;
    }

    /**
     * Отправить welcome-письмо немедленно
     */
    public function sendWelcomeEmail($registrationId) {
        $emailData = $this->db->queryOne(
            "SELECT ael.*, t.email_subject, t.email_template, t.code as touchpoint_code,
                    wr.webinar_id, wr.full_name, wr.email as reg_email, wr.created_at as reg_created_at,
                    w.title as webinar_title, w.slug as webinar_slug, w.video_url,
                    w.certificate_price, w.certificate_hours,
                    w.speaker_id,
                    s.full_name as speaker_name, s.position as speaker_position, s.photo as speaker_photo
             FROM autowebinar_email_log ael
             JOIN autowebinar_email_touchpoints t ON ael.touchpoint_id = t.id
             JOIN webinar_registrations wr ON ael.registration_id = wr.id
             JOIN webinars w ON wr.webinar_id = w.id
             LEFT JOIN speakers s ON w.speaker_id = s.id
             WHERE ael.registration_id = ? AND t.code = 'aw_welcome' AND ael.status = 'pending'",
            [$registrationId]
        );

        if (!$emailData) return false;

        return $this->sendEmail($emailData);
    }

    /**
     * Главный метод для cron: проверить состояния, запланировать и отправить письма
     */
    public function processPendingEmails() {
        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        // Шаг 1: Найти регистрации с состоянием в одном запросе (batch)
        $registrations = $this->db->query(
            "SELECT wr.id as registration_id, wr.user_id, wr.email, wr.created_at,
                    qr.passed as quiz_passed, qr.completed_at as quiz_completed_at,
                    wc.status as cert_status, wc.created_at as cert_created_at
             FROM webinar_registrations wr
             JOIN webinars w ON wr.webinar_id = w.id
             LEFT JOIN webinar_quiz_results qr ON qr.registration_id = wr.id AND qr.passed = 1
             LEFT JOIN webinar_certificates wc ON wc.registration_id = wr.id
             LEFT JOIN email_unsubscribes eu ON eu.email = wr.email
             WHERE w.status = 'videolecture'
               AND wr.status = 'registered'
               AND wr.created_at >= ?
               AND wr.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND eu.id IS NULL",
            [self::LAUNCH_DATE, self::MAX_AGE_DAYS]
        );

        // Загрузить touchpoints один раз
        $allTouchpoints = [];
        $tpRows = $this->db->query(
            "SELECT * FROM autowebinar_email_touchpoints WHERE is_active = 1 ORDER BY delay_hours ASC"
        );
        foreach ($tpRows as $tp) {
            $allTouchpoints[$tp['chain_type']][] = $tp;
        }

        // Загрузить уже существующие log-записи одним запросом
        $existingLogs = [];
        $logRows = $this->db->query(
            "SELECT registration_id, touchpoint_id FROM autowebinar_email_log"
        );
        foreach ($logRows as $lr) {
            $existingLogs[$lr['registration_id'] . '_' . $lr['touchpoint_id']] = true;
        }

        // Шаг 2: Для каждой регистрации — определить состояние и собрать batch INSERT
        $toInsert = [];
        $completedRegIds = [];
        $chainSkips = []; // [registration_id => activeChain]

        foreach ($registrations as $reg) {
            $state = $this->getStateFromRow($reg);
            $activeChain = $this->getActiveChainType($state);

            if ($activeChain === null) {
                $completedRegIds[] = $reg['registration_id'];
                continue;
            }

            $chainSkips[$reg['registration_id']] = $activeChain;

            $anchorTime = $this->getAnchorFromRow($reg, $activeChain);
            if ($anchorTime && !empty($allTouchpoints[$activeChain])) {
                foreach ($allTouchpoints[$activeChain] as $tp) {
                    $key = $reg['registration_id'] . '_' . $tp['id'];
                    if (isset($existingLogs[$key])) continue;

                    $scheduledAt = date('Y-m-d H:i:s',
                        strtotime($anchorTime) + ($tp['delay_hours'] * 3600)
                    );
                    $toInsert[] = [
                        $reg['registration_id'], $reg['user_id'],
                        $tp['id'], $reg['email'], $scheduledAt
                    ];
                }
            }
        }

        // Batch skip для завершённых
        if (!empty($completedRegIds)) {
            $placeholders = implode(',', array_fill(0, count($completedRegIds), '?'));
            $this->db->execute(
                "UPDATE autowebinar_email_log SET status = 'skipped', updated_at = NOW()
                 WHERE registration_id IN ({$placeholders}) AND status = 'pending'",
                $completedRegIds
            );
        }

        // Batch skip для устаревших цепочек (группируем по activeChain)
        $chainOrder = ['welcome', 'quiz_reminder', 'cert_reminder', 'payment_reminder'];
        $skipGroups = []; // activeChain => [regIds]
        foreach ($chainSkips as $regId => $activeChain) {
            $skipGroups[$activeChain][] = $regId;
        }
        foreach ($skipGroups as $activeChain => $regIds) {
            $activeIndex = array_search($activeChain, $chainOrder);
            if ($activeIndex > 0) {
                $obsolete = array_slice($chainOrder, 0, $activeIndex);
                $obsPh = implode(',', array_fill(0, count($obsolete), '?'));
                // Chunk по 500 регистраций
                foreach (array_chunk($regIds, 500) as $chunk) {
                    $regPh = implode(',', array_fill(0, count($chunk), '?'));
                    $this->db->execute(
                        "UPDATE autowebinar_email_log ael
                         JOIN autowebinar_email_touchpoints t ON ael.touchpoint_id = t.id
                         SET ael.status = 'skipped', ael.updated_at = NOW()
                         WHERE ael.registration_id IN ({$regPh})
                           AND ael.status = 'pending'
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
                $sql = "INSERT IGNORE INTO autowebinar_email_log
                        (registration_id, user_id, touchpoint_id, email, status, scheduled_at)
                        VALUES " . implode(', ', $placeholders);
                $this->pdo->prepare($sql)->execute($params);
            }
        }

        // Шаг 3: Обработать очередь отправки
        $pendingEmails = $this->db->query(
            "SELECT ael.*, t.email_subject, t.email_template, t.code as touchpoint_code, t.chain_type,
                    wr.webinar_id, wr.full_name, wr.email as reg_email, wr.created_at as reg_created_at,
                    w.title as webinar_title, w.slug as webinar_slug, w.video_url,
                    w.certificate_price, w.certificate_hours,
                    w.speaker_id,
                    s.full_name as speaker_name, s.position as speaker_position, s.photo as speaker_photo
             FROM autowebinar_email_log ael
             JOIN autowebinar_email_touchpoints t ON ael.touchpoint_id = t.id
             JOIN webinar_registrations wr ON ael.registration_id = wr.id
             JOIN webinars w ON wr.webinar_id = w.id
             LEFT JOIN speakers s ON w.speaker_id = s.id
             WHERE ael.status = 'pending'
               AND ael.scheduled_at <= NOW()
               AND ael.attempts < ?
             ORDER BY ael.scheduled_at ASC
             LIMIT ?",
            [self::MAX_ATTEMPTS, self::BATCH_SIZE]
        );

        foreach ($pendingEmails as $emailData) {
            // Повторно проверить состояние (пользователь мог прогрессировать)
            $state = $this->getRegistrationState($emailData['registration_id']);
            $activeChain = $this->getActiveChainType($state);

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
     * Отменить все pending-письма для регистрации (при оплате)
     */
    public function cancelForRegistration($registrationId) {
        $result = $this->db->execute(
            "UPDATE autowebinar_email_log
             SET status = 'skipped', updated_at = NOW()
             WHERE registration_id = ? AND status = 'pending'",
            [$registrationId]
        );

        $this->log("CANCEL | Registration {$registrationId} | Cancelled {$result} pending emails");
        return $result;
    }

    /**
     * Статистика для админки
     */
    public function getStats($days = 30) {
        $since = date('Y-m-d', strtotime("-{$days} days"));

        return [
            'total_sent' => $this->db->queryOne(
                "SELECT COUNT(*) as count FROM autowebinar_email_log
                 WHERE status = 'sent' AND sent_at >= ?", [$since]
            )['count'],

            'total_pending' => $this->db->queryOne(
                "SELECT COUNT(*) as count FROM autowebinar_email_log WHERE status = 'pending'"
            )['count'],

            'total_failed' => $this->db->queryOne(
                "SELECT COUNT(*) as count FROM autowebinar_email_log
                 WHERE status = 'failed' AND created_at >= ?", [$since]
            )['count'],

            'by_touchpoint' => $this->db->query(
                "SELECT t.code, t.name, t.chain_type,
                        COUNT(CASE WHEN ael.status = 'sent' THEN 1 END) as sent,
                        COUNT(CASE WHEN ael.status = 'pending' THEN 1 END) as pending,
                        COUNT(CASE WHEN ael.status = 'failed' THEN 1 END) as failed,
                        COUNT(CASE WHEN ael.status = 'skipped' THEN 1 END) as skipped
                 FROM autowebinar_email_touchpoints t
                 LEFT JOIN autowebinar_email_log ael ON t.id = ael.touchpoint_id
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
        if (!empty($row['cert_status'])) {
            if (in_array($row['cert_status'], ['paid', 'ready'])) {
                return 'cert_paid';
            }
            return 'cert_ordered';
        }
        if (!empty($row['quiz_passed'])) {
            return 'quiz_passed';
        }
        return 'quiz_pending';
    }

    /**
     * Получить якорное время из предзагруженной строки
     */
    private function getAnchorFromRow($row, $chainType) {
        return match ($chainType) {
            'quiz_reminder'    => $row['created_at'],
            'cert_reminder'    => $row['quiz_completed_at'] ?? null,
            'payment_reminder' => $row['cert_created_at'] ?? null,
            default            => null,
        };
    }

    /**
     * Определить текущее состояние регистрации (по ID, для re-check при отправке)
     * @return string 'quiz_pending'|'quiz_passed'|'cert_ordered'|'cert_paid'
     */
    private function getRegistrationState($registrationId) {
        $cert = $this->db->queryOne(
            "SELECT status FROM webinar_certificates WHERE registration_id = ?",
            [$registrationId]
        );

        if ($cert) {
            if (in_array($cert['status'], ['paid', 'ready'])) {
                return 'cert_paid';
            }
            return 'cert_ordered';
        }

        $quiz = $this->db->queryOne(
            "SELECT passed FROM webinar_quiz_results WHERE registration_id = ? AND passed = 1",
            [$registrationId]
        );

        if ($quiz) {
            return 'quiz_passed';
        }

        return 'quiz_pending';
    }

    /**
     * Определить активную цепочку по состоянию
     */
    private function getActiveChainType($state) {
        return match ($state) {
            'quiz_pending'  => 'quiz_reminder',
            'quiz_passed'   => 'cert_reminder',
            'cert_ordered'  => 'payment_reminder',
            'cert_paid'     => null,
        };
    }

    // ─── CHAIN MANAGEMENT ──────────────────────────────────────

    /**
     * Запланировать одно письмо (INSERT IGNORE для идемпотентности)
     */
    private function scheduleEmail($registrationId, $userId, $email, $touchpoint, $anchorTime) {
        $scheduledAt = date('Y-m-d H:i:s',
            strtotime($anchorTime) + ($touchpoint['delay_hours'] * 3600)
        );

        try {
            $this->pdo->prepare(
                "INSERT IGNORE INTO autowebinar_email_log
                 (registration_id, user_id, touchpoint_id, email, status, scheduled_at)
                 VALUES (?, ?, ?, ?, 'pending', ?)"
            )->execute([$registrationId, $userId, $touchpoint['id'], $email, $scheduledAt]);
        } catch (\PDOException $e) {
            // Duplicate — OK, уже запланировано
            if (strpos($e->getMessage(), 'Duplicate') === false) {
                throw $e;
            }
        }
    }

    /**
     * Пометить как skipped все pending-письма предыдущих цепочек
     */
    private function skipObsoleteChains($registrationId, $activeChain) {
        $chainOrder = ['welcome', 'quiz_reminder', 'cert_reminder', 'payment_reminder'];
        $activeIndex = array_search($activeChain, $chainOrder);
        if ($activeIndex === false) return;

        $obsoleteChains = array_slice($chainOrder, 0, $activeIndex);
        if (empty($obsoleteChains)) return;

        $placeholders = implode(',', array_fill(0, count($obsoleteChains), '?'));
        $params = array_merge([$registrationId], $obsoleteChains);

        $this->db->execute(
            "UPDATE autowebinar_email_log ael
             JOIN autowebinar_email_touchpoints t ON ael.touchpoint_id = t.id
             SET ael.status = 'skipped', ael.updated_at = NOW()
             WHERE ael.registration_id = ?
               AND ael.status = 'pending'
               AND t.chain_type IN ({$placeholders})",
            $params
        );
    }

    /**
     * Отменить все pending-письма (воронка завершена)
     */
    private function skipAllPending($registrationId) {
        $this->db->execute(
            "UPDATE autowebinar_email_log SET status = 'skipped', updated_at = NOW()
             WHERE registration_id = ? AND status = 'pending'",
            [$registrationId]
        );
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
            $autowebinarUrl = generateMagicUrl(
                $emailData['user_id'],
                '/kabinet/videolektsiya/' . $emailData['registration_id']
            );

            $certificateUrl = generateMagicUrl(
                $emailData['user_id'],
                '/pages/webinar-certificate.php?registration_id=' . $emailData['registration_id']
            );

            $templateData = [
                'user_name'         => $emailData['full_name'],
                'user_email'        => $emailData['email'],
                'user_id'           => $emailData['user_id'],
                'webinar_title'     => $emailData['webinar_title'],
                'webinar_slug'      => $emailData['webinar_slug'],
                'speaker_name'      => $emailData['speaker_name'] ?? '',
                'speaker_position'  => $emailData['speaker_position'] ?? '',
                'speaker_photo'     => $emailData['speaker_photo']
                    ? SITE_URL . '/assets/images/speakers/' . $emailData['speaker_photo']
                    : '',
                'certificate_price' => $emailData['certificate_price'] ?? 169,
                'certificate_hours' => $emailData['certificate_hours'] ?? 2,
                'autowebinar_url'   => $autowebinarUrl,
                'certificate_url'   => $certificateUrl,
                'cabinet_url'       => generateMagicUrl($emailData['user_id'], '/pages/cabinet.php?tab=webinars'),
                'registration_id'   => $emailData['registration_id'],
                'unsubscribe_url'   => $unsubscribeUrl,
                'site_url'          => SITE_URL,
                'site_name'         => SITE_NAME ?? 'Каменный город',
                'touchpoint_code'   => $emailData['touchpoint_code'],
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

            $this->log("SENT | {$emailData['email']} | {$emailData['touchpoint_code']} | Registration {$emailData['registration_id']}");
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
            str_starts_with($touchpointCode, 'aw_welcome') =>
                "Вы зарегистрированы на видеолекцию «{$data['webinar_title']}».\n\n" .
                "Перейти к просмотру: {$data['autowebinar_url']}\n\n",

            str_starts_with($touchpointCode, 'aw_quiz') =>
                "Напоминаем: вы зарегистрированы на видеолекцию «{$data['webinar_title']}».\n" .
                "Пройдите тест, чтобы получить сертификат на {$data['certificate_hours']} ч.\n\n" .
                "Перейти к тесту: {$data['autowebinar_url']}\n\n",

            str_starts_with($touchpointCode, 'aw_cert') =>
                "Поздравляем! Вы прошли тест по вебинару «{$data['webinar_title']}».\n" .
                "Оформите сертификат на {$data['certificate_hours']} академических часа.\n" .
                "Стоимость: " . number_format($data['certificate_price'], 0, ',', ' ') . " руб.\n\n" .
                "Оформить сертификат: {$data['certificate_url']}\n\n",

            str_starts_with($touchpointCode, 'aw_pay') =>
                "Ваш сертификат по вебинару «{$data['webinar_title']}» ожидает оплаты.\n" .
                "Стоимость: " . number_format($data['certificate_price'], 0, ',', ' ') . " руб.\n\n" .
                "Завершить оплату: {$data['autowebinar_url']}\n\n",

            default => "Перейти: {$data['autowebinar_url']}\n\n",
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
            ['{webinar_title}', '{user_name}', '{certificate_price}'],
            [$data['webinar_title'], $data['user_name'], $data['certificate_price']],
            $subject
        );
    }

    // ─── UTILITIES ─────────────────────────────────────────────

    private function getRegistrationData($registrationId) {
        return $this->db->queryOne(
            "SELECT wr.*, w.title as webinar_title, w.slug as webinar_slug, w.status as webinar_status
             FROM webinar_registrations wr
             JOIN webinars w ON wr.webinar_id = w.id
             WHERE wr.id = ? AND w.status = 'videolecture'",
            [$registrationId]
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

        return $this->db->update('autowebinar_email_log', $data, 'id = ?', [$id]);
    }

    private function incrementAttempts($id) {
        return $this->db->execute(
            "UPDATE autowebinar_email_log SET attempts = attempts + 1 WHERE id = ?",
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
        $logFile = BASE_PATH . '/logs/autowebinar-email-chain.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";

        $logDir = dirname($logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        error_log($logMessage, 3, $logFile);
    }
}
