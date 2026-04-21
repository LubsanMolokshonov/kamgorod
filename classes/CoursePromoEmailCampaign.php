<?php
/**
 * CoursePromoEmailCampaign
 * Персонализированная промо-рассылка курсов повышения квалификации/переподготовки.
 * Подбирает релевантный курс для каждого пользователя на основе 3-уровневой аудиторной сегментации.
 */

require_once __DIR__ . '/Database.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class CoursePromoEmailCampaign {
    private Database $db;
    private $pdo;
    private const MAX_ATTEMPTS = 3;
    private const BATCH_SIZE = 50;
    private const INTER_EMAIL_DELAY = 2; // секунды между письмами

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
    }

    /**
     * Подобрать лучший курс для пользователя
     * Каскадный матчинг: специализации → тип учреждения → категория → fallback
     */
    public function findBestCourseForUser(int $userId): ?array {
        $user = $this->db->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user) return null;

        // Уровень 3: совпадение по специализациям
        $specIds = $this->db->query(
            "SELECT specialization_id FROM user_specializations WHERE user_id = ?",
            [$userId]
        );

        if (!empty($specIds)) {
            $ids = array_column($specIds, 'specialization_id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $course = $this->db->queryOne(
                "SELECT c.*, COUNT(cs.specialization_id) as spec_matches
                 FROM courses c
                 JOIN course_specializations cs ON c.id = cs.course_id
                 WHERE cs.specialization_id IN ({$placeholders})
                   AND c.is_active = 1
                 GROUP BY c.id
                 ORDER BY spec_matches DESC, c.display_order ASC
                 LIMIT 1",
                $ids
            );

            if ($course) {
                return [
                    'course' => $course,
                    'match_level' => 3,
                    'match_score' => (int)$course['spec_matches']
                ];
            }
        }

        // Уровень 2: совпадение по типу учреждения
        if (!empty($user['institution_type_id'])) {
            $course = $this->db->queryOne(
                "SELECT c.*
                 FROM courses c
                 JOIN course_audience_types cat ON c.id = cat.course_id
                 WHERE cat.audience_type_id = ?
                   AND c.is_active = 1
                 ORDER BY c.display_order ASC
                 LIMIT 1",
                [$user['institution_type_id']]
            );

            if ($course) {
                return [
                    'course' => $course,
                    'match_level' => 2,
                    'match_score' => 1
                ];
            }
        }

        // Уровень 1: совпадение по категории аудитории
        if (!empty($user['audience_category_id'])) {
            $course = $this->db->queryOne(
                "SELECT c.*
                 FROM courses c
                 JOIN course_audience_categories cac ON c.id = cac.course_id
                 WHERE cac.category_id = ?
                   AND c.is_active = 1
                 ORDER BY c.display_order ASC
                 LIMIT 1",
                [$user['audience_category_id']]
            );

            if ($course) {
                return [
                    'course' => $course,
                    'match_level' => 1,
                    'match_score' => 1
                ];
            }
        }

        // Уровень 0: fallback — первый активный курс
        $course = $this->db->queryOne(
            "SELECT * FROM courses WHERE is_active = 1 ORDER BY display_order ASC LIMIT 1"
        );

        if ($course) {
            return [
                'course' => $course,
                'match_level' => 0,
                'match_score' => 0
            ];
        }

        return null;
    }

    /**
     * Заполнить очередь рассылки для всех пользователей.
     * Массовый SQL: 3 INSERT-а по уровням матчинга (spec → type → category),
     * затем fallback для оставшихся.
     */
    public function scheduleAllUsers(): array {
        // Fallback course ID
        $fallbackCourse = $this->db->queryOne(
            "SELECT id FROM courses WHERE is_active = 1 ORDER BY display_order ASC LIMIT 1"
        );
        $fallbackId = $fallbackCourse ? $fallbackCourse['id'] : null;

        if (!$fallbackId) {
            $this->log("SCHEDULE | ERROR: no active courses found");
            return ['scheduled' => 0, 'skipped_unsubscribed' => 0, 'skipped_no_course' => 1, 'already_scheduled' => 0];
        }

        // Уровень 3: матчинг по специализациям (лучший курс = макс совпадений)
        $this->db->execute(
            "INSERT IGNORE INTO course_promo_emails (user_id, email, course_id, match_level, match_score, status)
             SELECT sub.user_id, sub.email, sub.course_id, 3, sub.spec_matches, 'pending'
             FROM (
                 SELECT u.id as user_id, u.email, cs.course_id,
                        COUNT(cs.specialization_id) as spec_matches,
                        ROW_NUMBER() OVER (PARTITION BY u.id ORDER BY COUNT(cs.specialization_id) DESC, c.display_order ASC) as rn
                 FROM users u
                 JOIN user_specializations us ON u.id = us.user_id
                 JOIN course_specializations cs ON us.specialization_id = cs.specialization_id
                 JOIN courses c ON cs.course_id = c.id AND c.is_active = 1
                 WHERE u.email IS NOT NULL AND u.email != ''
                   AND u.id NOT IN (SELECT user_id FROM course_promo_emails)
                   AND u.email NOT IN (SELECT email FROM email_unsubscribes)
                 GROUP BY u.id, u.email, cs.course_id
             ) sub
             WHERE sub.rn = 1"
        );
        $level3 = $this->db->queryOne("SELECT COUNT(*) as cnt FROM course_promo_emails WHERE match_level = 3")['cnt'];
        $this->log("SCHEDULE | Level 3 (specialization): {$level3} users");

        // Уровень 2: матчинг по типу учреждения
        $this->db->execute(
            "INSERT IGNORE INTO course_promo_emails (user_id, email, course_id, match_level, match_score, status)
             SELECT sub.user_id, sub.email, sub.course_id, 2, 1, 'pending'
             FROM (
                 SELECT u.id as user_id, u.email, cat.course_id,
                        ROW_NUMBER() OVER (PARTITION BY u.id ORDER BY c.display_order ASC) as rn
                 FROM users u
                 JOIN course_audience_types cat ON u.institution_type_id = cat.audience_type_id
                 JOIN courses c ON cat.course_id = c.id AND c.is_active = 1
                 WHERE u.email IS NOT NULL AND u.email != ''
                   AND u.institution_type_id IS NOT NULL
                   AND u.id NOT IN (SELECT user_id FROM course_promo_emails)
                   AND u.email NOT IN (SELECT email FROM email_unsubscribes)
             ) sub
             WHERE sub.rn = 1"
        );
        $level2 = $this->db->queryOne("SELECT COUNT(*) as cnt FROM course_promo_emails WHERE match_level = 2")['cnt'];
        $this->log("SCHEDULE | Level 2 (type): {$level2} users total");

        // Уровень 1: матчинг по категории аудитории
        $this->db->execute(
            "INSERT IGNORE INTO course_promo_emails (user_id, email, course_id, match_level, match_score, status)
             SELECT sub.user_id, sub.email, sub.course_id, 1, 1, 'pending'
             FROM (
                 SELECT u.id as user_id, u.email, cac.course_id,
                        ROW_NUMBER() OVER (PARTITION BY u.id ORDER BY c.display_order ASC) as rn
                 FROM users u
                 JOIN course_audience_categories cac ON u.audience_category_id = cac.category_id
                 JOIN courses c ON cac.course_id = c.id AND c.is_active = 1
                 WHERE u.email IS NOT NULL AND u.email != ''
                   AND u.audience_category_id IS NOT NULL
                   AND u.id NOT IN (SELECT user_id FROM course_promo_emails)
                   AND u.email NOT IN (SELECT email FROM email_unsubscribes)
             ) sub
             WHERE sub.rn = 1"
        );
        $level1 = $this->db->queryOne("SELECT COUNT(*) as cnt FROM course_promo_emails WHERE match_level = 1")['cnt'];
        $this->log("SCHEDULE | Level 1 (category): {$level1} users total");

        // Уровень 0: fallback для всех оставшихся
        $this->db->execute(
            "INSERT IGNORE INTO course_promo_emails (user_id, email, course_id, match_level, match_score, status)
             SELECT u.id, u.email, ?, 0, 0, 'pending'
             FROM users u
             WHERE u.email IS NOT NULL AND u.email != ''
               AND u.id NOT IN (SELECT user_id FROM course_promo_emails)
               AND u.email NOT IN (SELECT email FROM email_unsubscribes)",
            [$fallbackId]
        );

        $total = $this->db->queryOne("SELECT COUNT(*) as cnt FROM course_promo_emails")['cnt'];
        $byLevel = $this->db->query("SELECT match_level, COUNT(*) as cnt FROM course_promo_emails GROUP BY match_level ORDER BY match_level DESC");

        $levelStats = [];
        foreach ($byLevel as $row) {
            $levelStats[$row['match_level']] = $row['cnt'];
        }

        $this->log("SCHEDULE | DONE | Total: {$total} | L3(spec): " . ($levelStats[3] ?? 0)
            . " | L2(type): " . ($levelStats[2] ?? 0)
            . " | L1(cat): " . ($levelStats[1] ?? 0)
            . " | L0(fallback): " . ($levelStats[0] ?? 0));

        return [
            'scheduled' => $total,
            'skipped_unsubscribed' => 0,
            'skipped_no_course' => 0,
            'already_scheduled' => 0,
            'by_level' => $levelStats
        ];
    }

    /**
     * Обработать один batch писем
     */
    public function processBatch(): array {
        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        $pending = $this->db->query(
            "SELECT cpe.*, u.full_name,
                    c.title as course_title, c.slug as course_slug,
                    c.description as course_description, c.hours as course_hours,
                    c.price as course_price, c.program_type as course_program_type,
                    c.target_audience_text
             FROM course_promo_emails cpe
             JOIN users u ON cpe.user_id = u.id
             JOIN courses c ON cpe.course_id = c.id
             WHERE cpe.status = 'pending'
               AND cpe.attempts < ?
             ORDER BY cpe.id ASC
             LIMIT ?",
            [self::MAX_ATTEMPTS, self::BATCH_SIZE]
        );

        foreach ($pending as $emailData) {
            // Проверка отписки
            if ($this->isUnsubscribed($emailData['email'])) {
                $this->updateStatus($emailData['id'], 'skipped', 'User unsubscribed');
                $results['skipped']++;
                continue;
            }

            $success = $this->sendPromoEmail($emailData);

            if ($success) {
                $this->updateStatus($emailData['id'], 'sent');
                $results['sent']++;
            } else {
                $this->incrementAttempts($emailData['id']);
                if ($emailData['attempts'] + 1 >= self::MAX_ATTEMPTS) {
                    $this->updateStatus($emailData['id'], 'failed', 'Max attempts reached');
                }
                $results['failed']++;
            }

            // Задержка между письмами
            if (self::INTER_EMAIL_DELAY > 0) {
                sleep(self::INTER_EMAIL_DELAY);
            }
        }

        $this->log("BATCH | Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}");
        return $results;
    }

    /**
     * Отправить тестовое письмо
     */
    public function sendTestEmail(string $toEmail, ?int $userId = null): array {
        // Найти пользователя для теста
        if ($userId) {
            $user = $this->db->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
        } else {
            // Взять пользователя с наиболее заполненным профилем
            $user = $this->db->queryOne(
                "SELECT u.* FROM users u
                 LEFT JOIN user_specializations us ON u.id = us.user_id
                 WHERE u.audience_category_id IS NOT NULL
                    OR u.institution_type_id IS NOT NULL
                 GROUP BY u.id
                 ORDER BY COUNT(us.specialization_id) DESC
                 LIMIT 1"
            );
        }

        if (!$user) {
            $user = $this->db->queryOne("SELECT * FROM users ORDER BY id ASC LIMIT 1");
        }

        if (!$user) {
            return ['success' => false, 'error' => 'No users found in database'];
        }

        $match = $this->findBestCourseForUser($user['id']);
        if (!$match) {
            return ['success' => false, 'error' => 'No active courses found'];
        }

        $course = $match['course'];

        // Подготовить данные как для реальной отправки
        $emailData = [
            'id' => 0,
            'user_id' => $user['id'],
            'email' => $toEmail, // Отправляем на тестовый адрес
            'full_name' => $user['full_name'],
            'course_id' => $course['id'],
            'course_title' => $course['title'],
            'course_slug' => $course['slug'],
            'course_description' => $course['description'],
            'course_hours' => $course['hours'],
            'course_price' => $course['price'],
            'course_program_type' => $course['program_type'],
            'target_audience_text' => $course['target_audience_text'] ?? '',
            'match_level' => $match['match_level'],
            'match_score' => $match['match_score'],
            'attempts' => 0
        ];

        $success = $this->sendPromoEmail($emailData);

        return [
            'success' => $success,
            'user' => $user['full_name'] . ' (' . $user['email'] . ')',
            'user_id' => $user['id'],
            'course' => $course['title'],
            'course_id' => $course['id'],
            'match_level' => $match['match_level'],
            'match_score' => $match['match_score'],
            'sent_to' => $toEmail
        ];
    }

    /**
     * Отправить одно промо-письмо
     */
    private function sendPromoEmail(array $emailData): bool {
        require_once BASE_PATH . '/vendor/autoload.php';

        try {
            $mail = new PHPMailer(true);

            // SMTP настройки (паттерн из EmailJourney)
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

            // Unsubscribe headers (RFC 8058)
            $unsubscribeToken = $this->generateUnsubscribeToken($emailData['email']);
            $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . $unsubscribeToken;

            $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
            $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            // Тема письма
            $programLabel = $emailData['course_program_type'] === 'pp'
                ? 'Профессиональная переподготовка'
                : 'Повышение квалификации';
            $subject = $programLabel . ': ' . mb_substr($emailData['course_title'], 0, 60);

            $mail->isHTML(true);
            $mail->Subject = mb_encode_mimeheader($subject, 'UTF-8', 'B');

            // Рендер шаблона
            $templateData = [
                'user_name' => $emailData['full_name'],
                'user_email' => $emailData['email'],
                'course_title' => $emailData['course_title'],
                'course_description' => $emailData['course_description'] ?? '',
                'course_hours' => $emailData['course_hours'],
                'course_price' => $emailData['course_price'],
                'course_program_type' => $emailData['course_program_type'],
                'course_url' => SITE_URL . '/kursy/' . $emailData['course_slug'] . '/',
                'unsubscribe_url' => $unsubscribeUrl,
                'site_url' => SITE_URL,
                'site_name' => SITE_NAME ?? 'Каменный город',
                'footer_reason' => 'зарегистрированы на нашей платформе'
            ];

            $mail->Body = $this->renderTemplate('course_promo', $templateData);
            $mail->AltBody = $this->renderTextVersion($templateData);

            require_once BASE_PATH . '/classes/EmailTracker.php';
            EmailTracker::prepareAndSend($mail, [
                'email_type'      => 'course_promo',
                'touchpoint_code' => $emailData['touchpoint_code'] ?? 'course_promo',
                'chain_log_id'    => $emailData['id'] ?? null,
                'chain_log_table' => 'course_promo_email_log',
                'user_id'         => $emailData['user_id'] ?? null,
                'recipient_email' => $emailData['email'],
                'unsubscribe_url' => $unsubscribeUrl,
            ]);

            $this->log("SENT | {$emailData['email']} | Course: {$emailData['course_title']}");
            return true;

        } catch (Exception $e) {
            $this->log("ERROR | {$emailData['email']} | " . $e->getMessage());
            if ($emailData['id'] > 0) {
                $this->updateStatus($emailData['id'], 'pending', $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Рендер HTML-шаблона
     */
    private function renderTemplate(string $templateName, array $data): string {
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
     * Текстовая версия письма
     */
    private function renderTextVersion(array $data): string {
        $programLabel = $data['course_program_type'] === 'pp'
            ? 'Профессиональная переподготовка'
            : 'Повышение квалификации';
        $price = number_format($data['course_price'], 0, ',', ' ');

        $text = "Здравствуйте, {$data['user_name']}!\n\n";
        $text .= "Рекомендуем вам курс {$programLabel}:\n";
        $text .= "{$data['course_title']}\n";
        $text .= "Объём: {$data['course_hours']} часов\n";
        $text .= "Стоимость: {$price} руб.\n\n";
        $text .= "С 1 сентября 2025 года изменились правила повышения квалификации ";
        $text .= "(Федеральный закон от 21.04.2025 № 86-ФЗ).\n\n";
        $text .= "Почему «ФГОС-практикум»:\n";
        $text .= "- ООО «Едурегионлаб» — участник проекта Сколково\n";
        $text .= "- Разрешение Фонда «Сколково» № 068 на образовательную деятельность\n";
        $text .= "- Удостоверение установленного образца\n";
        $text .= "- Данные вносятся в ФИС ФРДО в течение 30 дней\n";
        $text .= "- Действующая лицензия на образовательную деятельность\n\n";
        $text .= "Записаться: {$data['course_url']}\n\n";
        $text .= "---\n";
        $text .= "С уважением,\nКоманда «ФГОС-практикум»\n\n";
        $text .= "Отписаться от рассылки: {$data['unsubscribe_url']}\n";

        return $text;
    }

    /**
     * Статистика рассылки
     */
    public function getStats(): array {
        $total = $this->db->queryOne("SELECT COUNT(*) as cnt FROM course_promo_emails")['cnt'] ?? 0;
        $byStatus = $this->db->query(
            "SELECT status, COUNT(*) as cnt FROM course_promo_emails GROUP BY status"
        );
        $byMatchLevel = $this->db->query(
            "SELECT match_level, COUNT(*) as cnt FROM course_promo_emails GROUP BY match_level ORDER BY match_level DESC"
        );

        $statusMap = [];
        foreach ($byStatus as $row) {
            $statusMap[$row['status']] = (int)$row['cnt'];
        }

        $matchMap = [];
        $matchLabels = [0 => 'fallback', 1 => 'category', 2 => 'type', 3 => 'specialization'];
        foreach ($byMatchLevel as $row) {
            $label = $matchLabels[$row['match_level']] ?? 'unknown';
            $matchMap[$label] = (int)$row['cnt'];
        }

        return [
            'total' => $total,
            'by_status' => $statusMap,
            'by_match_level' => $matchMap
        ];
    }

    // --- Вспомогательные методы (паттерн из EmailJourney) ---

    private function isUnsubscribed(string $email): bool {
        $result = $this->db->queryOne(
            "SELECT id FROM email_unsubscribes WHERE email = ?",
            [$email]
        );
        return !empty($result);
    }

    private function generateUnsubscribeToken(string $email): string {
        $hash = substr(md5($email . SITE_URL), 0, 16);
        return base64_encode($email . ':' . $hash);
    }

    private function updateStatus(int $id, string $status, ?string $error = null): void {
        $data = ['status' => $status];
        if ($status === 'sent') {
            $data['sent_at'] = date('Y-m-d H:i:s');
        }
        if ($error) {
            $data['error_message'] = $error;
        }
        $this->db->update('course_promo_emails', $data, 'id = ?', [$id]);
    }

    private function incrementAttempts(int $id): void {
        $this->db->execute(
            "UPDATE course_promo_emails SET attempts = attempts + 1 WHERE id = ?",
            [$id]
        );
    }

    private function log(string $message): void {
        $logFile = BASE_PATH . '/logs/course-promo.log';
        $timestamp = date('Y-m-d H:i:s');
        $logDir = dirname($logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        error_log("[{$timestamp}] {$message}\n", 3, $logFile);
    }
}
