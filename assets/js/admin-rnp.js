/* РНП — Рука на пульсе: inline-edit расходов + графики */

(function () {
    const data = window.RNP_DATA || {};

    // ============================================================
    // Inline сохранение расходов
    // ============================================================
    const inputs = document.querySelectorAll('.rnp-cost-input');

    let saveTimers = new WeakMap();

    function saveCost(input) {
        const date = input.dataset.date;
        const field = input.dataset.field;
        const value = input.value === '' ? '0' : input.value;

        input.classList.remove('is-saved', 'is-error');
        input.classList.add('is-saving');

        const formData = new FormData();
        formData.append('csrf_token', data.csrf);
        formData.append('date', date);
        formData.append('field', field);
        formData.append('value', value);

        fetch('/admin/rnp/save-cost.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((res) => {
                input.classList.remove('is-saving');
                if (res.success) {
                    input.classList.add('is-saved');
                    setTimeout(() => input.classList.remove('is-saved'), 1200);
                } else {
                    input.classList.add('is-error');
                    if (res.message) console.warn('RNP save error:', res.message);
                }
            })
            .catch((err) => {
                input.classList.remove('is-saving');
                input.classList.add('is-error');
                console.error('RNP save fetch error', err);
            });
    }

    inputs.forEach((input) => {
        input.addEventListener('input', () => {
            // debounce
            clearTimeout(saveTimers.get(input));
            const t = setTimeout(() => saveCost(input), 600);
            saveTimers.set(input, t);
        });
        input.addEventListener('blur', () => {
            clearTimeout(saveTimers.get(input));
            saveCost(input);
        });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                input.blur();
            }
        });
    });

    // ============================================================
    // Графики (Chart.js)
    // ============================================================
    if (typeof Chart === 'undefined' || !data.chart) return;

    const labels = data.chart.labels || [];
    const moneyTicks = {
        callback: (v) => new Intl.NumberFormat('ru-RU').format(v) + ' ₽',
    };
    const baseOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top', labels: { boxWidth: 12, font: { size: 12 } } },
            tooltip: {
                callbacks: {
                    label: (ctx) => {
                        const v = ctx.parsed.y;
                        if (v === null) return ctx.dataset.label + ': —';
                        return ctx.dataset.label + ': ' + new Intl.NumberFormat('ru-RU').format(v) + ' ₽';
                    },
                },
            },
        },
        interaction: { intersect: false, mode: 'index' },
        scales: {
            y: { beginAtZero: true, ticks: moneyTicks, grid: { color: '#eef0f3' } },
            x: { grid: { display: false } },
        },
    };

    const ctxRevCost = document.getElementById('rnpChartRevenueCost');
    if (ctxRevCost) {
        new Chart(ctxRevCost, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Выручка',
                        data: data.chart.revenue,
                        backgroundColor: 'rgba(99, 102, 241, 0.7)',
                        borderColor: 'rgba(99, 102, 241, 1)',
                        borderWidth: 1,
                    },
                    {
                        label: 'Расход',
                        data: data.chart.cost,
                        backgroundColor: 'rgba(244, 114, 114, 0.7)',
                        borderColor: 'rgba(244, 114, 114, 1)',
                        borderWidth: 1,
                    },
                ],
            },
            options: baseOptions,
        });
    }

    const ctxProfit = document.getElementById('rnpChartProfit');
    if (ctxProfit) {
        new Chart(ctxProfit, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Маркетинговая прибыль',
                        data: data.chart.profit,
                        borderColor: 'rgba(34, 197, 94, 1)',
                        backgroundColor: 'rgba(34, 197, 94, 0.15)',
                        fill: true,
                        tension: 0.25,
                    },
                ],
            },
            options: {
                ...baseOptions,
                scales: {
                    y: { ticks: moneyTicks, grid: { color: '#eef0f3' } },
                    x: { grid: { display: false } },
                },
            },
        });
    }

    const ctxCpa = document.getElementById('rnpChartCPA');
    if (ctxCpa) {
        new Chart(ctxCpa, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'CPA',
                        data: data.chart.cpa,
                        borderColor: 'rgba(234, 88, 12, 1)',
                        backgroundColor: 'rgba(234, 88, 12, 0.15)',
                        fill: true,
                        tension: 0.25,
                        spanGaps: true,
                    },
                ],
            },
            options: baseOptions,
        });
    }
})();
