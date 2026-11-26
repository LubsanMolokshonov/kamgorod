<?php
declare(strict_types=1);

const COURSE_PAYMENT_LINK_TTL = 72 * 60 * 60;

function coursePaymentLinkSecret(): ?string
{
    $secret = defined('CRM_COURSE_PAYMENT_LINK_SECRET') ? (string)CRM_COURSE_PAYMENT_LINK_SECRET : '';
    return strlen(trim($secret)) >= 32 ? $secret : null;
}

function coursePaymentLinkSignature(string $payload, string $secret): string
{
    return hash_hmac('sha256', 'fgos:course-payment:v1:' . $payload, $secret);
}

function generateCoursePaymentLink(int $enrollmentId, int $userId, int $dealId, int $expiresAt): string
{
    $secret = coursePaymentLinkSecret();
    $now = time();
    if ($secret === null) {
        throw new RuntimeException('CRM_COURSE_PAYMENT_LINK_SECRET не задан');
    }
    if (min($enrollmentId, $userId, $dealId) < 1 || $expiresAt <= $now || $expiresAt > $now + COURSE_PAYMENT_LINK_TTL) {
        throw new InvalidArgumentException('Недопустимые параметры ссылки на оплату курса');
    }

    $origin = rtrim((string)PUBLIC_SITE_URL, '/');
    if (!preg_match('~\Ahttps://[a-z0-9.-]+(?::[0-9]+)?\z~i', $origin)) {
        throw new RuntimeException('Для ссылок на оплату требуется PUBLIC_SITE_URL с HTTPS');
    }

    $payload = implode('.', [$enrollmentId, $userId, $dealId, $expiresAt]);
    return $origin . '/oplata-kursa/#' . $payload . '.' . coursePaymentLinkSignature($payload, $secret);
}

/** @return array{enrollment_id:int,user_id:int,deal_id:int,expires_at:int}|null */
function validateCoursePaymentLink(string $token): ?array
{
    $secret = coursePaymentLinkSecret();
    if ($secret === null || !preg_match('~\A([1-9][0-9]{0,9})\.([1-9][0-9]{0,9})\.([1-9][0-9]{0,9})\.([1-9][0-9]{0,10})\.([a-f0-9]{64})\z~', $token, $parts)) {
        return null;
    }
    [, $enrollmentId, $userId, $dealId, $expiry, $signature] = $parts;
    $now = time();
    if ((int)$expiry <= $now || (int)$expiry > $now + COURSE_PAYMENT_LINK_TTL) {
        return null;
    }
    $payload = implode('.', [$enrollmentId, $userId, $dealId, $expiry]);
    if (!hash_equals(coursePaymentLinkSignature($payload, $secret), $signature)) {
        return null;
    }

    return [
        'enrollment_id' => (int)$enrollmentId,
        'user_id' => (int)$userId,
        'deal_id' => (int)$dealId,
        'expires_at' => (int)$expiry,
    ];
}

function resolveCoursePaymentLinkOwner(PDO $pdo, int $enrollmentId, int $dealId): ?int
{
    if (min($enrollmentId, $dealId) < 1) {
        return null;
    }
    $statement = $pdo->prepare(
        'SELECT user_id, email FROM course_enrollments
         WHERE id = ? AND bitrix_lead_id = ?
           AND NOT EXISTS (SELECT 1 FROM course_enrollments AS other WHERE other.bitrix_lead_id = ? AND other.id <> ?)'
    );
    $statement->execute([$enrollmentId, $dealId, $dealId, $enrollmentId]);
    $enrollment = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$enrollment) {
        return null;
    }

    if (!empty($enrollment['user_id'])) {
        $statement = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $statement->execute([(int)$enrollment['user_id']]);
        return (int)$statement->fetchColumn() ?: null;
    }

    $email = trim((string)$enrollment['email']);
    if ($email === '') {
        return null;
    }
    $statement = $pdo->prepare('SELECT id, email FROM users WHERE email = ? LIMIT 2');
    $statement->execute([$email]);
    $users = $statement->fetchAll(PDO::FETCH_ASSOC);
    return count($users) === 1 && strcasecmp($users[0]['email'], $email) === 0 ? (int)$users[0]['id'] : null;
}

function courseCheckoutPath(int $enrollmentId): string
{
    return '/oplata-kursa/' . $enrollmentId . '/';
}

function courseCheckoutEnrollmentId(array $order): ?int
{
    foreach ($order['items'] ?? [] as $item) {
        $enrollmentId = (int)($item['course_enrollment_id'] ?? 0);
        if ($enrollmentId > 0) {
            return $enrollmentId;
        }
    }
    return null;
}

/** Проверка обычного входа без установки user_id, обновления cookie и синхронизации корзины. */
function getCourseCheckoutAccountId(PDO $pdo): ?int
{
    if (session_status() === PHP_SESSION_NONE && isset($_COOKIE[session_name()])) {
        session_start(['read_and_close' => true, 'use_strict_mode' => true]);
    }
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId > 0) {
        return $userId;
    }

    $token = $_COOKIE['session_token'] ?? null;
    if (!is_string($token) || !preg_match('~\A[a-f0-9]{64}\z~', $token)) {
        return null;
    }
    $statement = $pdo->prepare('SELECT id FROM users WHERE session_token = ?');
    $statement->execute([$token]);
    return (int)$statement->fetchColumn() ?: null;
}
