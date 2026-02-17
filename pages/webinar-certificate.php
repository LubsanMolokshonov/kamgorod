<?php
/**
 * Webinar Certificate Page
 * Form for ordering a webinar participant certificate
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/WebinarRegistration.php';
require_once __DIR__ . '/../classes/WebinarCertificate.php';
require_once __DIR__ . '/../includes/session.php';

// Auto-login via cookie if session doesn't exist
if (!isset($_SESSION['user_email']) && isset($_COOKIE['session_token'])) {
    $userObj = new User($db);
    $user = $userObj->findBySessionToken($_COOKIE['session_token']);
    if ($user) {
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_id'] = $user['id'];
    }
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $redirectUrl = '/pages/webinar-certificate.php?registration_id=' . ($_GET['registration_id'] ?? '');
    header('Location: /pages/login.php?redirect=' . urlencode($redirectUrl));
    exit;
}

// Get registration ID from URL
$registrationId = intval($_GET['registration_id'] ?? 0);
if (!$registrationId) {
    header('Location: /pages/webinars.php');
    exit;
}

$webinarRegObj = new WebinarRegistration($db);
$webCertObj = new WebinarCertificate($db);

// Get registration with webinar data
$registration = $webinarRegObj->getById($registrationId);
if (!$registration) {
    header('Location: /pages/webinars.php');
    exit;
}

// Verify ownership
if ($registration['user_id'] != $_SESSION['user_id']) {
    header('Location: /pages/cabinet.php?tab=webinars');
    exit;
}

// Check certificate availability
$webinarTime = strtotime($registration['scheduled_at']);
$certificateAvailableTime = $webinarTime + 3600;

// For autowebinars: skip time check, require quiz passage
require_once __DIR__ . '/../classes/Webinar.php';
$webinarCheckObj = new Webinar($db);
$webinarData = $webinarCheckObj->getById($registration['webinar_id']);

if ($webinarData && $webinarData['status'] === 'autowebinar') {
    require_once __DIR__ . '/../classes/WebinarQuiz.php';
    $quizCheckObj = new WebinarQuiz($db);
    if (!$quizCheckObj->hasPassed($registrationId)) {
        header('Location: /kabinet/avtovebinar/' . $registrationId);
        exit;
    }
} else {
    // Regular webinars: time-based check
    if (time() < $certificateAvailableTime) {
        header('Location: /pages/cabinet.php?tab=webinars');
        exit;
    }
}

// Get existing certificate if any
$existingCert = $webCertObj->getByRegistrationId($registrationId);

// Get user data for pre-populating form
$userStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$userStmt->execute([$_SESSION['user_id']]);
$userData = $userStmt->fetch(PDO::FETCH_ASSOC);

// Webinar date formatting
$webinarDate = date('d.m.Y', $webinarTime);
$certificatePrice = $registration['certificate_price'] ?? 149;
$certificateHours = $registration['certificate_hours'] ?? 2;

// Get diploma templates for certificate background selection
$templates = $db->query(
    "SELECT * FROM diploma_templates WHERE is_active = 1 AND type = 'participant' ORDER BY display_order ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Page metadata
$pageTitle = 'Сертификат участника вебинара | ' . SITE_NAME;
$pageDescription = 'Оформите сертификат участника вебинара';
$additionalCSS = ['/assets/css/form.css?v=' . time()];

include __DIR__ . '/../includes/header.php';
?>

<div class="diploma-form-wrapper">
    <div class="diploma-form-container">
        <h1 class="diploma-form-title">Оформите <span class="highlight-blue">сертификат</span> участника вебинара</h1>

        <div class="diploma-form-content">
            <!-- Left side: Form -->
            <div class="diploma-form-left">
                <?php if ($existingCert && $existingCert['status'] === 'ready'): ?>
                    <!-- Certificate ready -->
                    <div class="certificate-ready-card">
                        <div class="success-icon">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                        </div>
                        <h3>Сертификат готов!</h3>
                        <p>Вы можете скачать его в личном кабинете</p>
                        <a href="/ajax/download-webinar-certificate.php?id=<?php echo $existingCert['id']; ?>" class="btn btn-submit" style="margin-bottom: 12px;">Скачать сертификат</a>
                        <a href="/pages/cabinet.php?tab=webinars" class="btn btn-outline" style="display: inline-block;">Перейти в личный кабинет</a>
                    </div>
                <?php elseif ($existingCert && $existingCert['status'] === 'paid'): ?>
                    <!-- Certificate paid, generating -->
                    <div class="certificate-pending-card">
                        <div class="pending-icon">
                            <span class="spinner"></span>
                        </div>
                        <h3>Сертификат формируется</h3>
                        <p>Оплата прошла успешно. Сертификат будет готов в ближайшее время.</p>
                        <a href="/pages/cabinet.php?tab=webinars" class="btn btn-submit">Проверить в личном кабинете</a>
                    </div>
                <?php else: ?>
                    <!-- Webinar info card -->
                    <div class="webinar-info-block">
                        <div class="webinar-info-label">Ваш вебинар:</div>
                        <div class="webinar-info-title"><?php echo htmlspecialchars($registration['webinar_title']); ?></div>
                        <div class="webinar-info-meta">
                            <?php if (!empty($registration['speaker_name'])): ?>
                                Спикер: <?php echo htmlspecialchars($registration['speaker_name']); ?> &bull;
                            <?php endif; ?>
                            <?php echo $webinarDate; ?> &bull;
                            <?php echo $certificateHours; ?> ч.
                        </div>
                    </div>

                    <!-- Form -->
                    <form id="webinarCertificateForm" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="registration_id" value="<?php echo $registrationId; ?>">
                        <input type="hidden" name="template_id" id="selectedTemplateId" value="<?php echo !empty($templates) ? $templates[0]['id'] : 1; ?>">

                        <div class="form-section-title">Данные для сертификата</div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="full_name"
                                   name="full_name"
                                   maxlength="55"
                                   value="<?php echo htmlspecialchars($registration['full_name'] ?? $userData['full_name'] ?? ''); ?>"
                                   placeholder="Фамилия Имя Отчество *"
                                   required>
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="organization"
                                   name="organization"
                                   value="<?php echo htmlspecialchars($registration['organization'] ?? $userData['organization'] ?? ''); ?>"
                                   placeholder="Образовательное учреждение">
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="city"
                                   name="city"
                                   value="<?php echo htmlspecialchars($registration['city'] ?? $userData['city'] ?? ''); ?>"
                                   placeholder="Населенный пункт">
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="position"
                                   name="position"
                                   value="<?php echo htmlspecialchars($registration['position'] ?? $userData['profession'] ?? ''); ?>"
                                   placeholder="Должность">
                        </div>

                        <div class="price-block">
                            <span class="price-label">Стоимость сертификата:</span>
                            <span class="price-value"><?php echo number_format($certificatePrice, 0, ',', ' '); ?> &#8381;</span>
                        </div>

                        <button type="submit" class="btn btn-submit">
                            ПЕРЕЙТИ К ОПЛАТЕ
                        </button>

                        <p class="form-hint">
                            После оплаты сертификат будет доступен<br>для скачивания в личном кабинете
                        </p>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Right side: Certificate Preview with Template Gallery -->
            <div class="diploma-form-right">
                <div class="diploma-preview-container">
                    <!-- Main Preview -->
                    <div class="diploma-preview-main">
                        <?php $firstTemplateId = !empty($templates) ? $templates[0]['id'] : 1; ?>
                        <div class="certificate-preview-wrapper">
                            <img id="diplomaPreview"
                                 src="/assets/images/diplomas/templates/backgrounds/template-<?php echo $firstTemplateId; ?>.svg"
                                 alt="Предпросмотр сертификата"
                                 onerror="this.src='/assets/images/diplomas/thumbnails/thumb-<?php echo $firstTemplateId; ?>.svg'">
                            <!-- Text overlay for live preview -->
                            <div class="certificate-text-overlay">
                                <div class="preview-header">СЕРТИФИКАТ</div>
                                <div class="preview-subtitle">УЧАСТНИКА ВЕБИНАРА</div>
                                <div class="preview-text">подтверждает, что</div>
                                <div class="preview-name"><?php echo htmlspecialchars($registration['full_name'] ?? $userData['full_name'] ?? 'Иванов Иван Иванович'); ?></div>
                                <div class="preview-text">принял(а) участие в вебинаре</div>
                                <div class="preview-webinar-title">&laquo;<?php echo htmlspecialchars($registration['webinar_title']); ?>&raquo;</div>
                                <div class="preview-hours">Объём: <?php echo $certificateHours; ?> ч.</div>
                                <div class="preview-number">ВЕБ-<?php echo date('Y'); ?>-XXXXXX</div>
                            </div>
                            <!-- Bottom: date, stamp, chairman -->
                            <div class="certificate-bottom-overlay">
                                <div class="preview-date"><?php echo date('d.m.Y'); ?></div>
                                <div class="preview-stamp-chairman">
                                    <img src="/assets/images/diplomas/stamp-brehach.png" class="preview-stamp" alt="">
                                    <div class="preview-chairman-label">Председатель Оргкомитета</div>
                                    <div class="preview-chairman-name">Брехач Р.А.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Template Gallery -->
                    <div class="diploma-gallery">
                        <?php if (!empty($templates)): ?>
                            <?php foreach ($templates as $index => $template): ?>
                                <div class="diploma-gallery-item <?php echo $index === 0 ? 'active' : ''; ?>"
                                     data-template-id="<?php echo $template['id']; ?>">
                                    <?php
                                    $thumbPath = '/assets/images/diplomas/thumbnails/thumb-' . $template['id'] . '.svg';
                                    if (!file_exists(__DIR__ . '/..' . $thumbPath)) {
                                        $thumbPath = '/assets/images/diplomas/thumbnails/diploma-template-' . $template['id'] . '.svg';
                                    }
                                    ?>
                                    <img src="<?php echo $thumbPath; ?>"
                                         alt="<?php echo htmlspecialchars($template['name']); ?>"
                                         onerror="this.src='https://via.placeholder.com/150x212/dac2ff/8742ee?text=<?php echo $template['id']; ?>'">
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <div class="diploma-gallery-item <?php echo $i === 1 ? 'active' : ''; ?>"
                                     data-template-id="<?php echo $i; ?>">
                                    <img src="/assets/images/diplomas/thumbnails/thumb-<?php echo $i; ?>.svg"
                                         alt="Шаблон <?php echo $i; ?>">
                                </div>
                            <?php endfor; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
</div>

<style>
/* Webinar Certificate specific styles */
.webinar-info-block {
    background: #f0f9ff;
    border-radius: 10px;
    padding: 16px 18px;
    margin-bottom: 24px;
    border-left: 4px solid #7c3aed;
}

