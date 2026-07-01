<?php
/**
 * Team Page — Команда ФГОС.ПРО
 * Руководство проекта, преподаватели курсов и спикеры вебинаров.
 * URL: /team
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/CourseExpert.php';
require_once __DIR__ . '/../includes/session.php';

// ── Данные ───────────────────────────────────────────────────────────────────

// Руководство проекта (ядро команды)
$coreTeam = [
    [
        'name' => 'Александр Дмитриевич Воронов',
        'role' => 'Руководитель проекта',
        'desc' => 'Опыт в образовательных проектах 12 лет. Резидент Сколково. Эксперт по аттестации педагогов.',
    ],
    [
        'name' => 'Елена Павловна Григорьева',
        'role' => 'Ведущий методист',
        'desc' => 'Кандидат педагогических наук. Автор 30+ публикаций по ФГОС. Педагогический стаж 15 лет.',
    ],
    [
        'name' => 'Максим Сергеевич Ковалёв',
        'role' => 'Технический директор',
        'desc' => 'Обеспечивает работу платформы, безопасность платежей и выдачу дипломов.',
    ],
];

// Преподаватели курсов
$experts = [];
try {
    $expertObj = new CourseExpert($db);
    $experts = $expertObj->getAll(true);
} catch (Throwable $e) {
    error_log('team.php experts: ' . $e->getMessage());
}

// Спикеры вебинаров
$speakers = [];
try {
    $dbWrap = new Database($db);
    $speakers = $dbWrap->query(
        "SELECT s.full_name, s.position, s.organization, s.bio, s.photo,
                COUNT(DISTINCT w.id) AS webinar_count
         FROM speakers s
         LEFT JOIN webinars w ON w.speaker_id = s.id AND w.is_active = 1
         WHERE s.is_active = 1
         GROUP BY s.id, s.full_name, s.position, s.organization, s.bio, s.photo, s.display_order
         ORDER BY s.display_order ASC, s.full_name ASC"
    );
} catch (Throwable $e) {
    error_log('team.php speakers: ' . $e->getMessage());
}

// ── Хелперы ──────────────────────────────────────────────────────────────────

/** Инициалы для аватара-заглушки. */
function teamInitials(string $name): string {
    $parts = preg_split('/\s+/u', trim($name));
    $initials = '';
    foreach ($parts as $p) {
        if ($p !== '' && mb_strlen($initials) < 2) {
            $initials .= mb_strtoupper(mb_substr($p, 0, 1, 'UTF-8'), 'UTF-8');
        }
    }
    return $initials !== '' ? $initials : '?';
}

/** Детерминированный индекс палитры аватара по имени. */
function teamAvatarTone(string $name): int {
    return (crc32($name) % 5) + 1;
}

// ── Метаданные ───────────────────────────────────────────────────────────────

$pageTitle = 'Команда ФГОС.ПРО — эксперты, методисты и преподаватели';
$pageDescription = 'Команда портала «ФГОС-Практикум»: руководство проекта, преподаватели курсов повышения квалификации и переподготовки, спикеры вебинаров. Резиденты Сколково, методисты с опытом от 10 лет.';
$canonicalUrl = SITE_URL . '/team/';

// JSON-LD: AboutPage + Person для членов команды и спикеров
$persons = [];
foreach ($coreTeam as $m) {
    $persons[] = [
        '@type' => 'Person',
        'name' => $m['name'],
        'jobTitle' => $m['role'],
        'description' => $m['desc'],
        'worksFor' => ['@type' => 'Organization', 'name' => SITE_NAME, 'url' => SITE_URL],
    ];
}
foreach ($speakers as $s) {
    $person = [
        '@type' => 'Person',
        'name' => $s['full_name'],
        'jobTitle' => $s['position'] ?: 'Спикер вебинаров',
    ];
    if (!empty($s['organization'])) {
        $person['affiliation'] = ['@type' => 'Organization', 'name' => $s['organization']];
    }
    if (!empty($s['photo'])) {
        $person['image'] = SITE_URL . $s['photo'];
    }
    $persons[] = $person;
}

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'AboutPage',
    'name' => 'Команда ФГОС.ПРО',
    'description' => 'Команда портала «ФГОС-Практикум» — эксперты в области педагогики, методисты и преподаватели.',
    'url' => $canonicalUrl,
    'mainEntity' => [
        '@type' => 'Organization',
        'name' => SITE_NAME,
        'url' => SITE_URL,
        'member' => $persons,
    ],
];

