<?php
/**
 * PublicationFormatter
 *
 * AI-оформление загруженных вручную публикаций (source='upload'). Извлечённый из
 * файла HTML обычно «плоский» (особенно из PDF — сплошные <p> без заголовков),
 * поэтому оглавление (buildArticleToc по <h2>/<h3>) и аккуратная типографика не
 * работают. Этот класс прогоняет контент через YandexGPT, который расставляет
 * смысловые заголовки, абзацы и списки.
 *
 * ГЛАВНЫЙ ИНВАРИАНТ: слова автора не меняются — только разметка. После ответа
 * модели сверяем текст без тегов с оригиналом; если слова разошлись больше порога,
 * откатываем фрагмент к исходному (sanitized) виду. Так AI не может ни переписать,
 * ни выкинуть, ни досочинить содержание.
 *
 * Результат прогоняется через тот же allowlist-санитайзер, что и извлечение из файла
 * (DocumentExtractor::sanitize), — единая фильтрация тегов.
 */

require_once __DIR__ . '/DocumentExtractor.php';

class PublicationFormatter {
    private $apiKey;
    private $folderId;
    private $model;
    private $endpoint = 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion';
    private $timeout = 60;

    /** Бюджет символов на один запрос к модели (длинные публикации режем по блокам). */
    private const CHUNK_CHARS = 14000;

    /**
     * Слова автора почти не должны пропадать/меняться — это и есть «переписывание».
     * Минимальный люфт на нормализацию (например, неразрывные пробелы).
     */
    private const WORDS_REMOVED_TOLERANCE = 0.02;

    /**
     * Добавленные слова допустимы шире: выделяя заголовок, модель часто дублирует
     * фразу, уже присутствующую в тексте (<h2>Цель урока</h2> + абзац). Заголовки
     * короткие относительно тела, поэтому небольшой бюджет добавлений безопасен.
     */
    private const WORDS_ADDED_TOLERANCE = 0.12;

    /** Короче этого (текст без тегов) — оформлять нечего. */
    private const MIN_TEXT_CHARS = 200;

    private $extractor;

    public function __construct() {
        $this->apiKey = YANDEX_GPT_API_KEY;
        $this->folderId = YANDEX_GPT_FOLDER_ID;
        $this->model = (defined('YANDEX_GPT_FORMAT_MODEL') && YANDEX_GPT_FORMAT_MODEL)
            ? YANDEX_GPT_FORMAT_MODEL
            : (YANDEX_GPT_MODEL ?: 'yandexgpt');
        $this->extractor = new DocumentExtractor();
    }

    public function isConfigured(): bool {
        return !empty($this->apiKey) && !empty($this->folderId);
    }

    /**
     * Оформить HTML публикации.
     *
     * @return array {status: 'done'|'skipped'|'failed'|'error', html: ?string, reason: string}
     *   - done    — контент переразмечен, слова сохранены;
     *   - skipped — оформлять нечего (пусто/слишком коротко/структура уже норм);
     *   - failed  — модель исказила текст (терминально, повтор не поможет);
     *   - error   — транзиентная ошибка API (имеет смысл повторить позже).
     */
    public function format(?string $html): array {
        $html = trim((string) $html);

        if ($html === '') {
            return ['status' => 'skipped', 'html' => null, 'reason' => 'Пустой контент'];
        }

        $baselineText = $this->normalizeText($html);
        if (mb_strlen($baselineText) < self::MIN_TEXT_CHARS) {
            return ['status' => 'skipped', 'html' => null, 'reason' => 'Слишком мало текста для оформления'];
        }

        if (!$this->isConfigured()) {
            return ['status' => 'error', 'html' => null, 'reason' => 'Yandex GPT не настроен'];
        }

        $chunks = $this->splitIntoChunks($html);
        $resultParts = [];
        $anyChanged = false;
        $anyDrift = false;

        foreach ($chunks as $chunk) {
            $original = $this->extractor->sanitize($chunk);

            try {
                $gptHtml = $this->callModel($chunk);
            } catch (Exception $e) {
                $this->log('API error', ['error' => $e->getMessage()]);
                return ['status' => 'error', 'html' => null, 'reason' => 'Ошибка API: ' . $e->getMessage()];
            }

            $formatted = $this->normalizeLists($this->extractor->sanitize($this->stripCodeFences($gptHtml)));

            // Предохранитель: слова автора не должны пропадать/меняться. Иначе — откат к оригиналу.
            if ($formatted === '' || !$this->wordsPreserved($chunk, $formatted)) {
                $anyDrift = true;
                $resultParts[] = $original;
                continue;
            }

            if ($this->normalizeText($formatted) !== $this->normalizeText($original)
                || $formatted !== $original) {
                $anyChanged = true;
            }
            $resultParts[] = $formatted;
        }

        $finalHtml = $this->normalizeLists($this->extractor->sanitize(implode("\n", $resultParts)));

        // Финальная сквозная проверка целостности текста против исходника.
        if (!$this->wordsPreserved($html, $finalHtml)) {
            return ['status' => 'failed', 'html' => null, 'reason' => 'Модель исказила текст — оформление отклонено'];
        }

        if (!$anyChanged) {
            $reason = $anyDrift
                ? 'Модель исказила текст — оставлен оригинал'
                : 'Структура уже корректна';
            return ['status' => 'skipped', 'html' => null, 'reason' => $reason];
        }

        return ['status' => 'done', 'html' => $finalHtml, 'reason' => 'Оформлено'];
    }

