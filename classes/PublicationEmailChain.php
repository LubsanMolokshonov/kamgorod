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
require_once __DIR__ . '/EmailDispatcher.php';
require_once __DIR__ . '/../includes/magic-link-helper.php';
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

        // Шаг 3: Обработать очередь отправки (через Unisender Go)
        require_once BASE_PATH . '/includes/email-helper.php';

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

            if (recipientRecentlyEmailed($this->pdo, $emailData['email'], CHAIN_MIN_INTERVAL_MINUTES)) {
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
        require_once BASE_PATH . '/classes/EmailDispatcher.php';

        try {
            $unsubscribeToken = $this->generateUnsubscribeToken($emailData['email']);
            $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . $unsubscribeToken;

            // Шаблонные данные
            $certificateUrl = generateMagicUrl(
                $emailData['user_id'],
                '/pages/publication-certificate.php?id=' . $emailData['publication_id']
            );

            $cabinetUrl = generateMagicUrl(
                $emailData['user_id'],
                '/pages/cabinet.php?tab=events'
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
                'site_name'           => 'Госпрактика',
                'touchpoint_code'     => $emailData['touchpoint_code'],
            ];

            $textBody = $this->renderTextTemplate($emailData['touchpoint_code'], $templateData);
            $subject  = $this->interpolateSubject($emailData['email_subject'], $templateData);

            EmailDispatcher::send([
                'to_email'        => $emailData['email'],
                'to_name'         => $emailData['full_name'],
                'subject'         => $subject,
                'text'            => $textBody,
                'unsubscribe_url' => $unsubscribeUrl,
                'meta'            => [
                    'email_type'      => 'publication',
                    'touchpoint_code' => $emailData['touchpoint_code'],
                    'chain_log_id'    => $emailData['id'],
                    'chain_log_table' => 'publication_email_log',
                    'user_id'         => $emailData['user_id'] ?? null,
                ],
            ]);

            $this->log("SENT | {$emailData['email']} | {$emailData['touchpoint_code']} | Publication {$emailData['publication_id']}");
            return true;

        } catch (\Throwable $e) {
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
     * Plain-text версия письма.
     *
     * Соглашения по форматированию (визуальные, не парсятся почтовыми клиентами):
     *   *жирно*  — для эмфазы/CTA
     *   _курсив_ — для тонких пометок
     *   ВЕРХНИЙ РЕГИСТР — для заголовков-секций
     * Ссылки выводятся как сырые URL — почтовые клиенты делают их кликабельными.
     */
    private function renderTextTemplate($touchpointCode, $data) {
        $name  = $data['user_name'];
        $title = $data['publication_title'];
        $price = number_format($data['certificate_price'], 0, ',', ' ');

        $body = match ($touchpointCode) {

            'pub_cert_2h' =>
                "Здравствуйте, {$name}!\n\n" .
                "Поздравляем — ваша публикация *«{$title}»* успешно прошла модерацию и размещена в нашем журнале.\n\n" .
                "Посмотреть публикацию:\n{$data['publication_url']}\n\n" .
                "Теперь вы можете оформить *именное свидетельство о публикации* — официальный документ для профессионального портфолио.\n\n" .
                "СТОИМОСТЬ: {$price} руб.\n\n" .
                "Оформить свидетельство:\n{$data['certificate_url']}\n\n" .
                "Что включает свидетельство:\n" .
                "  — ваше ФИО и название публикации\n" .
                "  — уникальный регистрационный номер\n" .
                "  — подтверждение публикации в СМИ\n" .
                "  — документ для аттестации и портфолио\n\n" .
                "_Акция «2+1»: при оплате двух документов третий — в подарок._\n",

            'pub_cert_24h' =>
                "Здравствуйте, {$name}!\n\n" .
                "Напоминаем: ваша публикация *«{$title}»* размещена в нашем журнале, но свидетельство ещё не оформлено.\n\n" .
                "ЗАЧЕМ НУЖНО СВИДЕТЕЛЬСТВО:\n" .
                "  — *Аттестация* — подтверждение публикационной активности\n" .
                "  — *Портфолио* — официальный документ с уникальным номером\n" .
                "  — *Карьерный рост* — дополнительные баллы при конкурсах\n" .
                "  — *Мгновенное получение* — PDF сразу после оплаты\n\n" .
                "Стоимость: {$price} руб.\n\n" .
                "Оформить свидетельство:\n{$data['certificate_url']}\n",

            'pub_cert_3d' =>
                "Здравствуйте, {$name}!\n\n" .
                "Ваша публикация *«{$title}»* размещена в журнале, но вы ещё не оформили свидетельство.\n\n" .
                "АКЦИЯ «2+1»\n" .
                "При оплате двух документов третий — *в подарок*.\n" .
                "Комбинируйте: дипломы конкурсов + свидетельства о публикации + сертификаты вебинаров.\n\n" .
                "Стоимость свидетельства: {$price} руб.\n\n" .
                "Оформить свидетельство:\n{$data['certificate_url']}\n\n" .
                "Каталог конкурсов и вебинаров:\n{$data['site_url']}\n",

            'pub_cert_7d' =>
                "Здравствуйте, {$name}!\n\n" .
                "Неделю назад ваша публикация *«{$title}»* была размещена в нашем журнале. " .
                "У вас ещё есть возможность оформить именное свидетельство.\n\n" .
                "*Не упустите момент!* Свидетельство — важный документ для портфолио и аттестации, " .
                "а PDF приходит сразу после оплаты.\n\n" .
                "Стоимость: {$price} руб.\n\n" .
                "Оформить свидетельство:\n{$data['certificate_url']}\n\n" .
                "_Акция «2+1»: при оплате двух документов третий — бесплатно._\n",

            'pub_pay_1h' =>
                "Здравствуйте, {$name}!\n\n" .
                "Вы оформили свидетельство о публикации *«{$title}»*, но оплата ещё не завершена.\n\n" .
                "К ОПЛАТЕ: {$price} руб.\n\n" .
                "Завершить оплату:\n{$data['cabinet_url']}\n\n" .
                "*После оплаты свидетельство будет сформировано автоматически* — " .
                "вы сможете скачать PDF из личного кабинета.\n",

            'pub_pay_24h' =>
                "Здравствуйте, {$name}!\n\n" .
                "Напоминаем: вы оформили свидетельство о публикации *«{$title}»*, но оплата пока не завершена.\n\n" .
                "ЧТО ВЫ ПОЛУЧИТЕ ПОСЛЕ ОПЛАТЫ:\n" .
                "  — именное свидетельство в формате PDF\n" .
                "  — уникальный регистрационный номер\n" .
                "  — подтверждение публикации в педагогическом журнале\n" .
                "  — документ для портфолио и аттестации\n\n" .
                "Стоимость: {$price} руб.\n\n" .
                "Завершить оплату:\n{$data['cabinet_url']}\n\n" .
                "_Акция «2+1»: при оплате двух документов третий — в подарок._\n",

            'pub_pay_3d' =>
                "Здравствуйте, {$name}!\n\n" .
                "Ваше свидетельство о публикации *«{$title}»* по-прежнему ожидает оплаты.\n\n" .
                "*Акция «2+1» скоро завершится!* При оплате двух документов третий вы получаете бесплатно — " .
                "успейте воспользоваться предложением.\n\n" .
                "Стоимость: {$price} руб.\n\n" .
                "Завершить оплату:\n{$data['cabinet_url']}\n\n" .
                "_Всего {$price} руб. — и у вас будет официальное подтверждение публикации._\n",

            'pub_rejected_24h' =>
                "Здравствуйте, {$name}!\n\n" .
                "К сожалению, ваша публикация *«{$title}»* не прошла модерацию.\n\n" .
                (!empty($data['moderation_comment'])
                    ? "ПРИЧИНА ОТКЛОНЕНИЯ:\n{$data['moderation_comment']}\n\n"
                    : "") .
                "Не расстраивайтесь — отправьте новый материал по педагогической тематике.\n\n" .
                "ПОДХОДЯЩИЕ МАТЕРИАЛЫ:\n" .
                "  — методические разработки и конспекты уроков\n" .
                "  — рабочие программы и планирование\n" .
                "  — статьи по педагогике, дидактике, психологии\n" .
                "  — сценарии мероприятий и классных часов\n" .
                "  — исследовательские и проектные работы\n" .
                "  — олимпиадные задания и тесты\n" .
                "  — презентации к урокам и занятиям\n\n" .
                "Отправить новую публикацию:\n{$data['submit_url']}\n\n" .
                "_Размещение материала в журнале полностью бесплатно. Оплата нужна только за оформление именного свидетельства._\n",

            default =>
                "Здравствуйте, {$name}!\n\n" .
                "Перейдите в личный кабинет:\n{$data['cabinet_url']}\n",
        };

        $footer  = "\n--\n";
        $footer .= "С уважением,\nкоманда «Госпрактика»\n";
        $footer .= "{$data['site_url']}\n\n";
        $footer .= "Если письмо вам больше не интересно — отпишитесь:\n{$data['unsubscribe_url']}\n";

        return $body . $footer;
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
