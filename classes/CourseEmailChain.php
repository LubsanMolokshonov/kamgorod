<?php
/**
 * CourseEmailChain
 * Email-цепочка дожима для неоплаченных записей на курсы.
 * Touchpoints: welcome (0), 15мин, 1ч, 90мин (bitrix_only), 24ч (+скидка), 2д (+скидка), 3д (+скидка).
 * Синхронизация стадий Bitrix24 при отправке каждого письма.
 * Мониторинг сделок в ЦДО: деактивация цепочки при прохождении «Подготовка документов».
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
        require_once BASE_PATH . '/includes/email-helper.php';
        if (chainEmailsPaused()) {
            $this->log("PROCESS | PAUSED until " . CHAINS_PAUSED_UNTIL . " — skip");
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'paused' => true];
        }

        $now = date('Y-m-d H:i:s');

        $pendingEmails = $this->db->query(
            "SELECT cel.*,
                    t.email_subject, t.email_template, t.code AS touchpoint_code,
                    t.delay_minutes, t.bitrix_stage_id, t.bitrix_only,
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

            // bitrix_only touchpoint — только перевод стадии, без отправки email
            if (!empty($email['bitrix_only'])) {
                if (!empty($email['bitrix_stage_id'])) {
                    $this->moveBitrixStage($email['enrollment_id'], $email['bitrix_stage_id']);
                }
                $this->updateEmailStatus($email['id'], 'sent');
                $results['sent']++;
                $this->log("BITRIX_ONLY | {$email['touchpoint_code']} | Enrollment #{$email['enrollment_id']}");
                continue;
            }

            if ($this->isUnsubscribed($email['email'])) {
                $this->updateEmailStatus($email['id'], 'skipped', 'User unsubscribed');
                $results['skipped']++;
                continue;
            }

            if (recipientRecentlyEmailed($this->pdo, $email['email'], CHAIN_MIN_INTERVAL_MINUTES)) {
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
            require_once BASE_PATH . '/includes/email-helper.php';
            $mail = new PHPMailer(true);
            configureBulkMailer($mail, $emailData['email']);
            $mail->addAddress($emailData['email'], $emailData['full_name']);

            // Unsubscribe
            $unsubscribeToken = $this->generateUnsubscribeToken($emailData['email']);
            $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . urlencode($unsubscribeToken);

            // Цена с учётом скидки (фиксированная / AB-тест)
            $abVariant = $emailData['ab_variant'] ?? 'A';
            $basePrice = floatval($emailData['course_price']);
            if (class_exists('CoursePriceAB')) {
                $abPrice = CoursePriceAB::getAdjustedPrice($basePrice, $abVariant, $emailData['course_program_type'] ?? null);
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

            // Яндекс жёстко фильтрует «красивый» HTML — отправляем plain-text,
            // ссылки сохраняем как есть (см. memory/project_payment_success_plaintext.md)
            $textBody = $this->renderTextTemplate($templateData, $emailData['email_template']);

            $subject = $this->interpolateSubject($emailData['email_subject'], $templateData);

            $mail->isHTML(false);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
            $mail->Body    = $textBody;

            $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
            $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            require_once BASE_PATH . '/classes/EmailTracker.php';
            EmailTracker::prepareAndSend($mail, [
                'email_type'      => 'course',
                'touchpoint_code' => $emailData['touchpoint_code'],
                'chain_log_id'    => $emailData['id'],
                'chain_log_table' => 'course_email_log',
                'user_id'         => $emailData['user_id'] ?? null,
                'recipient_email' => $emailData['email'],
                'unsubscribe_url' => $unsubscribeUrl,
            ]);

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
            require_once BASE_PATH . '/includes/email-helper.php';
            $mail = new PHPMailer(true);
            configureBulkMailer($mail, $enrollment['email']);
            $mail->addAddress($enrollment['email'], $enrollment['full_name']);

            // Цена с учётом скидки
            $abVariant = $enrollment['ab_variant'] ?? 'A';
            $basePrice = floatval($enrollment['course_price']);
            if (class_exists('CoursePriceAB')) {
                $abPrice = CoursePriceAB::getAdjustedPrice($basePrice, $abVariant, $enrollment['course_program_type'] ?? null);
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

            $templateData['payment_amount'] = $abPrice;
            $templateData['order_number']   = $orderNumber;
            $textBody = $this->renderTextTemplate($templateData, 'course_payment_success');

            $subject = 'Оплата курса «' . mb_substr($enrollment['course_title'], 0, 60) . '» подтверждена';

            $mail->isHTML(false);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
            $mail->Body    = $textBody;

            $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
            $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            require_once BASE_PATH . '/classes/EmailTracker.php';
            EmailTracker::prepareAndSend($mail, [
                'email_type'      => 'course',
                'touchpoint_code' => 'course_payment_success',
                'chain_log_id'    => null,
                'chain_log_table' => null,
                'user_id'         => $enrollment['user_id'] ?? null,
                'recipient_email' => $enrollment['email'],
                'unsubscribe_url' => $unsubscribeUrl,
            ]);

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
    //  Мониторинг ЦДО: деактивация email при прогрессе сделки
    // ──────────────────────────────────────────────

    /**
     * Проверить сделки, переведённые на менеджера (стадия «Перевод на менеджера»).
     * Если сделка в ЦДО прошла дальше этапа «Подготовка документов» — отменить email-цепочку.
     *
     * @return array ['cancelled' => int, 'checked' => int]
     */
    public function checkCdoDealsAndCancelEmails() {
        $managerStage = defined('BITRIX24_COURSE_STAGE_MANAGER') ? BITRIX24_COURSE_STAGE_MANAGER : 'C108:UC_DLXNLQ';
        $cdoDocsSort  = defined('BITRIX24_CDO_STAGE_DOCS_SORT') ? BITRIX24_CDO_STAGE_DOCS_SORT : 80;

        // Найти записи, у которых сделка была переведена на менеджера и есть pending emails
        $enrollments = $this->db->query(
            "SELECT DISTINCT ce.id AS enrollment_id, ce.bitrix_lead_id, ce.bitrix_stage
             FROM course_enrollments ce
             INNER JOIN course_email_log cel ON cel.enrollment_id = ce.id AND cel.status = 'pending'
             WHERE ce.status = 'new'
               AND ce.bitrix_lead_id IS NOT NULL
               AND ce.bitrix_stage = ?",
            [$managerStage]
        );

        if (empty($enrollments)) {
            return ['cancelled' => 0, 'checked' => 0];
        }

        require_once __DIR__ . '/Bitrix24Integration.php';
        $bitrix = new Bitrix24Integration();

        if (!$bitrix->isConfigured()) {
            return ['cancelled' => 0, 'checked' => 0];
        }

        // Загрузить стадии ЦДО для определения порядка (SORT)
        $cdoStages = $this->getCdoStagesSort($bitrix);
        if (empty($cdoStages)) {
            $this->log("CDO_CHECK | Failed to load ЦДО stages");
            return ['cancelled' => 0, 'checked' => 0];
        }

        $results = ['cancelled' => 0, 'checked' => 0];

        foreach ($enrollments as $enr) {
            $results['checked']++;

            $deal = $bitrix->getDeal($enr['bitrix_lead_id']);
            if (!$deal) {
                continue;
            }

            $dealStage = $deal['STAGE_ID'] ?? '';
            $dealCategory = $deal['CATEGORY_ID'] ?? '';

            // Сделка перешла в ЦДО (pipeline 4)?
            $cdoPipelineId = defined('BITRIX24_CDO_PIPELINE_ID') ? BITRIX24_CDO_PIPELINE_ID : 4;
            if ((int)$dealCategory !== (int)$cdoPipelineId) {
                continue;
            }

            // Проверить, прошла ли стадию «Подготовка документов» (sort > cdoDocsSort)
            $dealSort = $cdoStages[$dealStage] ?? 0;
            if ($dealSort > $cdoDocsSort) {
                $cancelled = $this->cancelForEnrollment($enr['enrollment_id']);
                $this->db->update('course_enrollments', [
                    'bitrix_stage' => $dealStage,
                ], 'id = ?', [$enr['enrollment_id']]);

                $this->log("CDO_CANCEL | Enrollment #{$enr['enrollment_id']} | Deal #{$enr['bitrix_lead_id']} at {$dealStage} (sort {$dealSort}) > docs (sort {$cdoDocsSort}) | Cancelled {$cancelled} emails");
                $results['cancelled']++;
            }
        }

        $this->log("CDO_CHECK | Checked: {$results['checked']}, Cancelled: {$results['cancelled']}");
        return $results;
    }

    /**
     * Получить маппинг стадий ЦДО: STATUS_ID → SORT
     */
    private function getCdoStagesSort($bitrix) {
        $cdoPipelineId = defined('BITRIX24_CDO_PIPELINE_ID') ? BITRIX24_CDO_PIPELINE_ID : 4;

        $url = rtrim(defined('BITRIX24_WEBHOOK_URL') ? BITRIX24_WEBHOOK_URL : '', '/');
        if (empty($url)) {
            return [];
        }

        $response = @file_get_contents($url . '/crm.dealcategory.stage.list.json?' . http_build_query(['id' => $cdoPipelineId]));
        if ($response === false) {
            return [];
        }

        $data = json_decode($response, true);
        if (empty($data['result'])) {
            return [];
        }

        $map = [];
        foreach ($data['result'] as $stage) {
            $map[$stage['STATUS_ID']] = (int)$stage['SORT'];
        }
        return $map;
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

    /**
     * Plain-text версия письма по имени шаблона (Яндекс блокирует HTML с богатой вёрсткой).
     * Сохраняет все ссылки и UTM-метки.
     */
    private function renderTextTemplate(array $data, string $templateName): string {
        $name        = $data['user_name'] ?? '';
        $title       = $data['course_title'] ?? '';
        $hours       = (int)($data['course_hours'] ?? 0);
        $price       = number_format((float)($data['course_price'] ?? 0), 0, ',', ' ');
        $progLabel   = $data['program_label'] ?? '';
        $docLabel    = $data['document_label'] ?? '';
        $progType    = $data['course_program_type'] ?? '';
        $courseUrl   = $data['course_url'] ?? '';
        $payUrl      = $data['payment_url'] ?? '';
        $discUrl     = $data['discount_url'] ?? null;
        $discPrice   = $data['discount_price'] ?? null;
        $unsubUrl    = $data['unsubscribe_url'] ?? '';
        $cabinetUrl  = $data['cabinet_url'] ?? null;
        $orderNumber = $data['order_number'] ?? '';
        $payAmount   = isset($data['payment_amount'])
            ? number_format((float)$data['payment_amount'], 0, ',', ' ')
            : $price;

        $docName = $progType === 'pp' ? 'диплом' : 'удостоверение';

        $appendUtm = function (string $url, string $campaign): string {
            if ($url === '') return '';
            $sep = strpos($url, '?') !== false ? '&' : '?';
            return $url . $sep . 'utm_source=email&utm_campaign=' . $campaign;
        };

        $signature  = "---\n";
        $signature .= "С уважением,\nКоманда ФГОС-Практикум\n";
        $signature .= "ООО «Едурегионлаб» — участник проекта «Сколково»,\n";
        $signature .= "разрешение Фонда «Сколково» № 068.\n\n";
        $signature .= "Отписаться от рассылки: {$unsubUrl}\n";

        switch ($templateName) {
            case 'course_enroll_welcome': {
                $link = $appendUtm($payUrl, 'course-enroll-welcome');
                $t  = "Здравствуйте, {$name}!\n\n";
                $t .= "Заявка на курс принята. Осталось завершить оплату, чтобы начать обучение.\n\n";
                $t .= "Курс: {$title}\n";
                $t .= "Программа: {$progLabel}\n";
                $t .= "Объём: {$hours} часов\n";
                $t .= "Формат: заочная с применением ДОТ\n";
                $t .= "Документ: {$docLabel}\n";
                $t .= "Стоимость: {$price} руб.\n\n";
                $t .= "Перейти к оплате:\n{$link}\n\n";
                $t .= "Страница курса: {$courseUrl}\n\n";
                $t .= "Если есть вопросы — просто ответьте на это письмо.\n\n";
                return $t . $signature;
            }

            case 'course_enroll_15min': {
                $link = $appendUtm($payUrl, 'course-enroll-15min');
                $t  = "Здравствуйте, {$name}!\n\n";
                $t .= "Ваше место на курсе «{$title}» забронировано.\n";
                $t .= "Чтобы приступить к обучению, нужно завершить оплату.\n\n";
                $t .= "Программа: {$progLabel}\n";
                $t .= "Объём: {$hours} часов\n";
                $t .= "Документ: {$docLabel}\n";
                $t .= "Стоимость: {$price} руб.\n\n";
                $t .= "Что даёт {$docName}:\n";
                $t .= "- соответствие требованиям аттестации и Рособрнадзора;\n";
                $t .= "- подтверждение квалификации перед работодателем;\n";
                $t .= "- запись в ФИС ФРДО;\n";
                $t .= "- основание для надбавок и карьерного роста.\n\n";
                $t .= "Оплатить курс:\n{$link}\n\n";
                $t .= "Страница курса: {$courseUrl}\n\n";
                return $t . $signature;
            }

            case 'course_enroll_1h': {
                $link = $appendUtm($payUrl, 'course-enroll-1h');
                $t  = "Здравствуйте, {$name}!\n\n";
                $t .= "Вы оставили заявку на курс «{$title}», но ещё не завершили оплату.\n\n";
                $t .= "Внимание: с 1 сентября 2025 года изменились правила повышения квалификации\n";
                $t .= "(Федеральный закон от 21.04.2025 № 86-ФЗ — новая ч. 5.2 ст. 47 273-ФЗ).\n\n";
                $t .= "Риски обучения в неуполномоченных организациях:\n";
                $t .= "- документ не примут при аттестации и проверке Рособрнадзора;\n";
                $t .= "- работодатель вправе не засчитать повышение квалификации;\n";
                $t .= "- потеря денег и времени — придётся переучиваться заново.\n\n";
                $t .= "Почему «ФГОС-практикум» — надёжный выбор:\n";
                $t .= "- разрешение Фонда «Сколково» № 068;\n";
                $t .= "- данные вносятся в ФИС ФРДО в течение 30 дней;\n";
                $t .= "- {$docLabel} принимают при любой проверке.\n\n";
                $t .= "Курс: {$title} ({$hours} ч., {$price} руб., дистанционно)\n\n";
                $t .= "Записаться на обучение:\n{$link}\n\n";
                $t .= "Основание: ч. 5.2 ст. 47 ФЗ от 29.12.2012 № 273-ФЗ (в ред. ФЗ от 21.04.2025 № 86-ФЗ),\n";
                $t .= "Постановление Правительства РФ № 850.\n\n";
                return $t . $signature;
            }

            case 'course_enroll_24h': {
                $url  = $discUrl ?: $payUrl;
                $link = $appendUtm($url, 'course-enroll-24h');
                $disc = $discPrice !== null ? number_format((float)$discPrice, 0, ',', ' ') : $price;
                $t  = "Здравствуйте, {$name}!\n\n";
                $t .= "Вчера вы подали заявку на курс «{$title}», но не завершили оплату.\n";
                $t .= "Мы подготовили для вас персональную скидку 10% — она действует 48 часов.\n\n";
                $t .= "Программа: {$progLabel}\n";
                $t .= "Объём: {$hours} часов\n";
                $t .= "Документ: {$docLabel}\n";
                $t .= "Обычная цена: {$price} руб.\n";
                $t .= "Цена со скидкой 10%: {$disc} руб.\n\n";
                $t .= "Оплатить со скидкой:\n{$link}\n\n";
                $t .= "Скидка действует 48 часов — после этого стоимость вернётся к {$price} руб.\n\n";
                return $t . $signature;
            }

            case 'course_enroll_2d': {
                $url  = $discUrl ?: $payUrl;
                $link = $appendUtm($url, 'course-enroll-2d');
                $disc = $discPrice !== null ? number_format((float)$discPrice, 0, ',', ' ') : $price;
                $t  = "Здравствуйте, {$name}!\n\n";
                $t .= "Напоминаем: ваша персональная скидка 10% на курс «{$title}» ещё активна.\n";
                $t .= "Осталось менее 24 часов до её сгорания.\n\n";
                $t .= "Программа: {$progLabel} ({$hours} ч., дистанционно)\n";
                $t .= "Обычная цена: {$price} руб.\n";
                $t .= "Цена со скидкой: {$disc} руб.\n\n";
                $t .= "Воспользоваться скидкой:\n{$link}\n\n";
                $t .= "Почему педагоги выбирают нас:\n";
                $t .= "- более 28 000 педагогов уже прошли обучение;\n";
                $t .= "- лицензия на образовательную деятельность + разрешение Фонда «Сколково»;\n";
                $t .= "- данные о {$docName} вносятся в ФИС ФРДО;\n";
                $t .= "- учитесь в любое время, из любого места.\n\n";
                return $t . $signature;
            }

            case 'course_enroll_3d': {
                $url  = $discUrl ?: $payUrl;
                $link = $appendUtm($url, 'course-enroll-3d');
                $disc = $discPrice !== null ? number_format((float)$discPrice, 0, ',', ' ') : $price;
                $save = $discPrice !== null
                    ? number_format((float)$data['course_price'] - (float)$discPrice, 0, ',', ' ')
                    : '';
                $t  = "Здравствуйте, {$name}!\n\n";
                $t .= "Это последнее напоминание: ваша скидка 10% на курс «{$title}» истекает сегодня.\n";
                $t .= "После этого стоимость вернётся к полной цене.\n\n";
                $t .= "Программа: {$progLabel}\n";
                $t .= "Объём: {$hours} часов\n";
                $t .= "Документ: {$docLabel}\n";
                $t .= "Обычная цена: {$price} руб.\n";
                $t .= "Цена со скидкой: {$disc} руб.\n";
                if ($save !== '') {
                    $t .= "Вы экономите: {$save} руб.\n";
                }
                $t .= "\nОплатить сейчас со скидкой:\n{$link}\n\n";
                $t .= "Преимущества:\n";
                $t .= "- участник проекта «Сколково», разрешение № 068;\n";
                $t .= "- документ вносится в ФИС ФРДО — примут при любой проверке;\n";
                $t .= "- дистанционное обучение без отрыва от работы;\n";
                $t .= "- более 28 000 педагогов уже прошли обучение.\n\n";
                return $t . $signature;
            }

            case 'course_payment_success': {
                $link = $appendUtm($cabinetUrl ?? '', 'course-payment-success');
                $t  = "Здравствуйте, {$name}!\n\n";
                $t .= "Оплата прошла успешно — благодарим вас!\n";
                $t .= "Ваш доступ к курсу активирован.\n\n";
                if ($orderNumber !== '') {
                    $t .= "Заказ: {$orderNumber}\n";
                }
                $t .= "Сумма: {$payAmount} руб.\n\n";
                $t .= "Курс: {$title}\n";
                $t .= "Программа: {$progLabel}\n";
                $t .= "Объём: {$hours} часов\n";
                $t .= "Формат: заочная с применением ДОТ\n";
                $t .= "Документ: {$docLabel}\n\n";
                $t .= "Что будет дальше:\n";
                $t .= "1. Доступ к учебным материалам — наш методист свяжется с вами для организации доступа.\n";
                $t .= "2. Обучение в удобном темпе — изучайте материалы дистанционно, без отрыва от работы.\n";
                $t .= "3. Получение {$docName} — {$docLabel} с внесением данных в ФИС ФРДО в течение 30 дней.\n\n";
                if ($link !== '') {
                    $t .= "Перейти в личный кабинет:\n{$link}\n\n";
                }
                $t .= "Если возникнут вопросы — просто ответьте на это письмо.\n\n";
                return $t . $signature;
            }
        }

        // Fallback на случай нового touchpoint
        $t  = "Здравствуйте, {$name}!\n\n";
        $t .= "Вы подали заявку на курс «{$title}» ({$hours} ч., {$price} руб.).\n";
        if ($discUrl) {
            $disc = $discPrice !== null ? number_format((float)$discPrice, 0, ',', ' ') : $price;
            $t .= "Цена со скидкой: {$disc} руб.\n";
            $t .= "Оплатить со скидкой: {$discUrl}\n\n";
        } else {
            $t .= "Перейти к оплате: {$payUrl}\n\n";
        }
        return $t . $signature;
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
