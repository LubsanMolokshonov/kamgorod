// @ts-check

// Шум сторонних трекеров/ресурсов: их CORS/загрузочные ошибки не относятся к
// коду портала и не должны валить проверки «нет JS-ошибок» (особенно в webkit).
const NOISE = [
  /mc\.yandex\.ru/i,
  /yandex\.ru\/watch/i,
  /google-analytics|googletagmanager|gtag/i,
  /doubleclick|googlesyndication/i,
  /vk\.com|top-fwz1|mail\.ru|mc\.webvisor/i,
  /access control checks/i,
  /Failed to load resource/i,
];

function isAppError(text) {
  return !NOISE.some((re) => re.test(text));
}

// Подключает сбор ТОЛЬКО первопартийных JS-ошибок страницы.
function collectAppErrors(page) {
  const errors = [];
  page.on('pageerror', (e) => {
    const t = String(e);
    if (isAppError(t)) errors.push(t);
  });
  page.on('console', (m) => {
    if (m.type() === 'error' && isAppError(m.text())) errors.push(m.text());
  });
  return errors;
}

module.exports = { collectAppErrors, isAppError };
