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
                        <a href="/index.php?category=methodology">Методические разработки</a><br>
                        <a href="/index.php?category=extracurricular">Внеурочная деятельность</a><br>
                        <a href="/index.php?category=student_projects">Проекты учащихся</a><br>
                        <a href="/index.php?category=creative">Творческие конкурсы</a>
                    </p>
                </div>

                <div class="footer-column">
                    <h4>Помощь</h4>
                    <p>
                        <a href="/pages/faq.php">Частые вопросы</a><br>
                        <a href="/pages/contacts.php">Контакты</a><br>
                        <a href="/pages/privacy.php">Политика конфиденциальности</a><br>
                        <a href="/pages/terms.php">Условия использования</a>
                    </p>
                </div>

                <div class="footer-column">
                    <h4>Контакты</h4>
                    <p>
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/main.js"></script>
    <script src="/assets/js/hero-parallax.js"></script>
    <?php if (isset($additionalJS)): ?>
        <?php foreach ($additionalJS as $js): ?>
            <script src="<?php echo $js; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
