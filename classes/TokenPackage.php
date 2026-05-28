<?php
/**
 * TokenPackage — тарифные пакеты токенов для покупки через Yookassa.
 *
 * Зачисление токенов после успешной оплаты делается на webhook'е
 * (api/webhook/yookassa.php) — там в metadata платежа лежит package_id и
 * user_id; вызывается UserTokens::credit($userId, $tokens + $bonus, 'purchase',
 * ['payment_id' => ..., 'package_id' => ...]).
 */

class TokenPackage
{
    private $db;

    public function __construct($pdo)
    {
        $this->db = new Database($pdo);
    }

    public function getActive(): array
    {
        return $this->db->query(
            "SELECT * FROM token_packages WHERE is_active = 1 ORDER BY display_order ASC, price_rub ASC"
        );
    }

    public function getById(int $id): ?array
    {
        $row = $this->db->queryOne("SELECT * FROM token_packages WHERE id = ?", [$id]);
        return $row ?: null;
    }

    /**
     * Сколько токенов даёт пакет суммарно (основные + бонусные).
     */
    public function totalTokens(array $package): int
    {
        return (int)$package['tokens'] + (int)($package['bonus_tokens'] ?? 0);
    }

    public function create(array $data): int
    {
        return $this->db->insert('token_packages', [
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'tokens' => (int)$data['tokens'],
            'bonus_tokens' => (int)($data['bonus_tokens'] ?? 0),
            'price_rub' => $data['price_rub'],
            'display_order' => $data['display_order'] ?? 0,
            'is_active' => $data['is_active'] ?? 1,
        ]);
    }

    public function update(int $id, array $data): int
    {
        $allowed = ['name', 'description', 'tokens', 'bonus_tokens', 'price_rub', 'display_order', 'is_active'];
        $update = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $update[$f] = $data[$f];
            }
        }
        if (empty($update)) {
            return 0;
        }
        return $this->db->update('token_packages', $update, 'id = ?', [$id]);
    }

    public function delete(int $id): int
    {
        return $this->db->delete('token_packages', 'id = ?', [$id]);
    }
}
