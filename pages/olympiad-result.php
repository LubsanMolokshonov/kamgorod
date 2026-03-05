<?php
/**
 * Olympiad Result Page
 * Shows the result after completing the olympiad quiz
 * Celebratory design with placement, score, and CTA buttons
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Olympiad.php';
require_once __DIR__ . '/../classes/OlympiadQuiz.php';
require_once __DIR__ . '/../includes/session.php';

// Get result ID from URL
$resultId = intval($_GET['result_id'] ?? 0);

if (!$resultId) {
    header('Location: /olimpiady');
    exit;
}

// Load result with olympiad and user info
$quizObj = new OlympiadQuiz($db);
$result = $quizObj->getResultById($resultId);

if (!$result) {
    header('Location: /olimpiady');
    exit;
}

// Extract result data
$score = intval($result['score']);
$totalQuestions = intval($result['total_questions']);
$placement = $result['placement']; // '1', '2', '3', or null
$olympiadTitle = $result['olympiad_title'];
$olympiadSlug = $result['olympiad_slug'];
$olympiadId = $result['olympiad_id'];
$fullName = $result['full_name'];
$diplomaPrice = $result['diploma_price'] ?? 169;

// Determine display data based on placement
$placementData = [
    '1' => [
        'medal' => '<svg width="80" height="80" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="40" cy="40" r="36" fill="url(#goldGrad)" stroke="#DAA520" stroke-width="3"/><circle cx="40" cy="40" r="28" fill="url(#goldGrad2)" stroke="#B8860B" stroke-width="1.5"/><text x="40" y="48" text-anchor="middle" fill="#7B5B00" font-size="28" font-weight="bold">1</text><defs><linearGradient id="goldGrad" x1="4" y1="4" x2="76" y2="76"><stop stop-color="#FFD700"/><stop offset="1" stop-color="#FFA500"/></linearGradient><linearGradient id="goldGrad2" x1="12" y1="12" x2="68" y2="68"><stop stop-color="#FFEC8B"/><stop offset="1" stop-color="#FFD700"/></linearGradient></defs></svg>',
        'title' => 'Поздравляем! 1 место!',
        'subtitle' => 'Превосходный результат! Вы показали отличные знания.',
        'color' => '#FFD700',
        'bg' => 'linear-gradient(135deg, #FFF9E6 0%, #FFF3CC 100%)',
        'border' => '#FFD700',
        'showConfetti' => true,
    ],
    '2' => [
        'medal' => '<svg width="80" height="80" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="40" cy="40" r="36" fill="url(#silverGrad)" stroke="#A9A9A9" stroke-width="3"/><circle cx="40" cy="40" r="28" fill="url(#silverGrad2)" stroke="#808080" stroke-width="1.5"/><text x="40" y="48" text-anchor="middle" fill="#555" font-size="28" font-weight="bold">2</text><defs><linearGradient id="silverGrad" x1="4" y1="4" x2="76" y2="76"><stop stop-color="#E8E8E8"/><stop offset="1" stop-color="#C0C0C0"/></linearGradient><linearGradient id="silverGrad2" x1="12" y1="12" x2="68" y2="68"><stop stop-color="#F5F5F5"/><stop offset="1" stop-color="#D3D3D3"/></linearGradient></defs></svg>',
        'title' => 'Отличный результат! 2 место!',
        'subtitle' => 'Вы показали высокий уровень знаний.',
        'color' => '#A9A9A9',
        'bg' => 'linear-gradient(135deg, #F8F8F8 0%, #EFEFEF 100%)',
        'border' => '#C0C0C0',
        'showConfetti' => true,
    ],
    '3' => [
        'medal' => '<svg width="80" height="80" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="40" cy="40" r="36" fill="url(#bronzeGrad)" stroke="#CD7F32" stroke-width="3"/><circle cx="40" cy="40" r="28" fill="url(#bronzeGrad2)" stroke="#8B5A2B" stroke-width="1.5"/><text x="40" y="48" text-anchor="middle" fill="#5C3A1E" font-size="28" font-weight="bold">3</text><defs><linearGradient id="bronzeGrad" x1="4" y1="4" x2="76" y2="76"><stop stop-color="#DEB887"/><stop offset="1" stop-color="#CD7F32"/></linearGradient><linearGradient id="bronzeGrad2" x1="12" y1="12" x2="68" y2="68"><stop stop-color="#F5DEB3"/><stop offset="1" stop-color="#DEB887"/></linearGradient></defs></svg>',
        'title' => 'Хороший результат! 3 место!',
        'subtitle' => 'Вы хорошо справились с заданиями.',
        'color' => '#CD7F32',
        'bg' => 'linear-gradient(135deg, #FFF8F0 0%, #FAEBD7 100%)',
        'border' => '#CD7F32',
        'showConfetti' => true,
    ],
];

$noPlaceData = [
    'medal' => '<svg width="80" height="80" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="40" cy="40" r="36" fill="#F0F4FF" stroke="#CBD5E1" stroke-width="3"/><circle cx="40" cy="40" r="28" fill="#F8FAFF" stroke="#E2E8F0" stroke-width="1.5"/><path d="M32 48L40 28L48 48" stroke="#94A3B8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/><line x1="34" y1="42" x2="46" y2="42" stroke="#94A3B8" stroke-width="2.5" stroke-linecap="round"/></svg>',
    'title' => 'Попробуйте ещё раз!',
    'subtitle' => 'Для получения диплома необходимо набрать минимум 7 баллов.',
    'color' => '#94A3B8',
    'bg' => 'linear-gradient(135deg, #F8FAFC 0%, #F0F4FF 100%)',
    'border' => '#CBD5E1',
    'showConfetti' => false,
];

$display = $placement ? $placementData[$placement] : $noPlaceData;

// Page metadata
$pageTitle = 'Результат олимпиады — ' . htmlspecialchars($olympiadTitle) . ' | ' . SITE_NAME;
$pageDescription = 'Результат олимпиады «' . htmlspecialchars($olympiadTitle) . '». Оформите диплом с указанием места.';
$noindex = true;

include __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================
   Olympiad Result Page Styles
   ============================================ */