$additionalCSS = ['/assets/css/team.css?v=' . filemtime(__DIR__ . '/../assets/css/team.css')];

$useRedesignBody = true;
$rdActivePage = 'team';
include __DIR__ . '/../includes/header.php';
?>

<!-- Hero -->
<section class="team-hero">
    <div class="team-container">
        <div class="team-hero-inner">
            <span class="team-eyebrow">Команда проекта</span>
            <h1>Команда ФГОС.ПРО</h1>
            <p class="team-hero-sub">
                За порталом «ФГОС-Практикум» стоит команда практиков: руководители,
                методисты, преподаватели курсов и спикеры вебинаров. Каждый —
                действующий эксперт в области педагогики и аттестации.
            </p>
            <div class="team-hero-stats">
                <div class="team-stat">
                    <span class="team-stat-num"><?php echo count($coreTeam); ?></span>
                    <span class="team-stat-label">в руководстве</span>
                </div>
                <div class="team-stat">
                    <span class="team-stat-num"><?php echo count($experts); ?></span>
                    <span class="team-stat-label">преподавателей курсов</span>
                </div>
                <div class="team-stat">
                    <span class="team-stat-num"><?php echo count($speakers); ?></span>
                    <span class="team-stat-label">спикеров вебинаров</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Руководство проекта -->
