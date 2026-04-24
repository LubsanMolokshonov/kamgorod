#!/usr/bin/env php
<?php
/**
 * Cron Script: Retry Unsent Document Emails
 *
 * Finds paid orders where:
 * 1. PDF generation failed at webhook time → retries generation
 * 2. Email was not sent (documents weren't ready) → sends email with attachments
 *
 * Recommended cron schedule: every 15 minutes
 *
 * For Docker (add to host crontab):
 * every-15-min docker exec pedagogy_web php /var/www/html/cron/retry-unsent-documents.php
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/Order.php';
require_once BASE_PATH . '/classes/User.php';
require_once BASE_PATH . '/classes/WebinarCertificate.php';
require_once BASE_PATH . '/classes/WebinarRegistration.php';
require_once BASE_PATH . '/classes/PublicationCertificate.php';
require_once BASE_PATH . '/classes/Diploma.php';
require_once BASE_PATH . '/includes/email-helper.php';
require_once BASE_PATH . '/vendor/autoload.php';

// Lock file
$lockFile = '/tmp/retry_unsent_docs_cron.lock';

if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    if (time() - $lockTime > 600) {
        unlink($lockFile);
        logMsg("Removed stale lock file");
    } else {
        logMsg("Another instance is running. Exiting.");
        exit(0);
    }
}

file_put_contents($lockFile, getmypid());

try {
    $db = $GLOBALS['db'];
    $orderObj = new Order($db);
    $userObj = new User($db);
    $certObj = new PublicationCertificate($db);
    $webCertObj = new WebinarCertificate($db);
    $webRegObj = new WebinarRegistration($db);

    // Find paid orders where email might not have been sent
    // Look for orders paid in the last 7 days with unsent webinar certificate emails
    // or orders with ungenerated documents
    $stmt = $db->query("
        SELECT DISTINCT o.id as order_id, o.order_number, o.user_id, o.paid_at
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        LEFT JOIN webinar_certificates wc ON wc.id = oi.webinar_certificate_id
        LEFT JOIN webinar_registrations wr ON wr.id = wc.registration_id
        LEFT JOIN publication_certificates pc ON pc.id = oi.certificate_id
        LEFT JOIN diplomas d ON d.registration_id = oi.registration_id AND d.recipient_type = 'participant'
        WHERE o.payment_status = 'succeeded'
        AND o.paid_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        AND (
            (oi.webinar_certificate_id IS NOT NULL AND (wc.status != 'ready' OR wr.certificate_email_sent = 0))
            OR (oi.certificate_id IS NOT NULL AND pc.status != 'ready')
            OR (oi.registration_id IS NOT NULL AND d.id IS NULL)
        )
        ORDER BY o.paid_at ASC
        LIMIT 20
    ");
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($orders)) {
        logMsg("No pending orders found. All clean.");
        cleanup($lockFile);
        exit(0);
    }

    logMsg("Found " . count($orders) . " orders to process");

    $emailsSent = 0;
    $certsGenerated = 0;
    $errors = 0;

    foreach ($orders as $order) {
        $orderId = $order['order_id'];
        $orderNumber = $order['order_number'];
        $userId = $order['user_id'];

        logMsg("Processing order {$orderNumber} (paid: {$order['paid_at']})");

        $orderItems = $orderObj->getOrderItems($orderId);

        // Step 1: Retry PDF generation for any failed documents
        $generationNeeded = false;

        foreach ($orderItems as $item) {
            // Retry webinar certificate generation
            if (!empty($item['webinar_certificate_id'])) {
                $wc = $webCertObj->getById($item['webinar_certificate_id']);
                if ($wc && $wc['status'] === 'paid') {
                    logMsg("  Retrying webinar cert #{$item['webinar_certificate_id']} generation...");
                    $result = $webCertObj->generate($item['webinar_certificate_id']);
                    if ($result['success']) {
                        logMsg("  SUCCESS: {$result['pdf_path']}");
                        $certsGenerated++;
                    } else {
                        logMsg("  FAILED: {$result['message']}");
                        $errors++;
                        $generationNeeded = true;
                    }
                }
            }

            // Retry publication certificate generation
            if (!empty($item['certificate_id'])) {
                $pc = $certObj->getById($item['certificate_id']);
                if ($pc && $pc['status'] === 'paid') {
                    logMsg("  Retrying pub cert #{$item['certificate_id']} generation...");
                    $result = $certObj->generate($item['certificate_id']);
                    if ($result['success']) {
                        logMsg("  SUCCESS: {$result['pdf_path']}");
                        $certsGenerated++;
                    } else {
                        logMsg("  FAILED: {$result['message']}");
                        $errors++;
                        $generationNeeded = true;
                    }
                }
            }

            // Retry diploma generation
            if (!empty($item['registration_id'])) {
                $dStmt = $db->prepare("SELECT id FROM diplomas WHERE registration_id = ? AND recipient_type = 'participant' LIMIT 1");
                $dStmt->execute([$item['registration_id']]);
                if (!$dStmt->fetch()) {
                    logMsg("  Retrying diploma for registration #{$item['registration_id']}...");
                    $diplomaObj = new Diploma($db);
                    $result = $diplomaObj->generate($item['registration_id'], 'participant');
                    if ($result['success']) {
                        logMsg("  SUCCESS: diploma generated");
                        $certsGenerated++;
                    } else {
                        logMsg("  FAILED: {$result['message']}");
                        $errors++;
                        $generationNeeded = true;
                    }
                }
            }
        }

        // Step 2: Verify all documents are now ready
        $allReady = true;
        $missingDocs = [];

        foreach ($orderItems as $item) {
            if (!empty($item['webinar_certificate_id'])) {
                $wc = $webCertObj->getById($item['webinar_certificate_id']);
                if (!$wc || $wc['status'] !== 'ready' || empty($wc['pdf_path']) || !file_exists(BASE_PATH . $wc['pdf_path'])) {
                    $allReady = false;
                    $missingDocs[] = "web_cert:{$item['webinar_certificate_id']}";
                }
            }
            if (!empty($item['certificate_id'])) {
                $pc = $certObj->getById($item['certificate_id']);
                if (!$pc || $pc['status'] !== 'ready' || empty($pc['pdf_path']) || !file_exists(BASE_PATH . $pc['pdf_path'])) {
                    $allReady = false;
                    $missingDocs[] = "pub_cert:{$item['certificate_id']}";
                }
            }
            if (!empty($item['registration_id'])) {
                $dStmt = $db->prepare("SELECT pdf_path FROM diplomas WHERE registration_id = ? AND recipient_type = 'participant' AND pdf_path IS NOT NULL AND pdf_path != '' LIMIT 1");
                $dStmt->execute([$item['registration_id']]);
                $dRow = $dStmt->fetch(PDO::FETCH_ASSOC);
                if (!$dRow || !file_exists(BASE_PATH . '/uploads/diplomas/' . $dRow['pdf_path'])) {
                    $allReady = false;
                    $missingDocs[] = "diploma:reg_{$item['registration_id']}";
                }
            }
        }

        if (!$allReady) {
            $missing = implode(', ', $missingDocs);
            logMsg("  SKIP email - documents still not ready: {$missing}");
            continue;
        }

        // Step 3: Check if email needs to be sent
        $needsEmail = false;
        foreach ($orderItems as $item) {
            if (!empty($item['webinar_certificate_id'])) {
                $wc = $webCertObj->getById($item['webinar_certificate_id']);
                if ($wc && !empty($wc['registration_id'])) {
                    $regStmt = $db->prepare("SELECT certificate_email_sent FROM webinar_registrations WHERE id = ?");
                    $regStmt->execute([$wc['registration_id']]);
                    $reg = $regStmt->fetch(PDO::FETCH_ASSOC);
                    if ($reg && !$reg['certificate_email_sent']) {
                        $needsEmail = true;
                        break;
                    }
                }
            }
        }

        // Also check if this order's email was ever logged
        if (!$needsEmail) {
            $logFile = BASE_PATH . '/logs/email.log';
            if (file_exists($logFile)) {
                $logContent = file_get_contents($logFile);
                if (strpos($logContent, $orderNumber) === false) {
                    $needsEmail = true;
                }
            }
        }

        if (!$needsEmail) {
            logMsg("  Email already sent for order {$orderNumber}, updating flags only");
            // Just update flags
            foreach ($orderItems as $item) {
                if (!empty($item['webinar_certificate_id'])) {
                    $wc = $webCertObj->getById($item['webinar_certificate_id']);
                    if ($wc && !empty($wc['registration_id'])) {
                        $webRegObj->markCertificateEmailSent($wc['registration_id']);
                    }
                }
            }
            continue;
        }

        // Step 4: Send email
        try {
            sendPaymentSuccessEmail($userId, $orderId);

            // Mark certificate_email_sent
            foreach ($orderItems as $item) {
                if (!empty($item['webinar_certificate_id'])) {
                    $wc = $webCertObj->getById($item['webinar_certificate_id']);
                    if ($wc && !empty($wc['registration_id'])) {
                        $webRegObj->markCertificateEmailSent($wc['registration_id']);
                    }
                }
            }

            $emailsSent++;
            logMsg("  EMAIL SENT for order {$orderNumber}");

        } catch (Exception $e) {
            $errors++;
            logMsg("  EMAIL FAILED for order {$orderNumber}: " . $e->getMessage());
        }
    }

    logMsg("=== Summary: {$emailsSent} emails sent, {$certsGenerated} certs generated, {$errors} errors ===");

} catch (Exception $e) {
    logMsg("FATAL: " . $e->getMessage());
}

cleanup($lockFile);

function logMsg($message) {
    echo date('Y-m-d H:i:s') . " - {$message}\n";
}

function cleanup($lockFile) {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}
