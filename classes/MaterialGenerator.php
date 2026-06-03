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
require_once __DIR__ . '/../includes/text-helper.php';

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
        $generationId = (int)$this->db->insert('material_generations', [
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

        return $this->executeGeneration($generationId, $type, $params, $mode, $userId, $funnelSessionId, $chargeTxnId, $tokenCost);
    }

    /**
     * Поставить генерацию в очередь (async): создаёт строку material_generations со
     * статусом 'pending' и сразу возвращает её id, не запуская ИИ. Обработку выполняет
     * фоновый воркер (cron/process-material-generations.php) через runPending().
     *
     * В full-режиме токены списываются здесь же (резерв до старта ИИ); id транзакции
     * сохраняется в input_params_json под ключом '_charge_txn_id', чтобы воркер мог
     * вернуть токены при сбое (отдельной колонки под txn нет).
     */
    public function enqueue(?int $userId, string $typeSlug, array $params, string $mode = 'preview', ?string $funnelSessionId = null, ?string $ipAddress = null): int
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

        $chargeTxnId = null;
        if (!$isPreview) {
            $chargeTxnId = $this->tokens->charge(
                $userId,
                $tokenCost,
                'generation',
                ['notes' => 'material_type=' . $typeSlug]
            );
        }

        $stored = $params;
        if ($chargeTxnId !== null) {
            $stored['_charge_txn_id'] = $chargeTxnId;
        }

        return (int)$this->db->insert('material_generations', [
            'user_id' => $userId,
            'funnel_session_id' => $funnelSessionId,
            'ip_address' => $ipAddress,
            'material_type_id' => $typeId,
            'ai_model_used' => $this->ai->resolveModel($type['ai_model_key'] ?? 'default'),
            'status' => 'pending',
            'mode' => $mode,
            'tokens_charged' => $isPreview ? 0 : $tokenCost,
            'input_params_json' => json_encode($stored, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Обработать одну отложенную задачу (вызывается фоновым воркером).
     * Атомарно захватывает строку (pending → running): если её уже взял другой
     * процесс (cron + on-demand spawn одновременно), rowCount()===0 → тихо выходим.
     */
    public function runPending(int $generationId): void
    {
        // Атомарный захват — защита от двойного запуска. started_at фиксирует момент
        // СТАРТА обработки (не создания) — по нему recovery отличает реально зависшие.
        $claimed = $this->db->execute(
            "UPDATE material_generations SET status = 'running', started_at = NOW() WHERE id = ? AND status = 'pending'",
            [$generationId]
        );
        if ($claimed === 0) {
            return;
        }

        $row = $this->db->queryOne('SELECT * FROM material_generations WHERE id = ?', [$generationId]);
        if (!$row) {
            return;
        }

        $type = $this->typeObj->getById((int)$row['material_type_id']);
        if (!$type) {
            $this->db->update('material_generations', [
                'status' => 'failed',
                'error_message' => 'Тип материала не найден (id=' . (int)$row['material_type_id'] . ')',
                'finished_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$generationId]);
            return;
        }

        $params = json_decode((string)($row['input_params_json'] ?? '[]'), true);
        if (!is_array($params)) {
            $params = [];
        }
        $chargeTxnId = isset($params['_charge_txn_id']) ? (int)$params['_charge_txn_id'] : null;
        unset($params['_charge_txn_id']);

        $userId    = $row['user_id'] !== null ? (int)$row['user_id'] : null;
        $mode      = (string)($row['mode'] ?? 'preview');
        $funnelSid = $row['funnel_session_id'] ?? null;
        $tokenCost = (int)($row['tokens_charged'] ?? 0);
        if ($tokenCost === 0) {
            $tokenCost = (int)($type['token_cost_default'] ?? 10);
        }

        // executeGeneration сам ставит done/failed и делает refund при сбое.
        $this->executeGeneration($generationId, $type, $params, $mode, $userId, $funnelSid, $chargeTxnId, $tokenCost);
    }

    /**
     * Ядро генерации: шаги 3–8 (промпт → ИИ → самопроверка → рендер → Material → done).
     * Лог material_generations уже создан и переведён в 'running' вызывающим кодом.
     * При любой ошибке после старта — refund токенов (если было списание) + status='failed'.
     */
    private function executeGeneration(int $generationId, array $type, array $params, string $mode, ?int $userId, ?string $funnelSessionId, ?int $chargeTxnId, int $tokenCost): array
    {
        $isPreview = ($mode === 'preview');
        $typeId = (int)$type['id'];
        $typeSlug = (string)($type['slug'] ?? '');

        try {
            // 3. Сформировать промпт
            $prompt = $this->typeObj->renderPrompt($typeId, $params);
            if ($prompt === null || $prompt === '') {
                throw new RuntimeException('Пустой шаблон промпта для типа ' . $typeSlug);
            }

            $messages = [
                [
                    'role' => 'system',
                    'content' => 'Ты — опытный российский методист. Отвечай строго JSON по схеме из задания, без markdown-обёрток и пояснений. ВЕСЬ текст пиши ИСКЛЮЧИТЕЛЬНО на русском языке кириллицей. КАТЕГОРИЧЕСКИ запрещены иероглифы и любые символы китайского, японского, корейского и других нелатинских/некириллических алфавитов — даже единичные. Латиница допустима только для общепринятых терминов и аббревиатур.',
                ],
                ['role' => 'user', 'content' => $prompt],
            ];

            // 4. Вызов ИИ. Длинным типам (развёрнутый ход/много слайдов/строк) даём больше
            //    места под ответ — иначе JSON обрывается на середине и не парсится.
            $longTypes = ['konspekt-uroka', 'prezentatsiya', 'ktp-fragment', 'tehkarta-uroka'];
            $maxTokens = in_array($typeSlug, $longTypes, true) ? 8000 : 6000;
            $aiResponse = $this->ai->generateJson(
                $type['ai_model_key'] ?? 'default',
                $messages,
                ['temperature' => 0.4, 'max_tokens' => $maxTokens]
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

            // 4c. Жёсткая страховка: убираем чужие письменности (иероглифы и т.п.),
            //     которые ИИ-модель иногда «протекает» в русский текст
            //     (напр. «Оценить自己的 работу»). Промпт это запрещает, но гарантию
            //     даёт только детерминированная пост-обработка по всему JSON.
            $aiData = strip_foreign_scripts_deep($aiData);

            // 5. Заголовок и slug материала
            $title = (string)($aiData['title']
                ?? ($params['topic'] ?? ($type['name'] . ' — материал')));
            $slug = $this->materialObj->generateSlug($title);

            // 6. Рендерим файл по output_format типа. В preview файл на диск не пишем —
            //    скачивание формирует нужный формат (DOCX/PPTX/PDF) на лету из ai_output_json
            //    (material-download.php), поэтому экономим время до оплаты.
            $renderResult = $isPreview ? null : $this->renderFile($type['output_format'], $aiData, $title, $slug, $typeSlug);

            // Рабочий лист и тест — это бланки ученика: на странице/в превью показываем БЕЗ
            //    ключей (они уходят в раздел «Ключи для учителя» только в скачиваемом файле).
            $studentBlank = in_array($typeSlug, ['rabochiy-list', 'test-kontrolnaya'], true);

            // 7. Создаём Material.
            //    full: сразу PUBLISHED — материал автоматически попадает в общий каталог
            //          (/materialy/katalog/) без действий пользователя, is_unlocked=1 (оплачено при генерации).
            //    preview: остаётся DRAFT — анонимный тизер до регистрации/оплаты в каталог не выносим,
            //             is_unlocked=0, unlock_token_cost=цена.
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
                'status' => $isPreview ? 'draft' : 'published',
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

            // 7b. Финализируем лог СРАЗУ, как только готов контент материала.
            //     Обложку (YandexART, +10–40с) генерируем уже ПОСЛЕ done — иначе её
            //     задержка раздувала общее время и фронт ловил ложный 5-минутный таймаут
            //     на длинных презентациях. Материал доступен сразу; обложка дорисуется фоном.
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

            // 8. Обложка через YandexART — best-effort, НЕ должна валить генерацию.
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
        $features = trim((string)($params['features'] ?? '')) !== '' ? (string)$params['features'] : 'не указаны';
        $stage = MaterialType::deriveStage((string)($params['class'] ?? ''), $program);
        if ($stage === '') {
            $stage = '—';
        }

        $checklist = <<<TXT
Ты — придирчивый методист-эксперт высшей категории, проверяющий разработку на соответствие ФГОС/ФОП и принципам дидактики. Тебе дан JSON сгенерированного материала «{$typeName}» (ступень: {$stage}, программа: {$program}).

ТВОЯ ЗАДАЧА — довести материал до уровня «рекомендовано к использованию» (5 из 5). Ты ОБЯЗАН существенно переработать слабые места: не ограничивайся косметикой. Если содержание поверхностное, не по возрасту, фактически неверное или это заглушка — перепиши его глубоко и предметно, наполни конкретикой уровня опытного учителя. Сохрани СТРУКТУРУ И КЛЮЧИ JSON (не переименовывай и не удаляй поля), но содержимое полей улучшай настолько, насколько нужно. Верни ИСПРАВЛЕННЫЙ JSON.

ЧЕК-ЛИСТ (исправляй найденное, не жалея усилий):
1. Фактические ошибки: проверь корректность ВСЕХ фактов (астрономия, климат/времена года, биология, история, фонетика — различай ЗВУК [в скобках] и БУКВУ). Любую неточность исправь на научно корректную.
2. СООТВЕТСТВИЕ ТЕМЫ КЛАССУ: тема и глубина содержания должны ТОЧНО соответствовать программе заявленного класса/ступени {$stage} по {$program}. Проверь по реальной программе РФ. ОРИЕНТИРЫ-ГРАНИЦЫ (типичные ошибки переноса между классами):
   • Математика НОО: умножение двузначного на однозначное УСТНЫМ приёмом (разрядные слагаемые) — 3 класс; письменное умножение В СТОЛБИК (любого числа на однозначное) — 4 класс; задачи на движение и формула пути S=v·t — 4 класс; в 3 классе нет письменного столбика и нет скорости как величины.
   • Русский язык: различение звук/буква, гласные/согласные, твёрдые/мягкие — 1 класс; ЙОТИРОВАННЫЕ буквы (я,е,ё,ю обозначают 2 звука), счёт «букв vs звуков», транскрипция — 2 класс (НЕ давать в 1 классе даже как advanced); разряды прилагательных, склонение, согласование, краткие формы, НЕ с прилагательными, суффиксы — 5 класс (не уровень «найди прилагательное»).
   ВАЖНО: если конкретный приём/тема по программе относятся к ДРУГОМУ классу, чем заявленный — НЕ сдвигай класс материала; вместо этого приведи СОДЕРЖАНИЕ к допустимому для заявленного класса уровню (либо замени приём на программный для этого класса, либо переформулируй тему). Заявленный класс ({$stage}) — главный, под него подгоняй содержание.
3. ТАЙМИНГ: если есть этапы с длительностью (duration_min), их сумма должна равняться заявленной длительности урока. Если сумма не сходится — исправь длительности этапов.
4. КЛЮЧИ И ВОПРОСЫ ТЕСТА: каждый правильный ответ должен реально присутствовать среди вариантов; слова-эталоны должны существовать в русском языке и быть посильны классу; для вопроса с type=single правильный ответ строго ОДИН (если правильных несколько — поставь type=multiple). Проверь, что пропущенная буква/звук в задании совпадает с ключом. ОЦЕНИВАНИЕ ТЕСТА: у каждого вопроса должно быть указано число баллов; сумма баллов всех вопросов ДОЛЖНА равняться note.max_score, а шкала (note.scale) — соответствовать этой сумме. Если не сходится — пересчитай. Для начальной школы (1-2 класс) не злоупотребляй развёрнутыми ПИСЬМЕННЫМИ ответами (блок С) — у первоклассника письмо только формируется; такие задания делай необязательными/устными или заменяй выбором.
5. Бланк ученика НЕ должен содержать готовых ответов/решений: в tasks.content и questions нет правильных ответов; они только в answer_key.
6. Артефакты: в answer_key и любых полях не должно быть «Array», пустых заглушек, англоязычных меток («fill»/«choose»). Замени на корректный русский текст.
7. ТЕРМИНОЛОГИЯ ПО СТУПЕНИ: для ДО (детский сад) НЕ употребляй школьный термин «УУД» — используй «целевые ориентиры»/«планируемые результаты» ФОП ДО; «контроль» замени на «педагогическое наблюдение». Для речевого развития не подменяй его познавательным (классификация — это познавательное).
8. Планируемые результаты (предметные/метапредметные/личностные) проверяемы, отделены от УУД, не дублируют их дословно, соответствуют ступени {$stage}.
9. КОНКРЕТИКА, НЕ ЗАГЛУШКИ: в полях narrative/structure должны быть реальные реплики учителя, тексты кейсов, конкретные задания — не общие фразы вроде «ученики обсуждают» без содержания. Разверни заглушки в проводимый сценарий.
10. Особенности группы ({$features}) учтены конкретными адаптациями на этапах урока, а не только в домашнем задании.
11. Дифференциация: есть задания базового и повышенного уровня. ВАЖНО: значение поля level — строго "base" или "advanced" (НЕ "increased", не "повышенный" — нормализуй к "advanced"). Критерии оценивания для открытых/творческих заданий обязательны. Нет дословных дублей текста.
13. ОБЪЁМ РАБОЧЕГО ЛИСТА: рабочий лист (поле tasks) должен содержать 5–8 заданий, выстроенных от простого к сложному, с охватом разных подтем программы (не 2–3 однотипных). Если заданий меньше 5 — добавь недостающие, оставаясь в рамках темы и класса. Добавь краткую шкалу оценивания (сколько верных = «5/4/3»), если её нет.
12. Для классного часа на острую тему (буллинг, зависимости, насилие) есть правила психологической безопасности (safety_rules); избегай травматичного отыгрывания ролей агрессор/жертва и дебатов «за/против» по недебатируемой теме.

Верни ТОЛЬКО исправленный JSON, без markdown и без комментариев.
TXT;

        $aiJson = json_encode($aiData, JSON_UNESCAPED_UNICODE);
        // Review-модель часто СУЩЕСТВЕННО дорабатывает (углубляет) материал, поэтому даём
        // большой лимит под исправленный ответ. У Gemini 2.5 Pro контекст это позволяет.
        $longTypes = ['konspekt-uroka', 'prezentatsiya', 'ktp-fragment', 'tehkarta-uroka', 'rabochiy-list', 'test-kontrolnaya'];
        $scMaxTokens = in_array((string)($type['slug'] ?? ''), $longTypes, true) ? 12000 : 8000;
        if (mb_strlen($aiJson) > 14000) {
            error_log('MaterialGenerator: self-check at-risk — большой JSON (' . mb_strlen($aiJson) . ' симв.) для типа ' . ($type['slug'] ?? '?'));
        }

        $messages = [
            ['role' => 'system', 'content' => 'Ты — методист-редактор. Возвращаешь строго валидный JSON в исходной структуре, без markdown и пояснений. Весь текст — только на русском языке кириллицей; иероглифы и символы китайского/японского/корейского и прочих нелатинских/некириллических алфавитов запрещены.'],
            ['role' => 'user', 'content' => $checklist . "\n\nJSON материала:\n" . $aiJson],
        ];

        // Сильная review-модель (Gemini 2.5 Pro) + низкая температура: задача — поймать
        // фактические/программные ошибки и аккуратно исправить, не сочинять заново.
        $resp = $this->ai->generateJson('review', $messages, ['temperature' => 0.2, 'max_tokens' => $scMaxTokens]);
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

    private function renderFile(string $outputFormat, array $aiData, string $title, string $slug, string $typeSlug = ''): array
    {
        return match ($outputFormat) {
            'pptx' => (new PptxRenderer())->render($aiData, $title, $slug),
            'docx' => (new DocxRenderer())->render($aiData, $title, $slug),
            default => (new PdfRenderer())->render($aiData, $title, $slug, $typeSlug),
        };
    }

    /**
     * Недавние генерации пользователя для личного кабинета — чтобы после закрытия
     * вкладки было видно, что с генерацией: в очереди / идёт / готово / ошибка.
     *
     * Возвращает строки material_generations с человекочитаемым заголовком (тема из
     * input_params_json), названием типа и slug готового материала (если done).
     *
     * @return array<int,array{
     *   id:int, status:string, mode:string, created_at:string, finished_at:?string,
     *   error_message:?string, type_name:string, type_slug:string,
     *   topic:string, material_slug:?string, material_id:?int
     * }>
     */
    public static function getRecentForUser($pdo, int $userId, int $limit = 20): array
    {
        $db = new Database($pdo);
        $limit = max(1, min(100, $limit));
        $rows = $db->query(
            "SELECT g.id, g.status, g.mode, g.created_at, g.finished_at, g.error_message,
                    g.input_params_json, g.output_material_id,
                    mt.name AS type_name, mt.slug AS type_slug,
                    m.slug AS material_slug
             FROM material_generations g
             LEFT JOIN material_types mt ON mt.id = g.material_type_id
             LEFT JOIN materials m ON m.id = g.output_material_id
             WHERE g.user_id = ?
             ORDER BY g.created_at DESC
             LIMIT {$limit}",
            [$userId]
        );

        $out = [];
        foreach ($rows as $r) {
            $params = json_decode((string)($r['input_params_json'] ?? '[]'), true);
            $params = is_array($params) ? $params : [];
            $topic = trim((string)($params['topic'] ?? ''));
            if ($topic === '') {
                $topic = trim((string)($params['subject'] ?? ''));
            }
            $out[] = [
                'id'            => (int)$r['id'],
                'status'        => (string)$r['status'],
                'mode'          => (string)$r['mode'],
                'created_at'    => (string)$r['created_at'],
                'finished_at'   => $r['finished_at'] !== null ? (string)$r['finished_at'] : null,
                'error_message' => $r['error_message'] !== null ? (string)$r['error_message'] : null,
                'type_name'     => (string)($r['type_name'] ?? 'Материал'),
                'type_slug'     => (string)($r['type_slug'] ?? ''),
                'topic'         => $topic !== '' ? $topic : (string)($r['type_name'] ?? 'Материал'),
                'material_slug' => $r['material_slug'] !== null ? (string)$r['material_slug'] : null,
                'material_id'   => $r['output_material_id'] !== null ? (int)$r['output_material_id'] : null,
            ];
        }
        return $out;
    }
}
