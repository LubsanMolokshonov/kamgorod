<?php
/**
 * SilentReengagementCampaign — реактивация «молчащих» пользователей (нет
 * полученных писем за 30 дней). Одно персональное письмо на пользователя,
 * никаких цепочек. Включает скидку 10% до указанной даты.
 *
 * Архитектура:
 *   - plan()  — собирает всех подходящих пользователей в silent_reengagement_log
 *               со статусом 'pending' и создаёт скидку в email_campaign_discounts.
 *   - send($limit) — отправляет до $limit писем в статусе pending.
 *
 * Паттерн (SMTP, unsubscribe, шаблон) — из CoursePromoEmailCampaign.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/EmailCampaignDiscount.php';
require_once __DIR__ . '/EmailTracker.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class SilentReengagementCampaign {
    public const CAMPAIGN_CODE = 'silent_reengagement_10';
    public const DISCOUNT_RATE = 0.10;
    public const SILENT_DAYS = 30;

    private Database $db;
    private PDO $pdo;
    private string $expiresAt;
    private string $expiresLabel;

    /**
     * @param string $expiresAt 'YYYY-MM-DD HH:MM:SS' — дедлайн скидки
     */
    public function __construct(PDO $pdo, string $expiresAt) {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
        $this->expiresAt = $expiresAt;
        $ts = strtotime($expiresAt);
        $monthsRu = [1=>'января',2=>'февраля',3=>'марта',4=>'апреля',5=>'мая',6=>'июня',7=>'июля',8=>'августа',9=>'сентября',10=>'октября',11=>'ноября',12=>'декабря'];
        $this->expiresLabel = (int)date('j', $ts) . ' ' . $monthsRu[(int)date('n', $ts)];
    }

    /**
     * Построить список получателей и записать в silent_reengagement_log
     * со статусом 'pending'. Идемпотентно (UNIQUE KEY campaign_code,user_id).
     *
     * @return array ['candidates' => N, 'inserted' => N, 'discounts_created' => N]
     */
    public function plan(): array {
        $sql = "
            SELECT u.id, u.email, u.full_name, u.audience_category_id, u.created_at,
                   CASE
                     WHEN EXISTS(SELECT 1 FROM registrations r WHERE r.user_id=u.id AND r.status IN ('paid','diploma_ready')) THEN 'A'
                     WHEN EXISTS(SELECT 1 FROM publications p WHERE p.user_id=u.id) THEN 'B'
                     WHEN EXISTS(SELECT 1 FROM course_enrollments e WHERE e.user_id=u.id) THEN 'C'
                     WHEN EXISTS(SELECT 1 FROM olympiad_registrations r WHERE r.user_id=u.id) THEN 'D'
                     WHEN EXISTS(SELECT 1 FROM webinar_registrations r WHERE r.user_id=u.id) THEN 'E'
                     WHEN EXISTS(SELECT 1 FROM registrations r WHERE r.user_id=u.id) THEN 'F'
                     ELSE 'G'
                   END AS segment
            FROM users u
            WHERE u.email NOT IN (SELECT email FROM email_unsubscribes)
              AND NOT EXISTS (SELECT 1 FROM email_journey_log l WHERE l.email=u.email AND l.status='sent' AND l.sent_at >= DATE_SUB(NOW(), INTERVAL " . self::SILENT_DAYS . " DAY))
              AND NOT EXISTS (SELECT 1 FROM webinar_email_log l WHERE l.email=u.email AND l.status='sent' AND l.sent_at >= DATE_SUB(NOW(), INTERVAL " . self::SILENT_DAYS . " DAY))
              AND NOT EXISTS (SELECT 1 FROM publication_email_log l WHERE l.email=u.email AND l.status='sent' AND l.sent_at >= DATE_SUB(NOW(), INTERVAL " . self::SILENT_DAYS . " DAY))
              AND NOT EXISTS (SELECT 1 FROM course_email_log l WHERE l.email=u.email AND l.status='sent' AND l.sent_at >= DATE_SUB(NOW(), INTERVAL " . self::SILENT_DAYS . " DAY))
              AND NOT EXISTS (SELECT 1 FROM autowebinar_email_log l WHERE l.email=u.email AND l.status='sent' AND l.sent_at >= DATE_SUB(NOW(), INTERVAL " . self::SILENT_DAYS . " DAY))
              AND NOT EXISTS (SELECT 1 FROM olympiad_email_log l WHERE l.email=u.email AND l.status='sent' AND l.sent_at >= DATE_SUB(NOW(), INTERVAL " . self::SILENT_DAYS . " DAY))
        ";

        $candidates = $this->db->query($sql);
        $inserted = 0;
        $discountsCreated = 0;

        $scheduledAt = date('Y-m-d H:i:s');

        foreach ($candidates as $u) {
            $exists = $this->db->queryOne(
                "SELECT id FROM silent_reengagement_log WHERE campaign_code=? AND user_id=?",
                [self::CAMPAIGN_CODE, $u['id']]
            );
            if (!$exists) {
                $this->db->insert('silent_reengagement_log', [
                    'campaign_code' => self::CAMPAIGN_CODE,
                    'user_id' => $u['id'],
                    'email' => $u['email'],
                    'segment' => $u['segment'],
                    'status' => 'pending',
                    'scheduled_at' => $scheduledAt,
                ]);
                $inserted++;
            }
            EmailCampaignDiscount::upsert($this->pdo, self::CAMPAIGN_CODE, (int)$u['id'], $u['email'], self::DISCOUNT_RATE, $this->expiresAt);
            $discountsCreated++;
        }

        return [
            'candidates' => count($candidates),
            'inserted' => $inserted,
            'discounts_created' => $discountsCreated,
        ];
    }

    /**
     * Отправить до $limit писем в статусе pending. Повторяет safety-фильтр:
     * если за время ожидания пользователь получил другое письмо или отписался —
     * строка помечается как 'skipped'.
     *
     * @return array ['sent'=>N, 'skipped'=>N, 'failed'=>N]
     */
    public function send(int $limit, bool $dryRun = false): array {
        $rows = $this->db->query(
            "SELECT id, user_id, email, segment FROM silent_reengagement_log
              WHERE campaign_code=? AND status='pending'
              ORDER BY id ASC LIMIT " . (int)$limit,
            [self::CAMPAIGN_CODE]
        );

        $stats = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            if ($this->shouldSkip($row['email'])) {
                $this->updateStatus((int)$row['id'], 'skipped', 'Recent activity or unsubscribed');
                $stats['skipped']++;
                continue;
            }

            $user = $this->db->queryOne(
                "SELECT id, email, full_name, audience_category_id FROM users WHERE id=?",
                [$row['user_id']]
            );
            if (!$user) {
                $this->updateStatus((int)$row['id'], 'skipped', 'User not found');
                $stats['skipped']++;
                continue;
            }

            try {
                if ($dryRun) {
                    echo "[DRY] {$user['email']} segment={$row['segment']}\n";
                    $this->updateStatus((int)$row['id'], 'pending');
                    $stats['sent']++;
                    continue;
                }

                $ok = $this->sendOne((int)$row['id'], $user, $row['segment']);
                if ($ok) {
                    $this->updateStatus((int)$row['id'], 'sent');
                    $stats['sent']++;
                } else {
                    $this->updateStatus((int)$row['id'], 'failed', 'send returned false');
                    $stats['failed']++;
                }
            } catch (\Throwable $e) {
                $this->log("ERROR | {$user['email']} | " . $e->getMessage());
                $this->updateStatus((int)$row['id'], 'failed', $e->getMessage());
                $stats['failed']++;
            }
        }

        return $stats;
    }

    private function shouldSkip(string $email): bool {
        // Отписка
        $u = $this->db->queryOne("SELECT id FROM email_unsubscribes WHERE email=?", [$email]);
        if ($u) return true;
        // Любое письмо за 30 дней
        $days = self::SILENT_DAYS;
        $tables = ['email_journey_log','webinar_email_log','publication_email_log','course_email_log','autowebinar_email_log','olympiad_email_log'];
        foreach ($tables as $t) {
            $r = $this->db->queryOne(
                "SELECT 1 FROM $t WHERE email=? AND status='sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL $days DAY) LIMIT 1",
                [$email]
            );
            if ($r) return true;
        }
        return false;
    }

    private function sendOne(int $logId, array $user, string $segment): bool {
        require_once BASE_PATH . '/includes/magic-link-helper.php';

        $templateData = $this->buildTemplateData($user, $segment);

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
        $mail->addAddress($user['email'], $user['full_name']);

        $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . $this->generateUnsubscribeToken($user['email']);
        $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $subject = 'Скидка ' . (int)($templateData['discount_percent']) . '% до ' . $templateData['discount_expires_label'] . ' — специально для вас';
        $mail->isHTML(true);
        $mail->Subject = mb_encode_mimeheader($subject, 'UTF-8', 'B');

        $templateData['unsubscribe_url'] = $unsubscribeUrl;
        $mail->Body = $this->renderTemplate('silent_reengagement', $templateData);
        $mail->AltBody = $this->renderTextVersion($templateData);

        EmailTracker::prepareAndSend($mail, [
            'email_type'      => 'silent_reengagement',
            'touchpoint_code' => self::CAMPAIGN_CODE,
            'chain_log_id'    => $logId,
            'chain_log_table' => 'silent_reengagement_log',
            'user_id'         => $user['id'],
            'recipient_email' => $user['email'],
            'unsubscribe_url' => $unsubscribeUrl,
        ]);

        $this->log("SENT | {$user['email']} | segment={$segment}");
        return true;
    }

    private function buildTemplateData(array $user, string $segment): array {
        $name = trim((string)$user['full_name']) ?: 'коллега';
        $audienceId = $user['audience_category_id'] ?? null;

        $magicUrl = function_exists('generateMagicUrl')
            ? generateMagicUrl((int)$user['id'], '/korzina/')
            : SITE_URL . '/korzina/';

        [$headline, $intro, $recommendations, $primaryCtaLabel, $primaryCtaPath] = $this->buildSegmentContent($segment, $audienceId);

        $magicPrimary = function_exists('generateMagicUrl') && $primaryCtaPath
            ? generateMagicUrl((int)$user['id'], $primaryCtaPath)
            : $magicUrl;

        return [
            'user_name' => $name,
            'site_url' => SITE_URL,
            'site_name' => defined('SITE_NAME') ? SITE_NAME : 'ФГОС-Практикум',
            'discount_percent' => (int)round(self::DISCOUNT_RATE * 100),
            'discount_expires_label' => $this->expiresLabel,
            'magic_login_url' => $magicPrimary,
            'primary_cta_url' => $magicPrimary,
            'primary_cta_label' => $primaryCtaLabel,
            'segment_code' => $segment,
            'headline' => $headline,
            'intro_text' => $intro,
            'recommendations' => $recommendations,
            'footer_reason' => 'зарегистрированы на нашем портале fgos.pro',
        ];
    }

    /**
     * Контент под сегмент — headline, intro, список рекомендаций (до 3), CTA.
     * @return array [headline, intro, recommendations, cta_label, cta_path]
     */
    private function buildSegmentContent(string $segment, ?int $audienceId): array {
        $audienceId = $audienceId ?: 1; // педагоги по умолчанию

        switch ($segment) {
            case 'A': // платил конкурс
                return [
                    'Новые конкурсы для ваших учеников',
                    'Мы заметили, что вы уже участвовали в наших конкурсах. Добавили новые номинации — возможно, что-то зацепит. По акции «2+1» третья работа всегда бесплатна, а по промо-скидке до ' . $this->expiresLabel . ' вы дополнительно получаете ' . (int)round(self::DISCOUNT_RATE * 100) . '% от итоговой суммы.',
                    $this->pickCompetitions($audienceId, 3),
                    'Смотреть все конкурсы',
                    '/konkursy/',
                ];
            case 'B': // публиковался
                return [
                    'Свежие публикации и научный журнал',
                    'Как автор нашего портала вы можете оформить новую публикацию со свидетельством СМИ. Для вас действует скидка ' . (int)round(self::DISCOUNT_RATE * 100) . '% до ' . $this->expiresLabel . ' на любую услугу.',
                    $this->pickCompetitions($audienceId, 2, ['zhurnal', 'publish']),
                    'Опубликоваться со скидкой',
                    '/opublikovat/',
                ];
            case 'C': // курсы
                return [
                    'Курсы повышения квалификации для вас',
                    'С 1 сентября 2025 года изменились правила повышения квалификации (ФЗ № 86-ФЗ). Обучение с удостоверением государственного образца в ФИС ФРДО — и ' . (int)round(self::DISCOUNT_RATE * 100) . '% скидка до ' . $this->expiresLabel . '.',
                    $this->pickCourses(3, $audienceId),
                    'Выбрать курс',
                    '/kursy/',
                ];
            case 'D': // олимпиады
                return [
                    'Новые олимпиады этого сезона',
                    'Подборка актуальных олимпиад. Участие — онлайн, дипломы и грамоты доступны сразу. Скидка ' . (int)round(self::DISCOUNT_RATE * 100) . '% до ' . $this->expiresLabel . '.',
                    $this->pickOlympiads($audienceId, 3),
                    'Смотреть олимпиады',
                    '/olimpiady/',
                ];
            case 'E': // вебинары
                return [
                    'Бесплатные вебинары и видеолекции',
                    'Для вас — ближайшие бесплатные вебинары и записи видеолекций. Сертификат об участии — по желанию, и с ' . (int)round(self::DISCOUNT_RATE * 100) . '% скидкой до ' . $this->expiresLabel . '.',
                    $this->pickWebinarsAndLectures(3),
                    'Смотреть расписание',
                    '/vebinary/',
                ];
            case 'F': // неоплаченный конкурс
                return [
                    'Работа ждёт вас в корзине',
                    'Вы начали оформление конкурса, но не завершили оплату. Для вас — скидка ' . (int)round(self::DISCOUNT_RATE * 100) . '% до ' . $this->expiresLabel . ' + акция «2+1» (3-я работа бесплатно).',
                    $this->pickCompetitions($audienceId, 2),
                    'Открыть корзину',
                    '/korzina/',
                ];
            case 'G':
            default:
                return [
                    'Что нового на fgos.pro — и ваша скидка ' . (int)round(self::DISCOUNT_RATE * 100) . '%',
                    'С момента регистрации на портале появилось много нового: конкурсы, олимпиады, бесплатные вебинары и курсы повышения квалификации. Чтобы было проще попробовать, мы оставили для вас скидку ' . (int)round(self::DISCOUNT_RATE * 100) . '% до ' . $this->expiresLabel . ' на любую покупку.',
                    array_merge(
                        $this->pickWebinarsAndLectures(1),
                        $this->pickCompetitions($audienceId, 1),
                        $this->pickCourses(1, $audienceId)
                    ),
                    'Посмотреть весь портал',
                    '/',
                ];
        }
    }

    private function pickCompetitions(int $audienceId, int $limit, array $skipSlugs = []): array {
        $params = [$audienceId];
        $excl = '';
        if (!empty($skipSlugs)) {
            $excl = " AND c.slug NOT IN (" . implode(',', array_fill(0, count($skipSlugs), '?')) . ")";
            foreach ($skipSlugs as $s) $params[] = $s;
        }

        $sql = "
            SELECT DISTINCT c.id, c.title, c.slug, LEFT(c.description, 200) AS description
            FROM competitions c
            LEFT JOIN competition_audience_categories cac ON cac.competition_id=c.id
            WHERE c.is_active=1
              AND (cac.category_id=? OR cac.competition_id IS NULL)
              $excl
            ORDER BY c.id DESC
            LIMIT " . (int)$limit;

        $rows = $this->db->query($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'badge' => 'Конкурс',
                'title' => $r['title'],
                'description' => $r['description'],
                'url' => SITE_URL . '/konkursy/' . $r['slug'] . '/',
                'meta' => ['Формат' => 'Онлайн, диплом победителя/участника'],
            ];
        }
        return $out;
    }

    private function pickOlympiads(int $audienceId, int $limit): array {
        $rows = $this->db->query(
            "SELECT DISTINCT o.id, o.title, o.slug, LEFT(o.description,200) AS description
             FROM olympiads o
             LEFT JOIN olympiad_audience_categories oac ON oac.olympiad_id=o.id
             WHERE o.is_active=1 AND (oac.category_id=? OR oac.olympiad_id IS NULL)
             ORDER BY o.id DESC LIMIT " . (int)$limit,
            [$audienceId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'badge' => 'Олимпиада',
                'title' => $r['title'],
                'description' => $r['description'],
                'url' => SITE_URL . '/olimpiady/' . $r['slug'] . '/',
            ];
        }
        return $out;
    }

    private function pickCourses(int $limit, int $audienceId = 1): array {
        $rows = $this->db->query(
            "SELECT id, title, slug, LEFT(description,200) AS description, hours, price, program_type
             FROM courses WHERE is_active=1 ORDER BY display_order ASC, id DESC LIMIT " . (int)$limit
        );
        $out = [];
        foreach ($rows as $r) {
            $label = $r['program_type'] === 'pp' ? 'Проф. переподготовка' : 'Повышение квалификации';
            $out[] = [
                'badge' => $label,
                'title' => $r['title'],
                'description' => $r['description'],
                'url' => SITE_URL . '/kursy/' . $r['slug'] . '/',
                'price' => $r['price'],
                'meta' => ['Объём' => (int)$r['hours'] . ' часов', 'Формат' => 'Заочно с ДОТ'],
            ];
        }
        return $out;
    }

    private function pickWebinarsAndLectures(int $limit): array {
        $upcoming = $this->db->query(
            "SELECT id, title, slug, LEFT(description,200) AS description, scheduled_at
             FROM webinars WHERE is_active=1 AND status='scheduled' AND scheduled_at>=NOW()
             ORDER BY scheduled_at ASC LIMIT 1"
        );
        $lectures = $this->db->query(
            "SELECT id, title, slug, LEFT(description,200) AS description
             FROM webinars WHERE is_active=1 AND status='videolecture'
             ORDER BY id DESC LIMIT " . max(0, $limit - count($upcoming))
        );
        $out = [];
        foreach ($upcoming as $r) {
            $out[] = [
                'badge' => 'Вебинар ' . date('d.m', strtotime($r['scheduled_at'])),
                'title' => $r['title'],
                'description' => $r['description'],
                'url' => SITE_URL . '/vebinar/' . $r['slug'] . '/',
                'meta' => ['Когда' => date('d.m.Y H:i', strtotime($r['scheduled_at'])) . ' МСК', 'Участие' => 'Бесплатно'],
            ];
        }
        foreach ($lectures as $r) {
            $out[] = [
                'badge' => 'Видеолекция',
                'title' => $r['title'],
                'description' => $r['description'],
                'url' => SITE_URL . '/vebinar/' . $r['slug'] . '/',
                'meta' => ['Формат' => 'Запись, смотреть в любой момент'],
            ];
        }
        return $out;
    }

    private function renderTemplate(string $name, array $data): string {
        $path = BASE_PATH . '/includes/email-templates/' . $name . '.php';
        if (!file_exists($path)) {
            throw new \Exception('Template not found: ' . $name);
        }
        extract($data);
        ob_start();
        include $path;
        return ob_get_clean();
    }

    private function renderTextVersion(array $d): string {
        $t = "Здравствуйте, {$d['user_name']}!\n\n";
        $t .= $d['intro_text'] . "\n\n";
        $t .= "Скидка {$d['discount_percent']}% до {$d['discount_expires_label']} применится автоматически после входа в ЛК:\n";
        $t .= $d['magic_login_url'] . "\n\n";
        if (!empty($d['recommendations'])) {
            $t .= "Для вас:\n";
            foreach ($d['recommendations'] as $r) {
                $t .= '- ' . $r['title'] . ': ' . $r['url'] . "\n";
            }
        }
        $t .= "\n---\nКаменный город / ФГОС-Практикум\nОтписаться: " . ($d['unsubscribe_url'] ?? '') . "\n";
        return $t;
    }

    private function updateStatus(int $id, string $status, ?string $error = null): void {
        $set = ['status' => $status];
        if ($status === 'sent') $set['sent_at'] = date('Y-m-d H:i:s');
        if ($error) $set['error_message'] = mb_substr($error, 0, 2000);
        $this->db->update('silent_reengagement_log', $set, 'id=?', [$id]);
        $this->db->execute("UPDATE silent_reengagement_log SET attempts=attempts+1 WHERE id=?", [$id]);
    }

    private function generateUnsubscribeToken(string $email): string {
        $hash = substr(md5($email . SITE_URL), 0, 16);
        return base64_encode($email . ':' . $hash);
    }

    private function log(string $msg): void {
        $logDir = BASE_PATH . '/logs';
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);
        error_log('[' . date('Y-m-d H:i:s') . "] $msg\n", 3, $logDir . '/silent-reengagement.log');
    }
}
