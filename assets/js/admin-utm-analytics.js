/**
 * UTM-аналитика — jQuery-логика для раскрытия дерева
 */
$(function() {
    var filters = window.utmFilters || {};
    var levelMap = {
        source: 'campaign',
        campaign: 'content',
        content: 'term'
    };

    var levelClasses = {
        campaign: 'utm-row-campaign',
        content: 'utm-row-content',
        term: 'utm-row-term'
    };

    // Клик по стрелке раскрытия
    $('#utmTable').on('click', '.utm-toggle', function(e) {
        e.stopPropagation();

        var $toggle = $(this);
        var $row = $toggle.closest('tr');
        var level = $row.data('level');
        var nextLevel = levelMap[level];

        // Если нет следующего уровня (term) — ничего не делаем
        if (!nextLevel) return;

        var isExpanded = $row.data('expanded') === true;

        if (isExpanded) {
            // Скрыть дочерние строки
            collapseChildren($row);
            $row.data('expanded', false);
            $toggle.removeClass('expanded');
        } else {
            // Если дочерние уже загружены — показать
            var childKey = getRowKey($row);
            var $nextRows = $row.nextUntil(':not(.utm-child-of-' + childKey + ')');

            if ($nextRows.length > 0) {
                $nextRows.show();
                $row.data('expanded', true);
                $toggle.addClass('expanded');
            } else {
                // Загрузить по AJAX
                loadChildren($row, nextLevel);
            }
        }
    });

    function getRowKey($row) {
        var parts = [];
        if ($row.data('utm-source')) parts.push(sanitizeKey($row.data('utm-source')));
        if ($row.data('utm-campaign')) parts.push(sanitizeKey($row.data('utm-campaign')));
        if ($row.data('utm-content')) parts.push(sanitizeKey($row.data('utm-content')));
        return parts.join('_') || 'root';
    }

    function sanitizeKey(str) {
        return String(str).replace(/[^a-zA-Z0-9а-яА-ЯёЁ_-]/g, '_').substring(0, 50);
    }

    function collapseChildren($row) {
        var childKey = getRowKey($row);
        var $children = $row.nextAll('.utm-child-of-' + childKey);
        $children.each(function() {
            // Рекурсивно сворачиваем вложенные
            var $child = $(this);
            if ($child.data('expanded')) {
                collapseChildren($child);
                $child.data('expanded', false);
                $child.find('.utm-toggle').removeClass('expanded');
            }
        });
        $children.hide();
    }

    function loadChildren($parentRow, level) {
        var $toggle = $parentRow.find('.utm-toggle');
        var parentKey = getRowKey($parentRow);

        // Показываем loading
        var $loadingRow = $('<tr class="utm-loading-row utm-child-of-' + parentKey + '"><td colspan="12">Загрузка...</td></tr>');
        $parentRow.after($loadingRow);

        // Собираем параметры
        var params = {
            level: level,
            date_from: filters.date_from || '',
            date_to: filters.date_to || '',
            paid_from: filters.paid_from || '',
            paid_to: filters.paid_to || '',
            product_type: filters.product_type || 'all'
        };

        // Передаём родительские UTM
        if ($parentRow.data('utm-source')) params.utm_source = $parentRow.data('utm-source');
        if ($parentRow.data('utm-campaign')) params.utm_campaign = $parentRow.data('utm-campaign');
        if ($parentRow.data('utm-content')) params.utm_content = $parentRow.data('utm-content');

        $.getJSON('/admin/analytics/ajax-utm-report.php', params, function(resp) {
            $loadingRow.remove();

            if (!resp.success || !resp.data || resp.data.length === 0) {
                var $emptyRow = $('<tr class="utm-row-empty utm-child-of-' + parentKey + '"><td class="col-label" style="padding-left: ' + getIndent(level) + 'px; color: #999; font-style: italic;" colspan="12">Нет данных</td></tr>');
                $parentRow.after($emptyRow);
                $parentRow.data('expanded', true);
                $toggle.addClass('expanded');
                return;
            }

            var rows = resp.data;
            var $lastInserted = $parentRow;

            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                var hasNextLevel = levelMap[level] !== undefined;

                var $tr = buildRow(row, level, parentKey, $parentRow, hasNextLevel);
                $lastInserted.after($tr);
                $lastInserted = $tr;
            }

            $parentRow.data('expanded', true);
            $toggle.addClass('expanded');
        }).fail(function() {
            $loadingRow.remove();
        });
    }

    function getIndent(level) {
        var indents = { campaign: 36, content: 60, term: 84 };
        return indents[level] || 12;
    }

    function buildRow(data, level, parentKey, $parentRow, hasToggle) {
        var rowClass = 'utm-row ' + (levelClasses[level] || '') + ' utm-child-of-' + parentKey + ' utm-row-child';

        var $tr = $('<tr>').addClass(rowClass).attr('data-level', level).attr('data-expanded', 'false');

        // Наследуем родительские UTM + добавляем текущий
        if ($parentRow.data('utm-source')) $tr.attr('data-utm-source', $parentRow.data('utm-source'));
        if ($parentRow.data('utm-campaign')) $tr.attr('data-utm-campaign', $parentRow.data('utm-campaign'));
        if ($parentRow.data('utm-content')) $tr.attr('data-utm-content', $parentRow.data('utm-content'));

        // Устанавливаем текущий уровень
        if (level === 'campaign') $tr.attr('data-utm-campaign', data.label);
        if (level === 'content') $tr.attr('data-utm-content', data.label);
        if (level === 'term') $tr.attr('data-utm-term', data.label);

        var toggleHtml = hasToggle
            ? '<span class="utm-toggle" title="Раскрыть">▶</span>'
            : '<span style="display:inline-block;width:16px;margin-right:4px;"></span>';

        var label = escapeHtml(data.label || '(не задано)');

        $tr.append('<td class="col-label">' + toggleHtml + '<span class="utm-label">' + label + '</span></td>');
        $tr.append('<td class="col-num">' + formatNum(data.visits) + '</td>');
        $tr.append('<td class="col-num">' + (data.avg_duration_formatted || '0 с') + '</td>');
        $tr.append('<td class="col-num">' + formatNum(data.course_applications) + '</td>');
        $tr.append('<td class="col-num">' + data.conv_visit_to_app + '%</td>');
        $tr.append('<td class="col-num">' + formatNum(data.created_orders) + '</td>');
        $tr.append('<td class="col-num">' + data.conv_visit_to_order + '%</td>');
        $tr.append('<td class="col-num">' + formatNum(data.paid_orders) + '</td>');
        $tr.append('<td class="col-num">' + data.conv_order_to_paid + '%</td>');
        $tr.append('<td class="col-num">' + data.conv_visit_to_paid + '%</td>');
        $tr.append('<td class="col-num">' + (data.revenue_formatted || '0') + ' ₽</td>');
        $tr.append('<td class="col-num">' + (data.avg_check_formatted || '0') + ' ₽</td>');

        return $tr;
    }

    function formatNum(n) {
        n = parseInt(n) || 0;
        return n.toLocaleString('ru-RU');
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
});
