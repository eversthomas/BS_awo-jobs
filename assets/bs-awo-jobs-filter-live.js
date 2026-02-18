/**
 * Live-Filter für Ort/Standort: Kacheln werden bei Eingabe sofort gefiltert (nur aktuelle Seite).
 */
(function () {
    'use strict';

    function onReady() {
        var container = document.querySelector('.bs-awo-jobs');
        if (!container) return;

        var ortInput = document.getElementById('bs-awo-jobs-ort');
        if (!ortInput) return;

        var list = container.querySelector('.bs-awo-jobs-list');
        if (!list) return;

        var cards = list.querySelectorAll('.bs-awo-jobs-card');
        if (!cards.length) return;

        var debounceMs = 250;
        var timeoutId = null;

        function filterCards() {
            var value = (ortInput.value || '').trim().toLowerCase();
            var visibleCount = 0;

            cards.forEach(function (card) {
                var ort = (card.getAttribute('data-ort') || '').toLowerCase();
                var show = value === '' || ort.indexOf(value) !== -1;
                card.classList.toggle('bs-awo-jobs-card--hidden', !show);
                if (show) visibleCount++;
            });
        }

        function scheduleFilter() {
            if (timeoutId) clearTimeout(timeoutId);
            timeoutId = setTimeout(filterCards, debounceMs);
        }

        ortInput.addEventListener('input', scheduleFilter);
        ortInput.addEventListener('change', scheduleFilter);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
})();
