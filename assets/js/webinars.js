/**
 * Webinars JavaScript
 * Countdown timer and registration form handling
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize countdown timer
    initCountdown();

    // Initialize registration form
    initRegistrationForm();
});

/**
 * Countdown Timer
 */
function initCountdown() {
    const countdownEl = document.getElementById('countdown');
    if (!countdownEl) return;

    const targetDate = new Date(countdownEl.dataset.target).getTime();

    function updateCountdown() {
        const now = new Date().getTime();
        const distance = targetDate - now;

        if (distance < 0) {
            document.getElementById('countdown-days').textContent = '0';
            document.getElementById('countdown-hours').textContent = '0';
            document.getElementById('countdown-minutes').textContent = '0';
            document.getElementById('countdown-seconds').textContent = '0';
            return;
        }

        const days = Math.floor(distance / (1000 * 60 * 60 * 24));
        const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((distance % (1000 * 60)) / 1000);

        document.getElementById('countdown-days').textContent = days;
        document.getElementById('countdown-hours').textContent = hours;
        document.getElementById('countdown-minutes').textContent = minutes;
        document.getElementById('countdown-seconds').textContent = seconds;
    }

    updateCountdown();
    setInterval(updateCountdown, 1000);
}

/**
 * Registration Form
 */
function initRegistrationForm() {
    const form = document.getElementById('webinarRegistrationForm');
    if (!form) return;

    const submitBtn = document.getElementById('submitBtn');
    const formMessage = document.getElementById('formMessage');

    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        // Disable button
        submitBtn.disabled = true;
        submitBtn.textContent = 'Регистрация...';

        // Clear previous messages
        formMessage.className = 'form-message';
        formMessage.style.display = 'none';

        // Collect form data
        const formData = new FormData(form);

        // Add UTM parameters from URL
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('utm_source')) formData.append('utm_source', urlParams.get('utm_source'));
        if (urlParams.get('utm_medium')) formData.append('utm_medium', urlParams.get('utm_medium'));
        if (urlParams.get('utm_campaign')) formData.append('utm_campaign', urlParams.get('utm_campaign'));

        try {
            const response = await fetch('/ajax/register-webinar.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                // Show success message
                formMessage.className = 'form-message success';
                formMessage.innerHTML = data.already_registered
                    ? 'Вы уже зарегистрированы на этот вебинар!'
                    : '<strong>Вы успешно зарегистрированы!</strong><br>На указанный email придет письмо с подтверждением.';
                formMessage.style.display = 'block';

                // Hide form fields
                if (!data.already_registered) {
                    form.querySelectorAll('.form-group').forEach(el => el.style.display = 'none');
                    submitBtn.style.display = 'none';
                    form.querySelector('.form-note').style.display = 'none';
                }

                // Track conversion (Yandex Metrika)
                if (typeof ym !== 'undefined') {
                    ym(106465857, 'reachGoal', 'webinar_registration');
                }
            } else {
                // Show error message
                formMessage.className = 'form-message error';
                formMessage.textContent = data.message || 'Произошла ошибка. Попробуйте позже.';
                formMessage.style.display = 'block';

                // Re-enable button
                submitBtn.disabled = false;
                submitBtn.textContent = 'Зарегистрироваться бесплатно';
            }
        } catch (error) {
            console.error('Registration error:', error);
            formMessage.className = 'form-message error';
            formMessage.textContent = 'Ошибка соединения. Проверьте интернет и попробуйте снова.';
            formMessage.style.display = 'block';

            // Re-enable button
            submitBtn.disabled = false;
            submitBtn.textContent = 'Зарегистрироваться бесплатно';
        }
    });
}
