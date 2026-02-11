<?php
/**
 * EmailJourney Class
 * Manages Customer Journey email automation for unpaid registrations
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailJourney {
    private $db;
    private $pdo;
    private const MAX_ATTEMPTS = 3;
    private const BATCH_SIZE = 50;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
    }

    /**
     * Schedule all touchpoints for a new registration
     * Called when user registers but doesn't pay immediately
     */
    public function scheduleForRegistration($registrationId, $userId) {
        $registration = $this->db->queryOne(
            "SELECT r.*, u.email, u.full_name, c.title as competition_title
             FROM registrations r
             JOIN users u ON r.user_id = u.id
             JOIN competitions c ON r.competition_id = c.id
             WHERE r.id = ? AND r.status = 'pending'",
            [$registrationId]
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
                "SELECT id FROM email_journey_log
                 WHERE registration_id = ? AND touchpoint_id = ?",
                [$registrationId, $touchpoint['id']]
            );

            if ($existing) {
                continue;
            }

            $this->db->insert('email_journey_log', [
                'registration_id' => $registrationId,
                'user_id' => $userId,
                'touchpoint_id' => $touchpoint['id'],
                'email' => $registration['email'],
                'status' => 'pending',
                'scheduled_at' => $scheduledAt
            ]);

            $scheduledCount++;
        }

        $this->log("SCHEDULE | Registration {$registrationId} | Scheduled {$scheduledCount} touchpoints");
        return $scheduledCount;
    }

    /**
     * Cancel all pending touchpoints for a registration (when payment succeeds)
     */
    public function cancelForRegistration($registrationId) {
        $result = $this->db->execute(
            "UPDATE email_journey_log
             SET status = 'skipped', updated_at = NOW()
             WHERE registration_id = ? AND status = 'pending'",
            [$registrationId]
        );

        $this->log("CANCEL | Registration {$registrationId} | Cancelled {$result} pending emails");
        return $result;
    }

    /**
     * Process pending emails that are due
     * Called by cron job
     */
    public function processPendingEmails() {
        $now = date('Y-m-d H:i:s');

        $pendingEmails = $this->db->query(
            "SELECT ejl.*,
                    t.email_subject, t.email_template, t.code as touchpoint_code,
                    r.competition_id, r.nomination, r.work_title,
                    u.full_name,
                    c.title as competition_title, c.price as competition_price, c.slug as competition_slug
             FROM email_journey_log ejl
             JOIN email_journey_touchpoints t ON ejl.touchpoint_id = t.id
             JOIN registrations r ON ejl.registration_id = r.id
             JOIN users u ON ejl.user_id = u.id
             JOIN competitions c ON r.competition_id = c.id
             WHERE ejl.status = 'pending'
               AND ejl.scheduled_at <= ?
               AND r.status = 'pending'
               AND ejl.attempts < ?
             ORDER BY ejl.scheduled_at ASC
             LIMIT ?",
            [$now, self::MAX_ATTEMPTS, self::BATCH_SIZE]
        );

        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($pendingEmails as $email) {
            $registration = $this->db->queryOne(
                "SELECT status FROM registrations WHERE id = ?",
                [$email['registration_id']]
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

            $success = $this->sendJourneyEmail($email);

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
     * Send a single journey email
     */
    private function sendJourneyEmail($emailData) {
        require_once BASE_PATH . '/vendor/autoload.php';

        try {
            $mail = new PHPMailer(true);

            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';

            // Configure authentication and encryption based on settings
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
                // No auth - internal relay mode
                $mail->SMTPAuth = false;
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($emailData['email'], $emailData['full_name']);

            $unsubscribeToken = $this->getOrCreateUnsubscribeToken($emailData['email'], $emailData['user_id']);
            $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . $unsubscribeToken;

            $templateData = [
                'user_name' => $emailData['full_name'],
                'user_email' => $emailData['email'],
                'user_id' => $emailData['user_id'],
                'competition_title' => $emailData['competition_title'],
                'competition_price' => $emailData['competition_price'],
                'nomination' => $emailData['nomination'],
                'work_title' => $emailData['work_title'],
                'payment_url' => generateMagicUrl($emailData['user_id'], '/pages/cart.php'),
                'competition_url' => SITE_URL . '/pages/competition-detail.php?slug=' . $emailData['competition_slug'],
                'unsubscribe_url' => $unsubscribeUrl,
                'site_url' => SITE_URL,
                'site_name' => SITE_NAME ?? 'Каменный город',
                'touchpoint_code' => $emailData['touchpoint_code']
            ];

            $htmlBody = $this->renderTemplate($emailData['email_template'], $templateData);
            $textBody = $this->renderTextTemplate($templateData);

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
     * Render HTML email template
     */
    private function renderTemplate($templateName, $data) {
        $templatePath = BASE_PATH . '/includes/email-templates/' . $templateName . '.php';

        if (!file_exists($templatePath)) {
            throw new Exception("Template not found: {$templateName}");
        }

        extract($data);

        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Render plain text version
     */
    private function renderTextTemplate($data) {
        $text = "Здравствуйте, {$data['user_name']}!\n\n";
        $text .= "Напоминаем о незавершённой регистрации на конкурс \"{$data['competition_title']}\".\n\n";

        if ($data['nomination']) {
            $text .= "Номинация: {$data['nomination']}\n";
        }
        if ($data['work_title']) {
            $text .= "Название работы: {$data['work_title']}\n";
        }

        $text .= "Стоимость участия: " . number_format($data['competition_price'], 0, ',', ' ') . " руб.\n\n";
        $text .= "Завершить регистрацию: {$data['payment_url']}\n\n";
        $text .= "---\n";
        $text .= "С уважением,\nКоманда проекта \"Каменный город\"\n\n";
        $text .= "Отписаться от рассылки: {$data['unsubscribe_url']}\n";

        return $text;
    }

    /**
     * Interpolate variables in email subject
     */
    private function interpolateSubject($subject, $data) {
        return str_replace(
            ['{competition_title}', '{user_name}'],
            [$data['competition_title'], $data['user_name']],
            $subject
        );
    }

    /**
     * Get all active touchpoints
     */
    public function getActiveTouchpoints() {
        return $this->db->query(
            "SELECT * FROM email_journey_touchpoints
             WHERE is_active = 1
             ORDER BY delay_hours ASC"
        );
    }

    /**
     * Update email status
     */
    private function updateEmailStatus($id, $status, $errorMessage = null) {
        $data = ['status' => $status];

        if ($status === 'sent') {
            $data['sent_at'] = date('Y-m-d H:i:s');
        }

        if ($errorMessage) {
            $data['error_message'] = $errorMessage;
        }

        return $this->db->update('email_journey_log', $data, 'id = ?', [$id]);
    }

    /**
     * Increment attempt counter
     */
    private function incrementAttempts($id) {
        return $this->db->execute(
            "UPDATE email_journey_log SET attempts = attempts + 1 WHERE id = ?",
            [$id]
        );
    }

    /**
     * Check if email is unsubscribed
     */
    public function isUnsubscribed($email) {
        $result = $this->db->queryOne(
            "SELECT id FROM email_unsubscribes WHERE email = ?",
            [$email]
        );
        return !empty($result);
    }

    /**
     * Get or create unsubscribe token for email
     */
    private function getOrCreateUnsubscribeToken($email, $userId = null) {
        $existing = $this->db->queryOne(
            "SELECT unsubscribe_token FROM email_unsubscribes WHERE email = ?",
            [$email]
        );

        if ($existing) {
            return $existing['unsubscribe_token'];
        }

        return bin2hex(random_bytes(32));
    }

    /**
     * Process unsubscribe request
     */
    public function unsubscribe($token, $reason = null) {
        $emailLog = $this->db->queryOne(
            "SELECT DISTINCT email, user_id FROM email_journey_log LIMIT 1"
        );

        $email = $this->db->queryOne(
            "SELECT email, user_id FROM email_journey_log WHERE email = (
                SELECT email FROM email_journey_log
                WHERE MD5(CONCAT(email, ?)) = ?
                LIMIT 1
            ) LIMIT 1",
            [SITE_URL, $token]
        );

        if (!$email) {
            $existingUnsubscribe = $this->db->queryOne(
                "SELECT email FROM email_unsubscribes WHERE unsubscribe_token = ?",
                [$token]
            );

            if ($existingUnsubscribe) {
                return true;
            }

            return false;
        }

        $this->db->insert('email_unsubscribes', [
            'user_id' => $email['user_id'],
            'email' => $email['email'],
            'unsubscribe_token' => $token,
            'reason' => $reason
        ]);

        $this->db->execute(
            "UPDATE email_journey_log SET status = 'skipped' WHERE email = ? AND status = 'pending'",
            [$email['email']]
        );

        $this->log("UNSUBSCRIBE | {$email['email']} | Reason: {$reason}");
        return true;
    }

    /**
     * Unsubscribe by token directly
     */
    public function unsubscribeByToken($token, $reason = null) {
        $decoded = base64_decode($token);
        if ($decoded === false) {
            return false;
        }

        $parts = explode(':', $decoded);
        if (count($parts) !== 2) {
            return false;
        }

        list($email, $hash) = $parts;

        $expectedHash = substr(md5($email . SITE_URL), 0, 16);
        if ($hash !== $expectedHash) {
            return false;
        }

        $existing = $this->db->queryOne(
            "SELECT id FROM email_unsubscribes WHERE email = ?",
            [$email]
        );

        if ($existing) {
            return true;
        }

        $user = $this->db->queryOne(
            "SELECT id FROM users WHERE email = ?",
            [$email]
        );

        $this->db->insert('email_unsubscribes', [
            'user_id' => $user ? $user['id'] : null,
            'email' => $email,
            'unsubscribe_token' => $token,
            'reason' => $reason
        ]);

        $this->db->execute(
            "UPDATE email_journey_log SET status = 'skipped' WHERE email = ? AND status = 'pending'",
            [$email]
        );

        $this->log("UNSUBSCRIBE | {$email} | Reason: {$reason}");
        return true;
    }

    /**
     * Generate unsubscribe token from email
     */
    public function generateUnsubscribeToken($email) {
        $hash = substr(md5($email . SITE_URL), 0, 16);
        return base64_encode($email . ':' . $hash);
    }

    /**
     * Get statistics for admin dashboard
     */
    public function getStats($days = 30) {
        $since = date('Y-m-d', strtotime("-{$days} days"));

        return [
            'total_sent' => $this->db->queryOne(
                "SELECT COUNT(*) as count FROM email_journey_log
                 WHERE status = 'sent' AND sent_at >= ?", [$since]
            )['count'],

            'total_pending' => $this->db->queryOne(
                "SELECT COUNT(*) as count FROM email_journey_log WHERE status = 'pending'"
            )['count'],

            'total_failed' => $this->db->queryOne(
                "SELECT COUNT(*) as count FROM email_journey_log
                 WHERE status = 'failed' AND created_at >= ?", [$since]
            )['count'],

            'by_touchpoint' => $this->db->query(
                "SELECT t.code, t.name,
                        COUNT(CASE WHEN ejl.status = 'sent' THEN 1 END) as sent,
                        COUNT(CASE WHEN ejl.status = 'pending' THEN 1 END) as pending,
                        COUNT(CASE WHEN ejl.status = 'failed' THEN 1 END) as failed
                 FROM email_journey_touchpoints t
                 LEFT JOIN email_journey_log ejl ON t.id = ejl.touchpoint_id
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
     * Log operations
     */
    private function log($message) {
        $logFile = BASE_PATH . '/logs/email-journey.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";

        $logDir = dirname($logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        error_log($logMessage, 3, $logFile);
    }
}
