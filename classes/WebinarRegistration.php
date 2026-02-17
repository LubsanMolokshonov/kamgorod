<?php
/**
 * WebinarRegistration Class
 * Регистрации на вебинары + интеграция с Bitrix24
 */

class WebinarRegistration {
    private $db;
    private $bitrix24;

    const STATUS_REGISTERED = 'registered';
    const STATUS_CANCELLED = 'cancelled';

    public function __construct($pdo) {
        $this->db = new Database($pdo);

        // Инициализировать Bitrix24 интеграцию если доступна
        if (class_exists('Bitrix24Integration')) {
            $this->bitrix24 = new Bitrix24Integration();
        }
    }

    // ==================== CRUD методы ====================

    /**
     * Создать регистрацию на вебинар
     *
     * @param array $data Данные регистрации
     * @return int ID созданной регистрации
     */
    public function create($data) {
        $registrationId = $this->db->insert('webinar_registrations', [
            'webinar_id' => $data['webinar_id'],
            'user_id' => $data['user_id'] ?? null,
            'full_name' => $data['full_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'organization' => $data['organization'] ?? null,
            'position' => $data['position'] ?? null,
            'city' => $data['city'] ?? null,
            'status' => self::STATUS_REGISTERED,
            'certificate_email_sent' => 0,
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'registration_source' => $data['registration_source'] ?? 'website'
        ]);

        // Обновить счетчик регистраций в вебинаре
        $this->updateWebinarRegistrationsCount($data['webinar_id']);

        // Отправить в Bitrix24 если интеграция доступна (можно отключить через skip_bitrix24)
        if (!($data['skip_bitrix24'] ?? false) && $this->bitrix24 && defined('BITRIX24_WEBHOOK_URL') && !empty(BITRIX24_WEBHOOK_URL)) {
            $this->syncWithBitrix24($registrationId);
        }

        return $registrationId;
    }

    /**
     * Обновить регистрацию
     *
     * @param int $id ID регистрации
     * @param array $data Новые данные
     * @return int Количество затронутых строк
     */
    public function update($id, $data) {
        $allowedFields = [
            'full_name', 'email', 'phone', 'organization', 'position', 'city',
            'status', 'certificate_email_sent', 'bitrix24_lead_id', 'unisender_contact_id'
        ];

        $updateData = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return 0;
        }

        return $this->db->update('webinar_registrations', $updateData, 'id = ?', [$id]);
    }

    /**
     * Удалить регистрацию
     *
     * @param int $id ID регистрации
     * @return int Количество удаленных строк
     */
    public function delete($id) {
        $registration = $this->getById($id);
        $result = $this->db->delete('webinar_registrations', 'id = ?', [$id]);

        if ($result && $registration) {
            $this->updateWebinarRegistrationsCount($registration['webinar_id']);
        }

        return $result;
    }

    // ==================== Получение данных ====================

    /**
     * Получить регистрацию по ID
     *
     * @param int $id ID регистрации
     * @return array|null Данные регистрации или null
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT wr.*, w.title as webinar_title, w.slug as webinar_slug,
                    w.scheduled_at, w.duration_minutes, w.certificate_price, w.certificate_hours,
                    u.institution_type_id, at.name as institution_type_name
             FROM webinar_registrations wr
             JOIN webinars w ON wr.webinar_id = w.id
             LEFT JOIN users u ON wr.user_id = u.id
             LEFT JOIN audience_types at ON u.institution_type_id = at.id
             WHERE wr.id = ?",
            [$id]
        );
    }

    /**
     * Получить регистрации по вебинару
     *
     * @param int $webinarId ID вебинара
     * @param string|null $status Фильтр по статусу
     * @return array Массив регистраций
     */
    public function getByWebinar($webinarId, $status = null) {
        $sql = "SELECT * FROM webinar_registrations WHERE webinar_id = ?";
        $params = [$webinarId];

        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY created_at DESC";

        return $this->db->query($sql, $params);
    }

    /**
     * Получить регистрации по email
     *
     * @param string $email Email участника
     * @return array Массив регистраций
     */
    public function getByEmail($email) {
        return $this->db->query(
            "SELECT wr.*, w.title as webinar_title, w.slug as webinar_slug,
                    w.scheduled_at, w.status as webinar_status
             FROM webinar_registrations wr
             JOIN webinars w ON wr.webinar_id = w.id
             WHERE wr.email = ?
             ORDER BY wr.created_at DESC",
            [$email]
        );
    }

    /**
     * Получить регистрации по пользователю
     *
     * @param int $userId ID пользователя
     * @return array Массив регистраций
     */
    public function getByUser($userId) {
        return $this->db->query(
            "SELECT wr.*, w.title as webinar_title, w.slug as webinar_slug,
                    w.scheduled_at, w.status as webinar_status, w.video_url,
                    w.broadcast_url, w.certificate_price, w.certificate_hours
             FROM webinar_registrations wr
             JOIN webinars w ON wr.webinar_id = w.id
             WHERE wr.user_id = ?
             ORDER BY w.scheduled_at DESC",
            [$userId]
        );
    }

    // ==================== Проверки ====================