.webinar-info-label {
    font-size: 12px;
    color: #6b7280;
    margin-bottom: 6px;
}

.webinar-info-title {
    font-size: 16px;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 4px;
    line-height: 1.4;
}

.webinar-info-meta {
    font-size: 13px;
    color: #4b5563;
}

.form-section-title {
    font-size: 15px;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 16px;
    padding-bottom: 10px;
    border-bottom: 1px solid #e5e7eb;
}

.price-block {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f0fdf4;
    border-radius: 10px;
    padding: 16px 18px;
    margin: 20px 0;
    border: 1px solid #bbf7d0;
}

.price-label {
    font-size: 14px;
    color: #374151;
    font-weight: 500;
}

.price-value {
    font-size: 24px;
    font-weight: 700;
    color: #059669;
}

.form-hint {
    text-align: center;
    font-size: 12px;
    color: #9ca3af;
    margin-top: 16px;
    line-height: 1.5;
}

/* Certificate ready/pending cards */
.certificate-ready-card,
.certificate-pending-card {
    text-align: center;
    padding: 40px 20px;
}

.certificate-ready-card .success-icon,
.certificate-pending-card .pending-icon {
    margin-bottom: 20px;
}

.certificate-ready-card h3,
.certificate-pending-card h3 {
    font-size: 20px;
    color: #1f2937;
    margin-bottom: 10px;
}

