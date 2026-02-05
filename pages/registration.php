<?php
/**
 * Registration Page - 2 Step Process
 * Step 1: Select diploma template
 * Step 2: Fill registration form
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Competition.php';
require_once __DIR__ . '/../includes/session.php';

// Get competition ID from URL
$competitionId = $_GET['competition_id'] ?? null;

if (!$competitionId) {
    header('Location: /index.php');
    exit;
}

// Get competition details
$competitionObj = new Competition($db);
$competition = $competitionObj->getById($competitionId);

if (!$competition) {
    header('Location: /index.php');
    exit;
}

// Get nomination options
$nominations = $competitionObj->getNominationOptions($competitionId);

// Get diploma templates
$templates = $db->query(
    "SELECT * FROM diploma_templates WHERE is_active = 1 AND type = 'participant' ORDER BY display_order ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Pre-fill user data if exists
$userData = [];
if (isset($_SESSION['user_id'])) {
    $userStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Page metadata
$pageTitle = 'Регистрация на конкурс: ' . htmlspecialchars($competition['title']) . ' | ' . SITE_NAME;
$pageDescription = 'Заполните форму регистрации для участия в конкурсе';
$additionalCSS = ['/assets/css/form.css?v=' . time()];
$additionalJS = ['/assets/js/diploma-preview.js?v=' . time(), '/assets/js/form-validation.js?v=' . time()];

// Include header
include __DIR__ . '/../includes/header.php';
?>

<div class="diploma-form-wrapper">
    <div class="diploma-form-container">
        <h1 class="diploma-form-title">Оформите <span class="highlight-blue">диплом</span> конкурса за 30 секунд</h1>

        <div class="diploma-form-content">
            <!-- Left side: Form -->
            <div class="diploma-form-left">
                <!-- Tab Switcher -->
                <div class="diploma-tabs">
                    <button type="button" class="diploma-tab active" data-tab="participant">
                        Диплом участника
                    </button>
                    <button type="button" class="diploma-tab" data-tab="supervisor">
                        Диплом руководителя
                    </button>
                </div>

                <!-- Form Container -->
                <form id="registrationForm" method="POST">
                    <input type="hidden" name="competition_id" value="<?php echo $competitionId; ?>">
                    <input type="hidden" name="template_id" id="selectedTemplateId">
                    <input type="hidden" name="current_tab" id="currentTab" value="participant">

                    <!-- Participant Form (Tab 1) -->
                    <div class="diploma-tab-content active" id="participantForm">
                        <div class="form-group">
                            <input type="email"
                                   class="form-control"
                                   id="email"
                                   name="email"
                                   value="<?php echo htmlspecialchars($userData['email'] ?? ''); ?>"
                                   placeholder="Email"
                                   required>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="fio"
                                   name="fio"
                                   maxlength="55"
                                   value="<?php echo htmlspecialchars($userData['full_name'] ?? ''); ?>"
                                   placeholder="ФИО участника"
                                   required>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="organization"
                                   name="organization"
                                   value="<?php echo htmlspecialchars($userData['organization'] ?? ''); ?>"
                                   placeholder="Название учреждения"
                                   required>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="city"
                                   name="city"
                                   value="<?php echo htmlspecialchars($userData['city'] ?? ''); ?>"
                                   placeholder="Населенный пункт"
                                   required>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <div class="form-row-inline">
                            <div class="form-group">
                                <label class="form-label-small">Очистите поле "Руководитель", если он отсутствует</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="supervisor_name"
                                   name="supervisor_name"
                                   maxlength="55"
                                   placeholder="Руководитель">
                        </div>

                        <div class="form-group">
                            <select class="form-control" id="placement" name="placement" required>
                                <option value="1" selected>1 место</option>
                                <option value="2">2 место</option>
                                <option value="3">3 место</option>
                                <option value="участник">Участник</option>
                            </select>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <div class="form-group">
                            <select class="form-control" id="competition_type" name="competition_type" required>
                                <option value="всероссийский" selected>Всероссийский</option>
                                <option value="международный">Международный</option>
                                <option value="межрегиональный">Межрегиональный</option>
                            </select>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <div class="form-group">
                            <select class="form-control" id="nomination" name="nomination" required>
                                <?php foreach ($nominations as $index => $nom): ?>
                                    <option value="<?php echo htmlspecialchars($nom); ?>"<?php echo $index === 0 ? ' selected' : ''; ?>>
                                        <?php echo htmlspecialchars($nom); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                            <div class="form-helper-link">
                                <a href="#" id="selectNominationLink">Выберите</a> или
                                <a href="#" id="enterNominationLink">введите свою</a> номинацию
                            </div>
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="work_title"
                                   name="work_title"
                                   placeholder="Название работы">
                        </div>

                        <div class="form-group">
                            <label class="form-label-small">Дата участия в творческом конкурсе:</label>
                            <input type="date"
                                   class="form-control"
                                   id="participation_date"
                                   name="participation_date"
                                   value="<?php echo date('Y-m-d'); ?>"
                                   placeholder="Дата участия"
                                   required>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <!-- Agreement Checkbox -->
                        <div class="form-agreement" style="margin-bottom: 20px;">
                            <label class="checkbox-label" style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                <input type="checkbox" name="agreement" id="agreement" required style="margin-top: 3px; width: 18px; height: 18px; flex-shrink: 0;">
                                <span class="agreement-text" style="font-size: 13px; color: #64748B; line-height: 1.5;">
                                    Я принимаю условия <a href="/pages/terms.php" target="_blank" style="color: #3B5998;">Пользовательского соглашения</a>
                                    и даю согласие на обработку персональных данных в соответствии с
                                    <a href="/pages/privacy.php" target="_blank" style="color: #3B5998;">Политикой конфиденциальности</a>
                                </span>
                            </label>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <button type="submit" class="btn btn-submit">
                            ПОПОЛНИТЬ ПОРТФОЛИО СЕЙЧАС
                        </button>
                    </div>

                    <!-- Supervisor Form (Tab 2) -->
                    <div class="diploma-tab-content" id="supervisorForm">
                        <div class="form-group">
                            <input type="email"
                                   class="form-control"
                                   id="supervisor_email"
                                   name="supervisor_email"
                                   placeholder="Email">
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   name="supervisor_name_alt"
                                   placeholder="ФИО руководителя">
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="supervisor_organization"
                                   name="supervisor_organization"
                                   placeholder="Название учреждения">
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   name="supervisor_city"
                                   placeholder="Населенный пункт">
                        </div>

                        <div class="form-group">
                            <select class="form-control" name="supervisor_competition_type">
                                <option value="">Тип конкурса:</option>
                                <option value="всероссийский">Всероссийский</option>
                                <option value="международный">Международный</option>
                                <option value="межрегиональный">Межрегиональный</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <select class="form-control" name="supervisor_nomination">
                                <option value="">Номинация:</option>
                                <?php foreach ($nominations as $nom): ?>
                                    <option value="<?php echo htmlspecialchars($nom); ?>">
                                        <?php echo htmlspecialchars($nom); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   name="supervisor_work_title"
                                   placeholder="Название работы">
                        </div>

                        <div class="form-group">
                            <label class="form-label-small">Дата участия в творческом конкурсе:</label>
                            <input type="date"
                                   class="form-control"
                                   name="supervisor_participation_date"
                                   value="<?php echo date('Y-m-d'); ?>"
                                   placeholder="Дата участия">
                        </div>

                        <!-- Agreement Checkbox for Supervisor -->
                        <div class="form-agreement" style="margin-bottom: 20px;">
                            <label class="checkbox-label" style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                <input type="checkbox" name="supervisor_agreement" id="supervisor_agreement" required style="margin-top: 3px; width: 18px; height: 18px; flex-shrink: 0;">
                                <span class="agreement-text" style="font-size: 13px; color: #64748B; line-height: 1.5;">
                                    Я принимаю условия <a href="/pages/terms.php" target="_blank" style="color: #3B5998;">Пользовательского соглашения</a>
                                    и даю согласие на обработку персональных данных в соответствии с
                                    <a href="/pages/privacy.php" target="_blank" style="color: #3B5998;">Политикой конфиденциальности</a>
                                </span>
                            </label>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <button type="submit" class="btn btn-submit">
                            ПОПОЛНИТЬ ПОРТФОЛИО СЕЙЧАС
                        </button>
                    </div>
                </form>
            </div>

            <!-- Right side: Diploma Preview -->
            <div class="diploma-form-right">
                <div class="diploma-preview-container">
                    <!-- Main Preview - Now first (on the left) -->
                    <div class="diploma-preview-main">
                        <img id="diplomaPreview"
                             src="/assets/images/diplomas/templates/backgrounds/template-1.svg"
                             alt="Предпросмотр диплома"
                             onerror="this.src='/assets/images/diplomas/thumbnails/thumb-1.svg'">
                    </div>

                    <!-- Template Gallery - Vertical on the right -->
                    <div class="diploma-gallery">
                        <?php if (empty($templates)): ?>
                            <!-- Default templates -->
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <div class="diploma-gallery-item <?php echo $i === 1 ? 'active' : ''; ?>"
                                     data-template-id="<?php echo $i; ?>">
                                    <img src="/assets/images/diplomas/thumbnails/thumb-<?php echo $i; ?>.svg"
                                         alt="Диплом <?php echo $i; ?>"
                                         onerror="this.src='https://via.placeholder.com/150x212/dac2ff/8742ee?text=<?php echo $i; ?>'">
                                </div>
                            <?php endfor; ?>
                        <?php else: ?>
                            <?php foreach ($templates as $index => $template): ?>
                                <div class="diploma-gallery-item <?php echo $index === 0 ? 'active' : ''; ?>"
                                     data-template-id="<?php echo $template['id']; ?>">
                                    <?php
                                    // Try thumb-X.svg first, then fall back to full template
                                    $thumbPath = '/assets/images/diplomas/thumbnails/thumb-' . $template['id'] . '.svg';
                                    if (!file_exists(__DIR__ . '/..' . $thumbPath)) {
                                        $thumbPath = '/assets/images/diplomas/thumbnails/' . $template['thumbnail_image'];
                                        if (!file_exists(__DIR__ . '/..' . $thumbPath)) {
                                            $thumbPath = '/assets/images/diplomas/thumbnails/diploma-template-' . $template['id'] . '.svg';
                                        }
                                    }
                                    ?>
                                    <img src="<?php echo $thumbPath; ?>"
                                         alt="<?php echo htmlspecialchars($template['name']); ?>"
                                         onerror="this.src='https://via.placeholder.com/150x212/dac2ff/8742ee?text=<?php echo urlencode($template['name']); ?>'">
                                </div>
                            <?php endforeach; ?>
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

<?php
// Include footer
include __DIR__ . '/../includes/footer.php';
?>