    /**
     * Проверить зарегистрирован ли участник на вебинар
     *
     * @param int $webinarId ID вебинара
     * @param string $email Email участника
     * @return bool True если зарегистрирован
     */
    public function isRegistered($webinarId, $email) {
        $result = $this->db->queryOne(
            "SELECT id FROM webinar_registrations
             WHERE webinar_id = ? AND email = ? AND status = 'registered'",
            [$webinarId, $email]
        );

        return $result !== false;
    }

    /**
     * Получить регистрацию по вебинару и email
     *
     * @param int $webinarId ID вебинара
     * @param string $email Email участника
     * @return array|null Данные регистрации или null
     */
    public function getByWebinarAndEmail($webinarId, $email) {
        return $this->db->queryOne(
            "SELECT * FROM webinar_registrations
             WHERE webinar_id = ? AND email = ? AND status = 'registered'",
            [$webinarId, $email]
        );
    }

    // ==================== Статусы ====================

    /**
     * Отменить регистрацию
     *
     * @param int $id ID регистрации
     * @return bool Успешность операции
     */
    public function cancel($id) {
        $registration = $this->getById($id);
        if (!$registration) {
            return false;
        }

        $result = $this->update($id, ['status' => self::STATUS_CANCELLED]);

        if ($result) {
            $this->updateWebinarRegistrationsCount($registration['webinar_id']);
        }

        return $result > 0;
    }

    /**
     * Отметить что email о сертификате отправлен
     *
     * @param int $id ID регистрации
     * @return bool Успешность операции
     */
    public function markCertificateEmailSent($id) {
        return $this->update($id, ['certificate_email_sent' => 1]) > 0;
    }

    // ==================== Статистика ====================

    /**
     * Подсчитать регистрации по вебинару
     *
     * @param int $webinarId ID вебинара
     * @return int Количество регистраций
     */
    public function countByWebinar($webinarId) {
        $result = $this->db->queryOne(
            "SELECT COUNT(*) as total FROM webinar_registrations
             WHERE webinar_id = ? AND status = 'registered'",
            [$webinarId]
        );

        return $result['total'] ?? 0;
    }

    /**
     * Получить регистрации для рассылки сертификатов
     * (вебинар завершен, email еще не отправлен)
     *
     * @return array Массив регистраций
     */
    public function getPendingCertificateEmails() {
        return $this->db->query(
            "SELECT wr.*, w.title as webinar_title, w.slug as webinar_slug,
                    w.certificate_price, w.certificate_hours
             FROM webinar_registrations wr
             JOIN webinars w ON wr.webinar_id = w.id
             WHERE w.status = 'completed'
             AND wr.status = 'registered'
             AND wr.certificate_email_sent = 0
             ORDER BY w.scheduled_at DESC"
        );
    }

    // ==================== Bitrix24 интеграция ====================

    /**
     * Синхронизировать регистрацию с Bitrix24 (создать Сделку)
     *
     * @param int $id ID регистрации
     * @return bool Успешность операции
     */
    public function syncWithBitrix24($id) {
        if (!$this->bitrix24) {
            return false;
        }

        $registration = $this->getById($id);
        if (!$registration) {
            return false;
        }

        try {
            // Подготовить данные вебинара
            $webinar = [
                'title' => $registration['webinar_title'],
                'scheduled_at' => $registration['scheduled_at'] ?? date('Y-m-d H:i:s'),
            ];

            // Создать сделку в Bitrix24
            $dealId = $this->bitrix24->createWebinarDeal($registration, $webinar);

            if ($dealId) {
                $this->updateBitrix24DealId($id, $dealId);
                return true;
            }
        } catch (Exception $e) {
            error_log("Bitrix24 sync error for registration {$id}: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Обновить ID сделки Bitrix24
     *
     * @param int $id ID регистрации
     * @param string $dealId ID сделки в Bitrix24
     * @return bool Успешность операции
     */
    public function updateBitrix24DealId($id, $dealId) {
        // Используем существующее поле bitrix24_lead_id для хранения deal_id
        return $this->update($id, ['bitrix24_lead_id' => $dealId]) > 0;
    }

    /**
     * @deprecated Use updateBitrix24DealId() instead
     */
    public function updateBitrix24LeadId($id, $leadId) {
        return $this->updateBitrix24DealId($id, $leadId);
    }

    // ==================== Приватные методы ====================

    /**
     * Обновить счетчик регистраций в вебинаре
     *
     * @param int $webinarId ID вебинара
     * @return void
     */
    private function updateWebinarRegistrationsCount($webinarId) {
        $this->db->execute(
            "UPDATE webinars SET registrations_count = (
                SELECT COUNT(*) FROM webinar_registrations
                WHERE webinar_id = ? AND status = 'registered'
            ) WHERE id = ?",
            [$webinarId, $webinarId]
        );
    }

    /**
     * Извлечь имя из полного ФИО
     *
     * @param string $fullName Полное ФИО
     * @return string Имя
     */
    private function extractFirstName($fullName) {
        $parts = explode(' ', trim($fullName));
        return $parts[1] ?? $parts[0] ?? '';
    }

    /**
     * Извлечь фамилию из полного ФИО
     *
     * @param string $fullName Полное ФИО
     * @return string Фамилия
     */
    private function extractLastName($fullName) {
        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? '';
    }
}
