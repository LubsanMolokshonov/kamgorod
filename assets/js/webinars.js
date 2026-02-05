/**
 * Webinars JavaScript
 * Countdown timer and registration form handling
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize countdown timer
    initCountdown();

    // Initialize phone mask
    initPhoneMask();

    // Initialize registration form
    initRegistrationForm();

    // Initialize smooth scroll for CTA button
    initSmoothScroll();
});

/**
 * Phone Input Mask
 */
function initPhoneMask() {
    const phoneInput = document.getElementById('phone');
    if (!phoneInput) return;

    phoneInput.addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');

        // If starts with 8, replace with 7
        if (value.startsWith('8')) {
            value = '7' + value.substring(1);
        }

        // Limit to 11 digits
        if (value.length > 11) {
            value = value.substring(0, 11);
        }

        // Format the number
        let formatted = '+7';
        if (value.length > 1) {
            formatted += ' (' + value.substring(1, 4);
        }
        if (value.length >= 4) {
            formatted += ') ' + value.substring(4, 7);
        }
        if (value.length >= 7) {
            formatted += '-' + value.substring(7, 9);
        }
        if (value.length >= 9) {
            formatted += '-' + value.substring(9, 11);
        }

        e.target.value = formatted;
    });

    // Set initial value
    if (!phoneInput.value || phoneInput.value.trim() === '') {
        phoneInput.value = '+7 ';
    }

    // Prevent deleting country code
    phoneInput.addEventListener('keydown', function(e) {
        if ((e.key === 'Backspace' || e.key === 'Delete') &&
            (e.target.selectionStart <= 3 || e.target.value.length <= 3)) {
            e.preventDefault();
            e.target.value = '+7 ';
        }
    });

    // Handle focus
    phoneInput.addEventListener('focus', function(e) {
        if (!e.target.value || e.target.value === '+7') {
            e.target.value = '+7 ';
        }
        // Set cursor after +7
        setTimeout(() => {
            e.target.setSelectionRange(3, 3);
        }, 0);
    });
}

/**
 * Smooth Scroll to Registration Form
 */
function initSmoothScroll() {
    const ctaButton = document.querySelector('.btn-hero-cta');
    if (!ctaButton) return;

    ctaButton.addEventListener('click', function(e) {
        e.preventDefault();
        const targetId = this.getAttribute('href');
        const targetSection = document.querySelector(targetId);

        if (targetSection) {
            targetSection.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
}

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

        // Validate consents first (152-FZ compliance)
        if (typeof window.ConsentValidation !== 'undefined') {
            if (!window.ConsentValidation.validate()) {
                // Consent validation failed, scroll to consents
                window.ConsentValidation.scrollToConsents();
                return;
            }
        }

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

        // Add consent data to FormData (152-FZ compliance)
        if (typeof window.ConsentValidation !== 'undefined') {
            window.ConsentValidation.addToFormData(formData);
        }

        try {
            const response = await fetch('/ajax/register-webinar.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                // Build success message
                let successHtml = data.already_registered
                    ? 'Вы уже зарегистрированы на этот вебинар!'
                    : '<strong>Вы успешно зарегистрированы!</strong><br>На указанный email придет письмо с подтверждением.';

                // Add cabinet info for new users
                if (!data.already_registered && data.cabinet_created) {
                    successHtml += '<br><br><strong>Создан личный кабинет!</strong><br>' +
                        'Теперь вы можете отслеживать все ваши вебинары и конкурсы.';
                }

                // Add button to cabinet
                const cabinetUrl = data.cabinet_url || '/pages/cabinet.php?tab=webinars';
                successHtml += '<div style="margin-top: 16px;">' +
                    '<a href="' + cabinetUrl + '" class="btn btn-outline" style="display: inline-block;">Перейти в личный кабинет</a></div>';

                // Show success message
                formMessage.className = 'form-message success';
                formMessage.innerHTML = successHtml;
                formMessage.style.display = 'block';

                // Hide form fields
                if (!data.already_registered) {
                    form.querySelectorAll('.form-group').forEach(el => el.style.display = 'none');
                    submitBtn.style.display = 'none';
                    const formNote = form.querySelector('.form-note');
                    if (formNote) formNote.style.display = 'none';

                    // Hide consent checkbox
                    const formCheckbox = form.querySelector('.form-checkbox');
                    if (formCheckbox) formCheckbox.style.display = 'none';

                    // Hide consent container
                    const consentContainer = form.querySelector('.consent-container');
                    if (consentContainer) consentContainer.style.display = 'none';
                }

                // Track conversion (Yandex Metrika)
                if (typeof ym !== 'undefined') {
                    ym(106465857, 'reachGoal', 'webinar_registration');

                    // Separate goal for cabinet creation
                    if (data.cabinet_created) {
                        ym(106465857, 'reachGoal', 'cabinet_created_webinar');
                    }
                }
            } else {
                // Show error message
                formMessage.className = 'form-message error';
                formMessage.textContent = data.message || 'Произошла ошибка. Попробуйте позже.';
                formMessage.style.display = 'block';

                // Re-enable button
                submitBtn.disabled = false;
                submitBtn.textContent = 'Зарегистрироваться';
            }
        } catch (error) {
            console.error('Registration error:', error);
            formMessage.className = 'form-message error';
            formMessage.textContent = 'Ошибка соединения. Проверьте интернет и попробуйте снова.';
            formMessage.style.display = 'block';

            // Re-enable button
            submitBtn.disabled = false;
            submitBtn.textContent = 'Зарегистрироваться';
        }
    });
}
