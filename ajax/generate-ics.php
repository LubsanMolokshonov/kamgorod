<?php
/**
 * Generate ICS file for webinar calendar event
 * Endpoint: /ajax/generate-ics.php?registration_id=123
 */

require_once dirname(__DIR__) . '/includes/config.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/IcsGenerator.php';

header('Content-Type: application/json');

try {
    $registrationId = isset($_GET['registration_id']) ? (int)$_GET['registration_id'] : 0;

    if (!$registrationId) {
        throw new Exception('Registration ID is required');
    }

    $db = new Database($pdo);

    // Get registration with webinar data
    $registration = $db->queryOne(
        "SELECT wr.*, w.id as webinar_id, w.title, w.slug, w.scheduled_at,
                w.duration_minutes, w.broadcast_url, w.short_description, w.description,
                w.speaker_name
         FROM webinar_registrations wr
         JOIN webinars w ON wr.webinar_id = w.id
         WHERE wr.id = ?",
        [$registrationId]
    );

    if (!$registration) {
        throw new Exception('Registration not found');
    }

    // Prepare webinar data for ICS
    $webinarData = [
        'id' => $registration['webinar_id'],
        'title' => $registration['title'],
        'scheduled_at' => $registration['scheduled_at'],
        'duration_minutes' => $registration['duration_minutes'] ?? 60,
        'broadcast_url' => $registration['broadcast_url'],
        'short_description' => $registration['short_description'] ?? $registration['description'],
        'speaker_name' => $registration['speaker_name']
    ];

    // Generate ICS content
    $icsContent = IcsGenerator::generateForWebinar($webinarData);

    // Generate filename from webinar title
    $filename = 'webinar-' . ($registration['slug'] ?? 'event');

    // Send as download
    IcsGenerator::sendAsDownload($icsContent, $filename);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
