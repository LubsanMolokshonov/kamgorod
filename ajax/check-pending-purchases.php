<?php
/**
 * Check Pending Purchases — отдаёт оплаченные заказы текущего пользователя,
 * для которых событие e-commerce purchase ещё НЕ доставлено в Я.Метрику.
 *
 * Используется assets/js/ecommerce-replay.js: на каждой загрузке любой страницы
 * клиент проверяет, есть ли «провисшие» оплаты (закрыл вкладку Yookassa и не
 * вернулся на /pages/payment-success.php — webhook отметил succeeded, но Метрика
 * об этом не узнала). Если есть — отправляет dataLayer.purchase + mark-metrika-sent.
 *
 * Authz: только заказы текущего session user_id; либо заказы по order_number из
 * localStorage, владельцем которых является текущий user_id. Без сессии — пусто.
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'orders' => []]);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) {
    echo json_encode(['success' => true, 'orders' => []]);
    exit;
}

// Опциональный фильтр по order_numbers из localStorage клиента — снижает нагрузку
// и страхует случай, когда в кабинете много старых оплаченных заказов (мы хотим
// слать только тот, который реально появился в pending_ecommerce_orders).
$orderNumbers = [];
if (isset($_POST['order_numbers']) && is_array($_POST['order_numbers'])) {
    foreach ($_POST['order_numbers'] as $on) {
        $on = trim((string)$on);
        if ($on !== '' && preg_match('~^[A-Za-z0-9_\-]{1,64}$~', $on)) {
            $orderNumbers[] = $on;
        }
        if (count($orderNumbers) >= 20) break;  // защита от переполнения
    }
}

$db = new Database($GLOBALS['db']);

// Берём только заказы за последние 7 дней — старые не релевантны и могли уже
// быть учтены руками (в случае ручной правки без metrika_sent_at).
$params = [$userId];
$sql = "SELECT id, order_number, final_amount, discount_amount, paid_at,
               utm_source, utm_medium, utm_campaign, utm_content, utm_term
        FROM orders
        WHERE user_id = ?
          AND payment_status = 'succeeded'
          AND metrika_sent_at IS NULL
          AND paid_at >= NOW() - INTERVAL 7 DAY";

if (!empty($orderNumbers)) {
    $placeholders = implode(',', array_fill(0, count($orderNumbers), '?'));
    $sql .= " AND order_number IN ($placeholders)";
    $params = array_merge($params, $orderNumbers);
}
$sql .= " ORDER BY paid_at DESC LIMIT 20";

$rows = $db->query($sql, $params);
if (empty($rows)) {
    echo json_encode(['success' => true, 'orders' => []]);
    exit;
}

// Подгружаем items для dataLayer.purchase.products
$itemsByOrder = [];
$orderIds = array_column($rows, 'id');
$idsPh = implode(',', array_fill(0, count($orderIds), '?'));
$itemRows = $db->query(
    "SELECT oi.order_id, oi.price,
            r.id AS reg_id, r.competition_id, c.title AS competition_title, r.nomination,
            cert.id AS cert_id, cert.publication_id, p.title AS publication_title,
            wcert.id AS wcert_id, wcert.webinar_id, w.title AS webinar_title,
            oreg.id AS oreg_id, oreg.olympiad_id, o.title AS olympiad_title,
            ce.id AS ce_id, ce.course_id AS ce_course_id, course.title AS course_title
     FROM order_items oi
     LEFT JOIN registrations r ON oi.registration_id = r.id
     LEFT JOIN competitions c ON r.competition_id = c.id
     LEFT JOIN publication_certificates cert ON oi.certificate_id = cert.id
     LEFT JOIN publications p ON cert.publication_id = p.id
     LEFT JOIN webinar_certificates wcert ON oi.webinar_certificate_id = wcert.id
     LEFT JOIN webinars w ON wcert.webinar_id = w.id
     LEFT JOIN olympiad_registrations oreg ON oi.olympiad_registration_id = oreg.id
     LEFT JOIN olympiads o ON oreg.olympiad_id = o.id
     LEFT JOIN course_enrollments ce ON oi.course_enrollment_id = ce.id
     LEFT JOIN courses course ON ce.course_id = course.id
     WHERE oi.order_id IN ($idsPh)",
    $orderIds
);

foreach ($itemRows as $it) {
    $product = null;
    if (!empty($it['reg_id'])) {
        $product = ['id' => (string)$it['competition_id'], 'name' => (string)$it['competition_title'], 'category' => 'Конкурсы', 'variant' => (string)$it['nomination']];
    } elseif (!empty($it['cert_id'])) {
        $product = ['id' => 'pub-' . $it['publication_id'], 'name' => (string)$it['publication_title'], 'category' => 'Публикации'];
    } elseif (!empty($it['wcert_id'])) {
        $product = ['id' => 'webinar-' . $it['webinar_id'], 'name' => (string)$it['webinar_title'], 'category' => 'Вебинары'];
    } elseif (!empty($it['oreg_id'])) {
        $product = ['id' => 'olymp-' . $it['olympiad_id'], 'name' => (string)$it['olympiad_title'], 'category' => 'Олимпиады'];
    } elseif (!empty($it['ce_id'])) {
        $product = ['id' => 'course-' . $it['ce_course_id'], 'name' => (string)$it['course_title'], 'category' => 'Курсы'];
    }
    if ($product) {
        $product['price'] = (float)$it['price'];
        $product['brand'] = 'Педпортал';
        $product['quantity'] = 1;
        $itemsByOrder[$it['order_id']][] = $product;
    }
}

$result = [];
foreach ($rows as $row) {
    $result[] = [
        'order_number' => $row['order_number'],
        'revenue'      => (float)$row['final_amount'],
        'coupon'       => (float)$row['discount_amount'] > 0 ? 'discount' : '',
        'products'     => $itemsByOrder[$row['id']] ?? [],
    ];
}

echo json_encode(['success' => true, 'orders' => $result], JSON_UNESCAPED_UNICODE);
