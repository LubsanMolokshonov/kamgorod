<?php
/**
 * MaterialAdapter — адаптация чужого учебного материала через ИИ.
 *
 * Пользователь вставляет свой текст (конспект, ТЗ, фрагмент рабочей программы) +
 * инструкцию («адаптируй под 2 класс с ОВЗ», «переделай под ФОП-2026»). ИИ
 * переписывает текст. Списываем фиксированную сумму токенов независимо от типа.
 *
 * Возвращает структуру:
 *   [
 *     'adaptation_id' => int,
 *     'result_text'   => string,
 *     'tokens_charged' => int,
 *   ]
 */

class MaterialAdapter
{
    private const TOKEN_COST = 10;
    private const MAX_SOURCE_CHARS = 20000;
    private const MAX_INSTRUCTIONS_CHARS = 2000;

    private $pdo;
    private Database $db;
    private UserTokens $tokens;
    private OpenRouterAIService $ai;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
        $this->tokens = new UserTokens($pdo);
        $this->ai = new OpenRouterAIService();
    }

    public function adapt(int $userId, string $sourceText, string $instructions): array
    {
        $sourceText = trim($sourceText);
        $instructions = trim($instructions);

        if ($sourceText === '') {
            throw new InvalidArgumentException('Не указан исходный текст');
        }
        if ($instructions === '') {
            throw new InvalidArgumentException('Не указано, как адаптировать материал');
        }

        $sourceText = mb_substr($sourceText, 0, self::MAX_SOURCE_CHARS);
        $instructions = mb_substr($instructions, 0, self::MAX_INSTRUCTIONS_CHARS);

        $chargeTxnId = $this->tokens->charge(
            $userId,
            self::TOKEN_COST,
            'adaptation',
            ['notes' => 'material adapter']
        );

        $adaptationId = $this->db->insert('material_adaptations', [
            'user_id' => $userId,
            'source_text' => $sourceText,
            'instructions' => $instructions,
            'ai_model_used' => $this->ai->resolveModel('default'),
            'tokens_charged' => self::TOKEN_COST,
            'status' => 'running',
        ]);

        try {
            $messages = [
                [
                    'role' => 'system',
                    'content' => 'Ты — опытный российский методист. Адаптируй учебные материалы под требования учителя, сохраняя педагогический смысл и структуру. Отвечай только адаптированным текстом, без вступительных фраз и без markdown-кода.',
                ],
                [
                    'role' => 'user',
                    'content' => "Адаптируй следующий учебный материал.\n\n"
                        . "Инструкция: {$instructions}\n\n"
                        . "Исходный материал:\n---\n{$sourceText}\n---",
                ],
            ];

            $aiResponse = $this->ai->chat('default', $messages, [
                'temperature' => 0.5,
                'max_tokens' => 6000,
            ]);

            $resultText = trim($aiResponse['content']);
            if ($resultText === '') {
                throw new OpenRouterAIServiceException('ИИ вернул пустой адаптированный текст');
            }

            $this->db->update(
                'material_adaptations',
                [
                    'result_text' => $resultText,
                    'ai_model_used' => $aiResponse['model'] ?? null,
                    'ai_tokens_in' => $aiResponse['tokens_in'] ?? 0,
                    'ai_tokens_out' => $aiResponse['tokens_out'] ?? 0,
                    'status' => 'done',
                    'finished_at' => date('Y-m-d H:i:s'),
                ],
                'id = ?',
                [$adaptationId]
            );

            return [
                'adaptation_id' => $adaptationId,
                'result_text' => $resultText,
                'tokens_charged' => self::TOKEN_COST,
            ];
        } catch (Throwable $e) {
            try {
                $this->tokens->refund($userId, self::TOKEN_COST, $chargeTxnId, [
                    'notes' => 'auto-refund on adaptation failure: adaptation_id=' . $adaptationId,
                ]);
            } catch (Throwable $refundError) {
                error_log('MaterialAdapter: refund failed: ' . $refundError->getMessage());
            }
            $this->db->update(
                'material_adaptations',
                [
                    'status' => 'failed',
                    'error_message' => mb_substr($e->getMessage(), 0, 65000),
                    'finished_at' => date('Y-m-d H:i:s'),
                ],
                'id = ?',
                [$adaptationId]
            );
            throw $e;
        }
    }

    public function getById(int $id): ?array
    {
        $row = $this->db->queryOne("SELECT * FROM material_adaptations WHERE id = ?", [$id]);
        return $row ?: null;
    }

    public function getByUser(int $userId, int $limit = 20): array
    {
        return $this->db->query(
            "SELECT * FROM material_adaptations
              WHERE user_id = ? AND status = 'done'
              ORDER BY created_at DESC
              LIMIT ?",
            [$userId, $limit]
        );
    }

    public static function tokenCost(): int
    {
        return self::TOKEN_COST;
    }
}
