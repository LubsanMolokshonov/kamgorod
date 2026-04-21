<?php
/**
 * Генератор статей для педагогов
 * Лендинг + многошаговый визард генерации
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/session.php';

$database = new Database($db);

// Логируем уникальный визит на лендинг генератора (одна запись на PHP-сессию)
try {
    $sid = session_id();
    if ($sid) {
        $stmt = $db->prepare(
            "INSERT IGNORE INTO ai_generator_visits
             (php_session_id, user_id, ip_address, user_agent, referrer, utm_source, utm_campaign)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $sid,
            $_SESSION['user_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500) ?: null,
            substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500) ?: null,
            isset($_GET['utm_source']) ? substr($_GET['utm_source'], 0, 100) : null,
            isset($_GET['utm_campaign']) ? substr($_GET['utm_campaign'], 0, 100) : null,
        ]);
    }
} catch (\Throwable $e) {
    error_log('ai_generator_visits log: ' . $e->getMessage());
}

// Загрузить категории аудитории
$audienceCategories = $database->query(
    "SELECT id, name, slug FROM audience_categories WHERE is_active = 1 ORDER BY display_order"
);

// Предзаполнение из сессии
$userData = [];
if (isset($_SESSION['user_id'])) {
    $userStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Счётчик публикаций
$countResult = $database->queryOne("SELECT COUNT(*) as total FROM publications WHERE status = 'published'");
$totalPublications = $countResult['total'] ?? 0;

$pageTitle = 'Генератор статей для педагогов | ' . SITE_NAME;
$pageDescription = 'Сгенерируйте педагогическую статью за 3 минуты с помощью ИИ. Опубликуйтесь в журнале и получите свидетельство о публикации в СМИ.';
$canonicalUrl = SITE_URL . '/generator-statej/';
$additionalCSS = ['/assets/css/article-generator.css?v=' . filemtime(__DIR__ . '/../assets/css/article-generator.css')];
$additionalJS = ['/assets/js/article-generator.js?v=' . filemtime(__DIR__ . '/../assets/js/article-generator.js')];

include __DIR__ . '/../includes/header.php';
?>

<div class="generator-page">

    <!-- Hero Section — в стиле hero-landing вебинаров -->
    <section class="hero-landing">
        <div class="container">
            <div class="hero-content">
                <h1 class="hero-title">Сгенерируйте статью за 3 минуты</h1>

                <p class="hero-subtitle">Искусственный интеллект напишет профессиональную педагогическую статью по вашей теме. Опубликуйтесь в журнале и получите свидетельство о публикации в СМИ.</p>

                <div class="hero-features hero-features--stats">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
                            </svg>
                        </div>
                        <div class="feature-text"><h3><?php echo number_format($totalPublications + 1250, 0, '', ' '); ?>+<br>публикаций</h3></div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">
                            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                            </svg>
                        </div>
                        <div class="feature-text"><h3>3 минуты<br>генерация</h3></div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">
                            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15l2 2 4-4"/>
                            </svg>
                        </div>
                        <div class="feature-text"><h3>Свидетельство<br>299 &#8381;</h3></div>
                    </div>
                </div>

                <button type="button" class="btn btn-hero" id="startGeneratorBtn">Создать статью</button>
            </div>

            <div class="hero-right">
                <div class="hero-images" id="heroImages">
                    <div class="hero-image-circle hero-img-1" data-parallax-speed="0.3">
                        <picture>
                            <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/1.webp" type="image/webp">
                            <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/1.jpg" type="image/jpeg">
                            <source srcset="/assets/images/teachers/optimized/desktop/1.webp" type="image/webp">
                            <source srcset="/assets/images/teachers/optimized/desktop/1.jpg" type="image/jpeg">
                            <img src="/assets/images/teachers/optimized/desktop/1.jpg" alt="Педагог" loading="lazy" width="220" height="220">
                        </picture>
                    </div>
                    <div class="hero-image-circle hero-img-2" data-parallax-speed="0.5">
                        <picture>
                            <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/2.webp" type="image/webp">
                            <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/2.jpg" type="image/jpeg">
                            <source srcset="/assets/images/teachers/optimized/desktop/2.webp" type="image/webp">
                            <source srcset="/assets/images/teachers/optimized/desktop/2.jpg" type="image/jpeg">
                            <img src="/assets/images/teachers/optimized/desktop/2.jpg" alt="Педагог" loading="lazy" width="300" height="300">
                        </picture>
                    </div>
                    <div class="hero-image-circle hero-img-4" data-parallax-speed="0.4">
                        <picture>
                            <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/4.webp" type="image/webp">
                            <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/4.jpg" type="image/jpeg">
                            <source srcset="/assets/images/teachers/optimized/desktop/4.webp" type="image/webp">
                            <source srcset="/assets/images/teachers/optimized/desktop/4.jpg" type="image/jpeg">
                            <img src="/assets/images/teachers/optimized/desktop/4.jpg" alt="Педагог" loading="lazy" width="230" height="230">
                        </picture>
                    </div>
                </div>

                <div class="hero-features hero-features--badges">
                    <div class="feature-card feature-card--badge">
                        <div class="feature-logo">
                            <img src="/assets/images/skolkovo.webp" alt="Сколково" width="70" height="70">
                        </div>
                        <div class="feature-text">
                            <span class="feature-label">Резидент</span>
                            <span class="feature-label">Сколково</span>
                        </div>
                    </div>

                    <div class="feature-card feature-card--badge">
                        <div class="feature-logo">
                            <img src="/assets/images/eagle_s.svg" alt="СМИ" width="70" height="70">
                        </div>
                        <div class="feature-text">
                            <span class="feature-label">Свидетельство о регистрации СМИ:</span>
                            <span class="feature-label">Эл. №ФС 77-74524</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Как это работает — 4 карточки в стиле webinar-benefits -->
    <section class="gen-steps-section" id="howItWorks">
        <div class="container">
            <h2 class="gen-section-title">Как это работает</h2>
            <div class="gen-steps-grid">
                <div class="gen-step-card">
                    <div class="gen-step-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>
                        </svg>
                    </div>
                    <div class="gen-step-num">Шаг 1</div>
                    <h3>Заполните данные</h3>
                    <p>Укажите ФИО, должность и тему статьи</p>
                </div>
                <div class="gen-step-card">
                    <div class="gen-step-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                        </svg>
                    </div>
                    <div class="gen-step-num">Шаг 2</div>
                    <h3>ИИ напишет статью</h3>
                    <p>Нейросеть сгенерирует профессиональный текст за 30 секунд</p>
                </div>
                <div class="gen-step-card">
                    <div class="gen-step-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
                        </svg>
                    </div>
                    <div class="gen-step-num">Шаг 3</div>
                    <h3>Отредактируйте</h3>
                    <p>Просмотрите и скорректируйте любой раздел</p>
                </div>
                <div class="gen-step-card">
                    <div class="gen-step-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                    </div>
                    <div class="gen-step-num">Шаг 4</div>
                    <h3>Опубликуйте</h3>
                    <p>Статья появится в журнале, а вы получите свидетельство</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Преимущества — карточки в стиле webinar-card -->
    <section class="gen-benefits-section" id="benefits">
        <div class="container">
            <h2 class="gen-section-title">Преимущества генератора</h2>
            <div class="gen-benefits-grid">
                <div class="gen-benefit-card">
                    <div class="gen-benefit-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <h3>Экономия времени</h3>
                    <p>Не нужно тратить часы на написание. Статья готова за 3 минуты.</p>
                </div>
                <div class="gen-benefit-card">
                    <div class="gen-benefit-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                    </div>
                    <h3>Профессиональный текст</h3>
                    <p>ИИ знает стандарты педагогических публикаций и ФГОС.</p>
                </div>
                <div class="gen-benefit-card">
                    <div class="gen-benefit-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    </div>
                    <h3>Свидетельство СМИ</h3>
                    <p>Официальное свидетельство о публикации для аттестации.</p>
                </div>
                <div class="gen-benefit-card">
                    <div class="gen-benefit-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <h3>Полный контроль</h3>
                    <p>Редактируйте любой раздел до полного удовлетворения результатом.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Визард генератора -->
    <section class="generator-wizard-section" id="generatorWizard" style="display: none;">
        <div class="container">
            <!-- Прогресс-бар -->
            <div class="wizard-progress">
                <div class="progress-step active" data-step="1">
                    <div class="progress-dot">1</div>
                    <span>Данные</span>
                </div>
                <div class="progress-line"></div>
                <div class="progress-step" data-step="2">
                    <div class="progress-dot">2</div>
                    <span>Тема</span>
                </div>
                <div class="progress-line"></div>
                <div class="progress-step" data-step="3">
                    <div class="progress-dot">3</div>
                    <span>Генерация</span>
                </div>
                <div class="progress-line"></div>
                <div class="progress-step" data-step="4">
                    <div class="progress-dot">4</div>
                    <span>Редактирование</span>
                </div>
                <div class="progress-line"></div>
                <div class="progress-step" data-step="5">
                    <div class="progress-dot">5</div>
                    <span>Публикация</span>
                </div>
            </div>

            <!-- Шаг 1: Личные данные -->
            <div class="wizard-step active" data-step="1">
                <div class="wizard-card">
                    <h2>Информация об авторе</h2>
                    <p class="wizard-card-desc">Эти данные будут указаны в статье и свидетельстве</p>

                    <form id="step1Form" class="generator-form">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="step" value="1">
                        <input type="hidden" name="session_token" id="sessionToken" value="">

                        <div class="form-row">
                            <div class="form-group">
                                <label for="gen_email">Email <span class="required">*</span></label>
                                <input type="email" class="form-control" id="gen_email" name="email"
                                       value="<?php echo htmlspecialchars($userData['email'] ?? ''); ?>"
                                       placeholder="example@mail.ru" required>
                            </div>
                            <div class="form-group">
                                <label for="gen_author_name">ФИО <span class="required">*</span></label>
                                <input type="text" class="form-control" id="gen_author_name" name="author_name"
                                       value="<?php echo htmlspecialchars($userData['full_name'] ?? ''); ?>"
                                       placeholder="Иванова Мария Владимировна" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="gen_organization">Образовательное учреждение <span class="required">*</span></label>
                                <input type="text" class="form-control" id="gen_organization" name="organization"
                                       value="<?php echo htmlspecialchars($userData['organization'] ?? ''); ?>"
                                       placeholder="МБОУ СОШ №1 г. Москва" required>
                            </div>
                            <div class="form-group">
                                <label for="gen_position">Должность</label>
                                <input type="text" class="form-control" id="gen_position" name="position"
                                       value="<?php echo htmlspecialchars($userData['profession'] ?? ''); ?>"
                                       placeholder="Учитель начальных классов">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="gen_city">Город</label>
                                <input type="text" class="form-control" id="gen_city" name="city"
                                       value="<?php echo htmlspecialchars($userData['city'] ?? ''); ?>"
                                       placeholder="Москва">
                            </div>
                        </div>

                        <div class="wizard-actions">
                            <button type="submit" class="btn btn-primary">Далее</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Шаг 2: Параметры статьи -->
            <div class="wizard-step" data-step="2">
                <div class="wizard-card">
                    <h2>Параметры статьи</h2>
                    <p class="wizard-card-desc">Опишите, о чём хотите написать, и ИИ создаст статью</p>

                    <form id="step2Form" class="generator-form">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="step" value="2">
                        <input type="hidden" name="session_token" class="session-token-input" value="">

                        <div class="form-group">
                            <label for="gen_audience">Категория аудитории</label>
                            <select class="form-control" id="gen_audience" name="audience_category_id">
                                <option value="">Выберите категорию</option>
                                <?php foreach ($audienceCategories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>">
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="gen_topic">Тема статьи <span class="required">*</span></label>
                            <input type="text" class="form-control" id="gen_topic" name="topic"
                                   placeholder="Например: Использование игровых технологий на уроках математики" required>
                            <div class="form-hint">Укажите предмет и тему, которые вас интересуют</div>
                        </div>

                        <div class="form-group">
                            <label for="gen_description">Краткое описание <span class="required">*</span></label>
                            <textarea class="form-control" id="gen_description" name="description" rows="4"
                                      placeholder="Опишите ключевые идеи, которые хотите раскрыть в статье. Например: хочу рассказать о том, как применяю настольные и цифровые игры для повышения мотивации учащихся 5-6 классов..." required></textarea>
                            <div class="form-hint">Чем подробнее описание, тем качественнее будет статья</div>
                        </div>

                        <div class="wizard-actions">
                            <button type="button" class="btn btn-outline wizard-back-btn" data-target="1">Назад</button>
                            <button type="submit" class="btn btn-primary">Сгенерировать статью</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Шаг 3: Генерация -->
            <div class="wizard-step" data-step="3">
                <div class="wizard-card wizard-card-centered">
                    <div class="generation-loader">
                        <div class="loader-spinner"></div>
                        <h2>Генерируем статью...</h2>
                        <p>Это займёт 20-40 секунд. Пожалуйста, не закрывайте страницу.</p>
                        <div class="generation-progress-bar">
                            <div class="generation-progress-fill"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Шаг 4: Просмотр и редактирование -->
            <div class="wizard-step" data-step="4">
                <div class="wizard-card wizard-card-wide">
                    <div class="article-review-header">
                        <h2>Ваша статья готова</h2>
                        <p>Просмотрите текст и при необходимости отредактируйте любой раздел</p>
                    </div>

                    <div class="article-title-display" id="articleTitle"></div>

                    <div class="article-sections" id="articleSections">
                        <!-- Секции будут вставлены через JS -->
                    </div>

                    <div class="wizard-actions">
                        <button type="button" class="btn btn-outline" id="regenerateBtn">Перегенерировать</button>
                        <button type="button" class="btn btn-primary btn-lg" id="confirmArticleBtn">Подтвердить и опубликовать</button>
                    </div>
                </div>
            </div>

            <!-- Шаг 5: Подтверждение -->
            <div class="wizard-step" data-step="5">
                <div class="wizard-card wizard-card-centered">
                    <div class="publish-loader" id="publishLoader" style="display: none;">
                        <div class="loader-spinner"></div>
                        <h2>Публикуем статью...</h2>
                    </div>

                    <div class="publish-confirm" id="publishConfirm">
                        <h2>Подтверждение публикации</h2>
                        <p>После публикации статья будет доступна в журнале всем посетителям портала.</p>

                        <div class="confirm-details">
                            <div class="confirm-item">
                                <span class="confirm-label">Автор:</span>
                                <span class="confirm-value" id="confirmAuthor"></span>
                            </div>
                            <div class="confirm-item">
                                <span class="confirm-label">Организация:</span>
                                <span class="confirm-value" id="confirmOrg"></span>
                            </div>
                            <div class="confirm-item">
                                <span class="confirm-label">Статья:</span>
                                <span class="confirm-value" id="confirmTitle"></span>
                            </div>
                        </div>

                        <label class="checkbox-label">
                            <input type="checkbox" id="agreePublish">
                            <span>Я подтверждаю публикацию и согласен с <a href="/polzovatelskoe-soglashenie/" target="_blank">условиями</a></span>
                        </label>

                        <div class="wizard-actions">
                            <button type="button" class="btn btn-outline wizard-back-btn" data-target="4">Вернуться к редактированию</button>
                            <button type="button" class="btn btn-primary btn-lg" id="publishBtn" disabled>Опубликовать в журнал</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Шаг 6: Успех -->
            <div class="wizard-step" data-step="6">
                <div class="wizard-card wizard-card-centered">
                    <div class="success-content">
                        <div class="success-icon">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                        </div>
                        <h2>Статья опубликована!</h2>
                        <p>Ваш материал размещён в электронном педагогическом журнале</p>

                        <div class="success-actions">
                            <a href="#" class="btn btn-outline" id="viewPublicationLink" target="_blank">Посмотреть публикацию</a>
                            <a href="#" class="btn btn-primary btn-lg" id="getCertificateLink">Оформить свидетельство за 299 &#8381;</a>
                        </div>

                        <div class="success-note">
                            <p>Свидетельство о публикации в СМИ подтвердит вашу публикацию для аттестации и портфолио</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
