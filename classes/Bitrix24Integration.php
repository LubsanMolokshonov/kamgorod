<?php
/**
 * Bitrix24Integration Class
 * Интеграция с Bitrix24 CRM через REST API (Incoming Webhooks)
 */

class Bitrix24Integration {
    private $webhookUrl;
    private $logFile;

    public function __construct() {
        $this->webhookUrl = defined('BITRIX24_WEBHOOK_URL') ? BITRIX24_WEBHOOK_URL : '';
        $this->logFile = __DIR__ . '/../logs/bitrix24.log';

        // Создать директорию для логов если не существует
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    /**
     * Проверить доступность интеграции
     *
     * @return bool True если интеграция настроена
     */
    public function isConfigured() {
        return !empty($this->webhookUrl);
    }

    /**
     * Проверить соединение с Bitrix24
     *
     * @return bool True если соединение успешно
     */
    public function testConnection() {
        if (!$this->isConfigured()) {
            return false;
        }

        $result = $this->call('profile');
        return $result !== null && isset($result['result']);
    }

    // ==================== Лиды ====================

    /**
     * Создать лид
     *
     * @param array $data Данные лида
     * @return string|null ID созданного лида или null при ошибке
     */
    public function createLead($data) {
        $params = [
            'fields' => $data,
            'params' => ['REGISTER_SONET_EVENT' => 'Y']
        ];

        $result = $this->call('crm.lead.add', $params);

        if ($result && isset($result['result'])) {
            $this->log("Lead created: " . $result['result']);
            return (string)$result['result'];
        }

        $this->log("Failed to create lead: " . json_encode($result), 'error');
        return null;
    }

    /**
     * Обновить лид
     *
     * @param string $leadId ID лида
     * @param array $data Новые данные
     * @return bool Успешность операции
     */
    public function updateLead($leadId, $data) {
        $params = [
            'id' => $leadId,
            'fields' => $data,
            'params' => ['REGISTER_SONET_EVENT' => 'Y']
        ];

        $result = $this->call('crm.lead.update', $params);

        if ($result && isset($result['result']) && $result['result']) {
            $this->log("Lead updated: {$leadId}");
            return true;
        }

        $this->log("Failed to update lead {$leadId}: " . json_encode($result), 'error');
        return false;
    }

    /**
     * Получить лид по ID
     *
     * @param string $leadId ID лида
     * @return array|null Данные лида или null
     */
    public function getLead($leadId) {
        $params = ['id' => $leadId];
        $result = $this->call('crm.lead.get', $params);

        if ($result && isset($result['result'])) {
            return $result['result'];
        }

        return null;
    }

    /**
     * Переместить лид на другую стадию воронки
     *
     * @param string $leadId ID лида
     * @param string $stageId ID стадии (например: NEW, UC_XXXXX)
     * @return bool Успешность операции
     */
    public function moveLead($leadId, $stageId) {
        return $this->updateLead($leadId, ['STATUS_ID' => $stageId]);
    }

    // ==================== Контакты ====================

    /**
     * Найти контакт по email
     *
     * @param string $email Email для поиска
     * @return array|null Данные контакта или null
     */
    public function findContact($email) {
        $params = [
            'filter' => ['EMAIL' => $email],
            'select' => ['ID', 'NAME', 'LAST_NAME', 'EMAIL', 'PHONE']
        ];

        $result = $this->call('crm.contact.list', $params);

        if ($result && isset($result['result']) && !empty($result['result'])) {
            return $result['result'][0];
        }

        return null;
    }

    /**
     * Создать контакт
     *
     * @param array $data Данные контакта
     * @return string|null ID созданного контакта или null
     */
    public function createContact($data) {
        $params = [
            'fields' => $data,
            'params' => ['REGISTER_SONET_EVENT' => 'Y']
        ];

        $result = $this->call('crm.contact.add', $params);

        if ($result && isset($result['result'])) {
            $this->log("Contact created: " . $result['result']);
            return (string)$result['result'];
        }

        $this->log("Failed to create contact: " . json_encode($result), 'error');
        return null;
    }

    /**
     * Обновить контакт
     *
     * @param string $contactId ID контакта
     * @param array $data Новые данные
     * @return bool Успешность операции
     */
    public function updateContact($contactId, $data) {
        $params = [
            'id' => $contactId,
            'fields' => $data,
            'params' => ['REGISTER_SONET_EVENT' => 'Y']
        ];

        $result = $this->call('crm.contact.update', $params);

        if ($result && isset($result['result']) && $result['result']) {
            $this->log("Contact updated: {$contactId}");
            return true;
        }

        $this->log("Failed to update contact {$contactId}: " . json_encode($result), 'error');
        return false;
    }

    // ==================== Сделки ====================

    /**
     * Создать сделку
     *
     * @param array $data Данные сделки
     * @return string|null ID созданной сделки или null
     */
    public function createDeal($data) {
        $params = [
            'fields' => $data,
            'params' => ['REGISTER_SONET_EVENT' => 'Y']
        ];

        $result = $this->call('crm.deal.add', $params);

        if ($result && isset($result['result'])) {
            $this->log("Deal created: " . $result['result']);
            return (string)$result['result'];
        }

        $this->log("Failed to create deal: " . json_encode($result), 'error');
        return null;
    }

    /**
     * Обновить сделку
     *
     * @param string $dealId ID сделки
     * @param array $data Новые данные
     * @return bool Успешность операции
     */
    public function updateDeal($dealId, $data) {
        $params = [
            'id' => $dealId,
            'fields' => $data,
            'params' => ['REGISTER_SONET_EVENT' => 'Y']
        ];

        $result = $this->call('crm.deal.update', $params);

        if ($result && isset($result['result']) && $result['result']) {
            $this->log("Deal updated: {$dealId}");
            return true;
        }

        $this->log("Failed to update deal {$dealId}: " . json_encode($result), 'error');
        return false;
    }

    // ==================== Вспомогательные методы для вебинаров ====================

    /**
     * Создать сделку для регистрации на вебинар
     * Сделка создается в воронке "Вебинары" на этапе "Регистрация"
     *
     * @param array $registration Данные регистрации на вебинар
     * @param array $webinar Данные вебинара
     * @return string|null ID сделки или null
     */
    public function createWebinarDeal($registration, $webinar) {
        // Сначала найти или создать контакт
        $contactId = $this->findOrCreateContact($registration);

        // ID воронки для вебинаров
        $categoryId = defined('BITRIX24_WEBINAR_PIPELINE_ID') ? BITRIX24_WEBINAR_PIPELINE_ID : 102;

        // Стадия "Регистрация" (первая стадия воронки)
        // Формат: C{CATEGORY_ID}:NEW или C{CATEGORY_ID}:{STAGE_ID}
        $stageId = 'C' . $categoryId . ':NEW';

        $data = [
            'TITLE' => mb_substr($webinar['title'], 0, 100) . ' — ' . $registration['full_name'],
            'CATEGORY_ID' => $categoryId,
            'STAGE_ID' => $stageId,
            'SOURCE_ID' => 'WEB',
            'SOURCE_DESCRIPTION' => 'Регистрация на вебинар через Каменный город',
            'COMMENTS' => $this->buildWebinarComments($registration, $webinar),
            'OPENED' => 'Y',
            'PROBABILITY' => 50,
        ];

        // Привязать контакт если есть
        if ($contactId) {
            $data['CONTACT_ID'] = $contactId;
        }

        $dealId = $this->createDeal($data);

        if ($dealId) {
            $this->log("Webinar deal created: {$dealId} for registration {$registration['email']}");
        }

        return $dealId;
    }

    /**
     * Найти или создать контакт по email
     *
     * @param array $registration Данные регистрации
     * @return string|null ID контакта
     */
    private function findOrCreateContact($registration) {
        // Попробовать найти существующий контакт
        $contact = $this->findContact($registration['email']);

        if ($contact) {
            return $contact['ID'];
        }

        // Создать новый контакт
        $nameParts = explode(' ', trim($registration['full_name']));
        $lastName = $nameParts[0] ?? '';
        $firstName = $nameParts[1] ?? '';
        $secondName = $nameParts[2] ?? '';

        $contactData = [
            'NAME' => $firstName ?: $lastName,
            'SECOND_NAME' => $secondName,
            'LAST_NAME' => $lastName,
            'EMAIL' => [['VALUE' => $registration['email'], 'VALUE_TYPE' => 'WORK']],
            'SOURCE_ID' => 'WEB',
            'OPENED' => 'Y',
        ];

        if (!empty($registration['phone'])) {
            $contactData['PHONE'] = [['VALUE' => $registration['phone'], 'VALUE_TYPE' => 'WORK']];
        }

        // Use institution type from user profile
        if (!empty($registration['institution_type_name'])) {
            $contactData['COMPANY_TITLE'] = $registration['institution_type_name'];
            $contactData['POST'] = 'Педагог';
        } else {
            // Fallback for legacy data
            if (!empty($registration['organization'])) {
                $contactData['COMPANY_TITLE'] = $registration['organization'];
            }
            if (!empty($registration['position'])) {
                $contactData['POST'] = $registration['position'];
            }
        }

        return $this->createContact($contactData);
    }

    /**
     * Переместить сделку на следующую стадию
     *
     * @param string $dealId ID сделки
     * @param string $stageId ID стадии (например: C102:PREPARATION)
     * @return bool Успешность операции
     */
    public function moveDeal($dealId, $stageId) {
        return $this->updateDeal($dealId, ['STAGE_ID' => $stageId]);
    }

    /**
     * @deprecated Use createWebinarDeal() instead
     * Создать лид для регистрации на вебинар (устаревший метод)
     */
    public function createWebinarLead($registration, $webinar) {
        // Используем новый метод с созданием сделки
        return $this->createWebinarDeal($registration, $webinar);
    }

    /**
     * Построить комментарии для лида вебинара
     *
     * @param array $registration Данные регистрации
     * @param array $webinar Данные вебинара
     * @return string Комментарий
     */
    private function buildWebinarComments($registration, $webinar) {
        $comments = [];
        $comments[] = "Вебинар: " . $webinar['title'];
        $comments[] = "Дата: " . date('d.m.Y H:i', strtotime($webinar['scheduled_at']));

        // Add institution type to comments
        if (!empty($registration['institution_type_name'])) {
            $comments[] = "Тип учреждения: " . $registration['institution_type_name'];
        }

        // Legacy data fallback
        if (!empty($registration['organization'])) {
            $comments[] = "Организация: " . $registration['organization'];
        }
        if (!empty($registration['position'])) {
            $comments[] = "Должность: " . $registration['position'];
        }
        if (!empty($registration['city'])) {
            $comments[] = "Город: " . $registration['city'];
        }
        if (!empty($registration['utm_source'])) {
            $comments[] = "UTM Source: " . $registration['utm_source'];
        }
        if (!empty($registration['utm_medium'])) {
            $comments[] = "UTM Medium: " . $registration['utm_medium'];
        }
        if (!empty($registration['utm_campaign'])) {
            $comments[] = "UTM Campaign: " . $registration['utm_campaign'];
        }

        return implode("\n", $comments);
    }

    // ==================== Вспомогательные методы для курсов ====================

    /**
     * Создать сделку для записи на курс
     * Сделка создается в воронке "Курсы" (CATEGORY_ID 108)
     *
     * @param array $enrollment Данные записи (full_name, email, phone, utm_*, ym_uid, source_page)
     * @param array $course Данные курса
     * @param string|null $stageId ID стадии (null = BITRIX24_COURSE_STAGE_NEW)
     * @return string|null ID сделки или null
     */
    public function createCourseDeal($enrollment, $course, $stageId = null, $adjustedPrice = null) {
        $contactId = $this->findOrCreateContact($enrollment);

        $categoryId = defined('BITRIX24_COURSE_PIPELINE_ID') ? BITRIX24_COURSE_PIPELINE_ID : 108;
        if ($stageId === null) {
            $stageId = defined('BITRIX24_COURSE_STAGE_NEW') ? BITRIX24_COURSE_STAGE_NEW : 'C' . $categoryId . ':NEW';
        }

        $data = [
            'TITLE' => mb_substr($course['title'], 0, 100) . ' — ' . $enrollment['full_name'],
            'CATEGORY_ID' => $categoryId,
            'STAGE_ID' => $stageId,
            'SOURCE_ID' => 'WEB',
            'SOURCE_DESCRIPTION' => 'Запись на курс через Каменный город',
            'COMMENTS' => $this->buildCourseComments($enrollment, $course, $adjustedPrice),
            'OPENED' => 'Y',
            'PROBABILITY' => 50,
            'OPPORTUNITY' => $adjustedPrice ?? $course['price'] ?? 0,
            'CURRENCY_ID' => 'RUB',
        ];

        // UTM-метки (стандартные поля Bitrix24)
        if (!empty($enrollment['utm_source'])) $data['UTM_SOURCE'] = $enrollment['utm_source'];
        if (!empty($enrollment['utm_medium'])) $data['UTM_MEDIUM'] = $enrollment['utm_medium'];
        if (!empty($enrollment['utm_campaign'])) $data['UTM_CAMPAIGN'] = $enrollment['utm_campaign'];
        if (!empty($enrollment['utm_content'])) $data['UTM_CONTENT'] = $enrollment['utm_content'];
        if (!empty($enrollment['utm_term'])) $data['UTM_TERM'] = $enrollment['utm_term'];

        if ($contactId) {
            $data['CONTACT_ID'] = $contactId;
        }

        $dealId = $this->createDeal($data);

        if ($dealId) {
            $this->log("Course deal created: {$dealId} for enrollment {$enrollment['email']}");
        }

        return $dealId;
    }

    /**
     * Создать сделку для заявки на консультацию по курсу
     *
     * @param array $consultation Данные заявки (phone, course_title, utm_*, ym_uid, source_page)
     * @return string|null ID сделки или null
     */
    public function createCourseConsultationDeal($consultation) {
        $categoryId = defined('BITRIX24_COURSE_PIPELINE_ID') ? BITRIX24_COURSE_PIPELINE_ID : 108;
        $stageId = 'C' . $categoryId . ':NEW';

        $comments = [];
        $comments[] = "Заявка на консультацию";
        $comments[] = "Телефон: " . $consultation['phone'];
        if (!empty($consultation['course_title'])) {
            $comments[] = "Курс: " . $consultation['course_title'];
        }
        if (!empty($consultation['source_page'])) {
            $comments[] = "Страница: " . $consultation['source_page'];
        }
        if (!empty($consultation['ym_uid'])) {
            $comments[] = "Яндекс.Метрика ID: " . $consultation['ym_uid'];
        }
        if (!empty($consultation['utm_source'])) {
            $comments[] = "UTM Source: " . $consultation['utm_source'];
        }
        if (!empty($consultation['utm_medium'])) {
            $comments[] = "UTM Medium: " . $consultation['utm_medium'];
        }
        if (!empty($consultation['utm_campaign'])) {
            $comments[] = "UTM Campaign: " . $consultation['utm_campaign'];
        }

        $data = [
            'TITLE' => 'Консультация по курсу — ' . $consultation['phone'],
            'CATEGORY_ID' => $categoryId,
            'STAGE_ID' => $stageId,
            'SOURCE_ID' => 'WEB',
            'SOURCE_DESCRIPTION' => 'Заявка на консультацию через Каменный город',
            'COMMENTS' => implode("\n", $comments),
            'OPENED' => 'Y',
        ];

        // UTM-метки
        if (!empty($consultation['utm_source'])) $data['UTM_SOURCE'] = $consultation['utm_source'];
        if (!empty($consultation['utm_medium'])) $data['UTM_MEDIUM'] = $consultation['utm_medium'];
        if (!empty($consultation['utm_campaign'])) $data['UTM_CAMPAIGN'] = $consultation['utm_campaign'];
        if (!empty($consultation['utm_content'])) $data['UTM_CONTENT'] = $consultation['utm_content'];
        if (!empty($consultation['utm_term'])) $data['UTM_TERM'] = $consultation['utm_term'];

        $dealId = $this->createDeal($data);

        if ($dealId) {
            $this->log("Course consultation deal created: {$dealId} for phone {$consultation['phone']}");
        }

        return $dealId;
    }

    /**
     * Построить комментарии для сделки курса
     *
     * @param array $enrollment Данные записи
     * @param array $course Данные курса
     * @return string Комментарий
     */
    private function buildCourseComments($enrollment, $course, $adjustedPrice = null) {
        $comments = [];
        $comments[] = "Курс: " . $course['title'];

        if (!empty($course['program_type'])) {
            $label = class_exists('Course') ? Course::getProgramTypeLabel($course['program_type']) : $course['program_type'];
            $comments[] = "Тип: " . $label;
        }
        if (!empty($course['hours'])) {
            $comments[] = "Объём: " . $course['hours'] . " ч.";
        }
        $displayPrice = $adjustedPrice ?? ($course['price'] ?? 0);
        if ($displayPrice > 0) {
            $comments[] = "Стоимость: " . number_format($displayPrice, 0, ',', ' ') . " руб.";
        }
        if (!empty($course['learning_format'])) {
            $comments[] = "Формат: " . $course['learning_format'];
        } else {
            $comments[] = "Формат: Дистанционный";
        }
        if (!empty($enrollment['phone'])) {
            $comments[] = "Телефон: " . $enrollment['phone'];
        }
        if (!empty($enrollment['source_page'])) {
            $comments[] = "Страница: " . $enrollment['source_page'];
        }
        if (!empty($enrollment['ym_uid'])) {
            $comments[] = "Яндекс.Метрика ID: " . $enrollment['ym_uid'];
        }
        if (!empty($enrollment['utm_source'])) {
            $comments[] = "UTM Source: " . $enrollment['utm_source'];
        }
        if (!empty($enrollment['utm_medium'])) {
            $comments[] = "UTM Medium: " . $enrollment['utm_medium'];
        }
        if (!empty($enrollment['utm_campaign'])) {
            $comments[] = "UTM Campaign: " . $enrollment['utm_campaign'];
        }
        if (!empty($enrollment['utm_content'])) {
            $comments[] = "UTM Content: " . $enrollment['utm_content'];
        }
        if (!empty($enrollment['utm_term'])) {
            $comments[] = "UTM Term: " . $enrollment['utm_term'];
        }

        return implode("\n", $comments);
    }

    // ==================== REST API вызов ====================

    /**
     * Выполнить REST API вызов к Bitrix24
     *
     * @param string $method Метод API (например: crm.lead.add)
     * @param array $params Параметры вызова
     * @return array|null Результат или null при ошибке
     */
    private function call($method, $params = []) {
        if (!$this->isConfigured()) {
            $this->log("Bitrix24 integration not configured", 'error');
            return null;
        }

        $url = rtrim($this->webhookUrl, '/') . '/' . $method . '.json';

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            $this->log("CURL Error: {$error}", 'error');
            return null;
        }

        if ($httpCode !== 200) {
            $this->log("HTTP Error: {$httpCode}", 'error');
            return null;
        }

        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("JSON decode error: " . json_last_error_msg(), 'error');
            return null;
        }

        // Проверить на ошибки Bitrix24
        if (isset($result['error'])) {
            $this->log("Bitrix24 API Error: " . $result['error'] . ' - ' . ($result['error_description'] ?? ''), 'error');
            return null;
        }

        return $result;
    }

    /**
     * Записать в лог
     *
     * @param string $message Сообщение
     * @param string $level Уровень (info, error)
     * @return void
     */
    private function log($message, $level = 'info') {
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);
        $logMessage = "[{$timestamp}] [{$levelUpper}] {$message}\n";

        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);

        // Также писать ошибки в стандартный error_log
        if ($level === 'error') {
            error_log("Bitrix24: {$message}");
        }
    }
}
