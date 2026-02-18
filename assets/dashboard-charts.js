/* global Chart, BsAwoJobsDashboardData */

(function () {
    function init() {
        // Tabs umschalten.
        var tabButtons = document.querySelectorAll('.bs-awo-jobs-tab-button');
        var panels = document.querySelectorAll('.bs-awo-jobs-tab-panel');

        var chartsRendered = false;
        var fluktuationChartsRendered = false;

        // Wenn Chart.js oder Daten nicht verfügbar sind, Diagramme später einfach nicht rendern.
        function canRenderDashboardCharts() {
            return (typeof Chart !== 'undefined' && typeof BsAwoJobsDashboardData !== 'undefined');
        }

        function canRenderFluktuationCharts() {
            return (typeof Chart !== 'undefined' && typeof BsAwoJobsFluktuationData !== 'undefined');
        }

        // Hilfsfunktion zum Erzeugen eines Standard-Bar-Charts.
        function createBarChart(canvasId, labels, values, options) {
            var canvas = document.getElementById(canvasId);
            if (!canvas || !labels || !labels.length) {
                return;
            }

            var ctx = canvas.getContext('2d');

            var cfg = {
                type: options && options.type ? options.type : 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: options && options.label ? options.label : '',
                            data: values,
                            backgroundColor: options && options.backgroundColor
                                ? options.backgroundColor
                                : 'rgba(0, 115, 170, 0.6)',
                            borderColor: options && options.borderColor
                                ? options.borderColor
                                : 'rgba(0, 115, 170, 1)',
                            borderWidth: 1,
                        },
                    ],
                },
                options: {
                    // Feste Größe basierend auf Canvas-Attributen, um flackernde Resizes zu vermeiden.
                    responsive: false,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: !!(options && options.showLegend),
                        },
                    },
                    scales: {
                        x: {
                            ticks: {
                                autoSkip: true,
                                maxRotation: 45,
                                minRotation: 0,
                            },
                        },
                        y: {
                            beginAtZero: true,
                            precision: 0,
                        },
                    },
                },
            };

            if (options && options.horizontal) {
                cfg.options.indexAxis = 'y';
            }

            new Chart(ctx, cfg);
        }

        function createPieChart(canvasId, labels, values, options) {
            var canvas = document.getElementById(canvasId);
            if (!canvas || !labels || !labels.length) {
                return;
            }

            var ctx = canvas.getContext('2d');

            var cfg = {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            data: values,
                            backgroundColor: (options && options.backgroundColor) || [
                                'rgba(0, 115, 170, 0.6)',
                                'rgba(70, 180, 80, 0.6)',
                                'rgba(213, 78, 33, 0.6)',
                                'rgba(180, 130, 70, 0.6)',
                                'rgba(140, 90, 170, 0.6)',
                            ],
                            borderWidth: 1,
                        },
                    ],
                },
                options: {
                    responsive: false,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'right',
                        },
                    },
                },
            };

            new Chart(ctx, cfg);
        }

        // Diagramme erst rendern, wenn der Diagramm-Tab sichtbar ist.
        function renderChartsOnce() {
            if (chartsRendered || !canRenderDashboardCharts()) {
                return;
            }

            var data = BsAwoJobsDashboardData;

            // Diagramm: Fachbereiche (API)
            if (data.api && data.api.labels && data.api.labels.length) {
                createBarChart('bs_awo_jobs_chart_api', data.api.labels, data.api.values, {
                    label: 'Jobs nach Fachbereich (API)',
                    backgroundColor: 'rgba(0, 115, 170, 0.6)',
                    borderColor: 'rgba(0, 115, 170, 1)',
                });
            }

            // Diagramm: Interne Fachbereiche (Mandantenfeld)
            if (data.custom && data.custom.labels && data.custom.labels.length) {
                createBarChart('bs_awo_jobs_chart_custom', data.custom.labels, data.custom.values, {
                    label: 'Jobs nach internem Fachbereich (Mandantenfeld)',
                    backgroundColor: 'rgba(70, 180, 80, 0.6)',
                    borderColor: 'rgba(70, 180, 80, 1)',
                });
            }

            // Diagramm: Top-Einrichtungen (horizontal)
            if (data.facility && data.facility.labels && data.facility.labels.length) {
                createBarChart('bs_awo_jobs_chart_facility', data.facility.labels, data.facility.values, {
                    label: 'Jobs nach Einrichtung (Top)',
                    backgroundColor: 'rgba(213, 78, 33, 0.6)',
                    borderColor: 'rgba(213, 78, 33, 1)',
                    horizontal: true,
                });
            }

            chartsRendered = true;
        }

        function renderFluktuationChartsOnce() {
            if (fluktuationChartsRendered || !canRenderFluktuationCharts()) {
                return;
            }

            var data = BsAwoJobsFluktuationData;

            if (data.vzeByDept && data.vzeByDept.labels && data.vzeByDept.labels.length) {
                createBarChart('bs_awo_jobs_fluktuation_vze_by_dept', data.vzeByDept.labels, data.vzeByDept.values, {
                    label: 'VZE nach internem Fachbereich',
                    backgroundColor: 'rgba(0, 115, 170, 0.6)',
                    borderColor: 'rgba(0, 115, 170, 1)',
                    horizontal: true,
                });
            }

            if (data.vzeByEmployment && data.vzeByEmployment.labels && data.vzeByEmployment.labels.length) {
                createPieChart('bs_awo_jobs_fluktuation_vze_by_employment', data.vzeByEmployment.labels, data.vzeByEmployment.values, {});
            }

            // Nicht-VZE-basierte Statistik: Häufig ausgeschriebene Jobtitel (COUNT(*)).
            if (data.jobsByTitle && data.jobsByTitle.labels && data.jobsByTitle.labels.length) {
                createBarChart('bs_awo_jobs_fluktuation_jobs_by_title', data.jobsByTitle.labels, data.jobsByTitle.values, {
                    label: 'Häufig ausgeschriebene Jobtitel',
                    backgroundColor: 'rgba(180, 130, 70, 0.6)',
                    borderColor: 'rgba(180, 130, 70, 1)',
                    horizontal: true,
                });
            }

            fluktuationChartsRendered = true;
        }

        tabButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = btn.getAttribute('data-bs-awo-jobs-tab');

                tabButtons.forEach(function (b) {
                    b.classList.toggle('active', b === btn);
                });

                panels.forEach(function (panel) {
                    var panelKey = panel.getAttribute('data-bs-awo-jobs-panel');
                    if (panelKey === target) {
                        panel.removeAttribute('hidden');
                    } else {
                        panel.setAttribute('hidden', 'hidden');
                    }
                });

                // URL anpassen, damit beim Aktualisieren derselbe Tab aktiv bleibt.
                var url = new URL(window.location.href);
                url.searchParams.set('tab', target);
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', url.toString());
                }

                if (target === 'charts') {
                    renderChartsOnce();
                } else if (target === 'fluktuation') {
                    renderFluktuationChartsOnce();
                }
            });
        });

        // Falls beim Laden bereits der Diagramm-Tab aktiv wäre, sofort Diagramme rendern.
        tabButtons.forEach(function (btn) {
            if (btn.classList.contains('active')) {
                if (btn.getAttribute('data-bs-awo-jobs-tab') === 'charts') {
                    renderChartsOnce();
                } else if (btn.getAttribute('data-bs-awo-jobs-tab') === 'fluktuation') {
                    renderFluktuationChartsOnce();
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

