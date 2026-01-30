/**
 * Certificate Form Handler
 * Supports both old (template-item) and new (diploma-gallery-item) layouts
 */

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('certificateForm');
    if (!form) return;

    // Template selection - support both class names
    const templateItems = document.querySelectorAll('.template-item, .diploma-gallery-item');
    const selectedTemplateInput = document.getElementById('selectedTemplateId');
    const previewImage = document.getElementById('certificatePreview') || document.getElementById('diplomaPreview');

    templateItems.forEach(item => {
        item.addEventListener('click', function() {
            // Update active state
            templateItems.forEach(t => t.classList.remove('active'));
            this.classList.add('active');

            // Update hidden input
            const templateId = this.dataset.templateId;
            selectedTemplateInput.value = templateId;

            // Update preview image
            if (previewImage) {
                // First try data-template-src attribute (for certificate templates)
                if (this.dataset.templateSrc) {
                    previewImage.src = this.dataset.templateSrc;
                } else {
                    // Fallback: transform thumbnail path to template path
                    const img = this.querySelector('img');
                    if (img) {
                        let thumbSrc = img.src;
                        let fullSrc = thumbSrc;

                        // Transform paths for certificates
                        if (thumbSrc.includes('/certificates/thumbnails/')) {
                            fullSrc = thumbSrc.replace('/thumbnails/', '/templates/')
                                              .replace('thumb-', 'certificate-template-');
                        }
                        // Transform paths for diplomas
                        else if (thumbSrc.includes('/diplomas/thumbnails/')) {
                            fullSrc = thumbSrc.replace('/thumbnails/', '/templates/')
                                              .replace('thumb-', 'diploma-template-');
                        }
                        else if (thumbSrc.includes('_thumb')) {
                            fullSrc = thumbSrc.replace('_thumb', '');
                        }

                        previewImage.src = fullSrc;
                    }
                }
            }
        });
    });

    // Live preview update (for old design with overlay)
    const authorNameInput = document.getElementById('author_name');
    const organizationInput = document.getElementById('organization');
    const previewAuthor = document.querySelector('.preview-author');
    const previewOrg = document.querySelector('.preview-org');

    if (authorNameInput && previewAuthor) {
        authorNameInput.addEventListener('input', function() {
            previewAuthor.textContent = this.value || 'ФИО автора';
        });
    }

    if (organizationInput && previewOrg) {
        organizationInput.addEventListener('input', function() {
            previewOrg.textContent = this.value;
        });
    }

    // Form submission
    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        // Find submit button (supports different button setups)
        const submitBtn = form.querySelector('button[type="submit"]');
        const payBtn = document.getElementById('payBtn') || submitBtn;
        const btnText = payBtn.querySelector('.btn-text');
        const btnLoader = payBtn.querySelector('.btn-loader');

        // Store original button text
        const originalText = payBtn.innerHTML;

        // Show loading
        if (btnText && btnLoader) {
            btnText.style.display = 'none';
            btnLoader.style.display = 'inline-flex';
        } else {
            // Fallback: change button text directly
            payBtn.innerHTML = '<span class="spinner-small"></span> Переход к оплате...';
        }
        payBtn.disabled = true;

        try {
            const formData = new FormData(form);

            const response = await fetch('/ajax/create-certificate-payment.php', {
                method: 'POST',
                body: formData
            });

            // Check if response is OK before parsing JSON
            if (!response.ok) {
                const errorText = await response.text();
                console.error('Server error:', response.status, errorText);
                throw new Error('Ошибка сервера: ' + response.status);
            }

            const result = await response.json();

            if (result.success) {
                // Redirect to cart
                window.location.href = result.redirect_url;
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

    // Initialize first template if templates exist
    if (templateItems.length > 0 && !selectedTemplateInput.value) {
        const firstTemplate = templateItems[0];
        selectedTemplateInput.value = firstTemplate.dataset.templateId;
    }
});