.certificate-ready-card p,
.certificate-pending-card p {
    color: #6b7280;
    margin-bottom: 24px;
}

.certificate-pending-card .spinner {
    width: 48px;
    height: 48px;
    border: 4px solid #e5e7eb;
    border-top-color: #7c3aed;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin: 0 auto;
}

/* Certificate preview wrapper - inline-block to shrink to image size */
.certificate-preview-wrapper {
    position: relative;
    display: inline-block;
}

.certificate-text-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    gap: 4px;
    padding: 15% 10%;
    pointer-events: none;
    text-align: center;
}

.preview-header {
    font-size: clamp(14px, 2.5vw, 28px);
    font-weight: bold;
    color: #0065B1;
    letter-spacing: 3px;
    text-shadow: 0 0 8px rgba(255,255,255,0.9);
}

.preview-subtitle {
    font-size: clamp(8px, 1.2vw, 14px);
    font-weight: 600;
    color: #333;
    margin-bottom: 6px;
    text-shadow: 0 0 6px rgba(255,255,255,0.9);
}

.preview-text {
    font-size: clamp(7px, 1vw, 12px);
    color: #444;
    font-style: italic;
    text-shadow: 0 0 6px rgba(255,255,255,0.9);
}

.preview-name {
    font-size: clamp(10px, 1.6vw, 18px);
    font-weight: bold;
    color: #000;
    margin: 4px 0;
    text-shadow: 0 0 8px rgba(255,255,255,0.9);
}

.preview-webinar-title {
    font-size: clamp(7px, 1.1vw, 13px);
    font-weight: 600;
    color: #0065B1;
    margin: 2px 0;
    line-height: 1.4;
    text-shadow: 0 0 6px rgba(255,255,255,0.9);
}

.preview-hours {
    font-size: clamp(7px, 1vw, 12px);
    font-weight: 600;
    color: #333;
    margin-top: 4px;
    text-shadow: 0 0 6px rgba(255,255,255,0.9);
}

