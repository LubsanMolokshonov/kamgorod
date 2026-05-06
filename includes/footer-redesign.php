</main>

<footer class="rd-footer">
  <div class="rd-wrap rd-foot-grid">
    <div class="rd-foot-about">
      <a class="rd-logo rd-logo-foot" href="/" style="display:inline-flex;margin-bottom:16px;" aria-label="<?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?>">
        <img src="/assets/images/logo.svg" alt="<?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?>">
      </a>
      <p>Педагогический портал — платформа для проведения всероссийских и международных конкурсов для педагогов и школьников.</p>
      <p style="margin-top:16px;color:#c2cdff;">+7 (922) 304-44-13<br>info@fgos.pro<br>Ежедневно 9:00–21:00</p>
    </div>

    <div>
      <h5>Конкурсы</h5>
      <a href="/konkursy/metodika/">Методические разработки</a>
      <a href="/konkursy/vneurochnaya/">Внеурочная деятельность</a>
      <a href="/konkursy/proekty/">Проекты учащихся</a>
      <a href="/konkursy/tvorchestvo/">Творческие конкурсы</a>
    </div>

    <div>
      <h5>Олимпиады</h5>
      <a href="/olimpiady/">Все олимпиады</a>
      <a href="/olimpiady/pedagogi/dou/">Для педагогов ДОУ</a>
      <a href="/olimpiady/shkolnikam/">Для школьников</a>
      <a href="/olimpiady/doshkolnikam/">Для дошкольников</a>
      <h5 style="margin-top:24px;">Вебинары</h5>
      <a href="/vebinary/">Все вебинары</a>
      <a href="/vebinary/predstoyashchie/">Ближайшие</a>
      <a href="/vebinary/zapisi/">Архив записей</a>
    </div>

    <div>
      <h5>Публикации</h5>
      <a href="/zhurnal/">Журнал публикаций</a>
      <a href="/opublikovat/">Опубликовать материал</a>
      <a href="/sertifikat-publikacii/">Получить сертификат</a>
      <h5 style="margin-top:24px;">Курсы</h5>
      <a href="/kursy/povyshenie-kvalifikatsii/">Повышение квалификации</a>
      <a href="/kursy/perepodgotovka/">Профессиональная переподготовка</a>
      <a href="/kursy/">Все курсы</a>
    </div>

    <div>
      <h5>Помощь</h5>
      <a href="/svedeniya/">Сведения об организации</a>
      <a href="/pages/contacts.php">Контакты</a>
      <a href="/polzovatelskoe-soglashenie/">Пользовательское соглашение</a>
      <a href="/politika-konfidencialnosti/">Политика конфиденциальности</a>
      <a href="/oferta-kursy/">Оферта (курсы)</a>
      <a href="/oferta-meropriyatiya/">Оферта (мероприятия)</a>
    </div>
  </div>

  <div class="rd-wrap rd-foot-bot">
    <div class="rd-foot-req">
      <strong>ООО «Едурегионлаб»</strong> · ИНН 5904368615 / КПП 773101001<br>
      121205, Россия, г. Москва, вн.тер.г. Муниципальный округ Можайский,<br>
      тер. Инновационного центра Сколково, б-р Большой, д. 42, стр. 1 · Лицензия № Л035-01212-59/00203856 от 17.12.2021 г.
    </div>
    <div>© <?php echo date('Y'); ?> ФГОС.про. Все права защищены.</div>
  </div>
</footer>

<!-- E-commerce DataLayer -->
<script>window.dataLayer = window.dataLayer || [];</script>
<script>
(function() {
  try {
    var pendingOrders = JSON.parse(localStorage.getItem('pending_ecommerce_orders') || '[]');
    if (!pendingOrders.length) return;
    var remaining = [], processed = 0, total = pendingOrders.length;
    pendingOrders.forEach(function(orderNum) {
      fetch('/api/get-ecommerce-data.php?order_number=' + encodeURIComponent(orderNum))
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.success && data.ecommerce) window.dataLayer.push({ ecommerce: data.ecommerce });
          else if (data.error === 'Order not succeeded') remaining.push(orderNum);
        })
        .catch(function() { remaining.push(orderNum); })
        .finally(function() {
          processed++;
          if (processed === total) {
            if (remaining.length) localStorage.setItem('pending_ecommerce_orders', JSON.stringify(remaining));
            else localStorage.removeItem('pending_ecommerce_orders');
          }
        });
    });
  } catch(e) {}
})();
</script>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="/assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/main.js'); ?>" defer></script>
<script src="/assets/js/search.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/search.js'); ?>" defer></script>
<?php /* redesign.js уже подключён в includes/header.php — повторное подключение ломает обработчики (двойной клик на FAQ, табы и т.д.) */ ?>

<?php if (isset($additionalJS)): ?>
  <?php foreach ($additionalJS as $js): ?>
    <?php if (strpos($js, 'redesign.js') === false): // не дублируем ?>
  <script src="<?php echo $js; ?>" defer></script>
    <?php endif; ?>
  <?php endforeach; ?>
<?php endif; ?>

<?php
$aiConsultantSkip = preg_match('#^/(admin|ajax|api)(/|$)#', $_SERVER['REQUEST_URI'] ?? '');
if (!$aiConsultantSkip):
  $aicCss = __DIR__ . '/../assets/css/ai-consultant.css';
  $aicJs  = __DIR__ . '/../assets/js/ai-consultant.js';
?>
<link rel="stylesheet" href="/assets/css/ai-consultant.css?v=<?php echo file_exists($aicCss) ? filemtime($aicCss) : 1; ?>">
<script src="/assets/js/ai-consultant.js?v=<?php echo file_exists($aicJs) ? filemtime($aicJs) : 1; ?>" defer></script>
<div id="ai-consultant-root"></div>
<?php endif; ?>
</body>
</html>
