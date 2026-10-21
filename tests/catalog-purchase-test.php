<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/session.php';
if (DB_NAME !== 'catalog_qa' || DB_HOST !== 'pedagogy_catalog_qa_db') throw new RuntimeException('Только QA-база');
$checks = 0;
function purchaseCheck(bool $ok, string $message): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function purchasePost(string $endpoint, array $data, string $session): array {
    $body = file_get_contents('http://localhost/' . $endpoint, false, stream_context_create(['http'=>[
        'method'=>'POST', 'header'=>"Content-Type: application/x-www-form-urlencoded\r\nCookie: PHPSESSID=$session\r\n",
        'content'=>http_build_query($data), 'ignore_errors'=>true, 'timeout'=>10]]));
    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}
$suffix = bin2hex(random_bytes(5)); $user = $order = $otherUser = null; $registrations = [];
$session = bin2hex(random_bytes(16));
try {
    $db->prepare('INSERT INTO users(email,full_name) VALUES(?,?)')->execute(["catalog-$suffix@example.invalid", 'Проверка аналитики']);
    $user = (int)$db->lastInsertId();
    session_id($session); session_start(); $session = session_id(); $_SESSION['user_id'] = $user;
    $csrf = generateCSRFToken(); session_write_close();
    // CLI (root) и Apache (www-data) читают только эту синтетическую QA-сессию.
    $sessionFile = (ini_get('session.save_path') ?: sys_get_temp_dir()) . '/sess_' . $session;
    chmod($sessionFile, 0666);
    $number = 'CATALOG-QA-' . $suffix;
    $db->prepare("INSERT INTO orders(user_id,order_number,total_amount,discount_amount,final_amount,payment_status,paid_at) VALUES(?,?,447,149,298,'succeeded',NOW())")->execute([$user,$number]);
    $order = (int)$db->lastInsertId();
    // Бесплатная третья позиция и произвольная номинация не должны исказить payload.
    foreach ([532,420,387] as $i=>$competition) {
        $db->prepare("INSERT INTO registrations(user_id,competition_id,participant_name,nomination,participation_date,status) VALUES(?,?,?,?,CURDATE(),'paid')")
            ->execute([$user,$competition,'Не передавать ФИО','private@example.invalid']);
        $registration = (int)$db->lastInsertId(); $registrations[] = $registration;
        $db->prepare('INSERT INTO order_items(order_id,registration_id,price,is_free_promotion) VALUES(?,?,149,?)')->execute([$order,$registration,$i===2 ? 1 : 0]);
    }
    $pending = purchasePost('ajax/check-pending-purchases.php', ['order_numbers'=>[$number]], $session);
    purchaseCheck($pending['success'] && count($pending['orders']) === 1, 'Оплаченный заказ недоступен владельцу: ' . json_encode($pending));
    $payload = $pending['orders'][0];
    purchaseCheck(count($payload['products']) === 3 && $payload['products'][2]['price'] == 0, 'Потеряны позиции или неверная цена 2+1');
    purchaseCheck(str_contains($payload['coupon'], '2+1'), 'Потерян купон 2+1');
    purchaseCheck(!str_contains(json_encode($payload), 'private@example.invalid') && !array_key_exists('variant', $payload['products'][0]), 'Номинация ушла в аналитику');
    $claim = purchasePost('ajax/claim-metrika-purchase.php', ['order_number'=>$number,'csrf_token'=>$csrf], $session);
    purchaseCheck($claim['success'] && strlen($claim['token']) === 48, 'Не создана аренда');
    purchaseCheck(!purchasePost('ajax/claim-metrika-purchase.php', ['order_number'=>$number,'csrf_token'=>$csrf], $session)['success'], 'Вторая вкладка получила аренду');
    purchaseCheck(!purchasePost('ajax/mark-metrika-sent.php', ['order_number'=>$number,'csrf_token'=>$csrf,'claim_token'=>str_repeat('b',48)], $session)['success'], 'Принят чужой токен аренды');
    purchaseCheck(purchasePost('ajax/mark-metrika-sent.php', ['order_number'=>$number,'csrf_token'=>$csrf,'claim_token'=>$claim['token']], $session)['success'], 'Отметка отправки не сохранена');
    purchaseCheck(purchasePost('ajax/check-pending-purchases.php', ['order_numbers'=>[$number]], $session)['orders'] === [], 'Повторно выдан отправленный заказ');
    $db->prepare("UPDATE orders SET metrika_sent_at=NULL,payment_status='pending',metrika_claimed_at=NULL WHERE id=?")->execute([$order]);
    purchaseCheck(!purchasePost('ajax/claim-metrika-purchase.php', ['order_number'=>$number,'csrf_token'=>$csrf], $session)['success'], 'Можно отправить неоплаченный заказ');
    $db->prepare('INSERT INTO users(email,full_name) VALUES(?,?)')->execute(["catalog-other-$suffix@example.invalid", 'Другой тестовый владелец']);
    $otherUser = (int)$db->lastInsertId();
    $db->prepare("UPDATE orders SET payment_status='succeeded',user_id=? WHERE id=?")->execute([$otherUser,$order]);
    purchaseCheck(!purchasePost('ajax/claim-metrika-purchase.php', ['order_number'=>$number,'csrf_token'=>$csrf], $session)['success'], 'Можно отправить чужой заказ');
    echo "PASS: $checks проверок реальных AJAX claim/replay/mark, оплаты и приватности\n";
} finally {
    if ($order) { $db->prepare('DELETE FROM order_items WHERE order_id=?')->execute([$order]); $db->prepare('DELETE FROM orders WHERE id=?')->execute([$order]); }
    foreach ($registrations as $id) $db->prepare('DELETE FROM registrations WHERE id=?')->execute([$id]);
    if ($user) $db->prepare('DELETE FROM users WHERE id=?')->execute([$user]);
    if ($otherUser) $db->prepare('DELETE FROM users WHERE id=?')->execute([$otherUser]);
    if (session_status() === PHP_SESSION_NONE) { session_id($session); session_start(); }
    session_destroy();
}
