/**
 * Certificate Form Handler
 * Handles template selection, live AJAX preview, and form submission
 */

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('certificateForm');
    if (!form) return;

    // Template selection
    const templateItems = document.querySelectorAll('.template-item, .diploma-gallery-item');
    const selectedTemplateInput = document.getElementById('selectedTemplateId');
    const previewImage = document.getElementById('certificatePreview') || document.getElementById('diplomaPreview');

    // Publication data from server (set in publication-certificate.php)
    const pubData = window.certificateData || {};
    let previewTimeout = null;

    // Template gallery click
    templateItems.forEach(item => {
        item.addEventListener('click', function() {
            templateItems.forEach(t => t.classList.remove('active'));
            this.classList.add('active');

            const templateId = this.dataset.templateId;
            selectedTemplateInput.value = templateId;

            // Update preview via AJAX
            updateCertificatePreview();
        });
    });

    // Live preview on input change (debounced 500ms)
    const formFields = ['#author_name', '#organization', '#city', '#position', '#publication_date'];
    formFields.forEach(selector => {
        const field = document.querySelector(selector);
        if (field) {
            field.addEventListener('input', debouncePreview);
            field.addEventListener('change', debouncePreview);
        }
    });

    function debouncePreview() {
        clearTimeout(previewTimeout);
        previewTimeout = setTimeout(updateCertificatePreview, 500);
    }

    /**
     * Fetch dynamic preview from server and update preview image
     */
    function updateCertificatePreview() {
        if (!selectedTemplateInput || !selectedTemplateInput.value) return;
        if (!previewImage) return;

        const publicationId = form.querySelector('input[name="publication_id"]');

        const formData = new FormData();
        formData.append('template_id', selectedTemplateInput.value);
        if (publicationId) {
            formData.append('publication_id', publicationId.value);
        }
        formData.append('author_name', document.getElementById('author_name')?.value || '');
        formData.append('organization', document.getElementById('organization')?.value || '');
        formData.append('city', document.getElementById('city')?.value || '');
        formData.append('position', document.getElementById('position')?.value || '');
        formData.append('publication_date', document.getElementById('publication_date')?.value || '');
        formData.append('publication_title', pubData.publicationTitle || '');
        formData.append('publication_type', pubData.publicationType || '');
        formData.append('direction', pubData.direction || '');

        fetch('/ajax/preview-certificate.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(result => {
            if (result.success && result.preview_url) {
                previewImage.src = result.preview_url;
            }
        })
        .catch(error => {
            console.error('Certificate preview error:', error);
        });
    }

    // Trigger initial preview on page load
    updateCertificatePreview();

    // Form submission
    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        const submitBtn = form.querySelector('button[type="submit"]');
        const payBtn = document.getElementById('payBtn') || submitBtn;
        const btnText = payBtn.querySelector('.btn-text');
        const btnLoader = payBtn.querySelector('.btn-loader');
        const originalText = payBtn.innerHTML;

        // Show loading
        if (btnText && btnLoader) {
            btnText.style.display = 'none';
            btnLoader.style.display = 'inline-flex';
        } else {
            payBtn.innerHTML = '<span class="spinner-small"></span> Переход к оплате...';
        }
        payBtn.disabled = true;

        try {
            const formData = new FormData(form);

            const response = await fetch('/ajax/create-certificate-payment.php', {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                const errorText = await response.text();
                console.error('Server error:', response.status, errorText);
                throw new Error('Ошибка сервера: ' + response.status);
            }

            const result = await response.json();

            if (result.success) {
                // E-commerce: Add to cart event
                if (result.ecommerce) {
                    window.dataLayer = window.dataLayer || [];
                    window.dataLayer.push({
                        "ecommerce": {
                            "currencyCode": "RUB",
                            "add": {
                                "products": [{
                                    "id": String(result.ecommerce.id),
                                    "name": result.ecommerce.name,
                                    "price": parseFloat(result.ecommerce.price),
                                    "brand": "Педпортал",
                                    "category": result.ecommerce.category,
                                    "quantity": 1
                                }]
                            }
                        }
                    });
                }
                // Задержка для отправки dataLayer перед редиректом
                setTimeout(function() { window.location.href = result.redirect_url; }, 300);
            } else {
                alert(result.message || 'Произошла ошибка');
                resetButton();
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Ошибка: ' + error.message);
            resetButton();
        }

        function resetButton() {
            if (btnText && btnLoader) {
                btnText.style.display = 'inline';
                btnLoader.style.display = 'none';
            } else {
                payBtn.innerHTML = originalText;
            }
            payBtn.disabled = false;
        }
    });

    // Initialize first template if needed
    if (templateItems.length > 0 && !selectedTemplateInput.value) {
        const firstTemplate = templateItems[0];
        selectedTemplateInput.value = firstTemplate.dataset.templateId;
    }
});
