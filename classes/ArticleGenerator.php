<?php
/**
 * ArticleGenerator Class
 * Генерация педагогических статей через Yandex GPT API
 */

class ArticleGenerator {
    private Database $db;
    private $pdo;
    private $apiKey;
    private $folderId;
    private $endpoint = 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion';

    public function __construct($pdo) {
        $this->db = new Database($pdo);
        $this->pdo = $pdo;
        $this->apiKey = YANDEX_GPT_API_KEY;
        $this->folderId = YANDEX_GPT_FOLDER_ID;

        if (empty($this->apiKey) || empty($this->folderId)) {
            throw new Exception('Yandex GPT credentials not configured');
        }
    }

    /**
     * Создать новую сессию генерации
     */
    public function createSession(array $data): string {
        $token = bin2hex(random_bytes(32));

        $this->db->insert('article_generation_sessions', [
            'session_token' => $token,
            'email' => $data['email'] ?? null,
            'author_name' => $data['author_name'] ?? null,
            'organization' => $data['organization'] ?? null,
            'position' => $data['position'] ?? null,
            'city' => $data['city'] ?? null,
            'current_step' => 1,
        ]);

        return $token;
    }

    /**
     * Получить сессию по токену
     */
    public function getSession(string $token): ?array {
        return $this->db->queryOne(
            "SELECT * FROM article_generation_sessions WHERE session_token = ? AND status = 'in_progress'",
            [$token]
        );
    }

    /**
     * Обновить данные сессии
     */
    public function updateSession(string $token, array $data): bool {
        $allowedFields = [
            'email', 'author_name', 'organization', 'position', 'city',
            'audience_category_id', 'topic', 'description',
            'generated_title', 'generated_content', 'generation_count',
            'current_step', 'publication_id', 'status', 'user_id'
        ];

        $updateData = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return false;
        }

