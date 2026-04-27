<?php
/**
 * OlympiadEmailChain Class
 * Управляет email-цепочкой напоминаний для неоплаченных дипломов олимпиад
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/EmailCampaignDiscount.php';
require_once __DIR__ . '/LoyaltyDiscount.php';
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
                    r.olympiad_id, r.olympiad_result_id, r.placement, r.score, r.has_supervisor, r.supervisor_name,
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

            // Для финальной скидки (14d): пользователи с loyalty уже получают 25% —
            // не дразним их скидкой 15%, которая не применится.
            if (($email['touchpoint_code'] ?? '') === 'olymp_pay_14d'
                && LoyaltyDiscount::isEligible($this->pdo, (int)$email['user_id'])) {
                $this->updateEmailStatus($email['id'], 'skipped', 'User has loyalty discount — 14d offer not applicable');
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
            require_once BASE_PATH . '/includes/email-helper.php';
            $mail = new PHPMailer(true);
            configureBulkMailer($mail, $emailData['email']);
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
                'diploma_url' => SITE_URL . '/olimpiada-diplom/' . ($emailData['olympiad_result_id'] ?? ''),
                'unsubscribe_url' => $unsubscribeUrl,
                'site_url' => SITE_URL,
                'site_name' => SITE_NAME ?? 'Каменный город',
                'touchpoint_code' => $emailData['touchpoint_code'],
                'footer_reason' => 'прошли олимпиаду на нашем портале'
            ];

            // 14d: выписать персональную скидку 15% на 48 часов. Скидка применяется
            // автоматически в корзине через EmailCampaignDiscount::getActive().
            if (($emailData['touchpoint_code'] ?? '') === 'olymp_pay_14d') {
                $discountRate = 0.15;
                $discountHours = 48;
                try {
                    EmailCampaignDiscount::upsert(
                        $this->pdo,
                        'olymp_final_14d',
                        (int)$emailData['user_id'],
                        $emailData['email'],
                        $discountRate,
                        date('Y-m-d H:i:s', time() + $discountHours * 3600)
                    );
                    $this->log("DISCOUNT_UPSERT | User {$emailData['user_id']} | olymp_final_14d | rate={$discountRate} | expires +{$discountHours}h");
                } catch (\Exception $e) {
                    $this->log("DISCOUNT_ERROR | User {$emailData['user_id']} | " . $e->getMessage());
                }
                $templateData['discount_rate'] = $discountRate;
                $templateData['discount_hours'] = $discountHours;
            }

            $htmlBody = $this->renderTemplate($emailData['email_template'], $templateData);
            $textBody = $this->renderTextTemplate($templateData);

            $mail->isHTML(true);
            $subject = $this->interpolateSubject($emailData['email_subject'], $templateData);
            $mail->Subject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;

            $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
            $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            require_once BASE_PATH . '/classes/EmailTracker.php';
            EmailTracker::prepareAndSend($mail, [
                'email_type'      => 'olympiad',
                'touchpoint_code' => $emailData['touchpoint_code'],
                'chain_log_id'    => $emailData['id'],
                'chain_log_table' => 'olympiad_email_log',
                'user_id'         => $emailData['user_id'] ?? null,
                'recipient_email' => $emailData['email'],
                'unsubscribe_url' => $unsubscribeUrl,
            ]);

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

    // =====================================================================
    // Методы для quiz-писем (регистрация, прохождение теста)
    // =====================================================================

    /**
     * Отправить приветственное письмо + запланировать напоминание о незавершённом тесте
     * Вызывается из ajax/register-olympiad-participant.php
     */
    public function scheduleRegistrationEmails($userId, $olympiadId) {
        $data = $this->db->queryOne(
            "SELECT u.email, u.full_name, o.title as olympiad_title, o.slug as olympiad_slug
             FROM users u, olympiads o
             WHERE u.id = ? AND o.id = ?",
            [$userId, $olympiadId]
        );

        if (!$data || $this->isUnsubscribed($data['email'])) {
            return false;
        }

        // Мгновенное приветственное письмо
        $this->scheduleQuizEmail($userId, $olympiadId, null, $data['email'], 'reg_welcome', date('Y-m-d H:i:s'));

        // Напоминание через 1 час (если не начнёт тест)
        $this->scheduleQuizEmail($userId, $olympiadId, null, $data['email'], 'reg_reminder_1h', date('Y-m-d H:i:s', time() + 3600));

        $this->log("QUIZ_SCHEDULE | User {$userId} | Olympiad {$olympiadId} | reg_welcome + reg_reminder_1h");
        return true;
    }

    /**
     * Отправить письмо по результатам теста
     * Вызывается из ajax/submit-olympiad-quiz.php
     */
    public function scheduleQuizResultEmails($userId, $olympiadId, $resultId, $score, $placement) {
        $data = $this->db->queryOne(
            "SELECT u.email, u.full_name FROM users u WHERE u.id = ?",
            [$userId]
        );

        if (!$data || $this->isUnsubscribed($data['email'])) {
            return false;
        }

        // Отменить напоминание о тесте (тест пройден)
        $this->cancelQuizEmail($userId, $olympiadId, 'reg_reminder_1h');

        if ($placement) {
            // Успешно — мгновенное поздравление
            $this->scheduleQuizEmail($userId, $olympiadId, $resultId, $data['email'], 'quiz_success', date('Y-m-d H:i:s'));

            // Напоминание через 24ч если не заказал диплом
            $this->scheduleQuizEmail($userId, $olympiadId, $resultId, $data['email'], 'quiz_success_reminder_24h', date('Y-m-d H:i:s', time() + 86400));

            $this->log("QUIZ_RESULT | User {$userId} | Olympiad {$olympiadId} | SUCCESS score={$score} placement={$placement}");
        } else {
            // Неуспешно — утешительное письмо
            $this->scheduleQuizEmail($userId, $olympiadId, $resultId, $data['email'], 'quiz_fail', date('Y-m-d H:i:s'));

            $this->log("QUIZ_RESULT | User {$userId} | Olympiad {$olympiadId} | FAIL score={$score}");
        }

        return true;
    }

    /**
     * Отменить quiz-письмо при переходе на следующий этап
     */
    public function cancelQuizEmail($userId, $olympiadId, $emailType) {
        return $this->db->execute(
            "UPDATE olympiad_quiz_email_log
             SET status = 'skipped', updated_at = NOW()
             WHERE user_id = ? AND olympiad_id = ? AND email_type = ? AND status = 'pending'",
            [$userId, $olympiadId, $emailType]
        );
    }

    /**
     * Отменить напоминание о дипломе (когда заказал диплом)
     * Вызывается из ajax/save-olympiad-registration.php
     */
    public function cancelDiplomaReminder($userId, $olympiadId) {
        return $this->cancelQuizEmail($userId, $olympiadId, 'quiz_success_reminder_24h');
    }

    /**
     * Запланировать одно quiz-письмо
     */
    private function scheduleQuizEmail($userId, $olympiadId, $resultId, $email, $emailType, $scheduledAt) {
        $existing = $this->db->queryOne(
            "SELECT id FROM olympiad_quiz_email_log
             WHERE user_id = ? AND olympiad_id = ? AND email_type = ?",
            [$userId, $olympiadId, $emailType]
        );

        if ($existing) {
            return false;
        }

        return $this->db->insert('olympiad_quiz_email_log', [
            'user_id' => $userId,
            'olympiad_id' => $olympiadId,
            'olympiad_result_id' => $resultId,
            'email' => $email,
            'email_type' => $emailType,
            'status' => 'pending',
            'scheduled_at' => $scheduledAt
        ]);
    }

    /**
     * Обработка очереди quiz-писем (вызывается из cron вместе с основной очередью)
     */
    public function processQuizEmails() {
        $now = date('Y-m-d H:i:s');

        $pendingEmails = $this->db->query(
            "SELECT qel.*,
                    u.full_name,
                    o.title as olympiad_title, o.slug as olympiad_slug, o.diploma_price,
                    res.score, res.placement
             FROM olympiad_quiz_email_log qel
             JOIN users u ON qel.user_id = u.id
             JOIN olympiads o ON qel.olympiad_id = o.id
             LEFT JOIN olympiad_results res ON qel.olympiad_result_id = res.id
             WHERE qel.status = 'pending'
               AND qel.scheduled_at <= ?
               AND qel.attempts < ?
             ORDER BY qel.scheduled_at ASC
             LIMIT ?",
            [$now, self::MAX_ATTEMPTS, self::BATCH_SIZE]
        );

        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($pendingEmails as $email) {
            // Для напоминания о тесте — проверить, не прошёл ли уже тест
            if ($email['email_type'] === 'reg_reminder_1h') {
                $hasResult = $this->db->queryOne(
                    "SELECT id FROM olympiad_results WHERE user_id = ? AND olympiad_id = ?",
                    [$email['user_id'], $email['olympiad_id']]
                );
                if ($hasResult) {
                    $this->updateQuizEmailStatus($email['id'], 'skipped', 'User already completed quiz');
                    $results['skipped']++;
                    continue;
                }
            }

            // Для напоминания о дипломе — проверить, не заказал ли уже
            if ($email['email_type'] === 'quiz_success_reminder_24h') {
                $hasRegistration = $this->db->queryOne(
                    "SELECT id FROM olympiad_registrations WHERE user_id = ? AND olympiad_id = ?",
                    [$email['user_id'], $email['olympiad_id']]
                );
                if ($hasRegistration) {
                    $this->updateQuizEmailStatus($email['id'], 'skipped', 'User already ordered diploma');
                    $results['skipped']++;
                    continue;
                }
            }

            if ($this->isUnsubscribed($email['email'])) {
                $this->updateQuizEmailStatus($email['id'], 'skipped', 'User unsubscribed');
                $results['skipped']++;
                continue;
            }

            $success = $this->sendQuizEmail($email);

            if ($success) {
                $this->updateQuizEmailStatus($email['id'], 'sent');
                $results['sent']++;
            } else {
                $this->incrementQuizAttempts($email['id']);
                if ($email['attempts'] + 1 >= self::MAX_ATTEMPTS) {
                    $this->updateQuizEmailStatus($email['id'], 'failed', 'Max attempts reached');
                }
                $results['failed']++;
            }
        }

        if ($results['sent'] + $results['failed'] + $results['skipped'] > 0) {
            $this->log("QUIZ_PROCESS | Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}");
        }
        return $results;
    }

    /**
     * Отправить одно quiz-письмо
     */
    private function sendQuizEmail($emailData) {
        require_once BASE_PATH . '/vendor/autoload.php';

        $templateMap = [
            'reg_welcome' => ['template' => 'olympiad_reg_welcome', 'subject' => 'Добро пожаловать на олимпиаду!'],
            'reg_reminder_1h' => ['template' => 'olympiad_reg_reminder_1h', 'subject' => 'Олимпиада ждёт вас — начните тест!'],
            'quiz_success' => ['template' => 'olympiad_quiz_success', 'subject' => '{user_name}, поздравляем с {placement}! Ваш диплом готов к оформлению'],
            'quiz_success_reminder_24h' => ['template' => 'olympiad_quiz_success_reminder_24h', 'subject' => '{user_name}, ваш диплом за {placement} ждёт оформления'],
            'quiz_fail' => ['template' => 'olympiad_quiz_fail', 'subject' => 'Спасибо за участие в олимпиаде!'],
        ];

        $emailType = $emailData['email_type'];
        if (!isset($templateMap[$emailType])) {
            $this->log("ERROR | Unknown email_type: {$emailType}");
            return false;
        }

        $tplConfig = $templateMap[$emailType];

        try {
            require_once BASE_PATH . '/includes/email-helper.php';
            $mail = new PHPMailer(true);
            configureBulkMailer($mail, $emailData['email']);
            $mail->addAddress($emailData['email'], $emailData['full_name']);

            $unsubscribeToken = $this->getOrCreateUnsubscribeToken($emailData['email'], $emailData['user_id']);
            $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . $unsubscribeToken;

            $placement = $emailData['placement'] ?? '';
            $placementText = '';
            if ($placement == '1') $placementText = '1 место';
            elseif ($placement == '2') $placementText = '2 место';
            elseif ($placement == '3') $placementText = '3 место';

            $resultId = $emailData['olympiad_result_id'] ?? '';
            $diplomaUrl = $resultId ? (SITE_URL . '/olimpiada-diplom/' . $resultId) : SITE_URL . '/olimpiady/' . $emailData['olympiad_slug'];

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
                'olympiad_url' => SITE_URL . '/olimpiady/' . $emailData['olympiad_slug'],
                'diploma_url' => $diplomaUrl,
                'result_id' => $resultId,
                'unsubscribe_url' => $unsubscribeUrl,
                'site_url' => SITE_URL,
                'site_name' => SITE_NAME ?? 'Каменный город',
            ];

            $htmlBody = $this->renderTemplate($tplConfig['template'], $templateData);

            $subject = $this->interpolateSubject($tplConfig['subject'], $templateData);
            $mail->isHTML(true);
            $mail->Subject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
            $mail->Body = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], "\n", $htmlBody));

            $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
            $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            require_once BASE_PATH . '/classes/EmailTracker.php';
            EmailTracker::prepareAndSend($mail, [
                'email_type'      => 'olympiad',
                'touchpoint_code' => $emailType,
                'chain_log_id'    => $emailData['id'] ?? null,
                'chain_log_table' => 'olympiad_quiz_email_log',
                'user_id'         => $emailData['user_id'] ?? null,
                'recipient_email' => $emailData['email'],
                'unsubscribe_url' => $unsubscribeUrl,
            ]);

            $this->log("QUIZ_SENT | {$emailData['email']} | {$emailType} | Olympiad {$emailData['olympiad_id']}");
            return true;

        } catch (Exception $e) {
            $this->log("QUIZ_ERROR | {$emailData['email']} | {$emailType} | " . $e->getMessage());
            return false;
        }
    }

    private function updateQuizEmailStatus($id, $status, $errorMessage = null) {
        $data = ['status' => $status];
        if ($status === 'sent') $data['sent_at'] = date('Y-m-d H:i:s');
        if ($errorMessage) $data['error_message'] = $errorMessage;
        return $this->db->update('olympiad_quiz_email_log', $data, 'id = ?', [$id]);
    }

    private function incrementQuizAttempts($id) {
        return $this->db->execute(
            "UPDATE olympiad_quiz_email_log SET attempts = attempts + 1 WHERE id = ?",
            [$id]
        );
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
