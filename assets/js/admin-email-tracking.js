// E-mail трекинг — поведение дашборда
// Форма фильтров — стандартный submit (перезагрузка страницы). Остальное — опциональный UX.
document.addEventListener('DOMContentLoaded', function () {
    // Сохранение текущего фильтра при пагинации (если появится)
    const form = document.getElementById('emailFilterForm');
    if (!form) return;

    // Сброс: чистим query-string
    const resetBtn = form.querySelector('a.btn-secondary');
    if (resetBtn) {
        resetBtn.addEventListener('click', function (e) {
            e.preventDefault();
            window.location.href = '/admin/email-tracking/';
        });
    }
});
