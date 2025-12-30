/**
 * Form Validation
 * Client-side validation and AJAX submission
 */

$(document).ready(function() {
    // Email validation on blur
    $('#email').on('blur', function() {
        const email = $(this).val().trim();

        if (email.length === 0) {
            return;
        }

        // Basic validation
        if (!validateEmail(email)) {
            $(this).addClass('error');
            $(this).next('.helper-text').hide();
            $(this).siblings('.error-message').text('Некорректный email адрес').show();
            return;
        }

        // AJAX check
        $.ajax({
            url: '/ajax/validate-email.php',
            type: 'GET',
            data: { email: encodeURIComponent(email) },
            success: function(response) {
                $('#email').removeClass('error');
                $('#email').next('.helper-text').show();
                $('#email').siblings('.error-message').hide();

                if (response.exists && response.user) {
                    // User exists, ask to pre-fill
                    if (confirm('Этот email уже зарегистрирован. Загрузить ваши данные?')) {
                        prefillUserData(response.user);
                    }
                }
            },
            error: function() {
                console.log('Email validation failed');
            }
        });
    });

    // Phone number formatting
    $('#phone').on('input', function() {
        let value = $(this).val().replace(/\D/g, '');

        if (value.length > 0) {
            if (value[0] === '8') {
                value = '7' + value.substring(1);
            }

            let formatted = '+7';
            if (value.length > 1) {
                formatted += ' (' + value.substring(1, 4);
            }
            if (value.length >= 5) {
                formatted += ') ' + value.substring(4, 7);
            }
            if (value.length >= 8) {
                formatted += '-' + value.substring(7, 9);
            }
            if (value.length >= 10) {
                formatted += '-' + value.substring(9, 11);
            }

            $(this).val(formatted);
        }
    });

    // Form submission
    $('#registrationForm').on('submit', function(e) {
        e.preventDefault();

        // Validate required fields
        let isValid = true;

        // Clear previous errors
        $('.form-control').removeClass('error');
        $('.error-message').hide();

        // Validate FIO
        const fio = $('#fio').val().trim();
        if (fio.length === 0 || fio.length > 55) {
            $('#fio').addClass('error');
            $('#fio').siblings('.error-message').text('ФИО обязательно (не более 55 символов)').show();
            isValid = false;
        }

        // Validate email
        const email = $('#email').val().trim();
        if (!validateEmail(email)) {
            $('#email').addClass('error');
            $('#email').siblings('.error-message').text('Некорректный email адрес').show();
            isValid = false;
        }

        // Validate organization
        const organization = $('#organization').val().trim();
        if (organization.length === 0) {
            $('#organization').addClass('error');
            $('#organization').siblings('.error-message').show();
            isValid = false;
        }

        // Validate nomination
        const nomination = $('#nomination').val();
        if (!nomination) {
            $('#nomination').addClass('error');
            $('#nomination').siblings('.error-message').show();
            isValid = false;
        }

        // Validate date
        const date = $('#participation_date').val();
        if (!date) {
            $('#participation_date').addClass('error');
            $('#participation_date').siblings('.error-message').show();
            isValid = false;
        }

        // Validate template selection
        const templateId = $('#selectedTemplateId').val();
        if (!templateId) {
            alert('Пожалуйста, выберите дизайн диплома из галереи');
            isValid = false;
            // Scroll to gallery
            $('html, body').animate({
                scrollTop: $('.diploma-gallery').offset().top - 100
            }, 300);
        }

        if (!isValid) {
            // Scroll to first error
            const firstError = $('.form-control.error').first();
            if (firstError.length) {
                $('html, body').animate({
                    scrollTop: firstError.offset().top - 100
                }, 300);
            }
            return false;
        }

        // Show loading overlay
        $('#loadingOverlay').addClass('active');
        $('body').css('overflow', 'hidden');

        // Submit via AJAX
        const formData = new FormData(this);

        $.ajax({
            url: '/ajax/save-registration.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    // Redirect to cart
                    window.location.href = '/pages/cart.php';
                } else {
                    // Show error
                    alert(response.message || 'Произошла ошибка при регистрации');
                    $('#loadingOverlay').removeClass('active');
                    $('body').css('overflow', 'auto');
                }
            },
            error: function(xhr, status, error) {
                console.error('Registration error:', error);
                alert('Произошла ошибка. Попробуйте снова.');
                $('#loadingOverlay').removeClass('active');
                $('body').css('overflow', 'auto');
            }
        });
    });

    // Helper functions
    function validateEmail(email) {
        // Check basic format
        const regex = /^[a-zA-Z0-9._%-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        if (!regex.test(email)) {
            return false;
        }

        // Check for Cyrillic
        if (/[А-Яа-яЁё]/u.test(email)) {
            return false;
        }

        return true;
    }

    function prefillUserData(user) {
        if (user.full_name) $('#fio').val(user.full_name);
        if (user.phone) $('#phone').val(user.phone);
        if (user.city) $('#city').val(user.city);
        if (user.organization) $('#organization').val(user.organization);

        // Show notification
        const notification = $('<div>')
            .css({
                position: 'fixed',
                top: '20px',
                right: '20px',
                background: 'var(--primary-purple)',
                color: 'white',
                padding: '16px 24px',
                borderRadius: '12px',
                boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                zIndex: 99999,
                fontSize: '14px'
            })
            .text('Данные загружены из профиля')
            .appendTo('body');

        setTimeout(() => {
            notification.fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    }

    // Real-time validation on input
    $('.form-control').on('input', function() {
        if ($(this).hasClass('error')) {
            $(this).removeClass('error');
            $(this).siblings('.error-message').hide();
            if ($(this).siblings('.helper-text').length) {
                $(this).siblings('.helper-text').show();
            }
        }
    });
});
