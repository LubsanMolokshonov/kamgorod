<?php
/**
 * Построить план рассылки реактивации молчащих пользователей и создать
 * скидки 10% в email_campaign_discounts. Идемпотентно.
 *
 *   php scripts/silent-reengagement-plan.php [--expires=2026-04-30 23:59:59]
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/SilentReengagementCampaign.php';

$expires = '2026-04-30 23:59:59';
foreach ($argv as $a) {
    if (strpos($a, '--expires=') === 0) {
        $expires = substr($a, 10);
    }
}

$campaign = new SilentReengagementCampaign($db, $expires);
$res = $campaign->plan();

echo "Plan built. Expires: $expires\n";
echo "Candidates:        {$res['candidates']}\n";
echo "Inserted (new):    {$res['inserted']}\n";
echo "Discounts upserted:{$res['discounts_created']}\n";
