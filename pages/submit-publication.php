<?php
/**
 * Submit Publication Page (redesigned)
 * /opublikovat/
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/PublicationType.php';
require_once __DIR__ . '/../classes/PublicationTag.php';
require_once __DIR__ . '/../includes/session.php';

$database = new Database($db);

$typeObj = new PublicationType($db);
$types = $typeObj->getAll();

$tagObj = new PublicationTag($db);
$directions = $tagObj->getDirections();
$subjects = $tagObj->getSubjects();

$userData = [];
if (isset($_SESSION['user_id'])) {
    $userStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

$countResult = $database->queryOne("SELECT COUNT(*) as total FROM publications WHERE status = 'published'");
$totalPublications = $countResult['total'] ?? 0;

// A/B-тест: в варианте B (не-подписчик) цены свидетельства нет — оформляется по подписке.
require_once __DIR__ . '/../classes/PricingMode.php';
require_once __DIR__ . '/../classes/SubscriptionService.php';
$pmUserId = $_SESSION['user_id'] ?? null;
$pmIsSubscriber = $pmUserId ? (new SubscriptionService($db))->coversCertificates((int)$pmUserId) : false;
$pmSubscriptionOnly = PricingMode::isSubscriptionOnly() && !$pmIsSubscriber;

$pageTitle = 'Опубликовать статью и получить свидетельство | ' . SITE_NAME;
$pageDescription = 'Опубликуйте свою педагогическую статью в электронном журнале и получите официальное свидетельство о публикации для аттестации';

$rdActivePage = 'zhurnal';
$additionalCSS = [
    '/assets/css/competition-detail.css?v=' . filemtime(__DIR__ . '/../assets/css/competition-detail.css'),
    '/assets/css/journal-redesign.css?v=' . filemtime(__DIR__ . '/../assets/css/journal-redesign.css'),
];
$additionalJS = ['/assets/js/publication-form.js?v=' . filemtime(__DIR__ . '/../assets/js/publication-form.js')];
$noindex = true;

include __DIR__ . '/../includes/header-redesign.php';
?>

<!-- HERO -->
<section class="rd-hero-catalog" style="padding-bottom:32px;">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <a href="/zhurnal/">Журнал</a>
      <span class="sep">/</span>
      <strong>Опубликовать</strong>
    </div>
  </div>
  <div class="rd-wrap" style="margin-top:24px;text-align:center;">
    <div class="rd-pill-row reveal-stagger" style="justify-content:center;">
      <span class="rd-pill"><span class="dot"></span><?php echo number_format($totalPublications + 1250, 0, '', ' '); ?>+ публикаций</span>
      <span class="rd-pill indigo">Свидетельство <?php echo $pmSubscriptionOnly ? 'по подписке' : '499&nbsp;₽'; ?></span>
      <span class="rd-pill">5&nbsp;минут оформление</span>
    </div>
    <h1 class="rd-hero-title rd-hero-title-sm reveal" style="max-width:880px;margin:0 auto;">Опубликуйте статью и&nbsp;получите <span class="accent">свидетельство о&nbsp;публикации</span></h1>
    <p class="rd-hero-sub reveal" style="max-width:720px;margin:14px auto 0;">Ваш материал разместят в&nbsp;электронном педагогическом журнале и&nbsp;он&nbsp;станет доступен коллегам по&nbsp;всей России.</p>
  </div>
</section>

<section class="rd-section" style="padding-top:0;">
  <div class="rd-wrap">

    <!-- Generator promo -->
    <a href="/generator-statej/" class="rd-gen-promo">
      <div class="ic">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
      </div>
      <div class="body">
        <div class="badge">Новое · ИИ‑генератор</div>
        <h2>Нет готовой статьи?</h2>
        <p>Искусственный интеллект напишет педагогическую статью по&nbsp;вашей теме за&nbsp;3&nbsp;минуты.</p>
      </div>
      <div class="arrow">Сгенерировать →</div>
    </a>

    <div class="rd-submit-layout">
      <!-- LEFT: Form -->
      <div>
        <div class="rd-form-card">
          <div class="rd-form-card-head">
            <h2>Загрузите публикацию</h2>
            <p>Заполните форму и&nbsp;прикрепите файл с&nbsp;материалом</p>
          </div>

          <form id="publicationForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

            <!-- Author -->
            <div class="rd-form-section">
              <h3 class="rd-form-section-title"><span class="n">1</span>Информация об&nbsp;авторе</h3>

              <div class="rd-form-row">
                <div class="rd-form-group">
                  <label for="email">Email <span class="required">*</span></label>
                  <input type="email" class="form-control" id="email" name="email"
                         value="<?php echo htmlspecialchars($userData['email'] ?? ''); ?>"
                         placeholder="example@mail.ru" required>
                  <div class="error-message"></div>
                </div>
                <div class="rd-form-group">
                  <label for="author_name">ФИО автора <span class="required">*</span></label>
                  <input type="text" class="form-control" id="author_name" name="author_name" maxlength="255"
                         value="<?php echo htmlspecialchars($userData['full_name'] ?? ''); ?>"
                         placeholder="Иванова Мария Владимировна" required>
                  <div class="error-message"></div>
                </div>
              </div>

              <div class="rd-form-row">
                <div class="rd-form-group">
                  <label for="organization">Образовательное учреждение <span class="required">*</span></label>
                  <input type="text" class="form-control" id="organization" name="organization"
                         value="<?php echo htmlspecialchars($userData['organization'] ?? ''); ?>"
                         placeholder="МБОУ СОШ №1 г. Москва" required>
                  <div class="error-message"></div>
                </div>
                <div class="rd-form-group">
                  <label for="position">Должность</label>
                  <input type="text" class="form-control" id="position" name="position"
                         value="<?php echo htmlspecialchars($userData['profession'] ?? ''); ?>"
                         placeholder="Учитель начальных классов">
                </div>
              </div>

              <div class="rd-form-row">
                <div class="rd-form-group">
                  <label for="phone">Телефон <span class="required">*</span></label>
                  <input type="tel" class="form-control" id="phone" name="phone"
                         value="<?php echo htmlspecialchars($userData['phone'] ?? ''); ?>"
                         placeholder="+7 (___) ___-__-__" required>
                  <div class="error-message"></div>
                </div>
              </div>
            </div>

            <!-- File upload -->
            <div class="rd-form-section">
              <h3 class="rd-form-section-title"><span class="n">2</span>Файл публикации</h3>

              <div class="rd-form-group">
                <div class="rd-file-upload" id="fileUploadArea">
                  <input type="file" id="publication_file" name="publication_file"
                         accept=".pdf,.doc,.docx" class="file-input" required>
                  <div class="file-upload-content">
                    <div class="upload-icon">
                      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="17 8 12 3 7 8"></polyline>
                        <line x1="12" y1="3" x2="12" y2="15"></line>
                      </svg>
                    </div>
                    <p class="upload-text">
                      <strong>Перетащите файл сюда</strong> или <span class="upload-link">выберите на&nbsp;компьютере</span>
                    </p>
                    <p class="upload-hint">PDF, DOC, DOCX до&nbsp;10&nbsp;МБ — поля ниже заполнятся автоматически</p>
                  </div>
                  <div class="file-preview" style="display:none;">
                    <div class="file-icon">
                      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                      </svg>
                    </div>
                    <div class="file-info">
                      <span class="file-name"></span>
                      <span class="file-size"></span>
                    </div>
                    <button type="button" class="file-remove">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                  </div>
                </div>
                <div id="analysisIndicatorContainer"></div>
                <div class="error-message"></div>
              </div>
            </div>

            <!-- Publication info -->
            <div class="rd-form-section" id="publicationInfoSection">
              <h3 class="rd-form-section-title"><span class="n">3</span>О&nbsp;публикации</h3>

              <div class="rd-form-group">
                <label for="title">Название публикации <span class="required">*</span></label>
                <input type="text" class="form-control" id="title" name="title" maxlength="500"
                       placeholder="Современные методы развития речи у дошкольников" required>
                <div class="error-message"></div>
              </div>

              <div class="rd-form-group">
                <label for="annotation">Краткое описание <span class="required">*</span></label>
                <textarea class="form-control" id="annotation" name="annotation" rows="3" maxlength="500"
                          placeholder="Краткое описание вашего материала (до 500 символов)" required></textarea>
                <div class="char-counter"><span id="annotationCount">0</span>/500</div>
                <div class="error-message"></div>
              </div>

              <div class="rd-form-row">
                <div class="rd-form-group">
                  <label for="publication_type">Тип публикации <span class="required">*</span></label>
                  <select class="form-control" id="publication_type" name="publication_type_id" required>
                    <option value="">Выберите тип</option>
                    <?php foreach ($types as $type): ?>
                      <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="error-message"></div>
                </div>
                <div class="rd-form-group"></div>
              </div>

              <div class="rd-form-group">
                <label>Направление <span class="required">*</span></label>
                <div class="rd-tag-selector" id="directionsSelector">
                  <?php foreach ($directions as $tag): ?>
                    <label class="rd-tag-chk">
                      <input type="checkbox" name="tag_ids[]" value="<?php echo $tag['id']; ?>">
                      <span class="tag-label"><?php echo htmlspecialchars($tag['name']); ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="error-message"></div>
              </div>

              <div class="rd-form-group">
                <label>Предмет <span class="hint-text">(необязательно)</span></label>
                <div class="rd-tag-selector" id="subjectsSelector">
                  <?php foreach ($subjects as $tag): ?>
                    <label class="rd-tag-chk">
                      <input type="checkbox" name="tag_ids[]" value="<?php echo $tag['id']; ?>">
                      <span class="tag-label"><?php echo htmlspecialchars($tag['name']); ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <!-- Agreement -->
            <div class="rd-form-agreement">
              <input type="checkbox" name="agreement" id="agreement" required>
              <label for="agreement">
                Я подтверждаю, что являюсь автором материала, принимаю условия
                <a href="/polzovatelskoe-soglashenie/" target="_blank">Пользовательского соглашения</a>,
                <a href="/oferta-meropriyatiya/" target="_blank">Договора‑оферты</a>
                и&nbsp;даю согласие на&nbsp;<a href="/politika-konfidencialnosti/" target="_blank">обработку персональных данных</a>
              </label>
            </div>

            <!-- Submit -->
            <div class="rd-form-submit">
              <button type="submit" class="rd-btn rd-btn-primary" id="submitBtn">
                <span class="btn-text">Опубликовать и&nbsp;получить свидетельство</span>
                <span class="btn-loader" style="display:none;">
                  <span class="spinner-small"></span>
                  Загрузка...
                </span>
              </button>
              <p class="submit-hint">После загрузки вы&nbsp;перейдёте к&nbsp;оформлению свидетельства<?php echo $pmSubscriptionOnly ? ' по подписке' : ' (499&nbsp;₽)'; ?></p>
            </div>
          </form>
        </div>
      </div>

      <!-- RIGHT: Info -->
      <aside class="rd-side-info">
        <!-- Mockup журнала -->
        <div class="rd-journal-mockup">
          <div class="head">
            <div class="dots"><span></span><span></span><span></span></div>
            <div class="title">Педагогический журнал</div>
          </div>
          <div class="body">
            <div>
              <span class="article-badge">Новая публикация</span>
              <div class="ttl-line"></div>
              <div class="ttl-line short"></div>
              <div class="meta-line"><span></span><span></span></div>
              <div class="text-line"></div>
              <div class="text-line"></div>
              <div class="text-line medium"></div>
            </div>
            <div>
              <div class="side-item"></div>
              <div class="side-item"></div>
              <div class="side-item"></div>
            </div>
          </div>
        </div>

        <!-- Benefits -->
        <div class="rd-side-benefits">
          <h3>Почему стоит опубликоваться?</h3>
          <ul>
            <li><span class="ic"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg></span><span>Официальное свидетельство для&nbsp;аттестации</span></li>
            <li><span class="ic"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg></span><span>Публикация в&nbsp;СМИ (Эл.&nbsp;№ФС&nbsp;77‑74524)</span></li>
            <li><span class="ic"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg></span><span>Доступ коллегам по&nbsp;всей России</span></li>
            <li><span class="ic"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg></span><span>Свидетельство сразу после оплаты</span></li>
          </ul>
        </div>

        <!-- FAQ mini -->
        <div class="rd-side-faq">
          <h3>Частые вопросы</h3>
          <details>
            <summary>Какие материалы можно публиковать?</summary>
            <p>Методические разработки, статьи, исследования, программы, презентации, мастер‑классы и&nbsp;другие авторские педагогические материалы.</p>
          </details>
          <details>
            <summary>Как быстро появится в&nbsp;журнале?</summary>
            <p>После модерации (1–2&nbsp;рабочих дня) публикация появится в&nbsp;каталоге журнала.</p>
          </details>
          <details>
            <summary>Какие форматы файлов?</summary>
            <p>PDF, DOC и&nbsp;DOCX размером до&nbsp;10&nbsp;МБ.</p>
          </details>
        </div>
      </aside>
    </div>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>