<section class="team-section">
    <div class="team-container">
        <div class="team-section-head">
            <span class="team-eyebrow">Руководство</span>
            <h2>Руководство проекта</h2>
            <p>Те, кто отвечает за развитие платформы, методическое качество и техническую надёжность.</p>
        </div>
        <div class="team-grid team-grid-core">
            <?php foreach ($coreTeam as $m): ?>
            <article class="team-card" itemscope itemtype="https://schema.org/Person">
                <div class="team-avatar team-avatar-tone-<?php echo teamAvatarTone($m['name']); ?>">
                    <?php echo htmlspecialchars(teamInitials($m['name']), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <h3 class="team-name" itemprop="name"><?php echo htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                <p class="team-role" itemprop="jobTitle"><?php echo htmlspecialchars($m['role'], ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="team-desc" itemprop="description"><?php echo htmlspecialchars($m['desc'], ENT_QUOTES, 'UTF-8'); ?></p>
                <meta itemprop="url" content="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Преподаватели курсов -->
<?php if (!empty($experts)): ?>
<section class="team-section team-section-alt">
    <div class="team-container">
        <div class="team-section-head">
            <span class="team-eyebrow">Преподаватели</span>
            <h2>Преподаватели курсов</h2>
            <p>Эксперты-практики курсов повышения квалификации и профессиональной переподготовки.</p>
        </div>
        <div class="team-grid">
            <?php foreach ($experts as $expert):
                $photo = trim((string)($expert['photo_url'] ?? ''));
                $isPlaceholder = ($photo === '' || strpos($photo, 'placeholder') !== false);
            ?>
            <article class="team-card team-card-sm" itemscope itemtype="https://schema.org/Person">
                <div class="team-photo">
                    <div class="team-avatar team-avatar-sm team-avatar-tone-<?php echo teamAvatarTone($expert['full_name']); ?>">
                        <?php echo htmlspecialchars(teamInitials($expert['full_name']), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php if (!$isPlaceholder): ?>
                    <img src="<?php echo htmlspecialchars($photo, ENT_QUOTES, 'UTF-8'); ?>"
                         alt="<?php echo htmlspecialchars($expert['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                         loading="lazy" itemprop="image"
                         onerror="this.remove();">
                    <?php endif; ?>
                </div>
                <h3 class="team-name" itemprop="name"><?php echo htmlspecialchars($expert['full_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                <?php if (!empty($expert['credentials'])): ?>
                <p class="team-role" itemprop="jobTitle"><?php echo htmlspecialchars($expert['credentials'], ENT_QUOTES, 'UTF-8'); ?></p>
                <?php else: ?>
                <p class="team-role" itemprop="jobTitle">Преподаватель курсов</p>
                <?php endif; ?>
                <?php if (!empty($expert['experience'])): ?>
                <p class="team-desc" itemprop="description">Стаж: <?php echo htmlspecialchars($expert['experience'], ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <meta itemprop="url" content="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
            </article>
            <?php endforeach; ?>
        </div>
        <div class="team-cta-inline">
            <a href="/kursy" class="team-link">Смотреть курсы с этими преподавателями →</a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Спикеры вебинаров -->
<?php if (!empty($speakers)): ?>
<section class="team-section">
    <div class="team-container">
        <div class="team-section-head">
            <span class="team-eyebrow">Спикеры</span>
            <h2>Спикеры вебинаров</h2>
            <p>Приглашённые эксперты, которые проводят вебинары и видеолекции для педагогов.</p>
        </div>
        <div class="team-grid">
            <?php foreach ($speakers as $sp):
                $photo = trim((string)($sp['photo'] ?? ''));
            ?>
            <article class="team-card team-card-sm" itemscope itemtype="https://schema.org/Person">
                <div class="team-photo">
                    <div class="team-avatar team-avatar-sm team-avatar-tone-<?php echo teamAvatarTone($sp['full_name']); ?>">
                        <?php echo htmlspecialchars(teamInitials($sp['full_name']), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php if ($photo !== ''): ?>
                    <img src="<?php echo htmlspecialchars($photo, ENT_QUOTES, 'UTF-8'); ?>"
                         alt="<?php echo htmlspecialchars($sp['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                         loading="lazy" itemprop="image"
                         onerror="this.remove();">
                    <?php endif; ?>
                </div>
                <h3 class="team-name" itemprop="name"><?php echo htmlspecialchars($sp['full_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                <?php if (!empty($sp['position'])): ?>
                <p class="team-role" itemprop="jobTitle"><?php echo htmlspecialchars($sp['position'], ENT_QUOTES, 'UTF-8'); ?></p>
                <?php else: ?>
                <p class="team-role" itemprop="jobTitle">Спикер вебинаров</p>
                <?php endif; ?>
                <?php if (!empty($sp['organization'])): ?>
                <p class="team-org" itemprop="affiliation"><?php echo htmlspecialchars($sp['organization'], ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <?php if (!empty($sp['webinar_count']) && (int)$sp['webinar_count'] > 0): ?>
                <span class="team-badge"><?php echo (int)$sp['webinar_count']; ?> <?php
                    $n = (int)$sp['webinar_count'];
                    $mod10 = $n % 10; $mod100 = $n % 100;
                    if ($mod10 === 1 && $mod100 !== 11) echo 'вебинар';
                    elseif ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) echo 'вебинара';
                    else echo 'вебинаров';
                ?></span>
                <?php endif; ?>
                <meta itemprop="url" content="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
            </article>
            <?php endforeach; ?>
        </div>
        <div class="team-cta-inline">
            <a href="/vebinary" class="team-link">Смотреть вебинары и видеолекции →</a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- CTA -->
<section class="team-section team-section-alt">
    <div class="team-container">
        <div class="team-final-cta">
            <h2>Учитесь у практиков</h2>
            <p>Курсы, вебинары и конкурсы портала ведут действующие эксперты. Присоединяйтесь.</p>
            <div class="team-cta-buttons">
                <a href="/kursy" class="rd-btn rd-btn-primary">Выбрать курс</a>
                <a href="/vebinary" class="rd-btn rd-btn-ghost">Записаться на вебинар</a>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/social-links-redesign.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
