<?php
/**
 * Olympiad Diploma Ordering Page
 * Route: /olimpiada-diplom/{result_id} => pages/olympiad-diploma.php?result_id=X
 *
 * Allows users who placed 1st/2nd/3rd in an olympiad to order a diploma.
 * Design mirrors the competition registration page (registration.php).
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/OlympiadQuiz.php';
require_once __DIR__ . '/../classes/Olympiad.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/session.php';

// ---------------------------------------------------------------------------
// 1. Load result and verify ownership / placement
// ---------------------------------------------------------------------------

$resultId = $_GET['result_id'] ?? null;

if (!$resultId) {
    header('Location: /olimpiady');
    exit;
}

$quizObj = new OlympiadQuiz($db);
$result  = $quizObj->getResultById($resultId);

if (!$result) {
    header('Location: /olimpiady');
    exit;
}

// Verify user owns this result
$userId = getUserId();
if (!$userId || (int)$result['user_id'] !== (int)$userId) {
    header('Location: /olimpiady');
    exit;
}

// Verify placement exists (1, 2 or 3)
if (!in_array($result['placement'], ['1', '2', '3'], true)) {
    header('Location: /olimpiady');
    exit;
}

// ---------------------------------------------------------------------------
// 2. Load diploma templates from DB
// ---------------------------------------------------------------------------

$templates = $db->query(
    "SELECT * FROM diploma_templates WHERE is_active = 1 AND type = 'participant' ORDER BY display_order ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------------------------
// 3. Pre-fill user data
// ---------------------------------------------------------------------------

$userData = [];
if ($userId) {
    $userStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Placement label
$placementLabels = [
    '1' => '1 место (Победитель)',
    '2' => '2 место (Призёр)',
    '3' => '3 место (Призёр)',
];
$placementLabel = $placementLabels[$result['placement']] ?? $result['placement'] . ' место';

// Diploma price (from olympiad or fallback)
$diplomaPrice = (int)($result['diploma_price'] ?? 169);

// CSRF token
$csrfToken = generateCSRFToken();

// ---------------------------------------------------------------------------
// 4. Page metadata
// ---------------------------------------------------------------------------

$pageTitle       = 'Оформить диплом олимпиады: ' . htmlspecialchars($result['olympiad_title']) . ' | ' . SITE_NAME;
$pageDescription = 'Оформите диплом олимпиады за 30 секунд';
$additionalCSS   = ['/assets/css/form.css?v=' . time()];
$additionalJS    = ['/assets/js/form-validation.js?v=' . time()];

// Include header
include __DIR__ . '/../includes/header.php';
?>

<div class="diploma-form-wrapper">
    <div class="diploma-form-container">
        <h1 class="diploma-form-title">Оформите <span class="highlight-blue">диплом</span> олимпиады за 30 секунд</h1>

        <div class="diploma-form-content">
            <!-- ============================================================ -->
            <!-- LEFT SIDE: Form                                              -->
            <!-- ============================================================ -->
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

                <!-- Form -->
                <form id="olympiadDiplomaForm" method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="result_id" value="<?php echo (int)$resultId; ?>">
                    <input type="hidden" name="template_id" id="selectedTemplateId" value="">
                    <input type="hidden" name="current_tab" id="currentTab" value="participant">

                    <!-- ===== Participant tab ===== -->
                    <div class="diploma-tab-content active" id="participantForm">

                        <!-- Email -->
                        <div class="form-group">
                            <input type="email"
                                   class="form-control"
                                   id="email"
                                   name="email"
                                   value="<?php echo htmlspecialchars($result['email'] ?? $userData['email'] ?? ''); ?>"
                                   placeholder="Email"
                                   required>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <!-- FIO -->
                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="fio"
                                   name="fio"
                                   maxlength="55"
                                   value="<?php echo htmlspecialchars($result['full_name'] ?? $userData['full_name'] ?? ''); ?>"
                                   placeholder="ФИО участника"
                                   required>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <!-- Phone -->
                        <div class="form-group">
                            <input type="tel"
                                   class="form-control"
                                   id="phone"
                                   name="phone"
                                   value="<?php echo htmlspecialchars($userData['phone'] ?? ''); ?>"
                                   placeholder="Телефон (необязательно)">
                        </div>

                        <!-- Organization -->
                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="organization"
                                   name="organization"
                                   value="<?php echo htmlspecialchars($result['organization'] ?? $userData['organization'] ?? ''); ?>"
                                   placeholder="Организация / Учреждение"
                                   required>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <!-- City -->
                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="city"
                                   name="city"
                                   value="<?php echo htmlspecialchars($result['city'] ?? $userData['city'] ?? ''); ?>"
                                   placeholder="Населённый пункт (необязательно)">
                        </div>

                        <!-- Participation date -->
                        <div class="form-group">
                            <label class="form-label-small">Дата участия в олимпиаде:</label>
                            <input type="date"
                                   class="form-control"
                                   id="participation_date"
                                   name="participation_date"
                                   value="<?php echo date('Y-m-d'); ?>"
                                   required>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <!-- Competition type -->
                        <div class="form-group">
                            <select class="form-control" id="competition_type" name="competition_type" required>
                                <option value="всероссийская" selected>Всероссийская олимпиада</option>
                                <option value="международная">Международная олимпиада</option>
                                <option value="межрегиональная">Межрегиональная олимпиада</option>
                            </select>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <!-- Placement (read-only) -->
                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   value="<?php echo htmlspecialchars($placementLabel); ?>"
                                   readonly
                                   style="background: #f9fafb; cursor: default;">
                            <input type="hidden" name="placement" value="<?php echo htmlspecialchars($result['placement']); ?>">
                        </div>

                        <!-- Supervisor checkbox -->
                        <div class="checkbox-group" id="supervisorToggle">
                            <input type="checkbox" id="hasSupervisor" name="has_supervisor" value="1">
                            <label for="hasSupervisor">Добавить диплом руководителя</label>
                        </div>

                        <!-- Supervisor fields (hidden by default) -->
                        <div class="supervisor-section" id="supervisorSection">
                            <div class="form-group">
                                <input type="text"
                                       class="form-control"
                                       id="supervisor_name"
                                       name="supervisor_name"
                                       maxlength="55"
                                       placeholder="ФИО руководителя">
                                <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                            </div>

                            <div class="form-group">
                                <input type="email"
                                       class="form-control"
                                       id="supervisor_email"
                                       name="supervisor_email"
                                       placeholder="Email руководителя">
                                <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                            </div>

                            <div class="form-group">
                                <input type="text"
                                       class="form-control"
                                       id="supervisor_organization"
                                       name="supervisor_organization"
                                       placeholder="Организация руководителя">
                            </div>
                        </div>

                        <!-- Agreement -->
                        <div class="form-agreement" style="margin-bottom: 20px;">
                            <label class="checkbox-label" style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                <input type="checkbox" name="agreement" id="agreement" style="margin-top: 3px; width: 18px; height: 18px; flex-shrink: 0;">
                                <span class="agreement-text" style="font-size: 13px; color: #64748B; line-height: 1.5;">
                                    Я принимаю условия <a href="/pages/terms.php" target="_blank" style="color: #3B5998;">Пользовательского соглашения</a>
                                    и даю согласие на обработку персональных данных в соответствии с
                                    <a href="/pages/privacy.php" target="_blank" style="color: #3B5998;">Политикой конфиденциальности</a>
                                </span>
                            </label>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <!-- Submit -->
                        <button type="submit" class="btn btn-submit">
                            ОФОРМИТЬ ДИПЛОМ ЗА <?php echo $diplomaPrice; ?> РУБ
                        </button>
                    </div>

                    <!-- ===== Supervisor tab ===== -->
                    <div class="diploma-tab-content" id="supervisorForm">

                        <div class="form-group">
                            <input type="email"
                                   class="form-control"
                                   id="sup_tab_email"
                                   name="supervisor_tab_email"
                                   placeholder="Email руководителя">
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="sup_tab_name"
                                   name="supervisor_tab_name"
                                   maxlength="55"
                                   placeholder="ФИО руководителя">
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="sup_tab_organization"
                                   name="supervisor_tab_organization"
                                   placeholder="Название учреждения">
                        </div>

                        <div class="form-group">
                            <input type="text"
                                   class="form-control"
                                   id="sup_tab_city"
                                   name="supervisor_tab_city"
                                   placeholder="Населённый пункт">
                        </div>

                        <div class="form-group">
                            <select class="form-control" name="supervisor_tab_competition_type">
                                <option value="">Тип олимпиады:</option>
                                <option value="всероссийская">Всероссийская</option>
                                <option value="международная">Международная</option>
                                <option value="межрегиональная">Межрегиональная</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label-small">Дата участия в олимпиаде:</label>
                            <input type="date"
                                   class="form-control"
                                   name="supervisor_tab_participation_date"
                                   value="<?php echo date('Y-m-d'); ?>"
                                   placeholder="Дата участия">
                        </div>

                        <!-- Agreement for supervisor tab -->
                        <div class="form-agreement" style="margin-bottom: 20px;">
                            <label class="checkbox-label" style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                <input type="checkbox" name="supervisor_agreement" id="supervisor_agreement" style="margin-top: 3px; width: 18px; height: 18px; flex-shrink: 0;">
                                <span class="agreement-text" style="font-size: 13px; color: #64748B; line-height: 1.5;">
                                    Я принимаю условия <a href="/pages/terms.php" target="_blank" style="color: #3B5998;">Пользовательского соглашения</a>
                                    и даю согласие на обработку персональных данных в соответствии с
                                    <a href="/pages/privacy.php" target="_blank" style="color: #3B5998;">Политикой конфиденциальности</a>
                                </span>
                            </label>
                            <div class="error-message" style="display:none; color: #ef4444; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <button type="submit" class="btn btn-submit">
                            ОФОРМИТЬ ДИПЛОМ ЗА <?php echo $diplomaPrice; ?> РУБ
                        </button>
                    </div>
                </form>
            </div>

            <!-- ============================================================ -->
            <!-- RIGHT SIDE: Diploma Template Gallery                         -->
            <!-- ============================================================ -->
            <div class="diploma-form-right">
                <div class="diploma-preview-container">

                    <!-- Main Preview -->
                    <div class="diploma-preview-main">
                        <img id="diplomaPreview"
                             src="/assets/images/diplomas/templates/backgrounds/template-1.svg"
                             alt="Предпросмотр диплома"
                             onerror="this.src='/assets/images/diplomas/thumbnails/thumb-1.svg'">
                    </div>

                    <!-- Template Gallery - Vertical strip -->
                    <div class="diploma-gallery">
                        <?php if (empty($templates)): ?>
                            <!-- Fallback: 6 default templates -->
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

<!-- ====================================================================== -->
<!-- Inline JS: tab switching, template selection, supervisor toggle, AJAX   -->
<!-- ====================================================================== -->
<script>
document.addEventListener('DOMContentLoaded', function () {

    // -----------------------------------------------------------------
    // Tab switching
    // -----------------------------------------------------------------
    var tabButtons = document.querySelectorAll('.diploma-tab');
    var tabContents = document.querySelectorAll('.diploma-tab-content');
    var currentTabInput = document.getElementById('currentTab');

    tabButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetTab = this.getAttribute('data-tab');

            tabButtons.forEach(function (b) { b.classList.remove('active'); });
            tabContents.forEach(function (c) { c.classList.remove('active'); });

            this.classList.add('active');
            currentTabInput.value = targetTab;

            if (targetTab === 'participant') {
                document.getElementById('participantForm').classList.add('active');
            } else {
                document.getElementById('supervisorForm').classList.add('active');
            }

            // Update preview when switching tabs
            if (typeof updateOlympiadPreview === 'function') {
                updateOlympiadPreview();
            }
        });
    });

    // -----------------------------------------------------------------
    // Template gallery selection
    // -----------------------------------------------------------------
    var galleryItems  = document.querySelectorAll('.diploma-gallery-item');
    var templateInput = document.getElementById('selectedTemplateId');
    var previewImage  = document.getElementById('diplomaPreview');
    var resultId      = <?php echo (int)$resultId; ?>;
    var previewTimeout = null;

    // Set initial template
    if (galleryItems.length > 0) {
        var firstItem = galleryItems[0];
        templateInput.value = firstItem.getAttribute('data-template-id');
    }

    galleryItems.forEach(function (item) {
        item.addEventListener('click', function () {
            var tplId = this.getAttribute('data-template-id');

            galleryItems.forEach(function (g) { g.classList.remove('active'); });
            this.classList.add('active');

            templateInput.value = tplId;
            updateOlympiadPreview();
        });
    });

    // -----------------------------------------------------------------
    // Olympiad diploma preview (AJAX)
    // -----------------------------------------------------------------
    function updateOlympiadPreview() {
        if (!templateInput.value) return;

        var currentTab = currentTabInput.value;
        var formData = new FormData();
        formData.append('template_id', templateInput.value);
        formData.append('result_id', resultId);

        if (currentTab === 'participant') {
            formData.append('recipient_type', 'participant');
            formData.append('fio', document.getElementById('fio').value || '');
            formData.append('organization', document.getElementById('organization').value || '');
            formData.append('city', document.getElementById('city').value || '');
            formData.append('competition_type', document.getElementById('competition_type').value || 'всероссийская');
            formData.append('placement', '<?php echo htmlspecialchars($result['placement']); ?>');
            formData.append('participation_date', document.getElementById('participation_date').value || '');
            var supName = document.getElementById('supervisor_name');
            if (supName && supName.value) {
                formData.append('supervisor_name', supName.value);
            }
        } else {
            formData.append('recipient_type', 'supervisor');
            formData.append('fio', document.getElementById('sup_tab_name').value || '');
            formData.append('supervisor_name', document.getElementById('sup_tab_name').value || '');
            formData.append('organization', document.getElementById('sup_tab_organization').value || '');
            formData.append('city', document.getElementById('sup_tab_city').value || '');
            var supCompType = document.querySelector('select[name="supervisor_tab_competition_type"]');
            formData.append('competition_type', supCompType ? supCompType.value || 'всероссийская' : 'всероссийская');
            formData.append('placement', '<?php echo htmlspecialchars($result['placement']); ?>');
            var supDate = document.querySelector('input[name="supervisor_tab_participation_date"]');
            formData.append('participation_date', supDate ? supDate.value : '');
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/ajax/preview-olympiad-diploma.php', true);
        xhr.onload = function () {
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.success && resp.preview_url) {
                    previewImage.src = resp.preview_url;
                } else {
                    previewImage.src = '/assets/images/diplomas/templates/backgrounds/template-' + templateInput.value + '.svg';
                }
            } catch (e) {
                previewImage.src = '/assets/images/diplomas/templates/backgrounds/template-' + templateInput.value + '.svg';
            }
        };
        xhr.onerror = function () {
            previewImage.src = '/assets/images/diplomas/templates/backgrounds/template-' + templateInput.value + '.svg';
        };
        xhr.send(formData);
    }

    // Debounced preview on input change
    var previewFields = [
        '#fio', '#organization', '#city', '#competition_type',
        '#participation_date', '#supervisor_name',
        '#sup_tab_name', '#sup_tab_organization', '#sup_tab_city',
        'select[name="supervisor_tab_competition_type"]',
        'input[name="supervisor_tab_participation_date"]'
    ];
    previewFields.forEach(function (sel) {
        var el = document.querySelector(sel);
        if (el) {
            el.addEventListener('input', function () {
                clearTimeout(previewTimeout);
                previewTimeout = setTimeout(updateOlympiadPreview, 500);
            });
            el.addEventListener('change', function () {
                clearTimeout(previewTimeout);
                previewTimeout = setTimeout(updateOlympiadPreview, 300);
            });
        }
    });

    // Initial preview load
    setTimeout(updateOlympiadPreview, 200);

    // -----------------------------------------------------------------
    // Supervisor toggle
    // -----------------------------------------------------------------
    var supervisorCheckbox = document.getElementById('hasSupervisor');
    var supervisorSection  = document.getElementById('supervisorSection');

    if (supervisorCheckbox && supervisorSection) {
        supervisorCheckbox.addEventListener('change', function () {
            if (this.checked) {
                supervisorSection.classList.add('active');
            } else {
                supervisorSection.classList.remove('active');
            }
        });
    }

    // -----------------------------------------------------------------
    // Form validation helpers
    // -----------------------------------------------------------------
    function showError(input, message) {
        input.classList.add('error');
        var errEl = input.parentNode.querySelector('.error-message');
        if (errEl) {
            errEl.textContent = message;
            errEl.style.display = 'block';
        }
    }

    function clearErrors() {
        document.querySelectorAll('.form-control.error').forEach(function (el) {
            el.classList.remove('error');
        });
        document.querySelectorAll('.error-message').forEach(function (el) {
            el.style.display = 'none';
            el.textContent = '';
        });
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    // -----------------------------------------------------------------
    // Form submission via AJAX
    // -----------------------------------------------------------------
    var form     = document.getElementById('olympiadDiplomaForm');
    var overlay  = document.getElementById('loadingOverlay');

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearErrors();

        var currentTab = currentTabInput.value;
        var valid = true;

        // ---- Validate participant tab ----
        if (currentTab === 'participant') {
            var emailField = document.getElementById('email');
            var fioField   = document.getElementById('fio');
            var orgField   = document.getElementById('organization');
            var agreementField = document.getElementById('agreement');

            if (!emailField.value.trim()) {
                showError(emailField, 'Укажите email');
                valid = false;
            } else if (!isValidEmail(emailField.value.trim())) {
                showError(emailField, 'Некорректный email');
                valid = false;
            }

            if (!fioField.value.trim()) {
                showError(fioField, 'Укажите ФИО');
                valid = false;
            } else if (fioField.value.trim().length > 55) {
                showError(fioField, 'ФИО не должно превышать 55 символов');
                valid = false;
            }

            if (!orgField.value.trim()) {
                showError(orgField, 'Укажите организацию');
                valid = false;
            }

            if (!agreementField.checked) {
                var agreementErr = agreementField.closest('.form-agreement').querySelector('.error-message');
                if (agreementErr) {
                    agreementErr.textContent = 'Необходимо принять условия';
                    agreementErr.style.display = 'block';
                }
                valid = false;
            }

            // Validate supervisor fields if checkbox is checked
            if (supervisorCheckbox && supervisorCheckbox.checked) {
                var supNameField = document.getElementById('supervisor_name');
                if (!supNameField.value.trim()) {
                    showError(supNameField, 'Укажите ФИО руководителя');
                    valid = false;
                }
            }
        }

        // ---- Validate supervisor tab ----
        if (currentTab === 'supervisor') {
            var supAgreement = document.getElementById('supervisor_agreement');
            if (supAgreement && !supAgreement.checked) {
                var supAgrErr = supAgreement.closest('.form-agreement').querySelector('.error-message');
                if (supAgrErr) {
                    supAgrErr.textContent = 'Необходимо принять условия';
                    supAgrErr.style.display = 'block';
                }
                valid = false;
            }
        }

        // Validate template selected
        if (!templateInput.value) {
            alert('Выберите шаблон диплома');
            valid = false;
        }

        if (!valid) return;

        // Show loading overlay
        overlay.classList.add('active');

        // Collect form data
        var formData = new FormData(form);

        // AJAX request
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/ajax/save-olympiad-registration.php', true);

        xhr.onload = function () {
            overlay.classList.remove('active');

            try {
                var response = JSON.parse(xhr.responseText);
            } catch (parseError) {
                alert('Ошибка сервера. Попробуйте снова.');
                return;
            }

            if (response.success) {
                // E-commerce dataLayer push if available
                if (typeof dataLayer !== 'undefined' && response.ecommerce) {
                    dataLayer.push({
                        'event': 'add_to_cart',
                        'ecommerce': {
                            'items': [{
                                'item_id': response.ecommerce.id,
                                'item_name': response.ecommerce.name,
                                'price': response.ecommerce.price,
                                'item_category': response.ecommerce.category,
                                'quantity': 1
                            }]
                        }
                    });
                }

                // Redirect to cart
                window.location.href = '/korzina';
            } else {
                alert(response.message || 'Произошла ошибка. Попробуйте снова.');
            }
        };

        xhr.onerror = function () {
            overlay.classList.remove('active');
            alert('Ошибка сети. Проверьте подключение и попробуйте снова.');
        };

        xhr.send(formData);
    });

    // -----------------------------------------------------------------
    // jQuery fallback — if jQuery is loaded, also wire up for compat
    // -----------------------------------------------------------------
    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('click', '.diploma-gallery-item', function () {
            // Already handled above via vanilla JS
        });
    }
});
</script>

<?php
// Include footer
include __DIR__ . '/../includes/footer.php';
?>