.olympiad-result-page {
    background: #F5F7FA;
    min-height: 100vh;
    padding: 40px 0 80px;
}

.olympiad-result-container {
    max-width: 680px;
    margin: 0 auto;
    padding: 0 20px;
}

/* ---------- Congratulations Card ---------- */
.olympiad-result-card {
    background: #fff;
    border-radius: 32px;
    padding: 48px 40px;
    box-shadow: 0 8px 40px rgba(0, 0, 0, 0.08);
    text-align: center;
    position: relative;
    overflow: hidden;
}

.olympiad-result-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 6px;
    background: <?php echo $display['color']; ?>;
    border-radius: 32px 32px 0 0;
}

/* ---------- Medal ---------- */
.olympiad-result-medal {
    margin-bottom: 24px;
    display: inline-block;
    animation: medalBounce 0.8s ease-out;
}

@keyframes medalBounce {
    0% { transform: scale(0) rotate(-20deg); opacity: 0; }
    50% { transform: scale(1.15) rotate(5deg); opacity: 1; }
    70% { transform: scale(0.95) rotate(-2deg); }
    100% { transform: scale(1) rotate(0deg); }
}

/* ---------- Title & Subtitle ---------- */
.olympiad-result-title {
    font-size: 28px;
    font-weight: 800;
    color: #2C3E50;
    margin: 0 0 10px;
    line-height: 1.3;
}

.olympiad-result-subtitle {
    font-size: 16px;
    color: #7F8C9B;
    margin: 0 0 32px;
    line-height: 1.5;
}

/* ---------- Score Display ---------- */
.olympiad-score-display {
    background: <?php echo $display['bg']; ?>;
    border: 2px solid <?php echo $display['border']; ?>;
    border-radius: 20px;
    padding: 28px 32px;
    margin-bottom: 32px;
    display: inline-block;
    min-width: 280px;
}

.olympiad-score-label {
    font-size: 14px;
    color: #7F8C9B;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 600;
    margin: 0 0 8px;
}

