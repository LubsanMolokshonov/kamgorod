<?php
/**
 * Get Diploma Preview AJAX Endpoint
 * Returns HTML preview of diploma with overlaid data
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';

try {
    // Check if user is logged in
    if (!isset($_SESSION['user_email'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized'
        ]);
        exit;
    }

    // Get parameters
    $registrationId = $_GET['registration_id'] ?? null;
    $recipientType = $_GET['type'] ?? 'participant';

    if (!$registrationId) {
        echo json_encode([
            'success' => false,
            'message' => 'Registration ID is required'
        ]);
        exit;
    }

    // Get registration data
    $stmt = $db->prepare("
        SELECT
            r.id,
            r.nomination,
            r.work_title,
            r.diploma_template_id,
            r.status,
            r.participation_date,
            r.placement,
            r.has_supervisor,
            r.supervisor_name,
            r.supervisor_organization,
            c.title as competition_name,
            c.category,
            u.full_name,
            u.email,
            u.organization,
            u.city
        FROM registrations r
        JOIN competitions c ON r.competition_id = c.id
        JOIN users u ON r.user_id = u.id
        WHERE r.id = ? AND u.email = ? AND r.status IN ('paid', 'diploma_ready')
    ");
    $stmt->execute([$registrationId, $_SESSION['user_email']]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registration) {
        echo json_encode([
            'success' => false,
            'message' => 'Registration not found or access denied'
        ]);
        exit;
    }

    // Determine recipient name based on type
    $recipientName = $recipientType === 'supervisor' && $registration['has_supervisor']
        ? $registration['supervisor_name']
        : $registration['full_name'];

    $recipientOrganization = $recipientType === 'supervisor' && $registration['has_supervisor']
        ? $registration['supervisor_organization']
        : $registration['organization'];

    // Format participation date
    $participationDate = $registration['participation_date']
        ? date('d.m.Y', strtotime($registration['participation_date']))
        : date('d.m.Y');

    // Get template
    $templateStmt = $db->prepare("SELECT * FROM diploma_templates WHERE id = ?");
    $templateStmt->execute([$registration['diploma_template_id']]);
    $template = $templateStmt->fetch(PDO::FETCH_ASSOC);

    if (!$template) {
        echo json_encode([
            'success' => false,
            'message' => 'Template not found'
        ]);
        exit;
    }

    // Decode field positions
    $fieldPositions = json_decode($template['field_positions'], true);

    // Helper function to create positioned text
    function createDiplomaField($text, $position) {
        if (!$position) return '';

        $x = $position['x'] ?? 0;
        $y = $position['y'] ?? 0;
        $fontSize = $position['size'] ?? 16;
        $fontWeight = $position['font_weight'] ?? 'normal';
        $textAlign = $position['align'] ?? 'center';
        $color = $position['color'] ?? '#000000';
        $maxWidth = $position['max_width'] ?? 200;

        // For A4 portrait: 210mm x 297mm
        // Convert mm to percentage
        $leftPercent = ($x / 210) * 100;
        $topPercent = ($y / 297) * 100;
        $widthPercent = ($maxWidth / 210) * 100;

        // Calculate left position based on alignment
        if ($textAlign === 'center') {
            $leftPercent = $leftPercent - ($widthPercent / 2);
        }

        // Scale font size as percentage of container width
        // A4 width is 210mm, we want font size as % of that
        // fontSize is in pt, convert to percentage: (fontSize / 210) * 100 * 0.47
        // The 0.47 factor is calibrated for proper display
        $fontSizePercent = ($fontSize / 210) * 100 * 0.47;

        $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        return sprintf(
            '<div class="diploma-field" style="left: %.2f%%; top: %.2f%%; width: %.2f%%; font-size: %.3fvw; font-weight: %s; text-align: %s; color: %s;">%s</div>',
            $leftPercent,
            $topPercent,
            $widthPercent,
            $fontSizePercent,
            $fontWeight,
            $textAlign,
            $color,
            $escapedText
        );
    }

    // Build overlay HTML
    $overlayHtml = '';

    // Add FIO
    if (isset($fieldPositions['fio'])) {
        $overlayHtml .= createDiplomaField($recipientName, $fieldPositions['fio']);
    }

    // Add organization
    $orgPosition = $fieldPositions['organization'] ?? $fieldPositions['org'] ?? null;
    if ($orgPosition && $recipientOrganization) {
        $overlayHtml .= createDiplomaField($recipientOrganization, $orgPosition);
    }

    // Add nomination
    if (isset($fieldPositions['nomination'])) {
        $overlayHtml .= createDiplomaField($registration['nomination'], $fieldPositions['nomination']);
    }

    // Add work title
    if (isset($fieldPositions['work_title']) && $registration['work_title']) {
        $overlayHtml .= createDiplomaField('«' . $registration['work_title'] . '»', $fieldPositions['work_title']);
    }

    // Add competition name
    if (isset($fieldPositions['competition_name'])) {
        $overlayHtml .= createDiplomaField($registration['competition_name'], $fieldPositions['competition_name']);
    }

    // Add placement
    if (isset($fieldPositions['placement']) && $registration['placement']) {
        $placementText = is_numeric($registration['placement'])
            ? $registration['placement'] . ' место'
            : $registration['placement'];
        $overlayHtml .= createDiplomaField($placementText, $fieldPositions['placement']);
    }

    // Add participation date
    if (isset($fieldPositions['participation_date'])) {
        $overlayHtml .= createDiplomaField($participationDate, $fieldPositions['participation_date']);
    }

    // Add city
    if (isset($fieldPositions['city']) && $registration['city']) {
        $overlayHtml .= createDiplomaField($registration['city'], $fieldPositions['city']);
    }

    // Return response
    echo json_encode([
        'success' => true,
        'template_image' => '/assets/images/diplomas/' . $template['template_image'],
        'overlay_html' => $overlayHtml,
        'recipient_type' => $recipientType,
        'recipient_name' => $recipientName,
        'competition_name' => $registration['competition_name']
    ]);

} catch (Exception $e) {
    error_log('Preview error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Failed to generate preview: ' . $e->getMessage()
    ]);
}
