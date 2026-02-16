<?php
/**
 * Generate ICS file for webinar calendar event
 * Endpoint: /ajax/generate-ics.php?registration_id=123
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/IcsGenerator.php';

header('Content-Type: application/json');

try {
    $registrationId = isset($_GET['registration_id']) ? (int)$_GET['registration_id'] : 0;

    if (!$registrationId) {
        throw new Exception('Registration ID is required');
    }

    $database = new Database($db);

    // Get registration with webinar data
    $registration = $database->queryOne(
        "SELECT wr.*, w.id as webinar_id, w.title, w.slug, w.scheduled_at,
                w.duration_minutes, w.broadcast_url, w.short_description, w.description,
                s.full_name as speaker_name
         FROM webinar_registrations wr
         JOIN webinars w ON wr.webinar_id = w.id
         LEFT JOIN speakers s ON w.speaker_id = s.id
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
