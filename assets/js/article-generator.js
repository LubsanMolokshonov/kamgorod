/**
 * Генератор статей — визард UI
 */
$(function() {
    var sessionToken = '';
    var csrfToken = $('input[name="csrf_token"]').first().val();
    var currentStep = 1;
    var articleSections = [];

    // ====== Навигация ======

    function goToStep(step) {
        currentStep = step;
        $('.wizard-step').removeClass('active');
        $('.wizard-step[data-step="' + step + '"]').addClass('active');

        // Обновить прогресс-бар
        $('.progress-step').each(function() {
            var s = parseInt($(this).data('step'));
            $(this).toggleClass('active', s <= step);
            $(this).toggleClass('completed', s < step);
        });

        // Скролл к визарду
        $('html, body').animate({
            scrollTop: $('#generatorWizard').offset().top - 20
        }, 300);
    }

    // Кнопка «Создать статью» — скрыть лендинг, показать визард
    $('#startGeneratorBtn').on('click', function() {
        $('.generator-hero, .generator-steps-section, .generator-benefits').slideUp(300);
        $('#generatorWizard').slideDown(300, function() {
            goToStep(1);
        });
    });

    // Кнопки «Назад»
    $(document).on('click', '.wizard-back-btn', function() {
        var target = parseInt($(this).data('target'));
        goToStep(target);
    });

    // ====== Шаг 1: Личные данные ======

    $('#step1Form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('.btn-primary');

        // Валидация
        var email = $('#gen_email').val().trim();
        var name = $('#gen_author_name').val().trim();
        var org = $('#gen_organization').val().trim();

        if (!email || !name || !org) {
            alert('Заполните все обязательные поля');
            return;
        }

        $btn.prop('disabled', true).text('Сохранение...');

        $.ajax({
            url: '/ajax/save-generator-step.php',
            method: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    sessionToken = resp.session_token;
                    // Синхронизировать токен во все формы
                    $('#sessionToken').val(sessionToken);
                    $('.session-token-input').val(sessionToken);
                    goToStep(2);
                } else {
                    alert(resp.message || 'Ошибка сохранения');
                }
            },
            error: function() {
                alert('Ошибка сети. Попробуйте ещё раз.');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Далее');
            }
        });
    });

    // ====== Шаг 2: Параметры статьи ======

    $('#step2Form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('.btn-primary');

        var topic = $('#gen_topic').val().trim();
        var desc = $('#gen_description').val().trim();

        if (!topic || !desc) {
            alert('Заполните тему и описание');
            return;
        }

        // Убедиться что токен сессии есть
        $form.find('.session-token-input').val(sessionToken);

        $btn.prop('disabled', true).text('Сохранение...');

        $.ajax({
            url: '/ajax/save-generator-step.php',
            method: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    goToStep(3);
                    startGeneration();
                } else {
                    alert(resp.message || 'Ошибка сохранения');
                }
            },
            error: function() {
                alert('Ошибка сети. Попробуйте ещё раз.');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Сгенерировать статью');
            }
        });
    });

    // ====== Шаг 3: Генерация ======

    function startGeneration() {
        // Анимация прогресс-бара
        var $fill = $('.generation-progress-fill');
        $fill.css('width', '0%');
        var progress = 0;
        var progressInterval = setInterval(function() {
            progress += Math.random() * 8;
            if (progress > 90) progress = 90;
            $fill.css('width', progress + '%');
        }, 1000);

        $.ajax({
            url: '/ajax/generate-article.php',
            method: 'POST',
            data: {
                csrf_token: csrfToken,
                session_token: sessionToken
            },
            dataType: 'json',
            timeout: 120000,
            success: function(resp) {
                clearInterval(progressInterval);
                $fill.css('width', '100%');

                if (resp.success) {
                    articleSections = resp.sections;
                    renderArticle(resp.title, resp.sections);
                    setTimeout(function() { goToStep(4); }, 500);
                } else {
                    alert(resp.message || 'Ошибка генерации');
                    goToStep(2);
                }
            },
            error: function() {
                clearInterval(progressInterval);
                alert('Ошибка генерации. Попробуйте ещё раз.');
                goToStep(2);
            }
        });
    }

    // ====== Шаг 4: Просмотр и редактирование ======

    function renderArticle(title, sections) {
        $('#articleTitle').text(title);

        var $container = $('#articleSections');
        $container.empty();

        sections.forEach(function(section) {
            var $section = $('<div class="article-section-card" data-section-id="' + section.id + '">' +
                '<div class="section-header">' +
                    '<h3>' + escapeHtml(section.heading) + '</h3>' +
                    '<button type="button" class="btn-edit-section" title="Изменить раздел">' +
                        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>' +
                        ' Изменить' +
                    '</button>' +
                '</div>' +
                '<div class="section-content">' + section.html + '</div>' +
                '<div class="section-edit-form" style="display:none;">' +
                    '<textarea class="form-control edit-instructions" rows="3" placeholder="Опишите, что нужно изменить. Например: добавь больше практических примеров, сделай акцент на ФГОС..."></textarea>' +
                    '<div class="edit-actions">' +
                        '<button type="button" class="btn btn-sm btn-outline cancel-edit-btn">Отмена</button>' +
                        '<button type="button" class="btn btn-sm btn-primary apply-edit-btn">Обновить раздел</button>' +
                    '</div>' +
                '</div>' +
            '</div>');

            $container.append($section);
        });
    }

    // Показать форму редактирования секции
    $(document).on('click', '.btn-edit-section', function() {
        var $card = $(this).closest('.article-section-card');
        $card.find('.section-edit-form').slideDown(200);
        $card.find('.edit-instructions').focus();
    });

    // Отмена редактирования
    $(document).on('click', '.cancel-edit-btn', function() {
        var $card = $(this).closest('.article-section-card');
        $card.find('.section-edit-form').slideUp(200);
        $card.find('.edit-instructions').val('');
    });

    // Применить редактирование секции
    $(document).on('click', '.apply-edit-btn', function() {
        var $card = $(this).closest('.article-section-card');
        var sectionId = $card.data('section-id');
        var instructions = $card.find('.edit-instructions').val().trim();

        if (!instructions) {
            alert('Опишите, что нужно изменить');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Обновление...');
        $card.addClass('section-loading');

        $.ajax({
            url: '/ajax/edit-article-section.php',
            method: 'POST',
            data: {
                csrf_token: csrfToken,
                session_token: sessionToken,
                section_id: sectionId,
                instructions: instructions
            },
            dataType: 'json',
            timeout: 90000,
            success: function(resp) {
                if (resp.success) {
                    $card.find('.section-content').html(resp.updated_html);
                    $card.find('.section-edit-form').slideUp(200);
                    $card.find('.edit-instructions').val('');

                    // Обновить в массиве
                    articleSections.forEach(function(s) {
                        if (s.id === sectionId) {
                            s.html = resp.updated_html;
                        }
                    });
                } else {
                    alert(resp.message || 'Ошибка редактирования');
                }
            },
            error: function() {
                alert('Ошибка сети. Попробуйте ещё раз.');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Обновить раздел');
                $card.removeClass('section-loading');
            }
        });
    });

    // Перегенерировать всю статью
    $('#regenerateBtn').on('click', function() {
        if (!confirm('Текущая статья будет заменена новой. Продолжить?')) return;
        goToStep(3);
        startGeneration();
    });

    // ====== Шаг 5: Подтверждение ======

    $('#confirmArticleBtn').on('click', function() {
        // Заполнить данные подтверждения
        $('#confirmAuthor').text($('#gen_author_name').val());
        $('#confirmOrg').text($('#gen_organization').val());
        $('#confirmTitle').text($('#articleTitle').text());
        $('#agreePublish').prop('checked', false);
        $('#publishBtn').prop('disabled', true);
        goToStep(5);
    });

    $('#agreePublish').on('change', function() {
        $('#publishBtn').prop('disabled', !$(this).is(':checked'));
    });

    // Опубликовать
    $('#publishBtn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Публикация...');
        $('#publishConfirm').hide();
        $('#publishLoader').show();

        $.ajax({
            url: '/ajax/publish-generated-article.php',
            method: 'POST',
            data: {
                csrf_token: csrfToken,
                session_token: sessionToken
            },
            dataType: 'json',
            timeout: 30000,
            success: function(resp) {
                if (resp.success) {
                    $('#viewPublicationLink').attr('href', resp.publication_url);
                    $('#getCertificateLink').attr('href', resp.certificate_url);
                    goToStep(6);
                } else {
                    alert(resp.message || 'Ошибка публикации');
                    $('#publishConfirm').show();
                    $('#publishLoader').hide();
                    $btn.prop('disabled', false).text('Опубликовать в журнал');
                }
            },
            error: function() {
                alert('Ошибка сети. Попробуйте ещё раз.');
                $('#publishConfirm').show();
                $('#publishLoader').hide();
                $btn.prop('disabled', false).text('Опубликовать в журнал');
            }
        });
    });

    // ====== Шаг 6: Ссылки на результат ======

    $('#viewPublicationLink').on('click', function(e) {
        e.preventDefault();
        var url = $(this).attr('href');
        if (url && url !== '#') {
            window.open(url, '_blank');
        }
    });

    $('#getCertificateLink').on('click', function(e) {
        e.preventDefault();
        var url = $(this).attr('href');
        if (url && url !== '#') {
            window.location.href = url;
        }
    });

    // ====== Утилиты ======

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }
});
