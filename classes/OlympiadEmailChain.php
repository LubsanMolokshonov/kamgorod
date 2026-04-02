<?php
/**
 * OlympiadEmailChain Class
 * Управляет email-цепочкой напоминаний для неоплаченных дипломов олимпиад
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class OlympiadEmailChain {
    private $db;
    private $pdo;
    private const MAX_ATTEMPTS = 3;
    private const BATCH_SIZE = 50;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
    }

    /**
     * Запланировать все касания для нового заказа диплома
     * Вызывается после создания olympiad_registration со status='pending'
     */
    public function scheduleForRegistration($olympiadRegistrationId, $userId) {
        $registration = $this->db->queryOne(
            "SELECT r.*, u.email, u.full_name,
                    o.title as olympiad_title, o.slug as olympiad_slug, o.diploma_price,
                    res.score, res.placement
             FROM olympiad_registrations r
             JOIN users u ON r.user_id = u.id
             JOIN olympiads o ON r.olympiad_id = o.id
             LEFT JOIN olympiad_results res ON r.olympiad_result_id = res.id
             WHERE r.id = ? AND r.status = 'pending'",
            [$olympiadRegistrationId]
        );

        if (!$registration) {
            return false;
        }

        if ($this->isUnsubscribed($registration['email'])) {
            $this->log("SKIP | User {$registration['email']} is unsubscribed");
            return false;
        }

        $touchpoints = $this->getActiveTouchpoints();

        $scheduledCount = 0;
        foreach ($touchpoints as $touchpoint) {
            $scheduledAt = date('Y-m-d H:i:s',
                strtotime($registration['created_at']) + ($touchpoint['delay_hours'] * 3600)
            );

            $existing = $this->db->queryOne(
                "SELECT id FROM olympiad_email_log
                 WHERE olympiad_registration_id = ? AND touchpoint_id = ?",
                [$olympiadRegistrationId, $touchpoint['id']]
            );

            if ($existing) {
                continue;
            }

            $this->db->insert('olympiad_email_log', [
                'olympiad_registration_id' => $olympiadRegistrationId,
                'user_id' => $userId,
                'touchpoint_id' => $touchpoint['id'],
                'email' => $registration['email'],
                'status' => 'pending',
                'scheduled_at' => $scheduledAt
            ]);

            $scheduledCount++;
        }

        $this->log("SCHEDULE | OlympiadRegistration {$olympiadRegistrationId} | Scheduled {$scheduledCount} touchpoints");
        return $scheduledCount;
    }

    /**
     * Отменить все ожидающие касания (при успешной оплате)
     */
    public function cancelForRegistration($olympiadRegistrationId) {
        $result = $this->db->execute(
            "UPDATE olympiad_email_log
             SET status = 'skipped', updated_at = NOW()
             WHERE olympiad_registration_id = ? AND status = 'pending'",
            [$olympiadRegistrationId]
        );

        $this->log("CANCEL | OlympiadRegistration {$olympiadRegistrationId} | Cancelled {$result} pending emails");
        return $result;
    }

    /**
     * Обработка очереди писем (вызывается из cron)
     */
    public function processPendingEmails() {
        $now = date('Y-m-d H:i:s');

        $pendingEmails = $this->db->query(
            "SELECT oel.*,
                    t.email_subject, t.email_template, t.code as touchpoint_code,
                    r.olympiad_id, r.placement, r.score, r.has_supervisor, r.supervisor_name,
                    u.full_name,
                    o.title as olympiad_title, o.slug as olympiad_slug, o.diploma_price
             FROM olympiad_email_log oel
             JOIN olympiad_email_touchpoints t ON oel.touchpoint_id = t.id
             JOIN olympiad_registrations r ON oel.olympiad_registration_id = r.id
             JOIN users u ON oel.user_id = u.id
             JOIN olympiads o ON r.olympiad_id = o.id
             WHERE oel.status = 'pending'
               AND oel.scheduled_at <= ?
               AND r.status = 'pending'
               AND oel.attempts < ?
             ORDER BY oel.scheduled_at ASC
             LIMIT ?",
            [$now, self::MAX_ATTEMPTS, self::BATCH_SIZE]
        );

        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($pendingEmails as $email) {
            // Перепроверка статуса регистрации
            $registration = $this->db->queryOne(
                "SELECT status FROM olympiad_registrations WHERE id = ?",
                [$email['olympiad_registration_id']]
            );

            if (!$registration || $registration['status'] !== 'pending') {
                $this->updateEmailStatus($email['id'], 'skipped', 'Registration already paid or deleted');
                $results['skipped']++;
                continue;
            }

            if ($this->isUnsubscribed($email['email'])) {
                $this->updateEmailStatus($email['id'], 'skipped', 'User unsubscribed');
                $results['skipped']++;
                continue;
            }

            $success = $this->sendChainEmail($email);

            if ($success) {
                $this->updateEmailStatus($email['id'], 'sent');
                $results['sent']++;
            } else {
                $this->incrementAttempts($email['id']);
                if ($email['attempts'] + 1 >= self::MAX_ATTEMPTS) {
                    $this->updateEmailStatus($email['id'], 'failed', 'Max attempts reached');
                }
                $results['failed']++;
            }
        }

        $this->log("PROCESS | Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}");
        return $results;
    }

    /**
     * Отправить одно письмо цепочки
     */
    private function sendChainEmail($emailData) {
        require_once BASE_PATH . '/vendor/autoload.php';

        try {
            $mail = new PHPMailer(true);

            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';

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

            $unsubscribeToken = $this->getOrCreateUnsubscribeToken($emailData['email'], $emailData['user_id']);
            $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . $unsubscribeToken;

            $placement = $emailData['placement'] ?? '';
            $placementText = '';
            if ($placement == '1') $placementText = '1 место';
            elseif ($placement == '2') $placementText = '2 место';
            elseif ($placement == '3') $placementText = '3 место';

            $templateData = [
                'user_name' => $emailData['full_name'],
                'user_email' => $emailData['email'],
                'user_id' => $emailData['user_id'],
                'olympiad_title' => $emailData['olympiad_title'],
                'olympiad_slug' => $emailData['olympiad_slug'],
                'olympiad_price' => $emailData['diploma_price'] ?? 169,
                'score' => $emailData['score'] ?? 0,
                'placement' => $placement,
                'placement_text' => $placementText,
                'has_supervisor' => $emailData['has_supervisor'] ?? false,
                'supervisor_name' => $emailData['supervisor_name'] ?? '',
                'payment_url' => generateMagicUrl($emailData['user_id'], '/pages/cart.php'),
                'olympiad_url' => SITE_URL . '/olimpiady/' . $emailData['olympiad_slug'],
                'unsubscribe_url' => $unsubscribeUrl,
                'site_url' => SITE_URL,
                'site_name' => SITE_NAME ?? 'Каменный город',
                'touchpoint_code' => $emailData['touchpoint_code'],
                'footer_reason' => 'прошли олимпиаду на нашем портале'
            ];

            $htmlBody = $this->renderTemplate($emailData['email_template'], $templateData);
            $textBody = $this->renderTextTemplate($templateData);

            $mail->isHTML(true);
            $subject = $this->interpolateSubject($emailData['email_subject'], $templateData);
            $mail->Subject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;

            $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
            $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            $mail->send();

            $this->log("SENT | {$emailData['email']} | {$emailData['touchpoint_code']} | OlympiadRegistration {$emailData['olympiad_registration_id']}");
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
     * Рендер текстовой версии
     */
    private function renderTextTemplate($data) {
        $text = "Здравствуйте, {$data['user_name']}!\n\n";
        $text .= "Напоминаем о неоплаченном дипломе олимпиады \"{$data['olympiad_title']}\".\n\n";

        if ($data['score']) {
            $text .= "Ваш результат: {$data['score']} из 10 баллов\n";
        }
        if ($data['placement_text']) {
            $text .= "Место: {$data['placement_text']}\n";
        }

        $text .= "Стоимость диплома: " . number_format($data['olympiad_price'], 0, ',', ' ') . " руб.\n\n";
        $text .= "Получить диплом: {$data['payment_url']}\n\n";
        $text .= "---\n";
        $text .= "С уважением,\nКоманда проекта \"Каменный город\"\n\n";
        $text .= "Отписаться от рассылки: {$data['unsubscribe_url']}\n";

        return $text;
    }

    /**
     * Подстановка переменных в тему письма
     */
    private function interpolateSubject($subject, $data) {
        return str_replace(
            ['{olympiad_title}', '{user_name}', '{placement}', '{score}'],
            [$data['olympiad_title'], $data['user_name'], $data['placement_text'], $data['score']],
            $subject
        );
    }

    /**
     * Получить активные touchpoints
     */
    public function getActiveTouchpoints() {
        return $this->db->query(
            "SELECT * FROM olympiad_email_touchpoints
             WHERE is_active = 1
             ORDER BY delay_hours ASC"
        );
    }

    /**
     * Обновить статус письма
     */
    private function updateEmailStatus($id, $status, $errorMessage = null) {
        $data = ['status' => $status];

        if ($status === 'sent') {
            $data['sent_at'] = date('Y-m-d H:i:s');
        }

        if ($errorMessage) {
            $data['error_message'] = $errorMessage;
        }

        return $this->db->update('olympiad_email_log', $data, 'id = ?', [$id]);
    }

    /**
     * Инкремент счётчика попыток
     */
    private function incrementAttempts($id) {
        return $this->db->execute(
            "UPDATE olympiad_email_log SET attempts = attempts + 1 WHERE id = ?",
            [$id]
        );
    }

    /**
     * Проверить отписку
     */
    public function isUnsubscribed($email) {
        $result = $this->db->queryOne(
            "SELECT id FROM email_unsubscribes WHERE email = ?",
            [$email]
        );
        return !empty($result);
    }

    /**
     * Получить или создать токен отписки
     */
    private function getOrCreateUnsubscribeToken($email, $userId = null) {
        $existing = $this->db->queryOne(
            "SELECT unsubscribe_token FROM email_unsubscribes WHERE email = ?",
            [$email]
        );

        if ($existing) {
            return $existing['unsubscribe_token'];
        }

        return $this->generateUnsubscribeToken($email);
    }

    /**
     * Сгенерировать токен отписки
     */
    public function generateUnsubscribeToken($email) {
        $hash = substr(md5($email . SITE_URL), 0, 16);
        return base64_encode($email . ':' . $hash);
    }

    /**
     * Статистика для админки
     */
    public function getStats($days = 30) {
        $since = date('Y-m-d', strtotime("-{$days} days"));

        return [
            'total_sent' => $this->db->queryOne(
                "SELECT COUNT(*) as count FROM olympiad_email_log
                 WHERE status = 'sent' AND sent_at >= ?", [$since]
            )['count'],

            'total_pending' => $this->db->queryOne(
                "SELECT COUNT(*) as count FROM olympiad_email_log WHERE status = 'pending'"
            )['count'],

            'total_failed' => $this->db->queryOne(
                "SELECT COUNT(*) as count FROM olympiad_email_log
                 WHERE status = 'failed' AND created_at >= ?", [$since]
            )['count'],

            'by_touchpoint' => $this->db->query(
                "SELECT t.code, t.name,
                        COUNT(CASE WHEN oel.status = 'sent' THEN 1 END) as sent,
                        COUNT(CASE WHEN oel.status = 'pending' THEN 1 END) as pending,
                        COUNT(CASE WHEN oel.status = 'failed' THEN 1 END) as failed
                 FROM olympiad_email_touchpoints t
                 LEFT JOIN olympiad_email_log oel ON t.id = oel.touchpoint_id
                 GROUP BY t.id
                 ORDER BY t.display_order"
            ),

            'unsubscribes' => $this->db->queryOne(
                "SELECT COUNT(*) as count FROM email_unsubscribes WHERE unsubscribed_at >= ?",
                [$since]
            )['count']
        ];
    }

    /**
     * Логирование
     */
    private function log($message) {
        $logFile = BASE_PATH . '/logs/olympiad-email-chain.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";

        $logDir = dirname($logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        error_log($logMessage, 3, $logFile);
    }
}