        return $this->db->update('article_generation_sessions', $updateData,
            'session_token = ?', [$token]) > 0;
    }

    /**
     * Сгенерировать статью через YandexGPT
     */
    public function generateArticle(string $sessionToken): array {
        $session = $this->getSession($sessionToken);
        if (!$session) {
            throw new Exception('Сессия не найдена');
        }

        $audienceLabel = '';
        if ($session['audience_category_id']) {
            $cat = $this->db->queryOne(
                "SELECT name FROM audience_categories WHERE id = ?",
                [$session['audience_category_id']]
            );
            $audienceLabel = $cat['name'] ?? '';
        }

        $systemPrompt = <<<PROMPT
Ты — профессиональный автор педагогических статей для образовательного журнала.
Напиши полноценную статью на заданную тему.

Требования к структуре:
- Введение (2-3 абзаца): актуальность темы, цели и задачи
- 2-4 основных раздела с подзаголовками: раскрытие темы с примерами из практики
- Заключение (1-2 абзаца): выводы и рекомендации
- Объём: 2000-4000 слов
- Стиль: научно-популярный, профессиональный, для педагогов
- Используй профессиональную терминологию
- Приводи конкретные примеры и методические рекомендации

ВАЖНО: Ответ — строго JSON без дополнительного текста:
{"title": "Заголовок статьи", "sections": [{"id": "intro", "heading": "Введение", "html": "<p>текст</p>"}, {"id": "section-1", "heading": "Название раздела", "html": "<p>текст</p>"}, {"id": "conclusion", "heading": "Заключение", "html": "<p>текст</p>"}]}
PROMPT;

        $userMessage = "Автор: {$session['author_name']}";
        if ($session['position']) {
            $userMessage .= ", {$session['position']}";
        }
        if ($session['organization']) {
            $userMessage .= ", {$session['organization']}";
        }
        if ($session['city']) {
            $userMessage .= ", г. {$session['city']}";
        }
        if ($audienceLabel) {
            $userMessage .= "\nАудитория: {$audienceLabel}";
        }
        $userMessage .= "\nТема: {$session['topic']}";
        $userMessage .= "\nОписание: {$session['description']}";

        $payload = [
            'modelUri' => "gpt://{$this->folderId}/yandexgpt/latest",
            'completionOptions' => [
                'stream' => false,
                'temperature' => 0.5,
                'maxTokens' => '8000',
            ],
            'messages' => [
                ['role' => 'system', 'text' => $systemPrompt],
                ['role' => 'user', 'text' => $userMessage],
            ],
        ];

        $response = $this->sendRequest($payload, 90);
        if ($response === null) {
            throw new Exception('Ошибка API Yandex GPT');
        }

        $gptText = $response['result']['alternatives'][0]['message']['text'] ?? '';
        if (empty($gptText)) {
            throw new Exception('Пустой ответ от Yandex GPT');
        }

        $article = $this->parseArticleResponse($gptText);

        // Сохраняем результат в сессию
        $contentHtml = $this->buildContentHtml($article['title'], $article['sections']);
        $this->updateSession($sessionToken, [
            'generated_title' => $article['title'],
            'generated_content' => $contentHtml,
            'generation_count' => ($session['generation_count'] ?? 0) + 1,
            'current_step' => 4,
        ]);

        $this->log('Article generated', [
            'session' => $session['id'],
            'title' => mb_substr($article['title'], 0, 100),
            'sections' => count($article['sections']),
        ]);

        return $article;
    }

    /**
     * Отредактировать секцию статьи
     */
    public function editSection(string $sessionToken, string $sectionId, string $instructions): array {
        $session = $this->getSession($sessionToken);
        if (!$session) {
            throw new Exception('Сессия не найдена');
        }

        // Парсим текущие секции из сохранённого HTML
        $sections = $this->extractSections($session['generated_content']);
        $targetSection = null;
        foreach ($sections as $s) {
            if ($s['id'] === $sectionId) {
                $targetSection = $s;
                break;
            }
        }

        if (!$targetSection) {
            throw new Exception('Раздел не найден');
        }

        $systemPrompt = <<<PROMPT
Ты — редактор педагогической статьи. Перепиши указанный раздел статьи по инструкции пользователя.
Сохрани профессиональный стиль и объём раздела.
Верни ТОЛЬКО обновлённый HTML-контент раздела (теги <p>, <ul>, <ol> и т.д.), без заголовка и без обёрток.
PROMPT;

        $userMessage = "Заголовок раздела: {$targetSection['heading']}\n\n";
        $userMessage .= "Текущий текст раздела:\n{$targetSection['html']}\n\n";
        $userMessage .= "Инструкция по изменению: {$instructions}";

        $payload = [
            'modelUri' => "gpt://{$this->folderId}/yandexgpt/latest",
            'completionOptions' => [
                'stream' => false,
                'temperature' => 0.3,
                'maxTokens' => '4000',
            ],
            'messages' => [
                ['role' => 'system', 'text' => $systemPrompt],
                ['role' => 'user', 'text' => $userMessage],
            ],
        ];

        $response = $this->sendRequest($payload, 60);
        if ($response === null) {
            throw new Exception('Ошибка API Yandex GPT');
        }

        $updatedHtml = $response['result']['alternatives'][0]['message']['text'] ?? '';
        if (empty($updatedHtml)) {
            throw new Exception('Пустой ответ от Yandex GPT');
        }

        // Убираем markdown-обёртки если есть
        $updatedHtml = trim($updatedHtml);
        $updatedHtml = preg_replace('/^```html?\s*/i', '', $updatedHtml);
        $updatedHtml = preg_replace('/\s*```$/', '', $updatedHtml);

        // Обновляем секцию в контенте
        foreach ($sections as &$s) {
            if ($s['id'] === $sectionId) {
                $s['html'] = $updatedHtml;
                break;
            }
        }
        unset($s);

        $contentHtml = $this->buildContentHtml($session['generated_title'], $sections);
        $this->updateSession($sessionToken, [
            'generated_content' => $contentHtml,
        ]);

        $this->log('Section edited', [
            'session' => $session['id'],
            'section' => $sectionId,
        ]);

        return [
            'section_id' => $sectionId,
            'updated_html' => $updatedHtml,
            'full_content' => $contentHtml,
        ];
    }

    /**
     * Опубликовать сгенерированную статью
     */
    public function publish(string $sessionToken): array {
        $session = $this->getSession($sessionToken);
        if (!$session) {
            throw new Exception('Сессия не найдена');
        }

        if (empty($session['generated_title']) || empty($session['generated_content'])) {
            throw new Exception('Статья ещё не сгенерирована');
        }

        require_once __DIR__ . '/User.php';
        require_once __DIR__ . '/Publication.php';
        require_once __DIR__ . '/PublicationCertificate.php';

        $userObj = new User($this->pdo);
        $publicationObj = new Publication($this->pdo);
        $certObj = new PublicationCertificate($this->pdo);

        $this->pdo->beginTransaction();

        try {
            // Создать или найти пользователя
            $user = $userObj->findByEmail($session['email']);
            if (!$user) {
                $userId = $userObj->create([
                    'email' => $session['email'],
                    'full_name' => $session['author_name'],
                    'organization' => $session['organization'],
                    'profession' => $session['position'] ?? '',
                ]);
            } else {
                $userId = $user['id'];
                $userObj->update($userId, [
                    'full_name' => $session['author_name'],
                    'organization' => $session['organization'],
                    'profession' => $session['position'] ?? $user['profession'],
                ]);
            }

            // Определить тип публикации "article"
            $articleType = $this->db->queryOne(
                "SELECT id FROM publication_types WHERE slug = 'article' AND is_active = 1 LIMIT 1"
            );
            $typeId = $articleType ? $articleType['id'] : null;

            // Создать аннотацию из первых 500 символов контента
            $plainText = strip_tags($session['generated_content']);
            $annotation = mb_substr($plainText, 0, 490);
            $lastDot = mb_strrpos($annotation, '.');
            if ($lastDot > 200) {
                $annotation = mb_substr($annotation, 0, $lastDot + 1);
            }

            // Создать публикацию
            $publicationId = $publicationObj->create([
                'user_id' => $userId,
                'title' => $session['generated_title'],
                'annotation' => $annotation,
                'content' => $session['generated_content'],
                'publication_type_id' => $typeId,
                'source' => 'generator',
                'status' => 'published',
                'certificate_status' => 'pending',
            ]);

            // Создать сертификат
            $certificateId = $certObj->create([
                'publication_id' => $publicationId,
                'user_id' => $userId,
                'author_name' => $session['author_name'],
                'organization' => $session['organization'],
                'position' => $session['position'] ?? '',
                'price' => 299.00,
            ]);

            // Обновить сессию
            $this->updateSession($sessionToken, [
                'user_id' => $userId,
                'publication_id' => $publicationId,
                'status' => 'published',
                'current_step' => 6,
            ]);

            $this->pdo->commit();

            // Установить сессию пользователя
            $_SESSION['user_id'] = $userId;
            $_SESSION['user_email'] = $session['email'];

            $sessionTokenUser = $userObj->generateSessionToken($userId);
            setcookie('session_token', $sessionTokenUser, time() + (30 * 24 * 60 * 60), '/', '', isset($_SERVER['HTTPS']), true);

            // Получить slug публикации
            $pub = $publicationObj->getById($publicationId);
            $publicationUrl = '/publikaciya/' . ($pub['slug'] ?? $publicationId) . '/';
            $certificateUrl = '/sertifikat-publikacii/?id=' . $publicationId;

            // Email-цепочка
            try {
                require_once __DIR__ . '/PublicationEmailChain.php';
                $emailChain = new PublicationEmailChain($this->pdo);
                $emailChain->scheduleInitialEmail($publicationId);
            } catch (Exception $e) {
                error_log("Email chain error for generated article #{$publicationId}: " . $e->getMessage());
            }

            $this->log('Article published', [
                'session' => $session['id'],
                'publication_id' => $publicationId,
            ]);

            return [
                'publication_id' => $publicationId,
                'certificate_id' => $certificateId,
                'publication_url' => $publicationUrl,
                'certificate_url' => $certificateUrl,
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Собрать HTML из секций
     */
    private function buildContentHtml(string $title, array $sections): string {
        $html = '';
        foreach ($sections as $section) {
            $id = htmlspecialchars($section['id'], ENT_QUOTES, 'UTF-8');
            $heading = htmlspecialchars($section['heading'], ENT_QUOTES, 'UTF-8');
            $html .= "<section data-id=\"{$id}\">\n";
            $html .= "<h2>{$heading}</h2>\n";
            $html .= $section['html'] . "\n";
            $html .= "</section>\n\n";
        }
        return $html;
    }

    /**
     * Извлечь секции из HTML-контента
     */
    private function extractSections(string $html): array {
        $sections = [];
        if (preg_match_all('/<section data-id="([^"]+)">\s*<h2>([^<]+)<\/h2>\s*([\s\S]*?)<\/section>/u', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $sections[] = [
                    'id' => $m[1],
                    'heading' => $m[2],
                    'html' => trim($m[3]),
                ];
            }
        }
        return $sections;
    }

    /**
     * Распарсить ответ GPT с JSON статьи
     */
    private function parseArticleResponse(string $gptText): array {
        $gptText = trim($gptText);

        // Убрать markdown code blocks
        $gptText = preg_replace('/^```json?\s*/i', '', $gptText);
        $gptText = preg_replace('/\s*```$/', '', $gptText);

        if (preg_match('/\{[\s\S]*\}/u', $gptText, $matches)) {
            $parsed = json_decode($matches[0], true);
            if ($parsed && !empty($parsed['title']) && !empty($parsed['sections'])) {
                $sections = [];
                foreach ($parsed['sections'] as $s) {
                    $sections[] = [
                        'id' => $s['id'] ?? ('section-' . count($sections)),
                        'heading' => $s['heading'] ?? 'Раздел',
                        'html' => $s['html'] ?? '',
                    ];
                }
                return [
                    'title' => $parsed['title'],
                    'sections' => $sections,
                ];
            }
        }

        throw new Exception('Не удалось распарсить ответ GPT. Попробуйте ещё раз.');
    }

    /**
     * Отправить запрос к Yandex GPT API
     */
    private function sendRequest(array $payload, int $timeout = 60): ?array {
        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Api-Key ' . $this->apiKey,
                'x-folder-id: ' . $this->folderId,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->log('cURL error', ['error' => $curlError]);
            return null;
        }

        if ($httpCode !== 200) {
            $this->log('API HTTP error', ['http_code' => $httpCode, 'response' => mb_substr($response, 0, 500)]);
            return null;
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            $this->log('Failed to decode API response');
            return null;
        }

        return $decoded;
    }

    private function log(string $message, array $context = []): void {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logEntry = date('Y-m-d H:i:s') . " [GENERATOR] {$message}";
        if (!empty($context)) {
            $logEntry .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        $logEntry .= PHP_EOL;

        file_put_contents($logDir . '/generator.log', $logEntry, FILE_APPEND | LOCK_EX);
    }
}