    /**
     * Вызов YandexGPT. Возвращает текст ответа (предположительно HTML).
     */
    private function callModel(string $chunkHtml): string {
        $payload = [
            'modelUri' => "gpt://{$this->folderId}/{$this->model}/latest",
            'completionOptions' => [
                'stream' => false,
                'temperature' => 0.0,
                'maxTokens' => '8000',
            ],
            'messages' => [
                ['role' => 'system', 'text' => $this->systemPrompt()],
                ['role' => 'user', 'text' => "Оформи фрагмент публикации:\n\n{$chunkHtml}"],
            ],
        ];

        $response = $this->sendRequest($payload);
        if ($response === null) {
            throw new Exception('Yandex GPT request failed');
        }

        $text = $response['result']['alternatives'][0]['message']['text'] ?? '';
        if (trim($text) === '') {
            throw new Exception('Empty response from Yandex GPT');
        }
        return $text;
    }

    private function systemPrompt(): string {
        return <<<PROMPT
Ты — верстальщик образовательного портала. Тебе дан фрагмент публикации в HTML. Улучши ТОЛЬКО оформление, не меняя содержание.

СТРОГИЕ ПРАВИЛА:
- НЕ меняй, не добавляй и не удаляй слова, числа, термины, формулы автора. Текст обязан остаться дословно тем же — ты только расставляешь теги разметки.
- Не исправляй орфографию и пунктуацию, не перефразируй, не сокращай, не дополняй.
- Выдели смысловые заголовки разделов тегом <h2>, подзаголовки — <h3>. Используй ТОЛЬКО <h2> и <h3>, не используй <h1>, <h4>, <h5>, <h6>. Заголовок — это короткая фраза-название раздела (например: «Введение», «Цель и задачи», «Ход занятия», «Заключение», «Список литературы»). Если такая фраза уже есть в тексте отдельной строкой — оберни её в <h2> или <h3>, не придумывая новых слов.
- Разбивай сплошной текст на логичные абзацы <p>.
- Перечисления оформляй списками: ВСЕ пункты одного перечисления — это отдельные <li> внутри ОДНОГО общего <ul> (или <ol> для нумерованных). НЕ вкладывай списки друг в друга и НЕ открывай новый <ul> для каждого пункта.
- Таблицы <table> сохраняй как есть.
- Разрешённые теги: <h2> <h3> <p> <strong> <em> <u> <ul> <ol> <li> <table> <thead> <tbody> <tr> <th> <td> <blockquote>. Никаких других тегов, атрибутов, классов, стилей.
- Не добавляй вступлений, комментариев, markdown, ``` — верни ТОЛЬКО готовый HTML-разметку фрагмента.
PROMPT;
    }

    /**
     * Разбить HTML на фрагменты по границам блочных элементов, не превышая бюджет символов.
     */
    private function splitIntoChunks(string $html): array {
        if (mb_strlen($html) <= self::CHUNK_CHARS) {
            return [$html];
        }

        // Разрез перед каждым открывающим блочным тегом верхнего уровня.
        $blocks = preg_split(
            '/(?<=>)\s*(?=<(?:p|h[1-6]|ul|ol|table|blockquote)\b)/i',
            $html
        );
        if (!$blocks) {
            return [$html];
        }

        $chunks = [];
        $current = '';
        foreach ($blocks as $block) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($block) > self::CHUNK_CHARS) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= $block;
        }
        if (trim($current) !== '') {
            $chunks[] = $current;
        }
        return $chunks ?: [$html];
    }

    /**
     * Сохранены ли слова автора между исходным ($a) и оформленным ($b) HTML.
     *
     * Сравнение по мультимножеству слов (без учёта порядка):
     *  - removed — слова из оригинала, пропавшие в результате (или изменённые): строгий порог,
     *    это и есть переписывание/потеря содержания;
     *  - added — слова, появившиеся в результате (обычно дублирование фразы в заголовке):
     *    допускается шире.
     */
    private function wordsPreserved(string $a, string $b): bool {
        // Короткие числа (≤2 цифр) исключаем из сверки: это почти всегда нумерация списков
        // («1.», «2.» …), которую модель сворачивает в <ol>. Числа из 3+ цифр (годы, количества)
        // остаются под защитой как осмысленное содержание.
        $isListMarker = static fn($w) => (bool) preg_match('/^\d{1,2}$/', $w);
        $freqA = array_count_values(array_filter($this->words($a), fn($w) => !$isListMarker($w)));
        $freqB = array_count_values(array_filter($this->words($b), fn($w) => !$isListMarker($w)));

        $total = array_sum($freqA);
        if ($total === 0) {
            return true; // в оригинале нет слов — терять нечего
        }

        $removed = 0;
        foreach ($freqA as $word => $cnt) {
            $inB = $freqB[$word] ?? 0;
            if ($cnt > $inB) {
                $removed += $cnt - $inB;
            }
        }

        $added = 0;
        foreach ($freqB as $word => $cnt) {
            $inA = $freqA[$word] ?? 0;
            if ($cnt > $inA) {
                $added += $cnt - $inA;
            }
        }

        return ($removed / $total) <= self::WORDS_REMOVED_TOLERANCE
            && ($added / $total) <= self::WORDS_ADDED_TOLERANCE;
    }

    /** Массив слов из текста без тегов (нижний регистр, только буквы/цифры). */
    private function words(string $html): array {
        $text = $this->normalizeText($html);
        if ($text === '') {
            return [];
        }
        return preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Текст без тегов: lowercase, всё не буквенно-цифровое → пробел, схлопывание пробелов. */
    private function normalizeText(string $html): string {
        // Заменяем теги на пробел (а не вырезаем), иначе соседние слова на границе
        // тегов склеятся: "</h2><p>Цель" → "Цель", а не "h2 Цель".
        $text = preg_replace('/<[^>]*>/', ' ', $html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');
        // ё→е: модель часто проставляет ё там, где у автора е (и наоборот). Это орфография,
        // а не изменение содержания — иначе предохранитель ложно отклоняет добросовестную вёрстку.
        $text = str_replace('ё', 'е', $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Чинит характерную ошибку модели: каждый пункт перечисления обёрнут в собственный
     * <ul>/<ol>, а закрывающие теги свалены в конец. Схлопываем во один плоский список.
     */
    private function normalizeLists(string $html): string {
        // Заголовки приводим к <h2>/<h3>: оглавление (buildArticleToc) строится только по ним,
        // а модель изредка проставляет h1/h4-h6 вопреки инструкции.
        $html = preg_replace('/<(\/?)h1\b[^>]*>/iu', '<$1h2>', $html);
        $html = preg_replace('/<(\/?)h[4-6]\b[^>]*>/iu', '<$1h3>', $html);
        // Пункты одного перечисления, ошибочно разорванные новым <ul>/<ol>, — в один список.
        $html = preg_replace('/<\/li>\s*<(?:ul|ol)>\s*<li>/iu', "</li>\n<li>", $html);
        // Пустые обёртки списков.
        $html = preg_replace('/<(ul|ol)>\s*<\/\1>/iu', '', $html);
        // Схлопываем подряд идущие открытия/закрытия списков (несколько проходов на вложенность).
        for ($i = 0; $i < 4; $i++) {
            $html = preg_replace('/<(ul|ol)>\s*<\1>/iu', '<$1>', $html);
            $html = preg_replace('/<\/(ul|ol)>\s*<\/\1>/iu', '</$1>', $html);
        }
        return trim($html);
    }

    /** Убрать markdown-обёртку ```html ... ``` если модель её добавила. */
    private function stripCodeFences(string $text): string {
        $text = trim($text);
        $text = preg_replace('/^```[a-z]*\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        return trim($text);
    }

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
            $this->log('API HTTP error', ['http_code' => $httpCode, 'response' => mb_substr((string)$response, 0, 500)]);
            return null;
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            $this->log('Failed to decode API response', ['response' => mb_substr((string)$response, 0, 500)]);
            return null;
        }
        return $decoded;
    }

    private function log(string $message, array $context = []): void {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $entry = date('Y-m-d H:i:s') . " [PUB_FORMAT] {$message}";
        if (!empty($context)) {
            $entry .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        file_put_contents($logDir . '/publication-format.log', $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
