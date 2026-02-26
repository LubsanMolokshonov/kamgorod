<?php
/**
 * Publication Certificate Page
 * Select template and pay for certificate
 * Design matching registration.php (diploma form)
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Publication.php';
require_once __DIR__ . '/../classes/PublicationCertificate.php';
require_once __DIR__ . '/../classes/CertificatePreview.php';
require_once __DIR__ . '/../includes/session.php';

// Get publication ID from URL
$publicationId = $_GET['id'] ?? null;

if (!$publicationId) {
    header('Location: /pages/submit-publication.php');
    exit;
}

$database = new Database($db);
$publicationObj = new Publication($db);
$certObj = new PublicationCertificate($db);

// Get publication
$publication = $publicationObj->getById($publicationId);

if (!$publication) {
    header('Location: /pages/submit-publication.php');
    exit;
}

// Check ownership if logged in
if (isset($_SESSION['user_id']) && $publication['user_id'] != $_SESSION['user_id']) {
    header('Location: /pages/submit-publication.php');
    exit;
}

// Get existing certificate if any
$existingCert = $certObj->getByPublicationId($publicationId);

// Get publication tags for direction
$publicationTags = $publicationObj->getTags($publicationId);
$directionTag = '';
foreach ($publicationTags as $tag) {
    if ($tag['tag_type'] === 'direction') {
        $directionTag = $tag['name'];
        break;
    }
}

// Certificate templates - use static array since we have SVG files
$templates = [
    ['id' => 1, 'name' => 'Синий классический'],
    ['id' => 2, 'name' => 'Зелёный'],
    ['id' => 3, 'name' => 'Фиолетовый'],
    ['id' => 4, 'name' => 'Красный'],
    ['id' => 5, 'name' => 'Оранжевый'],
    ['id' => 6, 'name' => 'Бирюзовый']
];

// Get user data
$userStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$userStmt->execute([$publication['user_id']]);
$userData = $userStmt->fetch(PDO::FETCH_ASSOC);

// Generate initial dynamic preview
$previewData = [
    'author_name'        => $userData['full_name'] ?? '',
    'organization'       => $userData['organization'] ?? '',
    'city'               => $userData['city'] ?? '',
    'position'           => $userData['profession'] ?? '',
    'publication_title'  => $publication['title'],
    'publication_type'   => $publication['type_name'] ?? '',
    'direction'          => $directionTag,
    'publication_date'   => date('Y-m-d'),
    'certificate_number' => ''
];
$initialPreview = new CertificatePreview(1, $previewData);
$initialPreviewUri = $initialPreview->getDataUri();

// Page metadata
$pageTitle = 'Оформление свидетельства о публикации | ' . SITE_NAME;
$pageDescription = 'Оформите свидетельство о публикации в электронном журнале';
$additionalCSS = ['/assets/css/form.css?v=' . time()];
$additionalJS = ['/assets/js/certificate-form.js?v=' . time()];

include __DIR__ . '/../includes/header.php';
?>

<div class="diploma-form-wrapper">
    <div class="diploma-form-container">
        <h1 class="diploma-form-title">Оформите <span class="highlight-blue">свидетельство</span> о публикации</h1>

        <div class="diploma-form-content">
            <!-- Left side: Form -->
            <div class="diploma-form-left">
                <?php if ($existingCert && $existingCert['status'] === 'ready'): ?>
                    <!-- Certificate already ready -->
                    <div class="certificate-ready-card">
                        <div class="success-icon">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                        </div>
                        <h3>Свидетельство готово!</h3>
                        <p>Вы можете скачать его в личном кабинете</p>
                        <a href="/pages/cabinet.php" class="btn btn-submit">Перейти в личный кабинет</a>
                    </div>
                <?php elseif ($existingCert && $existingCert['status'] === 'paid'): ?>
                    <!-- Certificate paid, generating -->
                    <div class="certificate-pending-card">
                        <div class="pending-icon">
                            <span class="spinner"></span>
                        </div>
                        <h3>Свидетельство формируется</h3>
                        <p>Оплата прошла успешно. Свидетельство будет готово в ближайшее время.</p>
                        <a href="/pages/cabinet.php" class="btn btn-submit">Проверить в личном кабинете</a>
                    </div>
                <?php else: ?>
                    <!-- Publication info card -->
                    <div class="publication-info-block">
                        <div class="pub-info-label">Ваша публикация:</div>
                        <div class="pub-info-title"><?php echo htmlspecialchars($publication['title']); ?></div>
                        <div class="pub-info-author">Автор: <?php echo htmlspecialchars($publication['author_name'] ?? $userData['full_name'] ?? ''); ?></div>
                    </div>

                    <!-- Form Container -->
                    <form id="certificateForm" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="publication_id" value="<?php echo $publicationId; ?>">
                        <input type="hidden" name="template_id" id="selectedTemplateId" value="<?php echo $templates[0]['id'] ?? 1; ?>">

                        <div class="form-section-title">Данные для свидетельства</div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="author_name"
                                   name="author_name"
                                   maxlength="55"
                                   value="<?php echo htmlspecialchars($userData['full_name'] ?? ''); ?>"
                                   placeholder="ФИО автора"
                                   required>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="organization"
                                   name="organization"
                                   value="<?php echo htmlspecialchars($userData['organization'] ?? ''); ?>"
                                   placeholder="Образовательное учреждение"
                                   required>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="city"
                                   name="city"
                                   value="<?php echo htmlspecialchars($userData['city'] ?? ''); ?>"
                                   placeholder="Населенный пункт">
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="position"
                                   name="position"
                                   value="<?php echo htmlspecialchars($userData['profession'] ?? ''); ?>"
                                   placeholder="Должность">
                        </div>

                        <div class="form-group">
                            <label class="form-label-small">Дата публикации:</label>
                            <input type="date"
                                   class="form-control"
                                   id="publication_date"
                                   name="publication_date"
                                   value="<?php echo date('Y-m-d'); ?>"
                                   required>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <div class="price-block">
                            <span class="price-label">Стоимость свидетельства:</span>
                            <span class="price-value">299 ₽</span>
                        </div>

                        <button type="submit" class="btn btn-submit">
                            ПЕРЕХОД К ОПЛАТЕ...
                        </button>

                        <p class="form-hint">
                            После оплаты свидетельство будет доступно<br>для скачивания в личном кабинете
                        </p>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Right side: Certificate Preview -->
            <div class="diploma-form-right">
                <div class="diploma-preview-container">
                    <!-- Main Preview -->
                    <div class="diploma-preview-main">
                        <img id="diplomaPreview"
                             src="<?php echo $initialPreviewUri; ?>"
                             alt="Предпросмотр свидетельства">
                    </div>

                    <!-- Template Gallery - Vertical on the right -->
                    <div class="diploma-gallery">
                        <?php foreach ($templates as $index => $template):
                            $thumbFile = '/assets/images/diplomas/templates/backgrounds/template-' . $template['id'] . '.svg';
                        ?>
                            <div class="diploma-gallery-item <?php echo $index === 0 ? 'active' : ''; ?>"
                                 data-template-id="<?php echo $template['id']; ?>">
                                <img src="<?php echo $thumbFile; ?>"
                                     alt="<?php echo htmlspecialchars($template['name']); ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Pass publication data to JS for dynamic preview -->
<script>
    window.certificateData = {
        publicationTitle: <?php echo json_encode($publication['title']); ?>,
        publicationType: <?php echo json_encode($publication['type_name'] ?? ''); ?>,
        direction: <?php echo json_encode($directionTag); ?>
    };
</script>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
</div>

<style>
/* Publication Certificate specific styles */
.publication-info-block {
    background: #f0f9ff;
    border-radius: 10px;
    padding: 16px 18px;
    margin-bottom: 24px;
    border-left: 4px solid #0065B1;
}

.pub-info-label {
    font-size: 12px;
    color: #6b7280;
    margin-bottom: 6px;
}

.pub-info-title {
    font-size: 16px;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 4px;
    line-height: 1.4;
}

.pub-info-author {
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
    border-top-color: #0065B1;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin: 0 auto;
}
</style>

<!-- E-commerce: Detail (просмотр товара — свидетельство о публикации) -->
<script>
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({
    "ecommerce": {
        "currencyCode": "RUB",
        "detail": {
            "products": [{
                "id": "pub-<?php echo $publicationId; ?>",
                "name": "<?php echo htmlspecialchars($publication['title'], ENT_QUOTES); ?>",
                "price": 299,
                "brand": "Педпортал",
                "category": "Публикации"
            }]
        }
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
