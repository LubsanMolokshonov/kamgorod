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
                        Email: <?php echo SMTP_FROM_EMAIL ?? 'info@example.com'; ?><br>
                        Телефон: +7 (XXX) XXX-XX-XX<br>
                        Работаем ежедневно с 9:00 до 21:00
                    </p>
                </div>
            </div>

            <div class="footer-bottom">
                &copy; <?php echo date('Y'); ?> <?php echo SITE_NAME ?? 'Педагогический портал'; ?>. Все права защищены.
            </div>
        </div>
    </footer>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/main.js"></script>
    <?php if (isset($additionalJS)): ?>
        <?php foreach ($additionalJS as $js): ?>
            <script src="<?php echo $js; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
