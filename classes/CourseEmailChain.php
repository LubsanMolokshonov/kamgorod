<?php
/**
 * CourseEmailChain
 * Email-цепочка дожима для неоплаченных записей на курсы.
 * 6 писем: welcome (0), 15мин, 1ч, 24ч (+скидка), 2д (+скидка), 3д (+скидка).
 * Синхронизация стадий Bitrix24 при отправке каждого письма.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class CourseEmailChain {
    private $db;
    private $pdo;
    private const MAX_ATTEMPTS = 3;
    private const BATCH_SIZE = 50;
    private const MAX_AGE_DAYS = 7;
    /** Письма с delay_minutes >= этого порога содержат скидку */
    private const DISCOUNT_THRESHOLD_MINUTES = 1440;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
    }

    // ──────────────────────────────────────────────
    //  Планирование / отмена
    // ──────────────────────────────────────────────

    /**
     * Запланировать все touchpoints для новой записи на курс
     */
    public function scheduleForEnrollment($enrollmentId) {
        $enrollment = $this->db->queryOne(
            "SELECT ce.*, c.title AS course_title
             FROM course_enrollments ce
             JOIN courses c ON ce.course_id = c.id
             WHERE ce.id = ? AND ce.status = 'new'",
            [$enrollmentId]
        );

        if (!$enrollment) {
            $this->log("SKIP | Enrollment #{$enrollmentId} not found or not 'new'");
            return false;
        }

        if ($this->isUnsubscribed($enrollment['email'])) {
            $this->log("SKIP | {$enrollment['email']} is unsubscribed");
            return false;
        }

        $touchpoints = $this->getActiveTouchpoints();
        $scheduledCount = 0;

        foreach ($touchpoints as $tp) {
            $scheduledAt = date('Y-m-d H:i:s',
                strtotime($enrollment['created_at']) + ($tp['delay_minutes'] * 60)
            );

            $existing = $this->db->queryOne(
                "SELECT id FROM course_email_log WHERE enrollment_id = ? AND touchpoint_id = ?",
                [$enrollmentId, $tp['id']]
            );
            if ($existing) {
                continue;
            }

            $this->db->insert('course_email_log', [
                'enrollment_id' => $enrollmentId,
                'user_id'       => $enrollment['user_id'],
                'touchpoint_id' => $tp['id'],
                'email'         => $enrollment['email'],
                'status'        => 'pending',
                'scheduled_at'  => $scheduledAt,
            ]);
            $scheduledCount++;
        }

        $this->log("SCHEDULE | Enrollment #{$enrollmentId} | {$scheduledCount} touchpoints");
        return $scheduledCount;
    }

    /**
     * Отменить все неотправленные письма (при оплате)
     */
    public function cancelForEnrollment($enrollmentId) {
        $result = $this->db->execute(
            "UPDATE course_email_log SET status = 'skipped', updated_at = NOW()
             WHERE enrollment_id = ? AND status = 'pending'",
            [$enrollmentId]
        );
        $this->log("CANCEL | Enrollment #{$enrollmentId} | Skipped {$result} pending emails");
        return $result;
    }

    // ──────────────────────────────────────────────
    //  Cron: обработка очереди
    // ──────────────────────────────────────────────

    /**
     * Отправить все письма, у которых наступило время
     */
    public function processPendingEmails() {
        $now = date('Y-m-d H:i:s');

        $pendingEmails = $this->db->query(
            "SELECT cel.*,
                    t.email_subject, t.email_template, t.code AS touchpoint_code,
                    t.delay_minutes, t.bitrix_stage_id,
                    ce.course_id, ce.full_name, ce.user_id, ce.ab_variant,
                    c.title AS course_title, c.price AS course_price,
                    c.hours AS course_hours, c.program_type AS course_program_type,
                    c.slug AS course_slug
             FROM course_email_log cel
             JOIN course_email_touchpoints t ON cel.touchpoint_id = t.id
             JOIN course_enrollments ce ON cel.enrollment_id = ce.id
             JOIN courses c ON ce.course_id = c.id
             WHERE cel.status = 'pending'
               AND cel.scheduled_at <= ?
               AND cel.attempts < ?
             ORDER BY cel.scheduled_at ASC
             LIMIT ?",
            [$now, self::MAX_ATTEMPTS, self::BATCH_SIZE]
        );

        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($pendingEmails as $email) {
            // Перепроверить статус записи
            $enrollment = $this->db->queryOne(
                "SELECT status FROM course_enrollments WHERE id = ?",
                [$email['enrollment_id']]
            );

            if (!$enrollment || $enrollment['status'] !== 'new') {
                $this->updateEmailStatus($email['id'], 'skipped', 'Enrollment paid or cancelled');
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

                // Двинуть стадию Bitrix24
                if (!empty($email['bitrix_stage_id'])) {
                    $this->moveBitrixStage($email['enrollment_id'], $email['bitrix_stage_id']);
                }
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

    // ──────────────────────────────────────────────
    //  Отправка письма
    // ──────────────────────────────────────────────

    private function sendChainEmail($emailData) {
        require_once BASE_PATH . '/vendor/autoload.php';

        try {
            $mail = new PHPMailer(true);

            $mail->isSMTP();
            $mail->Host    = SMTP_HOST;
            $mail->Port    = SMTP_PORT;
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
                $mail->SMTPAuth   = false;
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($emailData['email'], $emailData['full_name']);

            // Unsubscribe
            $unsubscribeToken = $this->generateUnsubscribeToken($emailData['email']);
            $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . urlencode($unsubscribeToken);

            // Цена с учётом скидки (фиксированная / AB-тест)
            $abVariant = $emailData['ab_variant'] ?? 'A';
            $basePrice = floatval($emailData['course_price']);
            if (class_exists('CoursePriceAB')) {
                $abPrice = CoursePriceAB::getAdjustedPrice($basePrice, $abVariant);
            } else {
                $abPrice = $basePrice;
            }

            // Magic-ссылка на кабинет с оплатой
            $paymentUrl = generateMagicUrl($emailData['user_id'], '/kabinet/?tab=courses');

            // Ссылка со скидкой (для писем 24ч, 2д, 3д)
            $discountUrl = null;
            $discountPrice = null;
            if ($emailData['delay_minutes'] >= self::DISCOUNT_THRESHOLD_MINUTES) {
                $discountToken = self::generateDiscountToken($emailData['enrollment_id'], 48);
                $discountUrl = generateMagicUrl(
                    $emailData['user_id'],
                    '/kabinet/?tab=courses&discount_token=' . urlencode($discountToken)
                );
                $discountPrice = round($abPrice * 0.9);
            }

            $programLabel = $emailData['course_program_type'] === 'pp'
                ? 'Профессиональная переподготовка'
                : 'Повышение квалификации';

            $documentLabel = $emailData['course_program_type'] === 'pp'
                ? 'Диплом о профессиональной переподготовке'
                : 'Удостоверение о повышении квалификации';

            $templateData = [
                'user_name'           => $emailData['full_name'],
                'user_email'          => $emailData['email'],
                'user_id'             => $emailData['user_id'],
                'course_title'        => $emailData['course_title'],
                'course_price'        => $abPrice,
                'course_hours'        => $emailData['course_hours'],
                'course_program_type' => $emailData['course_program_type'],
                'program_label'       => $programLabel,
                'document_label'      => $documentLabel,
                'course_url'          => SITE_URL . '/kursy/' . $emailData['course_slug'] . '/',
                'payment_url'         => $paymentUrl,
                'discount_url'        => $discountUrl,
                'discount_price'      => $discountPrice,
                'unsubscribe_url'     => $unsubscribeUrl,
                'site_url'            => SITE_URL,
                'site_name'           => defined('SITE_NAME') ? SITE_NAME : 'ФГОС-Практикум',
                'footer_reason'       => 'Вы получили это письмо, потому что подали заявку на курс на портале fgos.pro',
            ];

            $htmlBody = $this->renderTemplate($emailData['email_template'], $templateData);
            $textBody = $this->renderTextTemplate($templateData, $emailData['delay_minutes']);

            $subject = $this->interpolateSubject($emailData['email_subject'], $templateData);

            $mail->isHTML(true);
            $mail->Subject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody;

            $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
            $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            $mail->send();

            $this->log("SENT | {$emailData['email']} | {$emailData['touchpoint_code']} | Enrollment #{$emailData['enrollment_id']}");
            return true;

        } catch (Exception $e) {
            $this->log("ERROR | {$emailData['email']} | {$emailData['touchpoint_code']} | " . $e->getMessage());
            $this->updateEmailStatus($emailData['id'], 'pending', $e->getMessage());
            return false;
        }
    }

    // ──────────────────────────────────────────────
    //  Bitrix24
    // ──────────────────────────────────────────────

    private function moveBitrixStage($enrollmentId, $bitrixStageId) {
        try {
            $enrollment = $this->db->queryOne(
                "SELECT bitrix_lead_id FROM course_enrollments WHERE id = ?",
                [$enrollmentId]
            );

            if (empty($enrollment['bitrix_lead_id'])) {
                $this->log("BITRIX_SKIP | Enrollment #{$enrollmentId} | No deal ID yet");
                return;
            }

            require_once __DIR__ . '/Bitrix24Integration.php';
            $bitrix = new Bitrix24Integration();

            if (!$bitrix->isConfigured()) {
                return;
            }

            $bitrix->moveDeal($enrollment['bitrix_lead_id'], $bitrixStageId);
            $this->db->update('course_enrollments', [
                'bitrix_stage' => $bitrixStageId,
            ], 'id = ?', [$enrollmentId]);

            $this->log("BITRIX | Enrollment #{$enrollmentId} | Deal #{$enrollment['bitrix_lead_id']} → {$bitrixStageId}");

        } catch (\Exception $e) {
            $this->log("BITRIX_ERROR | Enrollment #{$enrollmentId} | " . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────
    //  Письмо об успешной оплате
    // ──────────────────────────────────────────────

    /**
     * Отправить письмо-подтверждение оплаты курса
     */
    public function sendPaymentConfirmation($enrollmentId, $orderNumber = '') {
        $enrollment = $this->db->queryOne(
            "SELECT ce.*, c.title AS course_title, c.price AS course_price,
                    c.hours AS course_hours, c.program_type AS course_program_type,
                    c.slug AS course_slug
             FROM course_enrollments ce
             JOIN courses c ON ce.course_id = c.id
             WHERE ce.id = ?",
            [$enrollmentId]
        );

        if (!$enrollment) {
            $this->log("PAY_CONFIRM_SKIP | Enrollment #{$enrollmentId} not found");
            return false;
        }

        require_once BASE_PATH . '/vendor/autoload.php';

        try {
            $mail = new PHPMailer(true);

            $mail->isSMTP();
            $mail->Host    = SMTP_HOST;
            $mail->Port    = SMTP_PORT;
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
                $mail->SMTPAuth   = false;
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($enrollment['email'], $enrollment['full_name']);

            // Цена с учётом скидки
            $abVariant = $enrollment['ab_variant'] ?? 'A';
            $basePrice = floatval($enrollment['course_price']);
            if (class_exists('CoursePriceAB')) {
                $abPrice = CoursePriceAB::getAdjustedPrice($basePrice, $abVariant);
            } else {
                $abPrice = $basePrice;
            }

            $programLabel = $enrollment['course_program_type'] === 'pp'
                ? 'Профессиональная переподготовка'
                : 'Повышение квалификации';

            $documentLabel = $enrollment['course_program_type'] === 'pp'
                ? 'Диплом о профессиональной переподготовке'
                : 'Удостоверение о повышении квалификации';

            $cabinetUrl = generateMagicUrl($enrollment['user_id'], '/kabinet/?tab=courses');

            $unsubscribeToken = $this->generateUnsubscribeToken($enrollment['email']);
            $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . urlencode($unsubscribeToken);

            $templateData = [
                'user_name'           => $enrollment['full_name'],
                'course_title'        => $enrollment['course_title'],
                'course_price'        => $abPrice,
                'course_hours'        => $enrollment['course_hours'],
                'course_program_type' => $enrollment['course_program_type'],
                'program_label'       => $programLabel,
                'document_label'      => $documentLabel,
                'course_url'          => SITE_URL . '/kursy/' . $enrollment['course_slug'] . '/',
                'cabinet_url'         => $cabinetUrl,
                'order_number'        => $orderNumber,
                'unsubscribe_url'     => $unsubscribeUrl,
                'site_url'            => SITE_URL,
                'site_name'           => defined('SITE_NAME') ? SITE_NAME : 'ФГОС-Практикум',
                'footer_reason'       => 'Вы получили это письмо, потому что оплатили курс на портале fgos.pro',
            ];

            $htmlBody = $this->renderTemplate('course_payment_success', $templateData);

            $textBody  = "Здравствуйте, {$enrollment['full_name']}!\n\n";
            $textBody .= "Оплата курса «{$enrollment['course_title']}» прошла успешно.\n";
            $textBody .= "Заказ: {$orderNumber}\n";
            $textBody .= "Сумма: " . number_format($abPrice, 0, ',', ' ') . " руб.\n\n";
            $textBody .= "Личный кабинет: {$cabinetUrl}\n\n";
            $textBody .= "С уважением,\nКоманда ФГОС-Практикум\n";

            $subject = 'Оплата курса «' . mb_substr($enrollment['course_title'], 0, 60) . '» подтверждена';

            $mail->isHTML(true);
            $mail->Subject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody;

            $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
            $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            $mail->send();

            $this->log("PAY_CONFIRM | {$enrollment['email']} | Enrollment #{$enrollmentId} | Order {$orderNumber}");

            // Bitrix24: перевести сделку в «Сделка успешна» (C108:WON)
            $wonStage = defined('BITRIX24_COURSE_STAGE_WON') ? BITRIX24_COURSE_STAGE_WON : 'C108:WON';
            $this->moveBitrixStage($enrollmentId, $wonStage);

            return true;

        } catch (Exception $e) {
            $this->log("PAY_CONFIRM_ERROR | {$enrollment['email']} | " . $e->getMessage());
            return false;
        }
    }

    // ──────────────────────────────────────────────
    //  Скидка: HMAC-токен
    // ──────────────────────────────────────────────

    /**
     * Сгенерировать подписанный токен скидки
     * @param int $enrollmentId
     * @param int $validityHours Срок действия в часах
     * @return string base64url-encoded токен
     */
    public static function generateDiscountToken($enrollmentId, $validityHours = 48) {
        $expiry  = time() + ($validityHours * 3600);
        $payload = $enrollmentId . ':' . $expiry;
        $secret  = defined('COURSE_EMAIL_DISCOUNT_SECRET') && COURSE_EMAIL_DISCOUNT_SECRET !== ''
            ? COURSE_EMAIL_DISCOUNT_SECRET
            : (defined('MAGIC_LINK_SECRET') ? MAGIC_LINK_SECRET : 'fallback');
        $hmac = hash_hmac('sha256', $payload, $secret);

        return base64url_encode($payload . ':' . $hmac);
    }

    /**
     * Валидировать токен скидки
     * @param string $token
     * @return int|false enrollment_id или false
     */
    public static function validateDiscountToken($token) {
        if (empty($token)) {
            return false;
        }

        $decoded = base64url_decode($token);
        if ($decoded === false) {
            return false;
        }

        $parts = explode(':', $decoded);
        if (count($parts) !== 3) {
            return false;
        }

        list($enrollmentId, $expiry, $hmac) = $parts;

        if (time() > (int)$expiry) {
            return false;
        }

        $payload = $enrollmentId . ':' . $expiry;
        $secret  = defined('COURSE_EMAIL_DISCOUNT_SECRET') && COURSE_EMAIL_DISCOUNT_SECRET !== ''
            ? COURSE_EMAIL_DISCOUNT_SECRET
            : (defined('MAGIC_LINK_SECRET') ? MAGIC_LINK_SECRET : 'fallback');
        $expected = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $hmac)) {
            return false;
        }

        return (int)$enrollmentId;
    }

    // ──────────────────────────────────────────────
    //  Вспомогательные
    // ──────────────────────────────────────────────

    private function getActiveTouchpoints() {
        return $this->db->query(
            "SELECT * FROM course_email_touchpoints WHERE is_active = 1 ORDER BY delay_minutes ASC"
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
        return $this->db->update('course_email_log', $data, 'id = ?', [$id]);
    }

    private function incrementAttempts($id) {
        return $this->db->execute(
            "UPDATE course_email_log SET attempts = attempts + 1 WHERE id = ?",
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

    private function renderTextTemplate($data, $delayMinutes = 0) {
        $text  = "Здравствуйте, {$data['user_name']}!\n\n";
        $text .= "Вы подали заявку на курс «{$data['course_title']}».\n";
        $text .= "Объём: {$data['course_hours']} часов\n";
        $text .= "Стоимость: " . number_format($data['course_price'], 0, ',', ' ') . " руб.\n\n";

        if ($delayMinutes >= self::DISCOUNT_THRESHOLD_MINUTES && $data['discount_url']) {
            $text .= "Специально для вас — скидка 10%!\n";
            $text .= "Цена со скидкой: " . number_format($data['discount_price'], 0, ',', ' ') . " руб.\n";
            $text .= "Оплатить со скидкой: {$data['discount_url']}\n\n";
        } else {
            $text .= "Перейти к оплате: {$data['payment_url']}\n\n";
        }

        $text .= "---\n";
        $text .= "С уважением,\nКоманда ФГОС-Практикум\n\n";
        $text .= "Отписаться: {$data['unsubscribe_url']}\n";

        return $text;
    }

    private function interpolateSubject($subject, $data) {
        return str_replace(
            ['{course_title}', '{user_name}'],
            [$data['course_title'], $data['user_name']],
            $subject
        );
    }

    private function log($message) {
        $logFile = (defined('BASE_PATH') ? BASE_PATH : __DIR__ . '/..') . '/logs/course-email-chain.log';
        $logDir  = dirname($logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        error_log('[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", 3, $logFile);
    }
}
