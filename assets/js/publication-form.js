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

            // Trigger AI analysis of the file
            analyzeFile(file);
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
                // Remove analysis indicator if present
                var indicator = document.getElementById('analysisIndicator');
                if (indicator) indicator.remove();
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

    // AI File Analysis
    var analysisAbortController = null;

    async function analyzeFile(file) {
        // Remove previous indicator if any
        var prevIndicator = document.getElementById('analysisIndicator');
        if (prevIndicator) prevIndicator.remove();

        // Abort previous analysis if still running
        if (analysisAbortController) {
            analysisAbortController.abort();
        }
        analysisAbortController = new AbortController();

        // Show loading indicator
        var container = document.getElementById('analysisIndicatorContainer');
        if (!container) return;

        var indicator = document.createElement('div');
        indicator.id = 'analysisIndicator';
        indicator.className = 'analysis-loading';
        indicator.innerHTML = '<span class="spinner-small"></span> Анализируем содержание файла...';
        container.appendChild(indicator);

        try {
            var formData = new FormData();
            formData.append('publication_file', file);
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

            var response = await fetch('/ajax/analyze-publication.php', {
                method: 'POST',
                body: formData,
                signal: analysisAbortController.signal
            });

            var result = await response.json();

            if (result.success && result.suggestions) {
                fillFormWithSuggestions(result.suggestions);
                indicator.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> Поля заполнены автоматически. Вы можете их отредактировать.';
                indicator.className = 'analysis-success';

                // Scroll to the publication info section
                var infoSection = document.getElementById('publicationInfoSection');
                if (infoSection) {
                    setTimeout(function() {
                        infoSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 300);
                }

                // Auto-remove success message after 8 seconds
                setTimeout(function() {
                    var ind = document.getElementById('analysisIndicator');
                    if (ind && ind.className === 'analysis-success') {
                        ind.remove();
                    }
                }, 8000);
            } else {
                // Analysis failed — silently remove indicator, user fills manually
                indicator.remove();
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('Analysis error:', error);
            }
            var ind = document.getElementById('analysisIndicator');
            if (ind) ind.remove();
        }

        analysisAbortController = null;
    }

    function fillFormWithSuggestions(suggestions) {
        // Title — fill only if empty
        var titleField = document.getElementById('title');
        if (titleField && !titleField.value.trim() && suggestions.title) {
            titleField.value = suggestions.title;
            clearError(titleField);
        }

        // Annotation — fill only if empty
        var annotationField = document.getElementById('annotation');
        if (annotationField && !annotationField.value.trim() && suggestions.annotation) {
            annotationField.value = suggestions.annotation;
            clearError(annotationField);
            // Update character counter
            if (annotationCount) {
                annotationCount.textContent = suggestions.annotation.length;
            }
        }

        // Publication type — fill only if not selected
        var typeSelect = document.getElementById('publication_type');
        if (typeSelect && !typeSelect.value && suggestions.publication_type_id) {
            typeSelect.value = suggestions.publication_type_id;
            clearError(typeSelect);
        }

        // Direction checkboxes — fill only if none are checked
        if (suggestions.direction_ids && suggestions.direction_ids.length > 0) {
            var directionCheckboxes = document.querySelectorAll('#directionsSelector input[type="checkbox"]');
            var anyDirectionChecked = Array.from(directionCheckboxes).some(function(cb) { return cb.checked; });
            if (!anyDirectionChecked) {
                suggestions.direction_ids.forEach(function(id) {
                    var cb = document.querySelector('#directionsSelector input[value="' + id + '"]');
                    if (cb) cb.checked = true;
                });
                // Clear direction error if any
                var dirSelector = document.getElementById('directionsSelector');
                if (dirSelector) {
                    var errorEl = dirSelector.parentElement.querySelector('.error-message');
                    if (errorEl) {
                        errorEl.textContent = '';
                        errorEl.style.display = 'none';
                    }
                }
            }
        }

        // Subject checkboxes — fill only if none are checked
        if (suggestions.subject_ids && suggestions.subject_ids.length > 0) {
            var subjectCheckboxes = document.querySelectorAll('#subjectsSelector input[type="checkbox"]');
            var anySubjectChecked = Array.from(subjectCheckboxes).some(function(cb) { return cb.checked; });
            if (!anySubjectChecked) {
                suggestions.subject_ids.forEach(function(id) {
                    var cb = document.querySelector('#subjectsSelector input[value="' + id + '"]');
                    if (cb) cb.checked = true;
                });
            }
        }
    }

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
                if (result.moderation_status === 'rejected') {
                    // Publication rejected — show rejection message, don't redirect
                    showRejectionScreen(result.moderation_message);
                    btnText.style.display = 'inline';
                    btnLoader.style.display = 'none';
                    submitBtn.disabled = false;
                } else if (result.moderation_status === 'approved') {
                    showModerationNotification('Публикация одобрена и размещена в журнале!', 'success');
                    setTimeout(() => {
                        window.location.href = result.redirect_url;
                    }, 2000);
                } else {
                    showModerationNotification('Публикация отправлена на модерацию.', 'info');
                    setTimeout(() => {
                        window.location.href = result.redirect_url;
                    }, 2000);
                }
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

    // Rejection screen — replaces form with a friendly message
    function showRejectionScreen(reasonMessage) {
        var reason = reasonMessage || 'Материал не соответствует тематике портала.';
        // Strip "Публикация отклонена: " prefix if present
        reason = reason.replace(/^Публикация отклонена:\s*/i, '');

        var formColumn = document.querySelector('.submit-form-column .form-card');
        if (!formColumn) formColumn = document.querySelector('.submit-form-column');
        if (!formColumn) return;

        formColumn.innerHTML =
            '<div style="text-align:center;padding:48px 32px;">' +
                '<div style="width:72px;height:72px;margin:0 auto 24px;border-radius:50%;' +
                    'background:linear-gradient(135deg,#fef3c7,#fde68a);display:flex;' +
                    'align-items:center;justify-content:center;">' +
                    '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" ' +
                        'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                        '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>' +
                        '<line x1="12" y1="9" x2="12" y2="13"/>' +
                        '<line x1="12" y1="17" x2="12.01" y2="17"/>' +
                    '</svg>' +
                '</div>' +
                '<h2 style="font-size:1.5rem;font-weight:700;color:#1f2937;margin-bottom:12px;">' +
                    'Материал не прошёл модерацию' +
                '</h2>' +
                '<p style="font-size:1rem;color:#6b7280;margin-bottom:24px;max-width:420px;margin-left:auto;margin-right:auto;">' +
                    '<strong style="color:#92400e;">' + escapeHtml(reason) + '</strong>' +
                '</p>' +
                '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;' +
                    'padding:24px;text-align:left;margin-bottom:32px;">' +
                    '<p style="font-weight:600;color:#0369a1;margin-bottom:12px;font-size:0.95rem;">' +
                        'Наш журнал принимает образовательные и научные материалы:' +
                    '</p>' +
                    '<ul style="color:#475569;font-size:0.9rem;line-height:1.8;padding-left:20px;margin:0;">' +
                        '<li>Методические разработки, конспекты уроков, рабочие программы</li>' +
                        '<li>Научные и исследовательские работы учеников и студентов</li>' +
                        '<li>Проектные работы по любым учебным дисциплинам</li>' +
                        '<li>Статьи по педагогике, дидактике и психологии обучения</li>' +
                        '<li>Сценарии мероприятий для образовательных учреждений</li>' +
                    '</ul>' +
                '</div>' +
                '<a href="/opublikovat" class="btn btn-primary" ' +
                    'style="display:inline-flex;align-items:center;gap:8px;padding:14px 32px;font-size:1rem;">' +
                    '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
                        'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                        '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>' +
                        '<polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/>' +
                        '<line x1="9" y1="15" x2="15" y2="15"/>' +
                    '</svg>' +
                    'Загрузить другой материал' +
                '</a>' +
            '</div>';

        formColumn.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    // Moderation result notification
    function showModerationNotification(message, type) {
        var colors = {
            success: { bg: '#ecfdf5', border: '#10b981', text: '#065f46' },
            warning: { bg: '#fef3c7', border: '#f59e0b', text: '#92400e' },
            info:    { bg: '#eff6ff', border: '#3b82f6', text: '#1e40af' }
        };
        var style = colors[type] || colors.info;

        var div = document.createElement('div');
        div.style.cssText =
            'position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:10000;' +
            'padding:16px 32px;border-radius:12px;font-weight:600;font-size:16px;' +
            'box-shadow:0 10px 25px rgba(0,0,0,0.15);max-width:500px;text-align:center;' +
            'background:' + style.bg + ';border:2px solid ' + style.border + ';color:' + style.text + ';';
        div.textContent = message;
        document.body.appendChild(div);
    }
});
