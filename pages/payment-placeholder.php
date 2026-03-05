<?php
/**
 * Payment Placeholder Page
 * Temporary page until Phase 5 (Yookassa) is implemented
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Registration.php';
require_once __DIR__ . '/../includes/session.php';

$registrationObj = new Registration($db);
$cartData = $registrationObj->calculateCartTotal($_SESSION['cart'] ?? []);

$pageTitle = 'Оплата | ' . SITE_NAME;
$noindex = true;
include __DIR__ . '/../includes/header.php';
?>

<style>
.placeholder-container {
    max-width: 700px;
    margin: 60px auto;
    padding: 48px;
    background: white;
    border-radius: var(--border-radius-card);
    box-shadow: var(--shadow-card);
    text-align: center;
}

.placeholder-icon {
    font-size: 80px;
    margin-bottom: 24px;
}

.info-box {
    background: #fff9f0;
    border-left: 4px solid #ff9800;
    padding: 20px;
    border-radius: 12px;
    margin: 24px 0;
    text-align: left;
}

.progress-list {
    text-align: left;
    padding-left: 24px;
    margin: 24px 0;
}

.progress-list li {
    margin-bottom: 12px;
    color: var(--text-medium);
}

.progress-list li.completed {
    color: #10b981;
}

.progress-list li.current {
    color: var(--primary-purple);
    font-weight: 600;
}

.progress-list li.pending {
    color: var(--text-medium);
    opacity: 0.5;
}
</style>

<div class="container">
    <div class="placeholder-container">
        <div class="placeholder-icon">💳</div>
        <h1>Интеграция оплаты</h1>
        <p style="font-size: 18px; color: var(--text-medium); margin-bottom: 24px;">
            Функционал оплаты будет доступен в <strong>Фазе 5</strong>
        </p>

        <div class="info-box">
            <strong>📋 Что уже работает:</strong>
            <ul class="progress-list">
                <li class="completed">✓ Выбор конкурса</li>
                <li class="completed">✓ Регистрация с выбором шаблона диплома</li>
                <li class="completed">✓ Корзина с акцией "2+1 бесплатно"</li>
                <li class="completed">✓ Расчет итоговой суммы: <strong><?php echo number_format($cartData['total'], 0, ',', ' '); ?> ₽</strong></li>
                <li class="current">⏳ Оплата через ЮКасса (Фаза 5)</li>
                <li class="pending">○ Личный кабинет (Фаза 6)</li>
                <li class="pending">○ Генерация PDF дипломов (Фаза 7)</li>
            </ul>
        </div>

        <h3 style="margin-top: 32px; color: var(--primary-purple);">Что будет в Фазе 5?</h3>
        <div style="text-align: left; margin-top: 16px;">
            <ol style="padding-left: 24px; color: var(--text-medium);">
                <li style="margin-bottom: 8px;">Создание заказа в базе данных</li>
                <li style="margin-bottom: 8px;">Интеграция с ЮКасса SDK</li>
                <li style="margin-bottom: 8px;">Перенаправление на страницу оплаты ЮКасса</li>
                <li style="margin-bottom: 8px;">Обработка webhook от ЮКасса</li>
                <li style="margin-bottom: 8px;">Обновление статусов регистраций после успешной оплаты</li>
                <li style="margin-bottom: 8px;">Email-уведомления</li>
            </ol>
        </div>

        <div style="margin-top: 40px; display: flex; gap: 16px; justify-content: center;">
            <a href="/pages/cart.php" class="btn btn-back">← Вернуться в корзину</a>
            <a href="/index.php" class="btn btn-primary">Выбрать другие конкурсы</a>
        </div>

        <p style="margin-top: 24px; font-size: 14px; color: var(--text-medium);">
            <strong>Для разработчиков:</strong> Файл <code>ajax/create-payment.php</code> будет реализован в Фазе 5
        </p>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
