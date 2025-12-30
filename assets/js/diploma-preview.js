/**
 * Diploma Template Selection
 * Handles template selection, tab switching, and live preview
 */

$(document).ready(function() {
    let selectedTemplateId = null;

    console.log('Diploma preview script loaded');

    // Initialize first template as selected
    const firstTemplate = $('.diploma-gallery-item.active').first();
    if (firstTemplate.length) {
        selectedTemplateId = firstTemplate.data('template-id');
        $('#selectedTemplateId').val(selectedTemplateId);
        console.log('Initial template selected:', selectedTemplateId);
    } else {
        console.warn('No active template found');
    }

    // Tab Switching
    $('.diploma-tab').on('click', function() {
        const targetTab = $(this).data('tab');

        // Update tab buttons
        $('.diploma-tab').removeClass('active');
        $(this).addClass('active');

        // Update tab content
        $('.diploma-tab-content').removeClass('active');
        if (targetTab === 'participant') {
            $('#participantForm').addClass('active');
            $('#currentTab').val('participant');
        } else {
            $('#supervisorForm').addClass('active');
            $('#currentTab').val('supervisor');
        }

        // Update preview when switching tabs
        updateDiplomaPreview();
    });

    // Template Gallery Selection
    $('.diploma-gallery-item').on('click', function() {
        // Remove previous selection
        $('.diploma-gallery-item').removeClass('active');

        // Mark as selected
        $(this).addClass('active');

        // Store template ID
        selectedTemplateId = $(this).data('template-id');
        $('#selectedTemplateId').val(selectedTemplateId);

        // Update preview (placeholder - will be replaced with actual preview)
        updateDiplomaPreview();
    });

    // Update diploma preview based on form data
    function updateDiplomaPreview() {
        if (!selectedTemplateId) {
            return;
        }

        const previewImg = $('#diplomaPreview');
        const currentTab = $('#currentTab').val();

        // Get competition_id from hidden input
        const competitionId = $('input[name="competition_id"]').val();

        // Collect form data based on current tab
        let formData = {
            template_id: selectedTemplateId,
            competition_id: competitionId
        };

        if (currentTab === 'participant') {
            formData = {
                ...formData,
                fio: $('#fio').val(),
                email: $('#email').val(),
                organization: $('#organization').val(),
                city: $('#city').val(),
                supervisor_name: $('#supervisor_name').val(),
                nomination: $('#nomination').val(),
                competition_type: $('#competition_type').val(),
                work_title: $('#work_title').val(),
                placement: $('#placement').val(),
                participation_date: $('#participation_date').val()
            };
        } else {
            formData = {
                ...formData,
                fio: $('input[name="supervisor_name_alt"]').val(),
                email: $('#supervisor_email').val(),
                organization: $('#supervisor_organization').val(),
                city: $('input[name="supervisor_city"]').val(),
                nomination: $('select[name="supervisor_nomination"]').val(),
                competition_type: $('select[name="supervisor_competition_type"]').val(),
                work_title: $('input[name="supervisor_work_title"]').val(),
                participation_date: $('input[name="supervisor_participation_date"]').val()
            };
        }

        // Send AJAX request to generate preview with real data
        $.ajax({
            url: '/ajax/preview-diploma.php',
            method: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    previewImg.attr('src', response.preview_url);
                } else {
                    console.warn('Preview generation failed:', response.message);
                    // Fallback to template image
                    const templatePath = '/assets/images/diplomas/templates/diploma-template-' + selectedTemplateId + '.svg';
                    previewImg.attr('src', templatePath + '?t=' + new Date().getTime());
                }
            },
            error: function(xhr, status, error) {
                console.error('Preview AJAX error:', error);
                // Fallback to template image
                const templatePath = '/assets/images/diplomas/templates/diploma-template-' + selectedTemplateId + '.svg';
                previewImg.attr('src', templatePath + '?t=' + new Date().getTime());
            }
        });
    }

    // Live preview on input change
    const participantFields = [
        '#fio', '#email', '#organization', '#city',
        '#supervisor_name', '#work_title', '#nomination',
        '#competition_type', '#placement', '#participation_date'
    ];

    const supervisorFields = [
        '#supervisor_email', 'input[name="supervisor_name_alt"]',
        '#supervisor_organization', 'input[name="supervisor_city"]',
        'select[name="supervisor_nomination"]', 'select[name="supervisor_competition_type"]',
        'input[name="supervisor_work_title"]', 'input[name="supervisor_participation_date"]'
    ];

    const allFields = [...participantFields, ...supervisorFields];

    allFields.forEach(field => {
        $(document).on('input change', field, function() {
            // Debounce preview update
            clearTimeout(window.previewTimeout);
            window.previewTimeout = setTimeout(() => {
                updateDiplomaPreview();
            }, 500);
        });
    });

    // Nomination helper links
    $('#selectNominationLink').on('click', function(e) {
        e.preventDefault();
        $('#nomination').focus();
    });

    $('#enterNominationLink').on('click', function(e) {
        e.preventDefault();
        // Convert select to input for custom nomination
        const currentValue = $('#nomination').val();
        const $select = $('#nomination');
        const $input = $('<input>', {
            type: 'text',
            class: 'form-control',
            id: 'nomination',
            name: 'nomination',
            placeholder: 'Введите свою номинацию',
            value: currentValue
        });

        $select.replaceWith($input);
        $input.focus();
    });

    // Legacy support for old template system
    $('.template-item').on('click', function() {
        // Remove previous selection
        $('.template-item').removeClass('selected');

        // Mark as selected
        $(this).addClass('selected');

        // Store template ID
        selectedTemplateId = $(this).data('template-id');
        $('#selectedTemplateId').val(selectedTemplateId);

        // Enable next button
        $('#nextToForm').prop('disabled', false);

        // Add subtle animation
        $(this).addClass('animated');
        setTimeout(() => {
            $(this).removeClass('animated');
        }, 300);
    });

    // Next to form button (legacy)
    $('#nextToForm').on('click', function() {
        if (!selectedTemplateId) {
            alert('Пожалуйста, выберите дизайн диплома');
            return;
        }

        // Update progress steps
        $('.step[data-step="1"]').removeClass('active').addClass('completed');
        $('.step[data-step="2"]').addClass('active');

        // Switch content
        $('#step1').removeClass('active');
        $('#step2').addClass('active');

        // Scroll to top
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // Back to templates button (legacy)
    $('#backToTemplates').on('click', function() {
        // Update progress steps
        $('.step[data-step="2"]').removeClass('active');
        $('.step[data-step="1"]').removeClass('completed').addClass('active');

        // Switch content
        $('#step2').removeClass('active');
        $('#step1').addClass('active');

        // Scroll to top
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // Supervisor checkbox toggle (legacy)
    $('#hasSupervisor').on('change', function() {
        if ($(this).is(':checked')) {
            $('#supervisorSection').addClass('active').slideDown(300);
        } else {
            $('#supervisorSection').removeClass('active').slideUp(300);
            // Clear supervisor fields
            $('#supervisor_name, #supervisor_email, #supervisor_organization').val('');
        }
    });

    // Add CSS animation class
    const style = document.createElement('style');
    style.textContent = `
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        .template-item.animated {
            animation: pulse 0.3s ease-in-out;
        }
    `;
    document.head.appendChild(style);
});