.preview-number {
    font-size: clamp(6px, 0.8vw, 10px);
    color: #666;
    margin-top: 8px;
    text-shadow: 0 0 6px rgba(255,255,255,0.9);
}

/* Bottom overlay: date, stamp, chairman — match competition diploma style */
.certificate-bottom-overlay {
    position: absolute;
    bottom: 10%;
    left: 8%;
    right: 6%;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    pointer-events: none;
}

.preview-date {
    font-size: clamp(8px, 1.2vw, 13px);
    color: #000;
    text-shadow: 0 0 6px rgba(255,255,255,0.9);
}

.preview-stamp-chairman {
    text-align: center;
    position: relative;
}

.preview-stamp {
    width: 28% !important;
    min-width: 120px !important;
    max-width: 180px !important;
    max-height: none !important;
    height: auto !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    opacity: 0.85;
    display: block;
    margin: 0 auto;
    position: absolute;
    bottom: -10px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 0;
}

.preview-chairman-label {
    font-size: clamp(7px, 1vw, 11px);
    color: #000;
    text-shadow: 0 0 6px rgba(255,255,255,0.9);
    position: relative;
    z-index: 1;
}

.preview-chairman-name {
    font-size: clamp(8px, 1.1vw, 12px);
    font-weight: bold;
    color: #000;
    text-shadow: 0 0 6px rgba(255,255,255,0.9);
    position: relative;
    z-index: 1;
}
</style>

<!-- E-commerce: Detail (просмотр товара — сертификат вебинара) -->
<script>
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({
    "ecommerce": {
        "currencyCode": "RUB",
        "detail": {
            "products": [{
                "id": "wc-<?php echo $registration['webinar_id']; ?>",
                "name": "<?php echo htmlspecialchars($registration['webinar_title'], ENT_QUOTES); ?>",
                "price": <?php echo $certificatePrice; ?>,
                "brand": "Педпортал",
                "category": "Вебинары"
            }]
        }
    }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('webinarCertificateForm');
    if (!form) return;

    // Template Gallery Selection
    const galleryItems = document.querySelectorAll('.diploma-gallery-item');
    const selectedTemplateInput = document.getElementById('selectedTemplateId');
    const previewImg = document.getElementById('diplomaPreview');

    galleryItems.forEach(function(item) {
        item.addEventListener('click', function() {
            // Remove previous selection
            galleryItems.forEach(function(g) { g.classList.remove('active'); });
            // Mark as selected
            this.classList.add('active');
            // Store template ID
            var templateId = this.dataset.templateId;
            if (selectedTemplateInput) {
                selectedTemplateInput.value = templateId;
            }
            // Update preview background
            if (previewImg) {
                previewImg.src = '/assets/images/diplomas/templates/backgrounds/template-' + templateId + '.svg';
            }
        });
    });

    // Live preview update
    const nameInput = document.getElementById('full_name');
    const previewName = document.querySelector('.preview-name');
    if (nameInput && previewName) {
        nameInput.addEventListener('input', function() {
            previewName.textContent = this.value || 'Иванов Иван Иванович';
        });
    }

    // Form submission
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        const btn = this.querySelector('button[type="submit"]');
        const overlay = document.getElementById('loadingOverlay');

        btn.disabled = true;
        btn.textContent = 'Обработка...';
        if (overlay) overlay.style.display = 'flex';

        const formData = new FormData(this);

        fetch('/ajax/create-webinar-cert-payment.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.redirect_url) {
                // E-commerce: Add to cart event
                if (data.ecommerce) {
                    window.dataLayer = window.dataLayer || [];
                    window.dataLayer.push({
                        "ecommerce": {
                            "currencyCode": "RUB",
                            "add": {
                                "products": [{
                                    "id": String(data.ecommerce.id),
                                    "name": data.ecommerce.name,
                                    "price": parseFloat(data.ecommerce.price),
                                    "brand": "Педпортал",
                                    "category": data.ecommerce.category,
                                    "quantity": 1
                                }]
                            }
                        }
                    });
                }
                // Delay redirect to allow dataLayer to send
                setTimeout(function() { window.location.href = data.redirect_url; }, 300);
            } else {
                alert(data.message || 'Произошла ошибка');
                btn.disabled = false;
                btn.textContent = 'ПЕРЕЙТИ К ОПЛАТЕ';
                if (overlay) overlay.style.display = 'none';
            }
        })
        .catch(err => {
            alert('Ошибка при обработке запроса');
            btn.disabled = false;
            btn.textContent = 'ПЕРЕЙТИ К ОПЛАТЕ';
            if (overlay) overlay.style.display = 'none';
        });
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
