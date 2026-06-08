<?php
/**
 * ParticipantGroup
 * Метаданные групповой заявки (групповые дипломы конкурсов/олимпиад).
 *
 * Хранит зафиксированный размер группы и % объёмной скидки на момент создания,
 * чтобы тариф не «плыл» при частичном удалении/оплате позиций корзины.
 *
 * Связь: participant_groups.id == registrations.group_batch_id
 *                              == olympiad_registrations.group_batch_id
 */

class ParticipantGroup {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Сгенерировать UUID v4 без внешних зависимостей.
     */
    public static function generateBatchId(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // версия 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // вариант
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Создать запись группы.
     * @param array $data id, user_id, product_type, product_id, size, discount_percent
     * @return string batch_id
     */
    public function create(array $data): string {
        $this->db->insert('participant_groups', [
            'id'               => $data['id'],
            'user_id'          => $data['user_id'],
            'product_type'     => $data['product_type'],
            'product_id'       => $data['product_id'],
            'size'             => $data['size'],
            'discount_percent' => $data['discount_percent'] ?? 0,
        ]);
        return $data['id'];
    }

    /**
     * Получить группу по batch_id.
     */
    public function getByBatchId(string $batchId): ?array {
        $row = $this->db->queryOne(
            "SELECT * FROM participant_groups WHERE id = ?",
            [$batchId]
        );
        return $row ?: null;
    }

    /**
     * Процент объёмной скидки группы (0, если группы нет).
     */
    public function getDiscountPercent(string $batchId): int {
        $row = $this->getByBatchId($batchId);
        return $row ? (int)$row['discount_percent'] : 0;
    }

    /**
     * Недавняя группа того же учителя на тот же продукт того же размера
     * (dedup-гард от двойного сабмита). $withinMinutes — окно в минутах.
     */
    public function findRecentDuplicate(int $userId, string $productType, int $productId, int $size, int $withinMinutes = 30): ?array {
        $row = $this->db->queryOne(
            "SELECT * FROM participant_groups
             WHERE user_id = ? AND product_type = ? AND product_id = ? AND size = ?
               AND created_at >= (NOW() - INTERVAL ? MINUTE)
             ORDER BY created_at DESC
             LIMIT 1",
            [$userId, $productType, $productId, $size, $withinMinutes]
        );
        return $row ?: null;
    }
}
