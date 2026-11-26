<?php
/** В гостевой форме проверяется формат адреса. Подстановка профиля доступна его владельцу. */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';

$email = $_GET['email'] ?? '';
if (!is_string($email) || !User::validateEmail($email)) {
    echo json_encode(['success' => false, 'exists' => false, 'message' => 'Некорректный email адрес']);
    exit;
}

$ownerId = verifiedAccountId($db);
$response = ['success' => true, 'exists' => false];
if ($ownerId !== null) {
    $user = (new User($db))->getById($ownerId);
    if ($user && strcasecmp($user['email'], $email) === 0) {
        $response['exists'] = true;
        $response['user'] = array_intersect_key($user, array_flip(['full_name', 'phone', 'city', 'organization']));
    }
}
echo json_encode($response, JSON_UNESCAPED_UNICODE);