.olympiad-score-value {
    font-size: 48px;
    font-weight: 800;
    color: #2C3E50;
    line-height: 1;
    margin: 0 0 4px;
}

.olympiad-score-value span {
    font-size: 24px;
    font-weight: 600;
    color: #7F8C9B;
}

.olympiad-score-total {
    font-size: 14px;
    color: #94A3B8;
    margin: 0;
}

/* ---------- Placement Badge ---------- */
.olympiad-placement-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 24px;
    border-radius: 50px;
    font-size: 15px;
    font-weight: 700;
    margin-bottom: 36px;
}

.olympiad-placement-badge.place-1 {
    background: linear-gradient(135deg, #FFF9E6, #FFECB3);
    color: #7B5B00;
    border: 1px solid #FFD700;
}

.olympiad-placement-badge.place-2 {
    background: linear-gradient(135deg, #F5F5F5, #E8E8E8);
    color: #555;
    border: 1px solid #C0C0C0;
}

.olympiad-placement-badge.place-3 {
    background: linear-gradient(135deg, #FFF5EB, #FFE4C4);
    color: #5C3A1E;
    border: 1px solid #CD7F32;
}

.olympiad-placement-badge.no-place {
    background: #F0F4FF;
    color: #64748B;
    border: 1px solid #CBD5E1;
}

/* ---------- Name ---------- */
.olympiad-result-name {
    font-size: 15px;
    color: #94A3B8;
    margin: 0 0 36px;
}

.olympiad-result-name strong {
    color: #2C3E50;
}

/* ---------- CTA Buttons ---------- */
.olympiad-cta-section {
    display: flex;
    flex-direction: column;
    gap: 14px;
    align-items: center;
}

.olympiad-cta-primary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 18px 40px;
    background: #0077FF;
    color: #fff;
    border: none;
    border-radius: 16px;
    font-size: 17px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.2s, transform 0.1s, box-shadow 0.2s;
    box-shadow: 0 4px 20px rgba(0, 119, 255, 0.35);
    width: 100%;
    max-width: 420px;
    text-align: center;
}

.olympiad-cta-primary:hover {
    background: #0060D0;
    box-shadow: 0 6px 28px rgba(0, 119, 255, 0.45);
    color: #fff;
    text-decoration: none;
}

.olympiad-cta-primary:active {
    transform: scale(0.98);
}

.olympiad-cta-primary svg {
    flex-shrink: 0;
}

.olympiad-cta-secondary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 14px 32px;
    background: transparent;
    color: #64748B;
    border: 2px solid #E2E8F0;
    border-radius: 14px;
    font-size: 15px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s;
    width: 100%;
    max-width: 420px;
    text-align: center;
}

.olympiad-cta-secondary:hover {
    border-color: #CBD5E1;
    background: #F8FAFC;
    color: #475569;
    text-decoration: none;
}

.olympiad-cta-hint {
    font-size: 13px;
    color: #94A3B8;
    margin: 4px 0 0;
    line-height: 1.4;
}

/* ---------- Olympiad Info ---------- */
.olympiad-result-info {
    margin-top: 32px;
    padding-top: 24px;
    border-top: 1px solid #F0F0F0;
    font-size: 14px;
    color: #94A3B8;
}

.olympiad-result-info strong {
    color: #2C3E50;
}

/* ---------- Confetti ---------- */
.confetti-canvas {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 9999;
}

/* ---------- Responsive ---------- */
@media (max-width: 768px) {
    .olympiad-result-page {
        padding: 24px 0 60px;
    }

    .olympiad-result-card {
        padding: 36px 24px;
        border-radius: 24px;
    }

    .olympiad-result-title {
        font-size: 24px;
    }

    .olympiad-score-display {
        min-width: auto;
        width: 100%;
        box-sizing: border-box;
        padding: 24px 20px;
    }

    .olympiad-score-value {
        font-size: 40px;
    }

    .olympiad-cta-primary {
        padding: 16px 28px;
        font-size: 16px;
    }

    .olympiad-cta-secondary {
        padding: 12px 24px;
        font-size: 14px;
    }
}

@media (max-width: 480px) {
    .olympiad-result-container {
        padding: 0 12px;
    }

    .olympiad-result-card {
        padding: 28px 18px;
        border-radius: 20px;
    }

    .olympiad-result-title {
        font-size: 21px;
    }

    .olympiad-result-subtitle {
        font-size: 14px;
    }

    .olympiad-score-value {
        font-size: 36px;
    }

    .olympiad-score-value span {
        font-size: 20px;
    }
}
</style>

<div class="olympiad-result-page">
    <div class="olympiad-result-container">

        <div class="olympiad-result-card">

            <!-- Medal -->
            <div class="olympiad-result-medal">
                <?php echo $display['medal']; ?>
            </div>

            <!-- Title -->
            <h1 class="olympiad-result-title"><?php echo htmlspecialchars($display['title']); ?></h1>
            <p class="olympiad-result-subtitle"><?php echo htmlspecialchars($display['subtitle']); ?></p>

            <!-- Score -->
            <div class="olympiad-score-display">
                <p class="olympiad-score-label">Ваш результат</p>
                <p class="olympiad-score-value"><?php echo $score; ?> <span>из <?php echo $totalQuestions; ?></span></p>
                <p class="olympiad-score-total">правильных ответов</p>
            </div>

            <!-- Placement Badge -->
            <div>
                <?php if ($placement === '1'): ?>
                    <div class="olympiad-placement-badge place-1">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="#FFD700" stroke="#B8860B" stroke-width="1.5"/><text x="12" y="16" text-anchor="middle" fill="#7B5B00" font-size="12" font-weight="bold">1</text></svg>
                        1 место
                    </div>
                <?php elseif ($placement === '2'): ?>
                    <div class="olympiad-placement-badge place-2">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="#D3D3D3" stroke="#A9A9A9" stroke-width="1.5"/><text x="12" y="16" text-anchor="middle" fill="#555" font-size="12" font-weight="bold">2</text></svg>
                        2 место
                    </div>
                <?php elseif ($placement === '3'): ?>
                    <div class="olympiad-placement-badge place-3">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="#DEB887" stroke="#CD7F32" stroke-width="1.5"/><text x="12" y="16" text-anchor="middle" fill="#5C3A1E" font-size="12" font-weight="bold">3</text></svg>
                        3 место
                    </div>
                <?php else: ?>
                    <div class="olympiad-placement-badge no-place">
                        Участник
                    </div>
                <?php endif; ?>
            </div>

            <!-- Name -->
            <?php if (!empty($fullName)): ?>
            <p class="olympiad-result-name">Участник: <strong><?php echo htmlspecialchars($fullName); ?></strong></p>
            <?php endif; ?>

            <!-- CTA Buttons -->
            <div class="olympiad-cta-section">

                <?php if ($placement): ?>
                <a href="/olimpiada-diplom/<?php echo $resultId; ?>" class="olympiad-cta-primary">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9 12L11 14L15 10M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Оформить диплом олимпиады за <?php echo intval($diplomaPrice); ?> руб
                </a>
                <p class="olympiad-cta-hint">Диплом будет сгенерирован автоматически с вашими данными</p>
                <?php endif; ?>

                <a href="/olimpiada-test/<?php echo $olympiadId; ?>" class="olympiad-cta-secondary">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M4 4V9H4.582M4.582 9C5.247 6.603 7.414 4.83 10 4.83C13.038 4.83 15.5 7.292 15.5 10.33C15.5 13.368 13.038 15.83 10 15.83C7.95 15.83 6.163 14.68 5.233 12.987M4.582 9H9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" transform="translate(2, 2)"/>
                    </svg>
                    Пройти тестирование повторно
                </a>
            </div>

            <!-- Olympiad Info -->
            <div class="olympiad-result-info">
                Олимпиада: <strong><?php echo htmlspecialchars($olympiadTitle); ?></strong>
            </div>

        </div>

    </div>
</div>

<?php if ($display['showConfetti']): ?>
<!-- Confetti Animation -->
<canvas class="confetti-canvas" id="confettiCanvas"></canvas>
<script>
(function() {
    'use strict';

    var canvas = document.getElementById('confettiCanvas');
    if (!canvas) return;

    var ctx = canvas.getContext('2d');
    var W = window.innerWidth;
    var H = window.innerHeight;
    canvas.width = W;
    canvas.height = H;

    var particles = [];
    var totalParticles = 120;
    var colors = ['#FFD700', '#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8', '#F7DC6F', '#BB8FCE', '#0077FF', '#FF69B4'];

    <?php if ($placement === '1'): ?>
    colors = ['#FFD700', '#FFA500', '#FF6347', '#FFD700', '#FFEC8B', '#FF4500', '#FFB700', '#FFC300', '#0077FF', '#00AAFF'];
    <?php elseif ($placement === '2'): ?>
    colors = ['#C0C0C0', '#A9A9A9', '#D3D3D3', '#87CEEB', '#B0C4DE', '#778899', '#0077FF', '#4682B4', '#F0F0F0', '#E0E0E0'];
    <?php elseif ($placement === '3'): ?>
    colors = ['#CD7F32', '#DEB887', '#D2691E', '#F4A460', '#FFDEAD', '#D2B48C', '#0077FF', '#8B4513', '#FFE4C4', '#DAA520'];
    <?php endif; ?>

    function randomRange(min, max) {
        return Math.random() * (max - min) + min;
    }

    function Particle() {
        this.x = randomRange(0, W);
        this.y = randomRange(-H, 0);
        this.w = randomRange(6, 14);
        this.h = randomRange(6, 14);
        this.color = colors[Math.floor(Math.random() * colors.length)];
        this.vx = randomRange(-2, 2);
        this.vy = randomRange(2, 6);
        this.rotation = randomRange(0, 360);
        this.rotationSpeed = randomRange(-6, 6);
        this.opacity = 1;
        this.shape = Math.random() > 0.5 ? 'rect' : 'circle';
    }

    Particle.prototype.update = function() {
        this.x += this.vx;
        this.y += this.vy;
        this.rotation += this.rotationSpeed;
        this.vy += 0.05; // gravity
        this.vx *= 0.99; // air resistance

        if (this.y > H + 20) {
            this.opacity -= 0.02;
        }
    };

    Particle.prototype.draw = function() {
        ctx.save();
        ctx.translate(this.x, this.y);
        ctx.rotate((this.rotation * Math.PI) / 180);
        ctx.globalAlpha = Math.max(0, this.opacity);
        ctx.fillStyle = this.color;

        if (this.shape === 'rect') {
            ctx.fillRect(-this.w / 2, -this.h / 2, this.w, this.h);
        } else {
            ctx.beginPath();
            ctx.arc(0, 0, this.w / 2, 0, Math.PI * 2);
            ctx.fill();
        }

        ctx.restore();
    };

    // Create particles in waves
    function createWave(count) {
        for (var i = 0; i < count; i++) {
            particles.push(new Particle());
        }
    }

    createWave(totalParticles);

    // Second wave after a short delay
    setTimeout(function() { createWave(60); }, 600);

    var startTime = Date.now();
    var duration = 4500; // 4.5 seconds

    function animate() {
        var elapsed = Date.now() - startTime;

        ctx.clearRect(0, 0, W, H);

        var alive = false;
        for (var i = 0; i < particles.length; i++) {
            particles[i].update();
            if (particles[i].opacity > 0) {
                particles[i].draw();
                alive = true;
            }
        }

        if (alive && elapsed < duration) {
            requestAnimationFrame(animate);
        } else {
            // Fade out canvas
            canvas.style.transition = 'opacity 0.8s';
            canvas.style.opacity = '0';
            setTimeout(function() {
                canvas.remove();
            }, 800);
        }
    }

    animate();

    // Handle window resize
    window.addEventListener('resize', function() {
        W = window.innerWidth;
        H = window.innerHeight;
        canvas.width = W;
        canvas.height = H;
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
