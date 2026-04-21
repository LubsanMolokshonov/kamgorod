<?php
declare(strict_types=1);

/**
 * Оркестратор чата: сессия → поиск товаров → промпт → YandexGPT → запись ответа.
 */
class ChatService
{
    private PDO $pdo;
    private SessionStore $sessions;
    private ProductSearch $search;
    private YandexGPTClient $gpt;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->sessions = new SessionStore($pdo);
        $this->search = new ProductSearch($pdo);
        $this->gpt = new YandexGPTClient(25);
    }

    /**
     * @param array{session_token:string, message:string, user_id:?int, user_email:?string, page_url:?string, cart:?array} $input
     */
    public function handle(array $input): array
    {
        $token = trim((string)($input['session_token'] ?? ''));
        $message = trim((string)($input['message'] ?? ''));
        $userId = $input['user_id'] ?? null;
        $userEmail = $input['user_email'] ?? null;
        $pageUrl = $input['page_url'] ?? null;
        $cart = is_array($input['cart'] ?? null) ? $input['cart'] : [];

        if ($token === '' || mb_strlen($token) < 16) {
            return ['success' => false, 'error' => 'invalid_token'];
        }
        if ($message === '' || mb_strlen($message) > 2000) {
            return ['success' => false, 'error' => 'invalid_message'];
        }

        $session = $this->sessions->findOrCreate($token, $userId, $userEmail, $pageUrl);

        // Сохраняем сообщение пользователя
        $this->sessions->saveMessage($session['id'], 'user', $message);

        // Ищем релевантные продукты
        try {
            $products = $this->search->search($message, 6);
        } catch (Throwable $e) {
            $products = [];
        }

        // Подгружаем товары из корзины для контекста
        $cartProducts = [];
        if (!empty($cart)) {
            try {
                $cartProducts = $this->search->getByIds($cart);
            } catch (Throwable $e) {
                $cartProducts = [];
            }
        }

        // История диалога (исключая только что сохранённое user-сообщение, которое и так идёт в конце)
        $history = $this->sessions->getRecentMessages($session['id'], 10);
        // Убираем последнее — оно будет отдельным user-сообщением
        if (!empty($history) && end($history)['role'] === 'user') {
            array_pop($history);
        }

        $messages = PromptBuilder::buildChatMessages($history, $message, $products, $pageUrl, $cartProducts);

        try {
            $response = $this->gpt->complete($messages, 0.5, 700);
        } catch (Throwable $e) {
            ai_log('CHAT', 'GPT failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'gpt_unavailable', 'message' => 'Извините, консультант временно недоступен. Попробуйте через минуту.'];
        }

        $reply = trim($response['text']);

        // Перехватываем служебный маркер [[CREATE_ALERT]]{...}[[/CREATE_ALERT]] и создаём заявку.
        $reply = $this->maybeCreateAlertFromReply($reply, $session['id'], $userId, $pageUrl);

        // Топ-3 продукта как карточки к ответу
        $recommendedProducts = array_slice($products, 0, 3);

        $this->sessions->saveMessage(
            $session['id'],
            'assistant',
            $reply,
            ['recommendations' => $recommendedProducts],
            $response['tokens']
        );

        return [
            'success' => true,
            'reply' => $reply,
            'recommendations' => $recommendedProducts,
            'session_id' => $session['id'],
        ];
    }

    /**
     * Извлекает маркер [[CREATE_ALERT]]{json}[[/CREATE_ALERT]] из ответа модели,
     * создаёт запись в support_alerts (через AlertService) и возвращает очищенный текст.
     * Маркер создаётся максимум один раз на chat_session.
     */
    private function maybeCreateAlertFromReply(string $reply, int $chatSessionId, ?int $userId, ?string $pageUrl): string
    {
        if (!preg_match('/\[\[CREATE_ALERT\]\]\s*(\{[\s\S]*?\})\s*\[\[\/CREATE_ALERT\]\]/u', $reply, $m)) {
            return $reply;
        }

        $cleanReply = trim(preg_replace('/\[\[CREATE_ALERT\]\][\s\S]*?\[\[\/CREATE_ALERT\]\]/u', '', $reply));

        // Защита от дублей — если по этой сессии уже есть алерт, не создаём повторно.
        try {
            $stmt = $this->pdo->prepare('SELECT id FROM support_alerts WHERE chat_session_id = ? LIMIT 1');
            $stmt->execute([$chatSessionId]);
            if ($stmt->fetch()) {
                ai_log('CHAT', 'Alert already exists for session, skip', ['session_id' => $chatSessionId]);
                return $cleanReply;
            }
        } catch (Throwable $e) {
            ai_log('CHAT', 'Alert dedup check failed', ['error' => $e->getMessage()]);
        }

        $parsed = json_decode($m[1], true);
        if (!is_array($parsed)) {
            ai_log('CHAT', 'Alert marker JSON invalid', ['raw' => $m[1]]);
            return $cleanReply;
        }

        $email = trim((string)($parsed['email'] ?? ''));
        $description = trim((string)($parsed['description'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($description) < 10) {
            ai_log('CHAT', 'Alert marker incomplete', ['email' => $email, 'desc_len' => mb_strlen($description)]);
            return $cleanReply;
        }

        try {
            $service = new AlertService($this->pdo);
            // Получаем session_token по id сессии для корректной привязки
            $tokStmt = $this->pdo->prepare('SELECT session_token FROM ai_chat_sessions WHERE id = ? LIMIT 1');
            $tokStmt->execute([$chatSessionId]);
            $sessionToken = (string)($tokStmt->fetchColumn() ?: '');

            $result = $service->create([
                'name' => (string)($parsed['name'] ?? ''),
                'email' => $email,
                'phone' => (string)($parsed['phone'] ?? ''),
                'description' => $description,
                'page_url' => $pageUrl,
                'session_token' => $sessionToken,
                'user_id' => $userId,
            ]);
            ai_log('CHAT', 'Alert created from chat', ['session_id' => $chatSessionId, 'result' => $result]);
        } catch (Throwable $e) {
            ai_log('CHAT', 'Alert create failed', ['error' => $e->getMessage()]);
        }

        return $cleanReply;
    }

    /**
     * Отдельный поток — рекомендация при открытии корзины.
     */
    public function recommend(array $input): array
    {
        $token = trim((string)($input['session_token'] ?? ''));
        $cart = is_array($input['cart'] ?? null) ? $input['cart'] : [];
        $userId = $input['user_id'] ?? null;
        $userEmail = $input['user_email'] ?? null;
        $pageUrl = $input['page_url'] ?? '/korzina/';

        if ($token === '' || mb_strlen($token) < 16) {
            return ['success' => false, 'error' => 'invalid_token'];
        }
        if (empty($cart)) {
            return ['success' => false, 'error' => 'empty_cart'];
        }

        $session = $this->sessions->findOrCreate($token, $userId, $userEmail, $pageUrl);

        // Получаем объекты товаров корзины
        $cartProducts = $this->search->getByIds($cart);
        if (empty($cartProducts)) {
            return ['success' => false, 'error' => 'cart_not_found'];
        }

        // Ищем рекомендации по названиям товаров корзины (используем объединённый текст как запрос)
        $queryHint = implode(' ', array_map(fn($p) => $p['title'], $cartProducts));
        $recommendations = $this->search->search($queryHint, 6);

        // Убираем из рекомендаций то, что уже в корзине
        $cartKeys = array_flip(array_map(fn($p) => $p['type'] . ':' . $p['id'], $cartProducts));
        $recommendations = array_values(array_filter(
            $recommendations,
            fn($p) => !isset($cartKeys[$p['type'] . ':' . $p['id']])
        ));
        $recommendations = array_slice($recommendations, 0, 3);

        if (empty($recommendations)) {
            return ['success' => false, 'error' => 'no_recommendations'];
        }

        // Генерируем приветственное сообщение
        $messages = PromptBuilder::buildRecommendMessages($cartProducts, $recommendations);
        try {
            $response = $this->gpt->complete($messages, 0.6, 400);
            $greeting = trim($response['text']);
        } catch (Throwable $e) {
            // Fallback на статичный текст
            $greeting = 'Заметил товары в вашей корзине. Могу предложить подходящие дополнения — посмотрите ниже.';
        }

        $this->sessions->saveMessage(
            $session['id'],
            'assistant',
            $greeting,
            ['recommendations' => $recommendations, 'trigger' => 'cart_recommend']
        );

        return [
            'success' => true,
            'greeting' => $greeting,
            'recommendations' => $recommendations,
            'session_id' => $session['id'],
        ];
    }
}
