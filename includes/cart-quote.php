<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/group-pricing.php';
require_once __DIR__ . '/../classes/Registration.php';
require_once __DIR__ . '/../classes/PublicationCertificate.php';
require_once __DIR__ . '/../classes/WebinarCertificate.php';
require_once __DIR__ . '/../classes/OlympiadRegistration.php';
require_once __DIR__ . '/../classes/ParticipantGroup.php';
require_once __DIR__ . '/../classes/SubscriptionService.php';
require_once __DIR__ . '/../classes/LoyaltyDiscount.php';
require_once __DIR__ . '/../classes/EmailCampaignDiscount.php';

function cartMayUseDocument(?int $accountId, string $cartType, array $row): bool
{
    $owner = (int)$row['user_id'];
    if (in_array($row['status'], CART_PAID_STATUSES[$cartType]['statuses'], true)) {
        return false;
    }
    return $accountId !== null ? $accountId === $owner : guestOwnsCartItem($cartType, (int)$row['id'], $owner);
}

function loadCartDocuments(PDO $db, ?int $accountId): array
{
    // Collect ALL items into one array for unified promotion calculation
    $allItems = [];
    $registrations = getCart();
    $certificates = getCartCertificates();
    sort($registrations, SORT_NUMERIC);
    sort($certificates, SORT_NUMERIC);

    // Get registrations
    $registrationObj = new Registration($db);
    foreach ($registrations as $regId) {
        $registration = $registrationObj->getById($regId);
        if ($registration && cartMayUseDocument($accountId, 'registration', $registration)) {
            $allItems[] = [
                'type' => 'registration',
                'id' => $regId,
                'name' => $registration['competition_title'],
                'meta' => 'Номинация: ' . $registration['nomination'],
                'price' => (float)$registration['competition_price'],
                'is_free' => false,
                'raw_data' => $registration
            ];
        }
    }

    // Get certificates
    $certObj = new PublicationCertificate($db);
    foreach ($certificates as $certId) {
        $cert = $certObj->getById($certId);
        if ($cert && cartMayUseDocument($accountId, 'publication_cert', $cert)) {
            $allItems[] = [
                'type' => 'certificate',
                'id' => $cert['id'],
                'name' => $cert['publication_title'],
                'meta' => 'Свидетельство о публикации • ' . $cert['author_name'],
                'price' => (float)($cert['price'] ?? PUBLICATION_CERTIFICATE_PRICE),
                'is_free' => false,
                'raw_data' => $cert
            ];
        }
    }

    // Get webinar certificates
    $webCertObj = new WebinarCertificate($db);
    $webinarCertificates = getCartWebinarCertificates();
    sort($webinarCertificates, SORT_NUMERIC);
    foreach ($webinarCertificates as $webCertId) {
        $webCert = $webCertObj->getById($webCertId);
        if ($webCert && cartMayUseDocument($accountId, 'webinar_cert', $webCert)) {
            $allItems[] = [
                'type' => 'webinar_certificate',
                'id' => $webCert['id'],
                'name' => $webCert['webinar_title'],
                'meta' => 'Сертификат участника вебинара • ' . $webCert['full_name'],
                'price' => (float)($webCert['price'] ?? 200),
                'is_free' => false,
                'raw_data' => $webCert
            ];
        }
    }

    // Get olympiad registrations
    $olympRegObj = new OlympiadRegistration($db);
    $olympiadRegistrations = getCartOlympiadRegistrations();
    sort($olympiadRegistrations, SORT_NUMERIC);
    foreach ($olympiadRegistrations as $olympRegId) {
        $olympReg = $olympRegObj->getById($olympRegId);
        if ($olympReg && cartMayUseDocument($accountId, 'olympiad_reg', $olympReg)) {
            $allItems[] = [
                'type' => 'olympiad_registration',
                'id' => $olympReg['id'],
                'name' => $olympReg['olympiad_title'],
                'meta' => 'Диплом олимпиады • ' . ($olympReg['placement'] == '1' ? '1 место' : ($olympReg['placement'] == '2' ? '2 место' : '3 место')),
                'price' => (float)($olympReg['diploma_price'] ?? OLYMPIAD_DIPLOMA_PRICE),
                'is_free' => false,
                'raw_data' => $olympReg
            ];
        }
    }

    return $allItems;
}

/** Одиночные документы сортируются по стоимости, каждая третья строка бесплатна. */
function applyCartPromotion(array $items): array
{
    $singles = array_filter($items, static fn(array $item): bool => empty($item['raw_data']['group_batch_id']));
    uasort($singles, static fn(array $a, array $b): int => ($b['price'] <=> $a['price'])
        ?: strcmp($a['type'], $b['type']) ?: ((int)$a['id'] <=> (int)$b['id']));
    $discountMinor = 0;
    $position = 0;
    foreach ($singles as $index => $item) {
        if (++$position % 3 === 0) {
            $items[$index]['is_free'] = true;
            $discountMinor += (int)round($item['price'] * 100);
        }
    }
    return ['items' => $items, 'discount' => $discountMinor / 100, 'eligible_count' => count($singles)];
}

function quoteCart(PDO $db, array $items, ?int $accountId): array
{
    $subtotal = array_sum(array_column($items, 'price'));
    $groups = new ParticipantGroup($db);
    $rates = [];
    foreach ($items as $item) {
        $batch = $item['raw_data']['group_batch_id'] ?? null;
        if ($batch && !isset($rates[$batch])) {
            $rates[$batch] = (int)($groups->getByBatchId($batch)['discount_percent'] ?? 0);
        }
    }
    $group = groupApplyDiscounts($items, $rates);
    $promo = applyCartPromotion($group['items']);
    $total = round($subtotal - $group['discount'] - $promo['discount'], 2);
    $subscriber = $accountId && (new SubscriptionService($db))->coversCertificates($accountId);
    $loyaltyRate = 0;
    $loyalty = 0;
    $campaign = null;
    $campaignAmount = 0;
    if ($subscriber) {
        $total = 0;
    } elseif ($accountId && $total > 0) {
        if (LoyaltyDiscount::isEligible($db, $accountId)) {
            $loyaltyRate = LoyaltyDiscount::getEffectiveRates($db, $accountId)['cart'];
            $loyalty = LoyaltyDiscount::calculateCartDiscount($total, $loyaltyRate)['amount'];
        }
        if (!$loyalty) {
            $campaign = EmailCampaignDiscount::getActive($db, $accountId);
            if ($campaign) {
                $campaignAmount = EmailCampaignDiscount::calculate($total, (float)$campaign['rate'])['amount'];
            }
        }
        $total = round($total - $loyalty - $campaignAmount, 2);
    }
    $lines = [];
    foreach ($promo['items'] as $item) {
        $lines[] = [$item['type'], (int)$item['id'], (int)round($item['price'] * 100), $item['is_free']];
    }
    $revision = hash('sha256', json_encode([$lines, (int)round($total * 100), (bool)$subscriber,
        $campaignAmount ? (int)$campaign['id'] : null], JSON_THROW_ON_ERROR));
    return [
        'items' => $promo['items'], 'eligible_count' => $promo['eligible_count'], 'subtotal' => $subtotal,
        'discount' => $subscriber ? 0 : $promo['discount'], 'group_discount' => $subscriber ? 0 : $group['discount'],
        'loyalty' => $loyalty, 'loyalty_rate' => $loyaltyRate, 'campaign' => $campaign, 'campaign_amount' => $campaignAmount,
        'subscriber' => (bool)$subscriber, 'total' => $total, 'revision' => $revision,
    ];
}
