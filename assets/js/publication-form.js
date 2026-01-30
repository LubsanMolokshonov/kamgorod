/**
 * Publication Form Handler
 */

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('publicationForm');
    if (!form) return;

    // Character counter for annotation
    const annotation = document.getElementById('annotation');
    const annotationCount = document.getElementById('annotationCount');

    if (annotation && annotationCount) {
        annotation.addEventListener('input', function() {
            annotationCount.textContent = this.value.length;
        });
    }

    // File upload area
    const fileUploadArea = document.getElementById('fileUploadArea');
    const fileInput = document.getElementById('publication_file');
    const fileUploadContent = fileUploadArea.querySelector('.file-upload-content');
    const filePreview = fileUploadArea.querySelector('.file-preview');

    if (fileInput && fileUploadArea) {
        // Drag and drop
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            fileUploadArea.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            fileUploadArea.addEventListener(eventName, () => {
                fileUploadArea.classList.add('dragover');
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            fileUploadArea.addEventListener(eventName, () => {
                fileUploadArea.classList.remove('dragover');
            });
        });

        fileUploadArea.addEventListener('drop', function(e) {
            const files = e.dataTransfer.files;
            if (files.length) {
                fileInput.files = files;
                handleFile(files[0]);
            }
        });

        fileInput.addEventListener('change', function() {
            if (this.files.length) {
                handleFile(this.files[0]);
            }
        });

        function handleFile(file) {
            // Validate file type
            const allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            if (!allowedTypes.includes(file.type)) {
                showError(fileInput, 'Разрешены только файлы PDF, DOC, DOCX');
                return;
            }

            // Validate size (10MB)
            if (file.size > 10 * 1024 * 1024) {
                showError(fileInput, 'Максимальный размер файла — 10 МБ');
                return;
            }

            // Show preview
            fileUploadContent.style.display = 'none';
            filePreview.style.display = 'flex';
            filePreview.querySelector('.file-name').textContent = file.name;
            filePreview.querySelector('.file-size').textContent = formatFileSize(file.size);

            clearError(fileInput);
        }

        // Remove file
        const removeBtn = filePreview.querySelector('.file-remove');
        if (removeBtn) {
            removeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                fileInput.value = '';
                fileUploadContent.style.display = 'block';
                filePreview.style.display = 'none';
            });
        }
    }

    // Format file size
    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' Б';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' КБ';
        return (bytes / (1024 * 1024)).toFixed(1) + ' МБ';
    }

    // FAQ toggle
    document.querySelectorAll('.faq-question').forEach(button => {
        button.addEventListener('click', function() {
            const item = this.closest('.faq-item');
            item.classList.toggle('active');
        });
    });

    // Sidebar collapsible sections
    document.querySelectorAll('.sidebar-section.collapsible .sidebar-title').forEach(title => {
        title.addEventListener('click', function() {
            const section = this.closest('.sidebar-section');
            section.classList.toggle('expanded');
            const icon = this.querySelector('.toggle-icon');
            if (icon) {
                icon.textContent = section.classList.contains('expanded') ? '−' : '+';
            }
        });
    });

    // Form submission
    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        // Validate form
        if (!validateForm()) {
            return;
        }

        const submitBtn = document.getElementById('submitBtn');
        const btnText = submitBtn.querySelector('.btn-text');
        const btnLoader = submitBtn.querySelector('.btn-loader');

        // Show loading
        btnText.style.display = 'none';
        btnLoader.style.display = 'inline-flex';
        submitBtn.disabled = true;

        try {
            const formData = new FormData(form);

            const response = await fetch('/ajax/save-publication.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                // Redirect to certificate page
                window.location.href = result.redirect_url;
            } else {
                alert(result.message || 'Произошла ошибка');
                btnText.style.display = 'inline';
                btnLoader.style.display = 'none';
                submitBtn.disabled = false;
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Ошибка отправки формы');
            btnText.style.display = 'inline';
            btnLoader.style.display = 'none';
            submitBtn.disabled = false;
        }
    });

    // Validation
    function validateForm() {
        let isValid = true;

        // Required fields
        const required = ['email', 'author_name', 'organization', 'title', 'annotation', 'publication_type'];
        required.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field && !field.value.trim()) {
                showError(field, 'Это поле обязательно');
                isValid = false;
            } else if (field) {
                clearError(field);
            }
        });

        // Email validation
        const email = document.getElementById('email');
        if (email && email.value) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email.value)) {
                showError(email, 'Введите корректный email');
                isValid = false;
            }
        }

        // At least one direction tag
        const checkedDirections = document.querySelectorAll('#directionsSelector input:checked');
        if (checkedDirections.length === 0) {
            const selector = document.getElementById('directionsSelector');
            if (selector) {
                const errorEl = selector.parentElement.querySelector('.error-message');
                if (errorEl) {
                    errorEl.textContent = 'Выберите хотя бы одно направление';
                    errorEl.style.display = 'block';
                }
            }
            isValid = false;
        }

        // File
        const fileInput = document.getElementById('publication_file');
        if (fileInput && !fileInput.files.length) {
            showError(fileInput, 'Прикрепите файл публикации');
            isValid = false;
        }

        // Agreement
        const agreement = document.getElementById('agreement');
        if (agreement && !agreement.checked) {
            alert('Необходимо подтвердить авторство материала');
            isValid = false;
        }

        return isValid;
    }

    function showError(field, message) {
        const errorEl = field.closest('.form-group')?.querySelector('.error-message');
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.style.display = 'block';
        }
        field.classList.add('error');
    }

    function clearError(field) {
        const errorEl = field.closest('.form-group')?.querySelector('.error-message');
        if (errorEl) {
            errorEl.textContent = '';
            errorEl.style.display = 'none';
        }
        field.classList.remove('error');
    }

    // Clear errors on input
    form.querySelectorAll('input, textarea, select').forEach(field => {
        field.addEventListener('input', () => clearError(field));
        field.addEventListener('change', () => clearError(field));
    });
});
