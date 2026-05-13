<?php
/**
 * Генератор статей для педагогов (редизайн в едином rd-* стиле)
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

$audienceCategories = $database->query(
    "SELECT id, name, slug FROM audience_categories WHERE is_active = 1 ORDER BY display_order"
);

$userData = [];
if (isset($_SESSION['user_id'])) {
    $userStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

$countResult = $database->queryOne("SELECT COUNT(*) as total FROM publications WHERE status = 'published'");
$totalPublications = $countResult['total'] ?? 0;

$pageTitle = 'Генератор статей для педагогов | ' . SITE_NAME;
$pageDescription = 'Сгенерируйте педагогическую статью за 3 минуты с помощью ИИ. Опубликуйтесь в журнале и получите свидетельство о публикации в СМИ.';
$canonicalUrl = SITE_URL . '/generator-statej/';

$rdActivePage = 'zhurnal';
$additionalCSS = [
    '/assets/css/competition-detail.css?v=' . filemtime(__DIR__ . '/../assets/css/competition-detail.css'),
    '/assets/css/journal-redesign.css?v=' . filemtime(__DIR__ . '/../assets/css/journal-redesign.css'),
    '/assets/css/article-generator.css?v=' . filemtime(__DIR__ . '/../assets/css/article-generator.css'),
];
$additionalJS = ['/assets/js/article-generator.js?v=' . filemtime(__DIR__ . '/../assets/js/article-generator.js')];

include __DIR__ . '/../includes/header-redesign.php';
?>

<!-- HERO -->
<section class="rd-hero-catalog generator-hero" style="padding-bottom:32px;">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <a href="/zhurnal/">Журнал</a>
      <span class="sep">/</span>
      <strong>Генератор статей</strong>
    </div>
  </div>
  <div class="rd-wrap" style="margin-top:24px;text-align:center;">
    <div class="rd-pill-row reveal-stagger" style="justify-content:center;">
      <span class="rd-pill"><span class="dot"></span>ИИ‑генератор</span>
      <span class="rd-pill indigo">3 минуты до статьи</span>
      <span class="rd-pill">Свидетельство СМИ 299&nbsp;₽</span>
    </div>
    <h1 class="rd-hero-title rd-hero-title-sm reveal" style="max-width:920px;margin:0 auto;">
      Сгенерируйте педагогическую статью за&nbsp;<span class="accent">3&nbsp;минуты</span>
    </h1>
    <p class="rd-hero-sub reveal" style="max-width:760px;margin:14px auto 0;">
      Искусственный интеллект напишет профессиональную статью по&nbsp;вашей теме. Опубликуйтесь в&nbsp;журнале и&nbsp;получите свидетельство о&nbsp;публикации в&nbsp;СМИ.
    </p>
    <div class="rd-hero-cta reveal" style="justify-content:center;margin-top:28px;">
      <button type="button" class="rd-btn rd-btn-primary" id="startGeneratorBtn">
        Создать статью
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
      </button>
      <a href="#howItWorks" class="rd-btn rd-btn-ghost">Как это работает</a>
    </div>
  </div>
</section>

<!-- USP -->
<div class="rd-usps generator-hero">
  <div class="rd-wrap rd-usp-grid reveal-stagger" style="grid-template-columns:repeat(3, 1fr);">
    <div class="rd-usp">
      <div class="ic">📰</div>
      <div>
        <div class="t"><?php echo number_format($totalPublications + 1250, 0, '', ' '); ?>+ публикаций</div>
        <div class="s">в электронном журнале</div>
      </div>
    </div>
    <div class="rd-usp">
      <div class="ic">⚡</div>
      <div>
        <div class="t">Готово за 3 минуты</div>
        <div class="s">генерация и публикация</div>
      </div>
    </div>
    <div class="rd-usp">
      <div class="ic">📜</div>
      <div>
        <div class="t">Свидетельство СМИ</div>
        <div class="s">Эл. №ФС 77‑74524 · 299&nbsp;₽</div>
      </div>
    </div>
  </div>
</div>

<!-- Как это работает -->
<section class="rd-section generator-steps-section" id="howItWorks">
  <div class="rd-wrap">
    <div class="reveal" style="text-align:center;">
      <div class="rd-eyebrow" style="justify-content:center;">Как это работает</div>
      <h2 class="rd-section-title" style="margin-left:auto;margin-right:auto;">4 шага до публикации</h2>
      <p class="rd-section-sub" style="margin-left:auto;margin-right:auto;">От темы до свидетельства о&nbsp;публикации — занимает считанные минуты.</p>
    </div>
    <div class="rd-steps four reveal-stagger" style="margin-top:32px;">
      <div class="rd-step">
        <div class="rd-step-n">1</div>
        <h4>Заполните данные</h4>
        <p>Укажите ФИО, должность, образовательное учреждение и&nbsp;тему статьи.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">2</div>
        <h4>ИИ напишет статью</h4>
        <p>Нейросеть сгенерирует профессиональный педагогический текст за&nbsp;30&nbsp;секунд.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">3</div>
        <h4>Отредактируйте</h4>
        <p>Просмотрите и&nbsp;при необходимости скорректируйте любой раздел статьи.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">4</div>
        <h4>Опубликуйте</h4>
        <p>Статья появится в&nbsp;журнале, а&nbsp;вы&nbsp;получите свидетельство о&nbsp;публикации.</p>
      </div>
    </div>
  </div>
</section>

<!-- Преимущества -->
<section class="rd-section generator-benefits" id="benefits" style="padding-top:0;">
  <div class="rd-wrap">
    <div class="reveal" style="text-align:center;">
      <div class="rd-eyebrow" style="justify-content:center;">Преимущества</div>
      <h2 class="rd-section-title" style="margin-left:auto;margin-right:auto;">Почему это удобно</h2>
    </div>
    <div class="rd-grid reveal-stagger" style="grid-template-columns:repeat(4, 1fr);margin-top:32px;">
      <div class="rd-card">
        <div class="rd-card-pat"></div>
        <div class="gen-bn-ic">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <h4>Экономия времени</h4>
        <div class="rd-card-meta">Не&nbsp;нужно тратить часы на&nbsp;написание. Статья готова за&nbsp;3&nbsp;минуты.</div>
      </div>
      <div class="rd-card">
        <div class="rd-card-pat"></div>
        <div class="gen-bn-ic">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        </div>
        <h4>Профессиональный текст</h4>
        <div class="rd-card-meta">ИИ&nbsp;знает стандарты педагогических публикаций и&nbsp;ФГОС.</div>
      </div>
      <div class="rd-card">
        <div class="rd-card-pat"></div>
        <div class="gen-bn-ic">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        </div>
        <h4>Свидетельство СМИ</h4>
        <div class="rd-card-meta">Официальное свидетельство о&nbsp;публикации для аттестации.</div>
      </div>
      <div class="rd-card">
        <div class="rd-card-pat"></div>
        <div class="gen-bn-ic">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <h4>Полный контроль</h4>
        <div class="rd-card-meta">Редактируйте любой раздел до&nbsp;полного удовлетворения результатом.</div>
      </div>
    </div>
  </div>
</section>

<!-- Визард генератора -->
<section class="rd-section generator-wizard-section" id="generatorWizard" style="display:none;padding-top:24px;">
  <div class="rd-wrap" style="max-width:920px;">

    <!-- Прогресс-бар -->
    <div class="wizard-progress reveal">
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
      <div class="rd-form-card">
        <div class="rd-form-card-head">
          <h2>Информация об&nbsp;авторе</h2>
          <p>Эти данные будут указаны в&nbsp;статье и&nbsp;свидетельстве</p>
        </div>

        <form id="step1Form">
          <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
          <input type="hidden" name="step" value="1">
          <input type="hidden" name="session_token" id="sessionToken" value="">

          <div class="rd-form-row">
            <div class="rd-form-group">
              <label for="gen_email">Email <span class="required">*</span></label>
              <input type="email" class="form-control" id="gen_email" name="email"
                     value="<?php echo htmlspecialchars($userData['email'] ?? ''); ?>"
                     placeholder="example@mail.ru" required>
            </div>
            <div class="rd-form-group">
              <label for="gen_author_name">ФИО <span class="required">*</span></label>
              <input type="text" class="form-control" id="gen_author_name" name="author_name"
                     value="<?php echo htmlspecialchars($userData['full_name'] ?? ''); ?>"
                     placeholder="Иванова Мария Владимировна" required>
            </div>
          </div>

          <div class="rd-form-row">
            <div class="rd-form-group">
              <label for="gen_organization">Образовательное учреждение <span class="required">*</span></label>
              <input type="text" class="form-control" id="gen_organization" name="organization"
                     value="<?php echo htmlspecialchars($userData['organization'] ?? ''); ?>"
                     placeholder="МБОУ СОШ №1 г. Москва" required>
            </div>
            <div class="rd-form-group">
              <label for="gen_position">Должность</label>
              <input type="text" class="form-control" id="gen_position" name="position"
                     value="<?php echo htmlspecialchars($userData['profession'] ?? ''); ?>"
                     placeholder="Учитель начальных классов">
            </div>
          </div>

          <div class="rd-form-group" style="max-width:50%;">
            <label for="gen_city">Город</label>
            <input type="text" class="form-control" id="gen_city" name="city"
                   value="<?php echo htmlspecialchars($userData['city'] ?? ''); ?>"
                   placeholder="Москва">
          </div>

          <div class="wizard-actions">
            <button type="submit" class="rd-btn rd-btn-primary">
              Далее
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Шаг 2: Параметры статьи -->
    <div class="wizard-step" data-step="2">
      <div class="rd-form-card">
        <div class="rd-form-card-head">
          <h2>Параметры статьи</h2>
          <p>Опишите, о&nbsp;чём хотите написать, и&nbsp;ИИ&nbsp;создаст статью</p>
        </div>

        <form id="step2Form">
          <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
          <input type="hidden" name="step" value="2">
          <input type="hidden" name="session_token" class="session-token-input" value="">

          <div class="rd-form-group">
            <label for="gen_audience">Категория аудитории</label>
            <select class="form-control" id="gen_audience" name="audience_category_id">
              <option value="">Выберите категорию</option>
              <?php foreach ($audienceCategories as $cat): ?>
                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="rd-form-group">
            <label for="gen_topic">Тема статьи <span class="required">*</span></label>
            <input type="text" class="form-control" id="gen_topic" name="topic"
                   placeholder="Например: Использование игровых технологий на уроках математики" required>
            <div class="hint-text" style="margin-top:6px;">Укажите предмет и&nbsp;тему, которые вас интересуют</div>
          </div>

          <div class="rd-form-group">
            <label for="gen_description">Краткое описание <span class="required">*</span></label>
            <textarea class="form-control" id="gen_description" name="description" rows="4"
                      placeholder="Опишите ключевые идеи, которые хотите раскрыть в статье. Например: хочу рассказать о том, как применяю настольные и цифровые игры для повышения мотивации учащихся 5-6 классов..." required></textarea>
            <div class="hint-text" style="margin-top:6px;">Чем подробнее описание, тем качественнее будет статья</div>
          </div>

          <div class="wizard-actions">
            <button type="button" class="rd-btn rd-btn-ghost wizard-back-btn" data-target="1">Назад</button>
            <button type="submit" class="rd-btn rd-btn-primary">
              Сгенерировать статью
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Шаг 3: Генерация -->
    <div class="wizard-step" data-step="3">
      <div class="rd-form-card wizard-card-centered">
        <div class="generation-loader">
          <div class="loader-spinner"></div>
          <h2>Генерируем статью...</h2>
          <p>Это займёт 20-40&nbsp;секунд. Пожалуйста, не&nbsp;закрывайте страницу.</p>
          <div class="generation-progress-bar">
            <div class="generation-progress-fill"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Шаг 4: Просмотр и редактирование -->
    <div class="wizard-step" data-step="4">
      <div class="rd-form-card wizard-card-wide">
        <div class="rd-form-card-head">
          <h2>Ваша статья готова</h2>
          <p>Просмотрите текст и&nbsp;при необходимости отредактируйте любой раздел</p>
        </div>

        <div class="article-title-display" id="articleTitle"></div>

        <div class="article-sections" id="articleSections">
          <!-- Секции вставляются через JS -->
        </div>

        <div class="wizard-actions">
          <button type="button" class="rd-btn rd-btn-ghost" id="regenerateBtn">Перегенерировать</button>
          <button type="button" class="rd-btn rd-btn-primary" id="confirmArticleBtn">
            Подтвердить и&nbsp;опубликовать
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          </button>
        </div>
      </div>
    </div>

    <!-- Шаг 5: Подтверждение -->
    <div class="wizard-step" data-step="5">
      <div class="rd-form-card wizard-card-centered">
        <div class="publish-loader" id="publishLoader" style="display:none;">
          <div class="loader-spinner"></div>
          <h2>Публикуем статью...</h2>
        </div>

        <div class="publish-confirm" id="publishConfirm">
          <div class="rd-form-card-head">
            <h2>Подтверждение публикации</h2>
            <p>После публикации статья будет доступна в&nbsp;журнале всем посетителям портала.</p>
          </div>

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

          <label class="rd-form-agreement" style="margin-top:18px;cursor:pointer;">
            <input type="checkbox" id="agreePublish">
            <span>Я&nbsp;подтверждаю публикацию и&nbsp;согласен с&nbsp;<a href="/polzovatelskoe-soglashenie/" target="_blank">условиями</a></span>
          </label>

          <div class="wizard-actions">
            <button type="button" class="rd-btn rd-btn-ghost wizard-back-btn" data-target="4">Вернуться к&nbsp;редактированию</button>
            <button type="button" class="rd-btn rd-btn-primary" id="publishBtn" disabled>Опубликовать в&nbsp;журнал</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Шаг 6: Успех -->
    <div class="wizard-step" data-step="6">
      <div class="rd-form-card wizard-card-centered">
        <div class="success-content">
          <div class="success-icon">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
              <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
          </div>
          <h2>Статья опубликована!</h2>
          <p>Ваш материал размещён в&nbsp;электронном педагогическом журнале</p>

          <div class="success-actions">
            <a href="#" class="rd-btn rd-btn-ghost" id="viewPublicationLink" target="_blank">Посмотреть публикацию</a>
            <a href="#" class="rd-btn rd-btn-primary" id="getCertificateLink">
              Оформить свидетельство за&nbsp;299&nbsp;₽
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
            </a>
          </div>

          <div class="success-note">
            <p>Свидетельство о&nbsp;публикации в&nbsp;СМИ подтвердит вашу публикацию для аттестации и&nbsp;портфолио.</p>
          </div>
        </div>
      </div>
    </div>

  </div>
</section>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>
