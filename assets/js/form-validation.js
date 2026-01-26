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
                // Email validation failed silently
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

        console.log('Form submission started');

        // Validate required fields
        let isValid = true;
        let errors = [];

        // Clear previous errors
        $('.form-control').removeClass('error');
        $('.error-message').hide();

        // Get current tab
        const currentTab = $('#currentTab').val();
        console.log('Current tab:', currentTab);

        if (currentTab === 'participant') {
            // Validate FIO
            const fio = $('#fio').val().trim();
            if (fio.length === 0 || fio.length > 55) {
                $('#fio').addClass('error');
                $('#fio').siblings('.error-message').text('ФИО обязательно (не более 55 символов)').show();
                errors.push('ФИО');
                isValid = false;
            }

            // Validate email
            const email = $('#email').val().trim();
            if (!validateEmail(email)) {
                $('#email').addClass('error');
                $('#email').siblings('.error-message').text('Некорректный email адрес').show();
                errors.push('Email');
                isValid = false;
            }

            // Validate organization
            const organization = $('#organization').val().trim();
            if (organization.length === 0) {
                $('#organization').addClass('error');
                $('#organization').siblings('.error-message').show();
                errors.push('Учреждение');
                isValid = false;
            }

            // Validate city
            const city = $('#city').val().trim();
            if (city.length === 0) {
                $('#city').addClass('error');
                $('#city').siblings('.error-message').show();
                errors.push('Населенный пункт');
                isValid = false;
            }

            // Validate placement
            const placement = $('#placement').val();
            if (!placement) {
                $('#placement').addClass('error');
                $('#placement').siblings('.error-message').show();
                errors.push('Место');
                isValid = false;
            }

            // Validate competition type
            const competitionType = $('#competition_type').val();
            if (!competitionType) {
                $('#competition_type').addClass('error');
                $('#competition_type').siblings('.error-message').show();
                errors.push('Тип конкурса');
                isValid = false;
            }

            // Validate nomination
            const nomination = $('#nomination').val();
            if (!nomination) {
                $('#nomination').addClass('error');
                $('#nomination').siblings('.error-message').show();
                errors.push('Номинация');
                isValid = false;
            }

            // Validate date
            const date = $('#participation_date').val();
            if (!date) {
                $('#participation_date').addClass('error');
                $('#participation_date').siblings('.error-message').show();
                errors.push('Дата участия');
                isValid = false;
            }

            // Validate template selection
            const templateId = $('#selectedTemplateId').val();
            if (!templateId) {
                alert('Пожалуйста, выберите дизайн диплома из галереи');
                errors.push('Шаблон диплома');
                isValid = false;
                // Scroll to gallery
                $('html, body').animate({
                    scrollTop: $('.diploma-gallery').offset().top - 100
                }, 300);
            }

            if (!isValid) {
                console.log('Validation errors:', errors);
            }
        } else if (currentTab === 'supervisor') {
            // Supervisor tab validation
            const supervisorFio = $('#supervisor_fio').val().trim();
            if (supervisorFio.length === 0 || supervisorFio.length > 55) {
                $('#supervisor_fio').addClass('error');
                $('#supervisor_fio').siblings('.error-message').text('ФИО обязательно (не более 55 символов)').show();
                errors.push('ФИО руководителя');
                isValid = false;
            }

            const supervisorEmail = $('#supervisor_email').val().trim();
            if (!validateEmail(supervisorEmail)) {
                $('#supervisor_email').addClass('error');
                $('#supervisor_email').siblings('.error-message').text('Некорректный email адрес').show();
                errors.push('Email руководителя');
                isValid = false;
            }

            const studentFio = $('#student_fio').val().trim();
            if (studentFio.length === 0 || studentFio.length > 55) {
                $('#student_fio').addClass('error');
                $('#student_fio').siblings('.error-message').text('ФИО обязательно (не более 55 символов)').show();
                errors.push('ФИО учащегося');
                isValid = false;
            }

            // Same validations as participant tab
            const supervisorOrg = $('#supervisor_organization').val().trim();
            if (supervisorOrg.length === 0) {
                $('#supervisor_organization').addClass('error');
                $('#supervisor_organization').siblings('.error-message').show();
                errors.push('Учреждение');
                isValid = false;
            }

            const supervisorCity = $('#supervisor_city').val().trim();
            if (supervisorCity.length === 0) {
                $('#supervisor_city').addClass('error');
                $('#supervisor_city').siblings('.error-message').show();
                errors.push('Населенный пункт');
                isValid = false;
            }

            const supervisorPlacement = $('#supervisor_placement').val();
            if (!supervisorPlacement) {
                $('#supervisor_placement').addClass('error');
                $('#supervisor_placement').siblings('.error-message').show();
                errors.push('Место');
                isValid = false;
            }

            const supervisorCompType = $('#supervisor_competition_type').val();
            if (!supervisorCompType) {
                $('#supervisor_competition_type').addClass('error');
                $('#supervisor_competition_type').siblings('.error-message').show();
                errors.push('Тип конкурса');
                isValid = false;
            }

            const supervisorNomination = $('#supervisor_nomination').val();
            if (!supervisorNomination) {
                $('#supervisor_nomination').addClass('error');
                $('#supervisor_nomination').siblings('.error-message').show();
                errors.push('Номинация');
                isValid = false;
            }

            const supervisorDate = $('#supervisor_participation_date').val();
            if (!supervisorDate) {
                $('#supervisor_participation_date').addClass('error');
                $('#supervisor_participation_date').siblings('.error-message').show();
                errors.push('Дата участия');
                isValid = false;
            }

            const supervisorTemplateId = $('#supervisor_selectedTemplateId').val();
            if (!supervisorTemplateId) {
                alert('Пожалуйста, выберите дизайн диплома из галереи');
                errors.push('Шаблон диплома');
                isValid = false;
                $('html, body').animate({
                    scrollTop: $('.diploma-gallery').offset().top - 100
                }, 300);
            }

            if (!isValid) {
                console.log('Validation errors:', errors);
            }
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
