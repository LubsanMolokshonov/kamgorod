<?php
/**
 * Submit Publication Page
 * Form for publishing articles with two-column layout
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/PublicationType.php';
require_once __DIR__ . '/../classes/PublicationTag.php';
require_once __DIR__ . '/../includes/session.php';

$database = new Database($db);

// Get publication types
$typeObj = new PublicationType($db);
$types = $typeObj->getAll();

// Get tags
$tagObj = new PublicationTag($db);
$directions = $tagObj->getDirections();
$subjects = $tagObj->getSubjects();

// Pre-fill user data if logged in
$userData = [];
if (isset($_SESSION['user_id'])) {
    $userStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Count published publications
$countResult = $database->queryOne("SELECT COUNT(*) as total FROM publications WHERE status = 'published'");
$totalPublications = $countResult['total'] ?? 0;

// Page metadata
$pageTitle = 'Опубликовать статью и получить свидетельство | ' . SITE_NAME;
$pageDescription = 'Опубликуйте свою педагогическую статью в электронном журнале и получите официальное свидетельство о публикации для аттестации';
$additionalCSS = ['/assets/css/journal.css?v=' . time()];
$additionalJS = ['/assets/js/publication-form.js?v=' . time()];

include __DIR__ . '/../includes/header.php';
?>

<div class="submit-publication-page">
    <!-- Hero Section -->
    <section class="submit-hero">
        <div class="container">
            <div class="submit-hero-content">
                <h1>Опубликуйте статью и получите свидетельство</h1>
                <p>Ваш материал будет размещён в электронном педагогическом журнале и доступен коллегам по всей России</p>
                <div class="hero-badges">
                    <span class="badge"><strong><?php echo number_format($totalPublications + 1250, 0, '', ' '); ?>+</strong> публикаций</span>
                    <span class="badge"><strong>149 ₽</strong> свидетельство</span>
                    <span class="badge"><strong>5 мин</strong> оформление</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Main Form Section -->
    <section class="submit-form-section">
        <div class="container">
            <div class="submit-layout">
                <!-- Left: Form -->
                <div class="submit-form-column">
                    <div class="form-card">
                        <div class="form-card-header">
                            <h2>Загрузите публикацию</h2>
                            <p>Заполните форму и прикрепите файл с материалом</p>
                        </div>

                        <form id="publicationForm" class="publication-form" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                            <!-- Author Info Section -->
                            <div class="form-section">
                                <h3 class="form-section-title">
                                    <span class="section-number">1</span>
                                    Информация об авторе
                                </h3>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="email">Email <span class="required">*</span></label>
                                        <input type="email"
                                               class="form-control"
                                               id="email"
                                               name="email"
                                               value="<?php echo htmlspecialchars($userData['email'] ?? ''); ?>"
                                               placeholder="example@mail.ru"
                                               required>
                                        <div class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label for="author_name">ФИО автора <span class="required">*</span></label>
                                        <input type="text"
                                               class="form-control"
                                               id="author_name"
                                               name="author_name"
                                               maxlength="255"
                                               value="<?php echo htmlspecialchars($userData['full_name'] ?? ''); ?>"
                                               placeholder="Иванова Мария Владимировна"
                                               required>
                                        <div class="error-message"></div>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="organization">Образовательное учреждение <span class="required">*</span></label>
                                        <input type="text"
                                               class="form-control"
                                               id="organization"
                                               name="organization"
                                               value="<?php echo htmlspecialchars($userData['organization'] ?? ''); ?>"
                                               placeholder="МБОУ СОШ №1 г. Москва"
                                               required>
                                        <div class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label for="position">Должность</label>
                                        <input type="text"
                                               class="form-control"
                                               id="position"
                                               name="position"
                                               value="<?php echo htmlspecialchars($userData['profession'] ?? ''); ?>"
                                               placeholder="Учитель начальных классов">
                                    </div>
                                </div>
                            </div>

                            <!-- Publication Info Section -->
                            <div class="form-section">
                                <h3 class="form-section-title">
                                    <span class="section-number">2</span>
                                    О публикации
                                </h3>

                                <div class="form-group">
                                    <label for="title">Название публикации <span class="required">*</span></label>
                                    <input type="text"
                                           class="form-control"
                                           id="title"
                                           name="title"
                                           maxlength="500"
                                           placeholder="Современные методы развития речи у дошкольников"
                                           required>
                                    <div class="error-message"></div>
                                </div>

                                <div class="form-group">
                                    <label for="annotation">Краткое описание <span class="required">*</span></label>
                                    <textarea class="form-control"
                                              id="annotation"
                                              name="annotation"
                                              rows="3"
                                              maxlength="500"
                                              placeholder="Краткое описание вашего материала (до 500 символов)"
                                              required></textarea>
                                    <div class="char-counter"><span id="annotationCount">0</span>/500</div>
                                    <div class="error-message"></div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="publication_type">Тип публикации <span class="required">*</span></label>
                                        <select class="form-control" id="publication_type" name="publication_type_id" required>
                                            <option value="">Выберите тип</option>
                                            <?php foreach ($types as $type): ?>
                                                <option value="<?php echo $type['id']; ?>">
                                                    <?php echo htmlspecialchars($type['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="error-message"></div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Направление <span class="required">*</span></label>
                                    <div class="tags-selector" id="directionsSelector">
                                        <?php foreach ($directions as $tag): ?>
                                            <label class="tag-checkbox">
                                                <input type="checkbox" name="tag_ids[]" value="<?php echo $tag['id']; ?>">
                                                <span class="tag-label"><?php echo htmlspecialchars($tag['name']); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="error-message"></div>
                                </div>

                                <div class="form-group">
                                    <label>Предмет <span class="hint-text">(необязательно)</span></label>
                                    <div class="tags-selector subjects-selector" id="subjectsSelector">
                                        <?php foreach ($subjects as $tag): ?>
                                            <label class="tag-checkbox">
                                                <input type="checkbox" name="tag_ids[]" value="<?php echo $tag['id']; ?>">
                                                <span class="tag-label subject-tag"><?php echo htmlspecialchars($tag['name']); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- File Upload Section -->
                            <div class="form-section">
                                <h3 class="form-section-title">
                                    <span class="section-number">3</span>
                                    Файл публикации
                                </h3>

                                <div class="form-group">
                                    <div class="file-upload-area" id="fileUploadArea">
                                        <input type="file"
                                               id="publication_file"
                                               name="publication_file"
                                               accept=".pdf,.doc,.docx"
                                               class="file-input"
                                               required>
                                        <div class="file-upload-content">
                                            <div class="upload-icon">
                                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                                    <polyline points="17 8 12 3 7 8"></polyline>
                                                    <line x1="12" y1="3" x2="12" y2="15"></line>
                                                </svg>
                                            </div>
                                            <p class="upload-text">
                                                <strong>Перетащите файл сюда</strong><br>
                                                или <span class="upload-link">выберите на компьютере</span>
                                            </p>
                                            <p class="upload-hint">PDF, DOC, DOCX до 10 МБ</p>
                                        </div>
                                        <div class="file-preview" style="display: none;">
                                            <div class="file-icon">
                                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                                    <polyline points="14 2 14 8 20 8"></polyline>
                                                </svg>
                                            </div>
                                            <div class="file-info">
                                                <span class="file-name"></span>
                                                <span class="file-size"></span>
                                            </div>
                                            <button type="button" class="file-remove">
                                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <line x1="18" y1="6" x2="6" y2="18"></line>
                                                    <line x1="6" y1="6" x2="18" y2="18"></line>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="error-message"></div>
                                </div>
                            </div>

                            <!-- Agreement -->
                            <div class="form-agreement">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="agreement" id="agreement" required>
                                    <span class="checkmark"></span>
                                    <span class="agreement-text">
                                        Я подтверждаю, что являюсь автором материала и согласен с
                                        <a href="/pages/terms.php" target="_blank">правилами публикации</a>
                                    </span>
                                </label>
                            </div>

                            <!-- Submit Button -->
                            <div class="form-submit">
                                <button type="submit" class="btn btn-primary btn-lg btn-block" id="submitBtn">
                                    <span class="btn-text">Опубликовать и получить свидетельство</span>
                                    <span class="btn-loader" style="display: none;">
                                        <span class="spinner-small"></span>
                                        Загрузка...
                                    </span>
                                </button>
                                <p class="submit-hint">
                                    После загрузки вы перейдёте к оформлению свидетельства (149 ₽)
                                </p>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Right: Image & Info -->
                <div class="submit-info-column">
                    <div class="journal-preview">
                        <div class="journal-image-wrapper">
                            <div class="journal-mockup">
                                <div class="mockup-header">
                                    <div class="mockup-dots">
                                        <span></span><span></span><span></span>
                                    </div>
                                    <div class="mockup-title">Педагогический журнал</div>
                                </div>
                                <div class="mockup-content">
                                    <div class="mockup-article">
                                        <div class="article-badge">Новая публикация</div>
                                        <div class="article-title-line"></div>
                                        <div class="article-title-line short"></div>
                                        <div class="article-meta">
                                            <span class="meta-author"></span>
                                            <span class="meta-date"></span>
                                        </div>
                                        <div class="article-text-line"></div>
                                        <div class="article-text-line"></div>
                                        <div class="article-text-line medium"></div>
                                    </div>
                                    <div class="mockup-sidebar">
                                        <div class="sidebar-item"></div>
                                        <div class="sidebar-item"></div>
                                        <div class="sidebar-item"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Benefits List -->
                    <div class="submit-benefits">
                        <h3>Почему стоит опубликоваться?</h3>
                        <ul class="benefits-list">
                            <li>
                                <span class="benefit-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                </span>
                                <span>Официальное свидетельство для аттестации</span>
                            </li>
                            <li>
                                <span class="benefit-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                </span>
                                <span>Публикация в СМИ (Эл. №ФС 77-74524)</span>
                            </li>
                            <li>
                                <span class="benefit-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                </span>
                                <span>Доступ коллегам по всей России</span>
                            </li>
                            <li>
                                <span class="benefit-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                </span>
                                <span>Свидетельство сразу после оплаты</span>
                            </li>
                        </ul>
                    </div>

                    <!-- FAQ Card -->
                    <div class="faq-card">
                        <h4>Частые вопросы</h4>
                        <div class="faq-mini-list">
                            <details class="faq-mini-item">
                                <summary>Какие материалы можно публиковать?</summary>
                                <p>Методические разработки, статьи, исследования, программы, презентации, мастер-классы и другие авторские педагогические материалы.</p>
                            </details>
                            <details class="faq-mini-item">
                                <summary>Как быстро появится в журнале?</summary>
                                <p>После модерации (1-2 рабочих дня) публикация появится в каталоге журнала.</p>
                            </details>
                            <details class="faq-mini-item">
                                <summary>Какие форматы файлов?</summary>
                                <p>PDF, DOC и DOCX размером до 10 МБ.</p>
                            </details>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
