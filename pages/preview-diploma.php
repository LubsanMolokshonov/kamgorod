<?php
/**
 * Diploma Preview Page
 * Shows preview of diploma for a paid registration
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';

// Check if user is logged in
if (!isset($_SESSION['user_email'])) {
    header('Location: /pages/login.php');
    exit;
}

// Get registration ID
$registrationId = $_GET['registration_id'] ?? null;
$recipientType = $_GET['type'] ?? 'participant';

if (!$registrationId) {
    die('Registration ID is required');
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
    die('Registration not found or access denied');
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
    die('Template not found');
}

// Decode field positions
$fieldPositions = json_decode($template['field_positions'], true);

$pageTitle = 'Предпросмотр диплома | ' . SITE_NAME;
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px;
        }

        .preview-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 32px;
            max-width: 900px;
            width: 100%;
            margin: 20px auto;
        }

        .preview-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .preview-header h1 {
            color: #1f2937;
            font-size: 28px;
            margin-bottom: 8px;
        }

        .preview-header p {
            color: #6b7280;
            font-size: 14px;
        }

        .diploma-preview {
            background: #f9fafb;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .diploma-container {
            position: relative;
            max-width: 100%;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .diploma-container img {
            display: block;
            width: 100%;
            height: auto;
            border-radius: 8px;
        }

        .diploma-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
        }

        .diploma-field {
            position: absolute;
            font-family: 'DejaVu Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .info-item {
            background: #f3f4f6;
            padding: 16px;
            border-radius: 8px;
        }

        .info-item label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .info-item .value {
            font-size: 16px;
            color: #1f2937;
            font-weight: 500;
        }

        .actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #e5e7eb;
            color: #1f2937;
        }

        .btn-secondary:hover {
            background: #d1d5db;
        }

        .recipient-type {
            display: inline-block;
            padding: 6px 12px;
            background: <?php echo $recipientType === 'supervisor' ? '#8b5cf6' : '#10b981'; ?>;
            color: white;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 12px;
        }
    </style>
</head>
<body>
    <div class="preview-container">
        <div class="preview-header">
            <h1>
                Предпросмотр диплома
                <span class="recipient-type">
                    <?php echo $recipientType === 'supervisor' ? 'Руководитель' : 'Участник'; ?>
                </span>
            </h1>
            <p><?php echo htmlspecialchars($registration['competition_name']); ?></p>
        </div>

        <div class="diploma-preview">
            <div class="diploma-container">
                <img src="<?php echo '/assets/images/diplomas/' . htmlspecialchars($template['template_image']); ?>"
                     alt="Diploma Preview"
                     id="diplomaImage">
                <div class="diploma-overlay" id="diplomaOverlay">
                    <?php
                    // Helper function to create positioned text
                    function createDiplomaField($text, $position, $imageId = 'diplomaImage') {
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

                        // Scale font size relative to container
                        $fontSizeVw = ($fontSize / 210) * 100;

                        $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

                        return sprintf(
                            '<div class="diploma-field" style="left: %.2f%%; top: %.2f%%; width: %.2f%%; font-size: %.2fvw; font-weight: %s; text-align: %s; color: %s;">%s</div>',
                            $leftPercent,
                            $topPercent,
                            $widthPercent,
                            $fontSizeVw,
                            $fontWeight,
                            $textAlign,
                            $color,
                            $escapedText
                        );
                    }

                    // Add FIO
                    if (isset($fieldPositions['fio'])) {
                        echo createDiplomaField($recipientName, $fieldPositions['fio']);
                    }

                    // Add organization
                    $orgPosition = $fieldPositions['organization'] ?? $fieldPositions['org'] ?? null;
                    if ($orgPosition && $recipientOrganization) {
                        echo createDiplomaField($recipientOrganization, $orgPosition);
                    }

                    // Add nomination
                    if (isset($fieldPositions['nomination'])) {
                        echo createDiplomaField($registration['nomination'], $fieldPositions['nomination']);
                    }

                    // Add work title
                    if (isset($fieldPositions['work_title']) && $registration['work_title']) {
                        echo createDiplomaField('«' . $registration['work_title'] . '»', $fieldPositions['work_title']);
                    }

                    // Add competition name
                    if (isset($fieldPositions['competition_name'])) {
                        echo createDiplomaField($registration['competition_name'], $fieldPositions['competition_name']);
                    }

                    // Add placement
                    if (isset($fieldPositions['placement']) && $registration['placement']) {
                        $placementText = is_numeric($registration['placement'])
                            ? $registration['placement'] . ' место'
                            : $registration['placement'];
                        echo createDiplomaField($placementText, $fieldPositions['placement']);
                    }

                    // Add participation date
                    if (isset($fieldPositions['participation_date'])) {
                        echo createDiplomaField($participationDate, $fieldPositions['participation_date']);
                    }

                    // Add city
                    if (isset($fieldPositions['city']) && $registration['city']) {
                        echo createDiplomaField($registration['city'], $fieldPositions['city']);
                    }
                    ?>
                </div>
            </div>
        </div>

        <div class="info-grid">
            <div class="info-item">
                <label>ФИО</label>
                <div class="value"><?php echo htmlspecialchars($recipientName); ?></div>
            </div>

            <?php if ($recipientOrganization): ?>
            <div class="info-item">
                <label>Организация</label>
                <div class="value"><?php echo htmlspecialchars($recipientOrganization); ?></div>
            </div>
            <?php endif; ?>

            <?php if ($registration['city']): ?>
            <div class="info-item">
                <label>Город</label>
                <div class="value"><?php echo htmlspecialchars($registration['city']); ?></div>
            </div>
            <?php endif; ?>

            <div class="info-item">
                <label>Номинация</label>
                <div class="value"><?php echo htmlspecialchars($registration['nomination']); ?></div>
            </div>

            <?php if ($registration['work_title']): ?>
            <div class="info-item">
                <label>Название работы</label>
                <div class="value">«<?php echo htmlspecialchars($registration['work_title']); ?>»</div>
            </div>
            <?php endif; ?>

            <?php if ($registration['placement']): ?>
            <div class="info-item">
                <label>Место</label>
                <div class="value">
                    <?php
                    echo is_numeric($registration['placement'])
                        ? $registration['placement'] . ' место'
                        : htmlspecialchars($registration['placement']);
                    ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="info-item">
                <label>Дата участия</label>
                <div class="value"><?php echo $participationDate; ?></div>
            </div>
        </div>

        <div class="actions">
            <a href="/ajax/download-diploma.php?registration_id=<?php echo $registration['id']; ?>&type=<?php echo $recipientType; ?>"
               class="btn btn-primary"
               target="_blank">
                📥 Скачать диплом (PDF)
            </a>

            <?php if ($recipientType === 'participant' && $registration['has_supervisor']): ?>
            <a href="/pages/preview-diploma.php?registration_id=<?php echo $registration['id']; ?>&type=supervisor"
               class="btn btn-primary"
               style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                👁️ Предпросмотр диплома руководителя
            </a>
            <?php endif; ?>

            <?php if ($recipientType === 'supervisor'): ?>
            <a href="/pages/preview-diploma.php?registration_id=<?php echo $registration['id']; ?>&type=participant"
               class="btn btn-primary">
                👁️ Предпросмотр диплома участника
            </a>
            <?php endif; ?>

            <a href="/pages/cabinet.php" class="btn btn-secondary">
                ← Вернуться в кабинет
            </a>
        </div>
    </div>
</body>
</html>
