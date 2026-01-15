<?php
/**
 * Personal Cabinet Page
 * Displays user's paid registrations and diplomas
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/session.php';

// Auto-login via cookie if session doesn't exist
if (!isset($_SESSION['user_email']) && isset($_COOKIE['session_token'])) {
    $userObj = new User($db);
    $user = $userObj->findBySessionToken($_COOKIE['session_token']);

    if ($user) {
        // Valid token, log user in
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_id'] = $user['id'];
    }
}

// Check if user is logged in
if (!isset($_SESSION['user_email'])) {
    // User is not logged in, redirect to login page
    header('Location: /pages/login.php');
    exit;
}

// DEBUG: Check all registrations for this user
$debugStmt = $db->prepare("
    SELECT r.id, r.status, c.title
    FROM registrations r
    JOIN competitions c ON r.competition_id = c.id
    JOIN users u ON r.user_id = u.id
    WHERE u.email = ?
");
$debugStmt->execute([$_SESSION['user_email']]);
$allUserRegs = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
error_log("Cabinet - User email: " . $_SESSION['user_email']);
error_log("Cabinet - All user registrations: " . json_encode($allUserRegs));

// Get user's paid registrations
$stmt = $db->prepare("
    SELECT
        r.id,
        r.nomination,
        r.work_title,
        r.diploma_template_id,
        r.status,
        r.created_at,
        r.has_supervisor,
        r.supervisor_name,
        r.supervisor_email,
        r.supervisor_organization,
        c.title as competition_name,
        c.price,
        u.full_name,
        u.email
    FROM registrations r
    JOIN competitions c ON r.competition_id = c.id
    JOIN users u ON r.user_id = u.id
    WHERE u.email = ? AND r.status IN ('paid', 'diploma_ready')
    ORDER BY r.created_at DESC
");
$stmt->execute([$_SESSION['user_email']]);
$registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
error_log("Cabinet - Paid registrations count: " . count($registrations));

// Page metadata
$pageTitle = 'Личный кабинет | ' . SITE_NAME;
$pageDescription = 'Ваши регистрации и дипломы';
$additionalCSS = ['/assets/css/cabinet.css'];

// Include header
include __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="cabinet-container">
        <!-- Header -->
        <div class="cabinet-header">
            <h1>Личный кабинет</h1>
            <p class="user-email">
                <span class="email-icon">📧</span>
                <?php echo htmlspecialchars($_SESSION['user_email']); ?>
            </p>
        </div>

        <!-- DEBUG INFO (temporary) -->
        <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 16px; border-radius: 8px; margin-bottom: 24px;">
            <h3 style="margin: 0 0 12px 0; color: #856404;">Debug Info:</h3>
            <p style="margin: 4px 0; font-size: 14px;">
                <strong>User Email:</strong> <?php echo htmlspecialchars($_SESSION['user_email']); ?>
            </p>
            <p style="margin: 4px 0; font-size: 14px;">
                <strong>Total Registrations (all statuses):</strong> <?php echo count($allUserRegs); ?>
            </p>
            <p style="margin: 4px 0; font-size: 14px;">
                <strong>Paid Registrations:</strong> <?php echo count($registrations); ?>
            </p>
            <?php if (!empty($allUserRegs)): ?>
                <details style="margin-top: 8px;">
                    <summary style="cursor: pointer; font-weight: bold;">View All Registrations</summary>
                    <pre style="background: white; padding: 8px; margin-top: 8px; border-radius: 4px; overflow: auto;"><?php echo json_encode($allUserRegs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?></pre>
                </details>
            <?php endif; ?>
        </div>

        <?php if (empty($registrations)): ?>
            <!-- No registrations -->
            <div class="empty-cabinet">
                <div class="empty-icon">📋</div>
                <h2>У вас пока нет оплаченных регистраций</h2>
                <p>Примите участие в конкурсах и ваши дипломы появятся здесь</p>
                <a href="/index.php" class="btn btn-primary">
                    Перейти к конкурсам
                </a>
            </div>
        <?php else: ?>
            <!-- Success message for new payments -->
            <?php if (isset($_GET['payment']) && $_GET['payment'] === 'success'): ?>
                <div class="success-message">
                    <div class="success-icon">✅</div>
                    <div>
                        <h3>Оплата успешно завершена!</h3>
                        <p>Ваши дипломы теперь доступны для скачивания</p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Registrations List -->
            <div class="registrations-section">
                <h2>Ваши регистрации (<?php echo count($registrations); ?>)</h2>

                <div class="registrations-grid">
                    <?php foreach ($registrations as $reg):
                        // Map status to display values
                        $statusMap = [
                            'pending' => ['name' => 'В ожидании', 'color' => '#fbbf24'],
                            'paid' => ['name' => 'Оплачено', 'color' => '#10b981'],
                            'diploma_ready' => ['name' => 'Диплом выдан', 'color' => '#3b82f6']
                        ];
                        $statusInfo = $statusMap[$reg['status']] ?? ['name' => 'Неизвестно', 'color' => '#9ca3af'];
                    ?>
                        <div class="registration-card">
                            <div class="card-header">
                                <h3><?php echo htmlspecialchars($reg['competition_name']); ?></h3>
                                <span class="status-badge" style="background-color: <?php echo $statusInfo['color']; ?>">
                                    <?php echo $statusInfo['name']; ?>
                                </span>
                            </div>

                            <div class="card-body">
                                <div class="info-row">
                                    <span class="label">ФИО:</span>
                                    <span class="value"><?php echo htmlspecialchars($reg['full_name']); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="label">Номинация:</span>
                                    <span class="value"><?php echo htmlspecialchars($reg['nomination']); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="label">Дата регистрации:</span>
                                    <span class="value"><?php echo date('d.m.Y H:i', strtotime($reg['created_at'])); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="label">Стоимость:</span>
                                    <span class="value"><?php echo number_format($reg['price'], 0, ',', ' '); ?> ₽</span>
                                </div>
                            </div>

                            <div class="card-actions">
                                <?php if ($reg['status'] === 'paid' || $reg['status'] === 'diploma_ready'): ?>
                                    <!-- Participant diploma -->
                                    <a href="/ajax/download-diploma.php?registration_id=<?php echo $reg['id']; ?>&type=participant"
                                       class="btn btn-success btn-download"
                                       target="_blank">
                                        📥 Скачать диплом
                                    </a>

                                    <!-- Supervisor diploma (if exists) -->
                                    <?php
                                    $hasSupervisor = !empty($reg['supervisor_name']);
                                    if ($hasSupervisor):
                                    ?>
                                        <a href="/ajax/download-diploma.php?registration_id=<?php echo $reg['id']; ?>&type=supervisor"
                                           class="btn btn-success btn-download"
                                           target="_blank"
                                           style="background: linear-gradient(135deg, #1E3A5F 0%, #3B5998 100%);">
                                            📥 Диплом руководителя
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <button onclick="openDiplomaPreview(<?php echo $reg['id']; ?>, 'participant')"
                                        class="btn btn-secondary btn-preview">
                                    👁️ Предпросмотр
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Info Section -->
            <div class="info-section">
                <h3>О дипломах</h3>
                <ul>
                    <li>
                        <strong>Генерация PDF:</strong> Дипломы генерируются автоматически в формате PDF высокого качества при первом скачивании
                    </li>
                    <li>
                        <strong>Диплом руководителя:</strong> Если вы указали руководителя при регистрации, для него также будет доступен отдельный диплом
                    </li>
                    <li>
                        <strong>Хранение:</strong> Все ваши дипломы хранятся в личном кабинете и доступны для повторного скачивания в любое время
                    </li>
                    <li>
                        <strong>Формат:</strong> Дипломы создаются на основе выбранного вами шаблона с вашими данными
                    </li>
                </ul>
            </div>

            <!-- Actions -->
            <div class="cabinet-actions">
                <a href="/index.php" class="btn btn-primary">
                    Принять участие в других конкурсах
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Diploma Preview Modal -->
<div id="diplomaModal" class="diploma-modal">
    <div class="diploma-modal-content">
        <div class="diploma-modal-header">
            <h2 id="modalTitle">Предпросмотр диплома</h2>
            <button class="diploma-modal-close" onclick="closeDiplomaPreview()">&times;</button>
        </div>
        <div class="diploma-modal-body" id="modalBody">
            <div class="diploma-modal-loading">
                <div class="spinner"></div>
                <p>Загрузка предпросмотра...</p>
            </div>
        </div>
    </div>
</div>

<script>
// Open diploma preview modal
function openDiplomaPreview(registrationId, type = 'participant') {
    const modal = document.getElementById('diplomaModal');
    const modalBody = document.getElementById('modalBody');
    const modalTitle = document.getElementById('modalTitle');

    // Show modal with loading state
    modal.classList.add('active');
    modalBody.innerHTML = `
        <div class="diploma-modal-loading">
            <div class="spinner"></div>
            <p>Загрузка предпросмотра...</p>
        </div>
    `;

    // Fetch diploma preview
    fetch(`/ajax/get-diploma-preview.php?registration_id=${registrationId}&type=${type}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update modal title
                const typeLabel = type === 'supervisor' ? 'Руководитель' : 'Участник';
                modalTitle.textContent = `Предпросмотр диплома - ${typeLabel}`;

                // Update modal body with diploma preview
                modalBody.innerHTML = `
                    <div class="diploma-preview-container">
                        <img src="${data.template_image}" alt="Diploma Template">
                        <div class="diploma-overlay">
                            ${data.overlay_html}
                        </div>
                    </div>
                `;
            } else {
                modalBody.innerHTML = `
                    <div class="diploma-modal-loading">
                        <p style="color: #ef4444;">Ошибка: ${data.message}</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading preview:', error);
            modalBody.innerHTML = `
                <div class="diploma-modal-loading">
                    <p style="color: #ef4444;">Ошибка загрузки предпросмотра</p>
                </div>
            `;
        });
}

// Close diploma preview modal
function closeDiplomaPreview() {
    const modal = document.getElementById('diplomaModal');
    modal.classList.remove('active');
}

// Close modal when clicking outside
document.getElementById('diplomaModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDiplomaPreview();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDiplomaPreview();
    }
});
</script>

<?php
// Include footer
include __DIR__ . '/../includes/footer.php';
?>
