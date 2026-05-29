<?php
/**
 * MaterialGenerator — оркестратор ИИ-генерации материалов ФОП.
 *
 * Шаги:
 *   1. Валидация типа материала и параметров.
 *   2. Списание токенов (UserTokens::charge) в транзакции.
 *   3. Запись material_generations(status=running).
 *   4. Вызов OpenRouterAIService::generateJson с шаблоном промпта из material_types.
 *   5. Сохранение файла через подходящий рендерер (PdfRenderer/DocxRenderer/PptxRenderer).
 *   6. Создание Material(status='draft', user_id=$userId, is_generated=1).
 *   7. material_generations(status=done, output_material_id, tokens_*).
 *   8. На любой ошибке после списания — refund токенов + status=failed.
 *
 * Все ошибки логируются в material_generations.error_message.
 * Вызывающий код (AJAX) должен ловить NotEnoughTokensException и
 * OpenRouterAIServiceException.
 */

require_once __DIR__ . '/renderers/PdfRenderer.php';
require_once __DIR__ . '/renderers/DocxRenderer.php';
require_once __DIR__ . '/renderers/PptxRenderer.php';
require_once __DIR__ . '/YandexArtService.php';

class MaterialGenerator
{
    private $pdo;
    private Database $db;
    private Material $materialObj;
    private MaterialType $typeObj;
    private UserTokens $tokens;
    private OpenRouterAIService $ai;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
        $this->materialObj = new Material($pdo);
        $this->typeObj = new MaterialType($pdo);
        $this->tokens = new UserTokens($pdo);
        $this->ai = new OpenRouterAIService();
    }

    /**
     * Сгенерировать материал.
     *
     * @param int    $userId
     * @param string $typeSlug — slug из material_types
     * @param array  $params   — поля формы (subject, class, topic, duration, features, ...)
     *
     * @return array Результат: [
     *   'material_id'   => int,
     *   'material_slug' => string,
     *   'generation_id' => int,
     *   'tokens_charged'=> int,
     *   'file_path'     => string,
     *   'file_format'   => string,
     * ]
     */
    public function generate(?int $userId, string $typeSlug, array $params, string $mode = 'full', ?string $funnelSessionId = null, ?string $ipAddress = null): array
    {
        $isPreview = ($mode === 'preview');
        if (!$isPreview && $userId === null) {
            throw new InvalidArgumentException('Полная генерация требует авторизации');
        }

        $type = $this->typeObj->getBySlug($typeSlug);
        if (!$type) {
            throw new InvalidArgumentException("Тип материала '{$typeSlug}' не найден");
        }
        $typeId = (int)$type['id'];
        $tokenCost = (int)($type['token_cost_default'] ?? 10);

        // 1. Списать токены до запуска ИИ — только в full-режиме. В preview генерация
        //    бесплатна, оплата переносится на разблокировку скачивания.
        $chargeTxnId = null;
        if (!$isPreview) {
            $chargeTxnId = $this->tokens->charge(
                $userId,
                $tokenCost,
                'generation',
                ['notes' => 'material_type=' . $typeSlug]
            );
        }

        // 2. Создать лог
        $generationId = $this->db->insert('material_generations', [
            'user_id' => $userId,
            'funnel_session_id' => $funnelSessionId,
            'ip_address' => $ipAddress,
            'material_type_id' => $typeId,
            'ai_model_used' => $this->ai->resolveModel($type['ai_model_key'] ?? 'default'),
            'status' => 'running',
            'mode' => $mode,
            'tokens_charged' => $isPreview ? 0 : $tokenCost,
            'input_params_json' => json_encode($params, JSON_UNESCAPED_UNICODE),
        ]);

        try {
            // 3. Сформировать промпт
            $prompt = $this->typeObj->renderPrompt($typeId, $params);
            if ($prompt === null || $prompt === '') {
                throw new RuntimeException('Пустой шаблон промпта для типа ' . $typeSlug);
            }

            $messages = [
                [
                    'role' => 'system',
                    'content' => 'Ты — опытный российский методист. Отвечай строго JSON по схеме из задания, без markdown-обёрток и пояснений.',
                ],
                ['role' => 'user', 'content' => $prompt],
            ];

            // 4. Вызов ИИ
            $aiResponse = $this->ai->generateJson(
                $type['ai_model_key'] ?? 'default',
                $messages,
                ['temperature' => 0.4, 'max_tokens' => 6000]
            );
            $aiData = $aiResponse['data'] ?? [];
            if (empty($aiData)) {
                throw new OpenRouterAIServiceException('ИИ вернул пустую структуру');
            }

            // 4b. Методическая самопроверка (второй проход) — best-effort.
            //     ИИ-методист сверяет результат с чек-листом ФГОС/ФОП и исправляет.
            //     Не должна валить генерацию: при любой ошибке оставляем исходный JSON.
            $selfCheckTokensIn = 0;
            $selfCheckTokensOut = 0;
            if (defined('MATERIAL_SELFCHECK_ENABLED') && MATERIAL_SELFCHECK_ENABLED) {
                try {
                    $checked = $this->selfCheck($type, $aiData, $params);
                    if (!empty($checked['data'])) {
                        $aiData = $checked['data'];
                        $selfCheckTokensIn  = (int)($checked['tokens_in'] ?? 0);
                        $selfCheckTokensOut = (int)($checked['tokens_out'] ?? 0);
                    }
                } catch (Throwable $scError) {
                    error_log('MaterialGenerator: self-check failed (non-fatal): ' . $scError->getMessage());
                }
            }

            // 5. Заголовок и slug материала
            $title = (string)($aiData['title']
                ?? ($params['topic'] ?? ($type['name'] . ' — материал')));
            $slug = $this->materialObj->generateSlug($title);

            // 6. Рендерим файл по output_format типа. В preview файл на диск не пишем —
            //    скачивание формирует нужный формат (DOCX/PPTX/PDF) на лету из ai_output_json
            //    (material-download.php), поэтому экономим время до оплаты.
            $renderResult = $isPreview ? null : $this->renderFile($type['output_format'], $aiData, $title, $slug);

            // Рабочий лист и тест — это бланки ученика: на странице/в превью показываем БЕЗ
            //    ключей (они уходят в раздел «Ключи для учителя» только в скачиваемом файле).
            $studentBlank = in_array($typeSlug, ['rabochiy-list', 'test-kontrolnaya'], true);

            // 7. Создаём Material как DRAFT — доступен автору, не публикуется в общий каталог.
            //    preview: is_unlocked=0, unlock_token_cost=цена; full: is_unlocked=1 (оплачено при генерации).
            $materialId = $this->materialObj->create([
                'user_id' => $userId,
                'funnel_session_id' => $isPreview ? $funnelSessionId : null,
                'title' => $title,
                'slug' => $slug,
                'description' => mb_substr(strip_tags((string)($aiData['intro'] ?? $aiData['title'] ?? '')), 0, 500),
                'content' => (new \MaterialHtmlRenderer())->render($aiData, !$studentBlank),
                'ai_output' => $aiData,
                'material_type_id' => $typeId,
                'file_path' => $renderResult['file_path'] ?? null,
                'file_size' => $renderResult['file_size'] ?? null,
                'file_format' => $renderResult['file_format'] ?? ($type['output_format'] ?? null),
                'is_generated' => true,
                'ai_model_used' => $aiResponse['model'] ?? null,
                'ai_prompt' => $prompt,
                'ai_params' => $params,
                'token_cost' => 0,
                'is_unlocked' => $isPreview ? 0 : 1,
                'unlock_token_cost' => $isPreview ? $tokenCost : 0,
                'status' => 'draft',
            ]);

            // Привязка к аудитории, если передана в params
            if (!empty($params['audience_category_ids']) || !empty($params['audience_type_ids']) || !empty($params['specialization_ids'])) {
                $this->materialObj->attachAudience(
                    $materialId,
                    (array)($params['audience_category_ids'] ?? []),
                    (array)($params['audience_type_ids'] ?? []),
                    (array)($params['specialization_ids'] ?? [])
                );
            }

            // 7b. Обложка через YandexART — best-effort, НЕ должна валить генерацию.
            //     Пропускаем для:
            //       - учительских материалов (techкарта/конспект/тест/КТП/классный час) —
            //         картинка там не нужна, экономим токены (needs_cover=0);
            //       - анонимного превью (контроль расходов на ИИ-картинки).
            $needsCover = !isset($type['needs_cover']) || (int)$type['needs_cover'] === 1;
            $skipCover = !$needsCover || ($isPreview && $userId === null);
            try {
                $art = $skipCover ? null : new YandexArtService();
                if ($art && $art->isEnabled()) {
                    $imagePrompt = trim((string)($aiData['image_prompt'] ?? ''));
                    if ($imagePrompt === '') {
                        $imagePrompt = 'Образовательная иллюстрация по теме «' . $title . '»'
                            . (!empty($params['subject']) ? ', предмет ' . $params['subject'] : '')
                            . ', плоский векторный стиль, спокойная палитра, без текста на картинке';
                    }
                    $coverPath = $art->generateAndStore($imagePrompt, $slug, '1:1');
                    if ($coverPath !== null) {
                        $this->materialObj->update($materialId, [
                            'preview_image_url' => '/' . ltrim($coverPath, '/'),
                        ]);
                    }
                }
            } catch (Throwable $imgError) {
                error_log('MaterialGenerator: cover image failed (non-fatal): ' . $imgError->getMessage());
            }

            // 8. Финализируем лог
            $this->db->update(
                'material_generations',
                [
                    'status' => 'done',
                    'output_material_id' => $materialId,
                    'ai_tokens_in' => ($aiResponse['tokens_in'] ?? 0) + $selfCheckTokensIn,
                    'ai_tokens_out' => ($aiResponse['tokens_out'] ?? 0) + $selfCheckTokensOut,
                    'ai_model_used' => $aiResponse['model'] ?? null,
                    'finished_at' => date('Y-m-d H:i:s'),
                ],
                'id = ?',
                [$generationId]
            );

            return [
                'material_id'       => $materialId,
                'material_slug'     => $slug,
                'generation_id'     => $generationId,
                'mode'              => $mode,
                'tokens_charged'    => $isPreview ? 0 : $tokenCost,
                'unlock_token_cost' => $isPreview ? $tokenCost : 0,
                'file_path'         => $renderResult['file_path'] ?? null,
                'file_format'       => $renderResult['file_format'] ?? ($type['output_format'] ?? null),
            ];
        } catch (Throwable $e) {
            // Refund только если в этом вызове было списание (full-режим)
            if ($chargeTxnId !== null) {
                try {
                    $this->tokens->refund($userId, $tokenCost, $chargeTxnId, [
                        'generation_id' => $generationId,
                        'notes' => 'auto-refund on generation failure',
                    ]);
                } catch (Throwable $refundError) {
                    error_log('MaterialGenerator: refund failed for txn=' . $chargeTxnId . ': ' . $refundError->getMessage());
                }
            }

            $this->db->update(
                'material_generations',
                [
                    'status' => 'failed',
                    'error_message' => mb_substr($e->getMessage(), 0, 65000),
                    'finished_at' => date('Y-m-d H:i:s'),
                ],
                'id = ?',
                [$generationId]
            );

            throw $e;
        }
    }

    /**
     * Методическая самопроверка: второй проход ИИ-методиста по чек-листу ФГОС/ФОП.
     * На вход — сгенерированный JSON, на выход — исправленный JSON в той же структуре.
     * Если правок не требуется, модель возвращает тот же объект. Возвращает массив
     * вида ['data' => ..., 'tokens_in' => int, 'tokens_out' => int] или [] при неудаче.
     */
    private function selfCheck(array $type, array $aiData, array $params): array
    {
        $typeName = (string)($type['name'] ?? 'материал');
        $program = (string)($params['program'] ?? '—');
        $stage = MaterialType::deriveStage((string)($params['class'] ?? ''), $program);
        if ($stage === '') {
            $stage = '—';
        }

        $checklist = <<<TXT
Ты — придирчивый методист-эксперт, проверяющий разработку на соответствие ФГОС/ФОП и принципам дидактики. Тебе дан JSON сгенерированного материала «{$typeName}» (ступень: {$stage}, программа: {$program}). Исправь его строго по чек-листу и верни ИСПРАВЛЕННЫЙ JSON В ТОЙ ЖЕ СТРУКТУРЕ И С ТЕМИ ЖЕ КЛЮЧАМИ (ничего не переименовывай, не удаляй обязательные поля). Если материал уже идеален — верни его без изменений.

ЧЕК-ЛИСТ (исправляй найденное):
1. Фактические ошибки: проверь корректность фактов (астрономия, климат/времена года, фонетика — различай ЗВУК и БУКВУ, и т.п.). Любую фактическую ошибку исправь.
2. Бланк ученика НЕ должен содержать готовых ответов/решений: в tasks.content и questions нет правильных ответов; они только в answer_key. Если ответ вписан в задание — убери его в answer_key.
3. В answer_key и любых полях не должно быть служебных артефактов («Array», пустых заглушек, англоязычных меток типа «fill»/«choose» в видимом тексте). Замени на корректный русский текст.
4. Планируемые результаты (предметные/метапредметные/личностные) присутствуют, проверяемы и отделены от УУД, соответствуют ступени {$stage}.
5. Возрастная адекватность для ступени {$stage}: нет заданий не по возрасту, тон и сложность уместны.
6. Дифференциация: есть задания базового и повышенного уровня (level), критерии оценивания для открытых/творческих заданий.
7. Нет дословных дублей текста (вводный абзац, повторяющиеся задания).
8. Для классного часа на острую тему (буллинг, зависимости, насилие) присутствуют правила психологической безопасности (safety_rules).

Верни ТОЛЬКО исправленный JSON, без markdown и без комментариев.
TXT;

        $aiJson = json_encode($aiData, JSON_UNESCAPED_UNICODE);
        // Крупные материалы (длинные КТП/презентации) могут не уместиться в окно модели
        // вместе с инструкцией и ответом → самопроверка тихо пропустится. Логируем, чтобы видеть.
        if (mb_strlen($aiJson) > 12000) {
            error_log('MaterialGenerator: self-check skipped/at-risk — большой JSON (' . mb_strlen($aiJson) . ' симв.) для типа ' . ($type['slug'] ?? '?'));
        }

        $messages = [
            ['role' => 'system', 'content' => 'Ты — методист-редактор. Возвращаешь строго валидный JSON в исходной структуре, без markdown и пояснений.'],
            ['role' => 'user', 'content' => $checklist . "\n\nJSON материала:\n" . $aiJson],
        ];

        // Структурированная модель + низкая температура: задача — аккуратная правка, не творчество.
        $resp = $this->ai->generateJson('structured', $messages, ['temperature' => 0.2, 'max_tokens' => 6000]);
        $data = $resp['data'] ?? [];
        // Защита: не принимаем явно деградировавший ответ. У материала должен остаться
        // хотя бы один опорный ключ (заголовок/раздел/набор строк/заданий/слайдов).
        $anchors = ['title', 'section', 'rows', 'tasks', 'questions', 'slides', 'stages', 'structure'];
        $hasAnchor = false;
        foreach ($anchors as $a) {
            if (!empty($data[$a])) { $hasAnchor = true; break; }
        }
        if (empty($data) || !$hasAnchor) {
            return [];
        }
        return [
            'data' => $data,
            'tokens_in' => $resp['tokens_in'] ?? 0,
            'tokens_out' => $resp['tokens_out'] ?? 0,
        ];
    }

    private function renderFile(string $outputFormat, array $aiData, string $title, string $slug): array
    {
        return match ($outputFormat) {
            'pptx' => (new PptxRenderer())->render($aiData, $title, $slug),
            'docx' => (new DocxRenderer())->render($aiData, $title, $slug),
            default => (new PdfRenderer())->render($aiData, $title, $slug),
        };
    }
}
