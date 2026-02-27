    </main>

    <footer class="footer">
        <div class="container">
            <div class="footer-container">
                <div class="footer-column">
                    <h4>О портале</h4>
                    <p><?php echo SITE_NAME ?? 'Педагогический портал'; ?> - платформа для проведения всероссийских и международных конкурсов для педагогов и школьников.</p>
                </div>

                <div class="footer-column">
                    <h4>Конкурсы</h4>
                    <p>
                        <a href="/konkursy/metodika">Методические разработки</a><br>
                        <a href="/konkursy/vneurochnaya">Внеурочная деятельность</a><br>
                        <a href="/konkursy/proekty">Проекты учащихся</a><br>
                        <a href="/konkursy/tvorchestvo">Творческие конкурсы</a>
                    </p>
                </div>

                <div class="footer-column">
                    <h4>Вебинары</h4>
                    <p>
                        <a href="/pages/webinars.php">Все вебинары</a><br>
                        <a href="/pages/webinars.php?upcoming=1">Ближайшие вебинары</a><br>
                        <a href="/pages/webinars.php?archive=1">Архив записей</a>
                    </p>
                </div>

                <div class="footer-column">
                    <h4>Публикации</h4>
                    <p>
                        <a href="/pages/journal.php">Журнал публикаций</a><br>
                        <a href="/pages/submit-publication.php">Опубликовать материал</a><br>
                        <a href="/pages/publication-certificate.php">Получить сертификат</a>
                    </p>
                </div>

                <div class="footer-column">
                    <h4>Помощь</h4>
                    <p>
                        <a href="/svedeniya/">Сведения об организации</a><br>
                        <a href="/pages/faq.php">Частые вопросы</a><br>
                        <a href="/pages/contacts.php">Контакты</a><br>
                        <a href="/pages/terms.php">Пользовательское соглашение</a><br>
                        <a href="/pages/privacy.php">Политика конфиденциальности</a>
                    </p>
                </div>

                <div class="footer-column">
                    <h4>Контакты</h4>
                    <p>
                        Тех. поддержка: <a href="tel:+79223044413">+7 (922) 304-44-13</a><br>
                        Email: <a href="mailto:info@fgos.pro">info@fgos.pro</a><br>
                        Работаем ежедневно с 9:00 до 21:00
                    </p>
                </div>
            </div>

            <div class="footer-requisites">
                <div class="requisites-content">
                    <div class="requisites-column">
                        <strong>ООО «Едурегионлаб»</strong><br>
                        ИНН 5904368615 / КПП 773101001<br>
                        121205, Россия, г. Москва, вн.тер.г. Муниципальный округ Можайский,<br>
                        тер. Инновационного центра Сколково, б-р Большой, д. 42, стр. 1
                    </div>
                    <div class="requisites-column">
                        <strong>Банковские реквизиты:</strong><br>
                        р/с 40702810049770043643<br>
                        Волго-Вятский банк ПАО Сбербанк<br>
                        БИК 042202603 / к/с 30101810900000000603
                    </div>
                    <div class="requisites-column">
                        <strong>Лицензия:</strong><br>
                        № Л035-01212-59/00203856<br>
                        от 17.12.2021 г.
                    </div>
                </div>
            </div>

            <div class="footer-bottom">
                &copy; <?php echo date('Y'); ?> <?php echo SITE_NAME ?? 'Педагогический портал'; ?>. Все права защищены.
            </div>
        </div>
    </footer>

    <!-- E-commerce DataLayer Init -->
    <script>
    window.dataLayer = window.dataLayer || [];
    </script>

    <!-- Deferred E-commerce Purchase Tracking -->
    <!-- Догоняет purchase события для заказов, где пользователь закрыл вкладку до подтверждения оплаты -->
    <script>
    (function() {
        try {
            var pendingOrders = JSON.parse(localStorage.getItem('pending_ecommerce_orders') || '[]');
            if (!pendingOrders.length) return;

            var remaining = [];
            var processed = 0;
            var total = pendingOrders.length;

            pendingOrders.forEach(function(orderNum) {
                fetch('/api/get-ecommerce-data.php?order_number=' + encodeURIComponent(orderNum))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.ecommerce) {
                            // Отправить purchase событие в dataLayer
                            window.dataLayer.push({ "ecommerce": data.ecommerce });
                        } else if (data.error === 'Order not succeeded') {
                            // Заказ ещё обрабатывается — оставить в списке для следующей попытки
                            remaining.push(orderNum);
                        }
                        // Для других ошибок (not found, access denied) — убрать из списка
                    })
                    .catch(function() {
                        // Сетевая ошибка — оставить для повторной попытки
                        remaining.push(orderNum);
                    })
                    .finally(function() {
                        processed++;
                        if (processed === total) {
                            if (remaining.length) {
                                localStorage.setItem('pending_ecommerce_orders', JSON.stringify(remaining));
                            } else {
                                localStorage.removeItem('pending_ecommerce_orders');
                            }
                        }
                    });
            });
        } catch(e) {}
    })();
    </script>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/main.js"></script>
    <script src="/assets/js/search.js"></script>
    <script src="/assets/js/hero-parallax.js"></script>
    <?php if (isset($additionalJS)): ?>
        <?php foreach ($additionalJS as $js): ?>
            <script src="<?php echo $js; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
