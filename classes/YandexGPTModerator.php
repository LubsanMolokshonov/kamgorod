<?php
/**
 * YandexGPTModerator Class
 * Automated content moderation using Yandex GPT API
 * Checks if publication content is education-related
 */

class YandexGPTModerator {
    private $apiKey;
    private $folderId;
    private $model;
    private $endpoint = 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion';
    private $timeout = 15;

    public function __construct() {
        $this->apiKey = YANDEX_GPT_API_KEY;
        $this->folderId = YANDEX_GPT_FOLDER_ID;
        $this->model = YANDEX_GPT_MODEL ?: 'yandexgpt-lite';

        if (empty($this->apiKey) || empty($this->folderId)) {
            throw new Exception('Yandex GPT credentials not configured');
        }
    }

    /**
     * Moderate a publication by analyzing title and annotation
     * @param string $title Publication title
     * @param string $annotation Publication annotation/description
     * @return array {is_educational: bool, confidence: float, reason: string, raw_response: string}
     */
    public function moderate(string $title, string $annotation): array {
        $payload = [
            'modelUri' => "gpt://{$this->folderId}/{$this->model}/latest",
            'completionOptions' => [
                'stream' => false,
                'temperature' => 0.1,
                'maxTokens' => '500',
            ],
            'messages' => $this->buildMessages($title, $annotation),
        ];

        $response = $this->sendRequest($payload);

        if ($response === null) {
            throw new Exception('Yandex GPT API request failed');
        }

        $gptText = $response['result']['alternatives'][0]['message']['text'] ?? '';
        if (empty($gptText)) {
            throw new Exception('Empty response from Yandex GPT');
        }

        $result = $this->parseResponse($gptText);
        $result['raw_response'] = $gptText;

        $this->log('Moderation result', [
            'title' => mb_substr($title, 0, 100),
            'is_educational' => $result['is_educational'],
            'confidence' => $result['confidence'],
        ]);

        return $result;
    }

    /**
     * Build messages for the GPT prompt
     */
    private function buildMessages(string $title, string $annotation): array {
        $systemPrompt = <<<PROMPT
Ты — модератор образовательного портала. Твоя задача — определить, относится ли публикация к сфере образования и науки.

ПРИНИМАЮТСЯ следующие материалы:
- Методические разработки, конспекты уроков, рабочие программы, планы занятий
- Статьи по педагогике, дидактике, психологии обучения
- Научные и исследовательские работы учеников и студентов (по биологии, химии, физике, математике, истории, литературе, информатике и любым другим дисциплинам)
- Проектные и исследовательские работы школьников и студентов
- Рефераты, доклады и курсовые по учебным предметам
- Презентации для учебного процесса
- Программы воспитания, сценарии образовательных мероприятий
- Олимпиадные задания и разборы
- Материалы для дошкольного, школьного, среднего и высшего образования

НЕ ПРИНИМАЮТСЯ:
- Реклама товаров и услуг, коммерческие предложения
- Спам, накрутки, SEO-тексты
- Политическая агитация
- Материалы 18+
- Контент, никак не связанный с образованием, наукой или учебным процессом

Ответь строго в формате JSON без дополнительного текста:
{"is_educational": true, "confidence": 0.95, "reason": "краткое объяснение на русском"}
PROMPT;

        $userMessage = "Оцени, является ли следующая публикация образовательным материалом.\n\n";
        $userMessage .= "Название: {$title}\n\n";
        $userMessage .= "Аннотация: {$annotation}";

        return [
            ['role' => 'system', 'text' => $systemPrompt],
            ['role' => 'user', 'text' => $userMessage],
        ];
    }

    /**
     * Send HTTP request to Yandex GPT API
     * @return array|null Parsed response or null on failure
     */
    private function sendRequest(array $payload): ?array {
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
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
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
            $this->log('Failed to decode API response', ['response' => mb_substr($response, 0, 500)]);
            return null;
        }

