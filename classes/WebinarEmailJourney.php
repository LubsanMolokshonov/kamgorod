<?php
/**
 * WebinarEmailJourney Class
 * Manages email automation for webinar registrations
 * 5 emails: confirmation, 24h reminder, 1h broadcast link, 15min reminder, follow-up
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class WebinarEmailJourney {
    private $db;
    private $pdo;
    private const MAX_ATTEMPTS = 3;
    private const BATCH_SIZE = 50;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
    }

    /**
     * Schedule all touchpoints for a new webinar registration
     * Called after successful registration
     *
     * @param int $registrationId
     * @return int Number of scheduled emails
     */
    public function scheduleForRegistration($registrationId) {
        $registration = $this->db->queryOne(
            "SELECT wr.*, w.title as webinar_title, w.scheduled_at as webinar_scheduled_at,
                    w.duration_minutes, w.broadcast_url, w.status as webinar_status
             FROM webinar_registrations wr
             JOIN webinars w ON wr.webinar_id = w.id
             WHERE wr.id = ?",
            [$registrationId]
        );

        if (!$registration) {
            $this->log("ERROR | Registration {$registrationId} not found");
            return 0;
        }

        if ($this->isUnsubscribed($registration['email'])) {
            $this->log("SKIP | User {$registration['email']} is unsubscribed");
            return 0;
        }

        $touchpoints = $this->getActiveTouchpoints();
        $webinarTime = strtotime($registration['webinar_scheduled_at']);
        $duration = (int)($registration['duration_minutes'] ?? 60);
        $now = time();

        $scheduledCount = 0;
        $lateRegistrationBroadcastScheduled = false;
        foreach ($touchpoints as $touchpoint) {
            // Calculate scheduled time based on webinar time
            // delay_minutes: negative = before webinar, positive = after start
            $delayMinutes = (int)$touchpoint['delay_minutes'];

            if ($delayMinutes === 0) {
                // Confirmation email - send immediately
                $scheduledAt = date('Y-m-d H:i:s');
            } elseif ($delayMinutes < 0) {
                // Before webinar (e.g., -1440 = 24h before, -60 = 1h before)
                $scheduledAt = date('Y-m-d H:i:s', $webinarTime + ($delayMinutes * 60));
            } else {
                // After webinar start (e.g., +180 = 3h after start)
                $scheduledAt = date('Y-m-d H:i:s', $webinarTime + ($delayMinutes * 60));
            }

            // Skip if scheduled time is in the past (except for immediate emails)
            if ($delayMinutes !== 0 && strtotime($scheduledAt) < $now) {
                // For broadcast touchpoints: if webinar is still ongoing, send immediately
                $broadcastCodes = ['webinar_broadcast_link', 'webinar_reminder_15min'];
                $webinarEnd = $webinarTime + ($duration * 60);

                if (in_array($touchpoint['code'], $broadcastCodes) && $now < $webinarEnd) {
                    // Skip reminder_15min if broadcast_link is already scheduled for this late registration
                    if ($touchpoint['code'] === 'webinar_reminder_15min' && $lateRegistrationBroadcastScheduled) {
                        $this->log("SKIP | Touchpoint {$touchpoint['code']} (broadcast_link already scheduled) for registration {$registrationId}");
                        continue;
                    }
                    // Schedule for immediate delivery
                    $scheduledAt = date('Y-m-d H:i:s');
                    if ($touchpoint['code'] === 'webinar_broadcast_link') {
                        $lateRegistrationBroadcastScheduled = true;
                    }
                    $this->log("LATE_REG | Touchpoint {$touchpoint['code']} scheduled NOW for registration {$registrationId}");
                } else {
                    $this->log("SKIP | Touchpoint {$touchpoint['code']} already passed for registration {$registrationId}");
                    continue;
                }
            }

            // Check for existing entry
            $existing = $this->db->queryOne(
                "SELECT id FROM webinar_email_log
                 WHERE webinar_registration_id = ? AND touchpoint_id = ?",
                [$registrationId, $touchpoint['id']]
            );

            if ($existing) {
                continue;
            }

            $this->db->insert('webinar_email_log', [
                'webinar_registration_id' => $registrationId,
                'touchpoint_id' => $touchpoint['id'],
                'email' => $registration['email'],
                'status' => 'pending',
                'scheduled_at' => $scheduledAt
            ]);

            $scheduledCount++;
        }

        $this->log("SCHEDULE | Registration {$registrationId} | Scheduled {$scheduledCount} emails for webinar {$registration['webinar_title']}");
        return $scheduledCount;
    }

    /**
     * Send confirmation email immediately after registration
     *
     * @param int $registrationId
     * @return bool
     */
    public function sendConfirmationEmail($registrationId) {
        $emailLog = $this->db->queryOne(
            "SELECT wel.*,
                    t.email_subject, t.email_template, t.code as touchpoint_code,
                    wr.full_name, wr.email, wr.phone, wr.organization, wr.city, wr.user_id,
                    w.id as webinar_id, w.title as webinar_title, w.slug as webinar_slug,
                    w.scheduled_at as webinar_scheduled_at, w.duration_minutes,
                    w.broadcast_url, w.video_url, w.short_description,
                    s.full_name as speaker_name, s.position as speaker_position, s.photo as speaker_photo,
                    w.certificate_price, w.certificate_hours, w.status as webinar_status
             FROM webinar_email_log wel
             JOIN webinar_email_touchpoints t ON wel.touchpoint_id = t.id
             JOIN webinar_registrations wr ON wel.webinar_registration_id = wr.id
             JOIN webinars w ON wr.webinar_id = w.id
             LEFT JOIN speakers s ON w.speaker_id = s.id
             WHERE wel.webinar_registration_id = ? AND t.code = 'webinar_confirmation'",
            [$registrationId]
        );

        if (!$emailLog) {
            $this->log("ERROR | Confirmation email not found for registration {$registrationId}");
            return false;
        }

        return $this->sendEmail($emailLog);
    }

    /**
     * Cancel all pending emails for a registration
     * Use when webinar is cancelled or registration is deleted
     *
     * @param int $registrationId
     * @return int Number of cancelled emails
     */
    public function cancelForRegistration($registrationId) {
        $result = $this->db->execute(
            "UPDATE webinar_email_log
             SET status = 'skipped', updated_at = NOW()
             WHERE webinar_registration_id = ? AND status = 'pending'",
            [$registrationId]
        );

        $this->log("CANCEL | Registration {$registrationId} | Cancelled {$result} pending emails");
        return $result;
    }

    /**
     * Process pending emails that are due
     * Called by cron job every 5 minutes
     *
     * @return array Results with counts
     */
    public function processPendingEmails() {
        $now = date('Y-m-d H:i:s');

        $pendingEmails = $this->db->query(
            "SELECT wel.*,
                    t.email_subject, t.email_template, t.code as touchpoint_code,
                    wr.full_name, wr.email, wr.phone, wr.organization, wr.city, wr.user_id,
                    w.id as webinar_id, w.title as webinar_title, w.slug as webinar_slug,
                    w.scheduled_at as webinar_scheduled_at, w.duration_minutes,
                    w.broadcast_url, w.video_url, w.short_description,
                    s.full_name as speaker_name, s.position as speaker_position, s.photo as speaker_photo,
                    w.certificate_price, w.certificate_hours, w.status as webinar_status
             FROM webinar_email_log wel
             JOIN webinar_email_touchpoints t ON wel.touchpoint_id = t.id
             JOIN webinar_registrations wr ON wel.webinar_registration_id = wr.id
             JOIN webinars w ON wr.webinar_id = w.id
             LEFT JOIN speakers s ON w.speaker_id = s.id
             WHERE wel.status = 'pending'
               AND wel.scheduled_at <= ?
               AND wel.attempts < ?
               AND wr.status = 'registered'
             ORDER BY wel.scheduled_at ASC
             LIMIT ?",
            [$now, self::MAX_ATTEMPTS, self::BATCH_SIZE]
        );

        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($pendingEmails as $emailData) {
            // Skip if webinar is cancelled or deleted
            if (!in_array($emailData['webinar_status'], ['scheduled', 'live', 'completed', 'videolecture'])) {
                $this->updateEmailStatus($emailData['id'], 'skipped', 'Webinar not active');
                $results['skipped']++;
                continue;
            }

            // Check unsubscribe
            if ($this->isUnsubscribed($emailData['email'])) {
                $this->updateEmailStatus($emailData['id'], 'skipped', 'User unsubscribed');
                $results['skipped']++;
                continue;
            }

            $success = $this->sendEmail($emailData);

            if ($success) {
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
     * Send a single email
     *
     * @param array $emailData Email log entry with all joined data
     * @return bool
     */
    private function sendEmail($emailData) {
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

            // Prepare template data
            $unsubscribeToken = $this->generateUnsubscribeToken($emailData['email']);
            $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . $unsubscribeToken;

            $templateData = $this->prepareTemplateData($emailData, $unsubscribeUrl);

            // Render email
            $htmlBody = $this->renderTemplate($emailData['email_template'], $templateData);
            $textBody = $this->renderTextTemplate($emailData, $templateData);

            $mail->isHTML(true);
            $subject = $this->interpolateSubject($emailData['email_subject'], $templateData);
            $mail->Subject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;

            // Unsubscribe headers
            $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
            $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            $mail->send();

            $this->updateEmailStatus($emailData['id'], 'sent');
            $this->log("SENT | {$emailData['email']} | {$emailData['touchpoint_code']} | Webinar: {$emailData['webinar_title']}");
            return true;

        } catch (Exception $e) {
            $this->log("ERROR | {$emailData['email']} | {$emailData['touchpoint_code']} | " . $e->getMessage());
            $this->updateEmailStatus($emailData['id'], 'pending', $e->getMessage());
            return false;
        }
    }

    /**
     * Prepare data for email template
     */
    private function prepareTemplateData($emailData, $unsubscribeUrl) {
        $webinarDate = new DateTime($emailData['webinar_scheduled_at'], new DateTimeZone('Europe/Moscow'));

        // Russian month names
        $months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
                   'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        $days = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];

        $formattedDate = $webinarDate->format('j') . ' ' . $months[(int)$webinarDate->format('n') - 1] . ' ' . $webinarDate->format('Y');
        $formattedTime = $webinarDate->format('H:i');
        $dayOfWeek = $days[(int)$webinarDate->format('w')];

        // Extract first name from full name
        $nameParts = explode(' ', trim($emailData['full_name']));
        $firstName = count($nameParts) > 1 ? $nameParts[1] : $nameParts[0]; // Surname Name -> Name

        $userId = $emailData['user_id'] ?? null;

        return [
            'user_name' => $emailData['full_name'],
            'user_first_name' => $firstName,
            'user_email' => $emailData['email'],
            'user_phone' => $emailData['phone'] ?? '',
            'user_organization' => $emailData['organization'] ?? '',
            'user_city' => $emailData['city'] ?? '',
            'user_id' => $userId,

            'webinar_id' => $emailData['webinar_id'],
            'webinar_title' => $emailData['webinar_title'],
            'webinar_slug' => $emailData['webinar_slug'],
            'webinar_date' => $formattedDate,
            'webinar_time' => $formattedTime,
            'webinar_day_of_week' => $dayOfWeek,
            'webinar_datetime_full' => "{$formattedDate}, {$dayOfWeek}, в {$formattedTime} МСК",
            'webinar_duration' => $emailData['duration_minutes'] ?? 60,
            'webinar_description' => $emailData['short_description'] ?? '',
            'broadcast_url' => $emailData['broadcast_url'] ?? '',
            'video_url' => $emailData['video_url'] ?? '',

            'speaker_name' => $emailData['speaker_name'] ?? '',
            'speaker_position' => $emailData['speaker_position'] ?? '',
            'speaker_photo' => $emailData['speaker_photo'] ? (str_starts_with($emailData['speaker_photo'], '/') ? SITE_URL . $emailData['speaker_photo'] : SITE_URL . '/uploads/speakers/' . $emailData['speaker_photo']) : '',

            'certificate_price' => $emailData['certificate_price'] ?? 200,
            'certificate_hours' => $emailData['certificate_hours'] ?? 2,

            'registration_id' => $emailData['webinar_registration_id'],
            'calendar_url' => SITE_URL . '/ajax/generate-ics.php?registration_id=' . $emailData['webinar_registration_id'],
            'google_calendar_url' => $this->buildGoogleCalendarUrl($webinarDate, $emailData),
            'webinar_url' => SITE_URL . '/vebinar/' . $emailData['webinar_slug'],
            'cabinet_url' => generateMagicUrl($userId, '/pages/cabinet.php?tab=webinars'),
            'certificate_url' => generateMagicUrl($userId, '/pages/webinar-certificate.php?registration_id=' . $emailData['webinar_registration_id']),
            'unsubscribe_url' => $unsubscribeUrl,
            'site_url' => SITE_URL,
            'site_name' => SITE_NAME ?? 'ФГОС-Практикум',
            'touchpoint_code' => $emailData['touchpoint_code']
        ];
    }

    /**
     * Build Google Calendar URL for webinar
     */
    private function buildGoogleCalendarUrl(DateTime $webinarDate, array $emailData): string {
        $startUtc = (clone $webinarDate)->setTimezone(new DateTimeZone('UTC'));
        $duration = (int)($emailData['duration_minutes'] ?? 60);
        $endUtc = (clone $startUtc)->modify("+{$duration} minutes");

        $dates = $startUtc->format('Ymd\THis\Z') . '/' . $endUtc->format('Ymd\THis\Z');
        $title = $emailData['webinar_title'] ?? '';
        $details = 'Вебинар на ФГОС-Практикум. Страница: ' . SITE_URL . '/vebinar/' . ($emailData['webinar_slug'] ?? '');

        return 'https://calendar.google.com/calendar/render?action=TEMPLATE'
            . '&text=' . rawurlencode($title)
            . '&dates=' . $dates
            . '&details=' . rawurlencode($details);
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
    private function renderTextTemplate($emailData, $data) {
        $text = "Здравствуйте, {$data['user_name']}!\n\n";

        switch ($emailData['touchpoint_code']) {
            case 'webinar_confirmation':
                $text .= "Вы зарегистрированы на вебинар \"{$data['webinar_title']}\".\n\n";
                $text .= "Дата и время: {$data['webinar_datetime_full']}\n";
                if ($data['speaker_name']) {
                    $text .= "Спикер: {$data['speaker_name']}\n";
                }
                $text .= "\nСсылка на трансляцию придет за 1 час до начала.\n";
                break;

            case 'webinar_reminder_24h':
                $text .= "Напоминаем: завтра состоится вебинар \"{$data['webinar_title']}\".\n\n";
                $text .= "Время: {$data['webinar_time']} МСК\n";
                break;

            case 'webinar_broadcast_link':
                $text .= "Через 1 час начнётся вебинар \"{$data['webinar_title']}\"!\n\n";
                $text .= "Ссылка на трансляцию: {$data['broadcast_url']}\n\n";
                $text .= "Нажмите на ссылку за 5 минут до начала.\n";
                break;

            case 'webinar_reminder_15min':
                $text .= "До начала вебинара \"{$data['webinar_title']}\" осталось 15 минут!\n\n";
                $text .= "Ссылка на трансляцию: {$data['broadcast_url']}\n\n";
                $text .= "Войдите прямо сейчас, чтобы занять место.\n";
                break;

            case 'webinar_followup':
                $text .= "Спасибо за участие в вебинаре \"{$data['webinar_title']}\"!\n\n";
                if ($data['video_url']) {
                    $text .= "Смотреть запись: {$data['video_url']}\n\n";
                }
                $text .= "Скачать презентацию и подарок: https://disk.360.yandex.ru/d/zLDKwR2dmVUQ-g\n\n";
                $text .= "Заполнить анкету обратной связи: https://clck.ru/3Rktcu\n\n";
                $text .= "Получите именной сертификат на {$data['certificate_hours']} часа за {$data['certificate_price']} руб.\n";
                $text .= "Оформить: {$data['certificate_url']}\n\n";
                $text .= "Приглашаем на следующий вебинар «Как сохранить ресурс и не потерять качество работы при росте требований?»:\n";
                $text .= "5 марта 2026 в 14:00 МСК\n";
                $text .= "https://fgos.pro/vebinar/kak-sokhranit-resurs?utm_source=email&utm_campaign=pismoposle1veba\n";
                break;
        }

        $text .= "\n---\n";
        $text .= "С уважением,\nКоманда ФГОС-Практикум\n";
        $text .= "{$data['site_url']}\n\n";
        $text .= "Отписаться от рассылки: {$data['unsubscribe_url']}\n";

        return $text;
    }

    /**
     * Interpolate variables in email subject
     */
    private function interpolateSubject($subject, $data) {
        return str_replace(
            ['{webinar_title}', '{user_name}', '{webinar_date}', '{webinar_time}'],
            [$data['webinar_title'], $data['user_name'], $data['webinar_date'], $data['webinar_time']],
            $subject
        );
    }

    /**
     * Get all active touchpoints
     */
    public function getActiveTouchpoints() {
        return $this->db->query(
            "SELECT * FROM webinar_email_touchpoints
             WHERE is_active = 1
             ORDER BY display_order ASC"
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

        return $this->db->update('webinar_email_log', $data, 'id = ?', [$id]);
    }

    /**
     * Increment attempt counter
     */
    private function incrementAttempts($id) {
        return $this->db->execute(
            "UPDATE webinar_email_log SET attempts = attempts + 1, updated_at = NOW() WHERE id = ?",
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
                "SELECT COUNT(*) as count FROM webinar_email_log
                 WHERE status = 'sent' AND sent_at >= ?", [$since]
            )['count'],

            'total_pending' => $this->db->queryOne(
                "SELECT COUNT(*) as count FROM webinar_email_log WHERE status = 'pending'"
            )['count'],

            'total_failed' => $this->db->queryOne(
                "SELECT COUNT(*) as count FROM webinar_email_log
                 WHERE status = 'failed' AND created_at >= ?", [$since]
            )['count'],

            'by_touchpoint' => $this->db->query(
                "SELECT t.code, t.name,
                        COUNT(CASE WHEN wel.status = 'sent' THEN 1 END) as sent,
                        COUNT(CASE WHEN wel.status = 'pending' THEN 1 END) as pending,
                        COUNT(CASE WHEN wel.status = 'failed' THEN 1 END) as failed
                 FROM webinar_email_touchpoints t
                 LEFT JOIN webinar_email_log wel ON t.id = wel.touchpoint_id
                 GROUP BY t.id
                 ORDER BY t.display_order"
            )
        ];
    }

    /**
     * Reschedule emails when webinar time changes
     *
     * @param int $webinarId
     * @param string $newScheduledAt New datetime
     */
    public function rescheduleForWebinar($webinarId, $newScheduledAt) {
        $registrations = $this->db->query(
            "SELECT id FROM webinar_registrations WHERE webinar_id = ? AND status = 'registered'",
            [$webinarId]
        );

        $webinarTime = strtotime($newScheduledAt);
        $now = time();
        $updated = 0;

        foreach ($registrations as $reg) {
            $pendingEmails = $this->db->query(
                "SELECT wel.*, t.delay_minutes
                 FROM webinar_email_log wel
                 JOIN webinar_email_touchpoints t ON wel.touchpoint_id = t.id
                 WHERE wel.webinar_registration_id = ? AND wel.status = 'pending'",
                [$reg['id']]
            );

            foreach ($pendingEmails as $email) {
                $delayMinutes = (int)$email['delay_minutes'];
                $newScheduledAt = date('Y-m-d H:i:s', $webinarTime + ($delayMinutes * 60));

                // Skip if new time is in the past
                if (strtotime($newScheduledAt) < $now) {
                    $this->updateEmailStatus($email['id'], 'skipped', 'Rescheduled to past');
                } else {
                    $this->db->update('webinar_email_log',
                        ['scheduled_at' => $newScheduledAt],
                        'id = ?', [$email['id']]
                    );
                    $updated++;
                }
            }
        }

        $this->log("RESCHEDULE | Webinar {$webinarId} | Updated {$updated} emails");
        return $updated;
    }

    /**
     * Backfill missing touchpoints for upcoming webinars
     * Finds registrations that are missing email_log entries for active touchpoints
     * and creates them. This handles the case when a new touchpoint is added after
     * users have already registered.
     *
     * @return int Number of backfilled entries
     */
    public function backfillMissingTouchpoints() {
        $touchpoints = $this->getActiveTouchpoints();
        $now = time();
        $backfilled = 0;

        // Get upcoming/live webinars (scheduled or live)
        $webinars = $this->db->query(
            "SELECT id, scheduled_at, duration_minutes, status
             FROM webinars
             WHERE status IN ('scheduled', 'live')
               AND is_active = 1"
        );

        foreach ($webinars as $webinar) {
            $webinarTime = strtotime($webinar['scheduled_at']);
            $duration = (int)($webinar['duration_minutes'] ?? 60);
            $webinarEnd = $webinarTime + ($duration * 60);

            foreach ($touchpoints as $touchpoint) {
                $delayMinutes = (int)$touchpoint['delay_minutes'];

                // Calculate when this touchpoint should be sent
                if ($delayMinutes === 0) {
                    // Confirmation — skip backfill, it's handled at registration time
                    continue;
                }

                $scheduledAt = date('Y-m-d H:i:s', $webinarTime + ($delayMinutes * 60));

                // Skip touchpoints whose time has already passed
                // (except broadcast codes if webinar is still ongoing)
                if (strtotime($scheduledAt) < $now) {
                    $broadcastCodes = ['webinar_broadcast_link', 'webinar_reminder_15min'];
                    if (in_array($touchpoint['code'], $broadcastCodes) && $now < $webinarEnd) {
                        $scheduledAt = date('Y-m-d H:i:s'); // send immediately
                    } else {
                        continue;
                    }
                }

                // Find registrations missing this touchpoint
                $missingRegistrations = $this->db->query(
                    "SELECT wr.id, wr.email
                     FROM webinar_registrations wr
                     WHERE wr.webinar_id = ?
                       AND wr.status = 'registered'
                       AND wr.id NOT IN (
                           SELECT wel.webinar_registration_id
                           FROM webinar_email_log wel
                           WHERE wel.touchpoint_id = ?
                       )",
                    [$webinar['id'], $touchpoint['id']]
                );

                foreach ($missingRegistrations as $reg) {
                    if ($this->isUnsubscribed($reg['email'])) {
                        continue;
                    }

                    $this->db->insert('webinar_email_log', [
                        'webinar_registration_id' => $reg['id'],
                        'touchpoint_id' => $touchpoint['id'],
                        'email' => $reg['email'],
                        'status' => 'pending',
                        'scheduled_at' => $scheduledAt
                    ]);
                    $backfilled++;
                }
            }
        }

        if ($backfilled > 0) {
            $this->log("BACKFILL | Created {$backfilled} missing email entries");
        }

        return $backfilled;
    }

    /**
     * Log operations
     */
    private function log($message) {
        $logFile = BASE_PATH . '/logs/webinar-email-journey.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";

        $logDir = dirname($logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        error_log($logMessage, 3, $logFile);
    }
}
