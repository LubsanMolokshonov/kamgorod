<?php
/**
 * AJAX: разблокировка скачивания сгенерированного материала (paywall превью-модели).
 * Списывает unlock_token_cost токенов, помечает материал is_unlocked=1 и отдаёт ссылку
 * на скачивание. Для анонима возвращает code=unauthorized — фронт показывает модалку
 * регистрации (quick-register), затем повторяет запрос.
 *
 * POST: csrf, material_id
 * Ответ: { success, download_url } | { success:false, error, code, buy_url? }
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../classes/Material.php';
require_once __DIR__ . '/../classes/UserTokens.php';
require_once __DIR__ . '/../includes/material-tracking.php';

function ulRespond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ulRespond(['success' => false, 'error' => 'Method not allowed', 'code' => 'method'], 405);
}
if (!validateCSRFToken($_POST['csrf'] ?? '')) {
    ulRespond(['success' => false, 'error' => 'Сессия истекла, обновите страницу', 'code' => 'csrf'], 403);
}

$materialId = (int)($_POST['material_id'] ?? 0);
if ($materialId <= 0) {
    ulRespond(['success' => false, 'error' => 'Не указан материал', 'code' => 'invalid'], 400);
}

$materialObj = new Material($db);
$material = $materialObj->getById($materialId);
if (!$material) {
    ulRespond(['success' => false, 'error' => 'Материал не найден', 'code' => 'not_found'], 404);
}

$downloadUrl = '/material-download.php?id=' . $materialId;

// Уже разблокирован (редакционный/оплаченный) — отдаём сразу
if ((int)$material['is_unlocked'] === 1) {
    ulRespond(['success' => true, 'download_url' => $downloadUrl]);
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
if ($userId === null) {
    ulRespond(['success' => false, 'error' => 'Зарегистрируйтесь, чтобы скачать материал', 'code' => 'unauthorized'], 401);
}

// Проверка владения: либо это автор, либо анонимное превью текущей воронки (claim).
$fsid = $_COOKIE['mat_fsid'] ?? '';
$ownerId = $material['user_id'] !== null ? (int)$material['user_id'] : null;

if ($ownerId === null) {
    if (strlen($fsid) === 32 && $material['funnel_session_id'] === $fsid) {
        claimAnonymousMaterials($db, $userId);
        $material['user_id'] = $userId;
        $ownerId = $userId;
    } else {
        ulRespond(['success' => false, 'error' => 'Материал недоступен', 'code' => 'forbidden'], 403);
    }
}

if ($ownerId !== $userId) {
    ulRespond(['success' => false, 'error' => 'Материал принадлежит другому пользователю', 'code' => 'forbidden'], 403);
}

$cost = (int)$material['unlock_token_cost'];

try {
    if ($cost > 0) {
        $tokens = new UserTokens($db);
        $alreadyPaid = (new Database($db))->queryOne(
            "SELECT id FROM token_transactions
              WHERE user_id = ? AND reason = 'download' AND material_id = ? LIMIT 1",
            [$userId, $materialId]
        );
        if (!$alreadyPaid) {
            $tokens->charge($userId, $cost, 'download', ['material_id' => $materialId]);
        }
    }

    $materialObj->update($materialId, [
        'is_unlocked' => 1,
        'token_cost'  => 0,
    ]);

    // Оплаченный материал не должен оставаться черновиком: новые генерации публикуются
    // сразу при создании, но старые draft'ы доводим до published здесь. Отклонённые/
    // архивные админкой (rejected/archived) намеренно не трогаем.
    if ($material['status'] === 'draft') {
        $materialObj->publish($materialId);
    }

    // Материал оплачен — гасим неотправленные письма дожима «превью без оплаты»
    try {
        require_once __DIR__ . '/../classes/MaterialTokenEmailChain.php';
        (new MaterialTokenEmailChain($db))->cancelPendingForUser($userId, ['preview_abandon']);
    } catch (Throwable $e) {
        error_log('unlock cancel preview_abandon: ' . $e->getMessage());
    }

    ulRespond([
        'success'      => true,
        'download_url' => $downloadUrl,
        'tokens_left'  => (new UserTokens($db))->getBalance($userId),
    ]);
} catch (NotEnoughTokensException $e) {
    // Горячий сигнал брошенной корзины — запланировать дожим preview_abandon
    try {
        require_once __DIR__ . '/../classes/MaterialTokenEmailChain.php';
        (new MaterialTokenEmailChain($db))->schedulePreviewAbandon($userId);
    } catch (Throwable $ee) {
        error_log('unlock schedulePreviewAbandon: ' . $ee->getMessage());
    }
    ulRespond([
        'success'     => false,
        'error'       => 'Недостаточно токенов для скачивания',
        'code'        => 'not_enough_tokens',
        'buy_url'     => '/material-balance/?unlock_material=' . $materialId,
        'tokens_left' => (new UserTokens($db))->getBalance($userId),
        'needed'      => $cost,
    ], 402);
} catch (Throwable $e) {
    error_log('unlock-material error: ' . $e->getMessage());
    ulRespond(['success' => false, 'error' => 'Внутренняя ошибка, попробуйте позже', 'code' => 'internal'], 500);
}