        return $decoded;
    }

    /**
     * Parse GPT text response into structured moderation result
     * Fail-open: defaults to is_educational=true on parse failure
     */
    private function parseResponse(string $gptText): array {
        $default = [
            'is_educational' => true,
            'confidence' => 0.0,
            'reason' => 'Не удалось определить (автоматически одобрено)',
        ];

        $gptText = trim($gptText);

        // Extract JSON from response (GPT may wrap it in markdown code blocks)
        if (preg_match('/\{[\s\S]*\}/', $gptText, $matches)) {
            $parsed = json_decode($matches[0], true);
            if ($parsed !== null && isset($parsed['is_educational'])) {
                return [
                    'is_educational' => (bool) $parsed['is_educational'],
                    'confidence' => (float) ($parsed['confidence'] ?? 0.5),
                    'reason' => $parsed['reason'] ?? 'Без комментария',
                ];
            }
        }

        // Parse failure — fail-open to avoid false rejections
        $this->log('Failed to parse GPT response', ['raw' => $gptText]);
        return $default;
    }

    /**
     * Analyze publication text content and suggest metadata
     * @param string $text Extracted text from the publication file
     * @return array {title, annotation, publication_type, directions[], subjects[]}
     */
    public function analyzeContent(string $text): array {
        // Truncate text to fit within yandexgpt-lite token limits (~8K tokens total)
        // System prompt uses ~1K tokens, response needs ~300 tokens, leaving ~6.5K for content
        // Russian text is ~2-3 chars per token, so 6000 chars ≈ 2000-3000 tokens
        $textForGpt = mb_substr($text, 0, 6000);
        // Try to cut at last sentence boundary
        $lastPeriod = mb_strrpos($textForGpt, '.');
        if ($lastPeriod > 3000) {
            $textForGpt = mb_substr($textForGpt, 0, $lastPeriod + 1);
        }

        $systemPrompt = <<<PROMPT
Ты — ассистент образовательного портала для педагогов. Проанализируй текст публикации и предложи метаданные.

Верни ответ СТРОГО в формате JSON без дополнительного текста:
{
    "title": "предлагаемое название (до 200 символов)",
    "annotation": "краткое описание содержания публикации, 2-3 предложения (до 500 символов)",
    "publication_type": "один slug из списка типов",
    "directions": ["один или несколько slug из списка направлений"],
    "subjects": ["ноль или несколько slug из списка предметов"]
}

Доступные типы публикаций (publication_type):
- methodology — Методическая разработка (планы уроков, сценарии занятий, технологические карты)
- article — Статья (авторские статьи на педагогическую тематику)
- research — Исследование (научно-практические и исследовательские работы)
- program — Программа (рабочие программы, КТП)
- presentation — Презентация (мультимедийные материалы)
- masterclass — Мастер-класс (обучающие материалы для коллег)
- project — Проект (описание проектной деятельности)
- experience — Обобщение опыта (описание педагогического опыта)

Доступные направления (directions):
- preschool — Дошкольное образование
- primary-school — Начальная школа (1-4 классы)
- secondary-school — Основное общее образование (5-9 классы)
- high-school — Среднее общее образование (10-11 классы)
- extra-education — Дополнительное образование
- special-education — Коррекционная педагогика
- educational-work — Воспитательная работа
- psychology — Психология образования
- innovations — Инновационные технологии
- health — Здоровьесбережение

Доступные предметы (subjects):
- russian-literature — Русский язык и литература
- mathematics — Математика
- foreign-languages — Иностранные языки
- natural-sciences — Естественные науки (физика, химия, биология, география)
- history-social — История и обществознание
- arts — Музыка и ИЗО
- technology — Технология и труд
- physical-education — Физическая культура
- life-safety — ОБЖ
- informatics — Информатика

ВАЖНО:
- Используй ТОЛЬКО slug значения из списков выше
- Выбери 1-3 наиболее подходящих направления
- Предметы выбирай, только если они явно соответствуют содержанию
- Аннотация должна быть содержательной, 2-3 предложения
- Название должно быть кратким и точным
PROMPT;

        $userMessage = "Проанализируй текст публикации:\n\n{$textForGpt}";

        $payload = [
            'modelUri' => "gpt://{$this->folderId}/{$this->model}/latest",
            'completionOptions' => [
                'stream' => false,
                'temperature' => 0.3,
                'maxTokens' => '1000',
            ],
            'messages' => [
                ['role' => 'system', 'text' => $systemPrompt],
                ['role' => 'user', 'text' => $userMessage],
            ],
        ];

        // Use longer timeout for content analysis (more data than moderation)
        $prevTimeout = $this->timeout;
        $this->timeout = 30;
        $response = $this->sendRequest($payload);
        $this->timeout = $prevTimeout;

        if ($response === null) {
            throw new Exception('Yandex GPT API request failed');
        }

        $gptText = $response['result']['alternatives'][0]['message']['text'] ?? '';
        if (empty($gptText)) {
            throw new Exception('Empty response from Yandex GPT');
        }

        $result = $this->parseAnalysisResponse($gptText);

        $this->log('Content analysis result', [
            'title' => mb_substr($result['title'] ?? '', 0, 100),
            'type' => $result['publication_type'] ?? '',
        ]);

        return $result;
    }

    /**
     * Parse GPT response for content analysis
     */
    private function parseAnalysisResponse(string $gptText): array {
        $default = [
            'title' => '',
            'annotation' => '',
            'publication_type' => '',
            'directions' => [],
            'subjects' => [],
        ];

        $gptText = trim($gptText);

        // Extract JSON from response (GPT may wrap it in markdown code blocks)
        if (preg_match('/\{[\s\S]*\}/', $gptText, $matches)) {
            $parsed = json_decode($matches[0], true);
            if ($parsed !== null) {
                return [
                    'title' => mb_substr($parsed['title'] ?? '', 0, 500),
                    'annotation' => mb_substr($parsed['annotation'] ?? '', 0, 500),
                    'publication_type' => $parsed['publication_type'] ?? '',
                    'directions' => is_array($parsed['directions'] ?? null) ? $parsed['directions'] : [],
                    'subjects' => is_array($parsed['subjects'] ?? null) ? $parsed['subjects'] : [],
                ];
            }
        }

        $this->log('Failed to parse analysis response', ['raw' => mb_substr($gptText, 0, 500)]);
        return $default;
    }

    /**
     * Log moderation events for debugging
     */
    private function log(string $message, array $context = []): void {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logEntry = date('Y-m-d H:i:s') . " [MODERATION] {$message}";
        if (!empty($context)) {
            $logEntry .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        $logEntry .= PHP_EOL;

        file_put_contents($logDir . '/moderation.log', $logEntry, FILE_APPEND | LOCK_EX);
    }
}
