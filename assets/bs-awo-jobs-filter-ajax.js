/**
 * AJAX-Filter für Stellenliste: Formular-Submit ohne vollständigen Seiten-Reload,
 * inkl. Lade-Spinner und URL-Update (history.pushState).
 */
(function () {
    'use strict';

    function onReady() {
        if (typeof bsAwoJobsFilter === 'undefined' || !bsAwoJobsFilter.ajaxUrl || !bsAwoJobsFilter.nonce) {
            return;
        }

        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || !form.classList.contains('bs-awo-jobs-filters')) {
                return;
            }
            e.preventDefault();

            var container = form.closest('.bs-awo-jobs');
            if (!container) {
                return;
            }

            var params = new URLSearchParams();
            var inputs = form.querySelectorAll('input, select');
            for (var i = 0; i < inputs.length; i++) {
                var el = inputs[i];
                var name = el.name;
                if (!name) continue;
                if (el.type === 'radio' || el.type === 'checkbox') {
                    if (el.checked) params.set(name, el.value);
                } else {
                    params.set(name, el.value);
                }
            }
            params.set('action', 'bs_awo_jobs_filter');
            params.set('nonce', bsAwoJobsFilter.nonce);
            params.set('base_url', form.action || (window.location.origin + window.location.pathname));

            container.classList.add('bs-awo-jobs--loading');
            var spinner = document.createElement('div');
            spinner.className = 'bs-awo-jobs-spinner';
            spinner.setAttribute('aria-hidden', 'true');
            container.appendChild(spinner);

            fetch(bsAwoJobsFilter.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString(),
                credentials: 'same-origin'
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.text();
                })
                .then(function (html) {
                    if (!html || html.length < 50 || html === '-1' || html === '0' || (html.trim().indexOf('<!') === 0 && html.indexOf('bs-awo-jobs') === -1)) {
                        container.classList.remove('bs-awo-jobs--loading');
                        if (spinner && spinner.parentNode) spinner.parentNode.removeChild(spinner);
                        var fallbackParams = new URLSearchParams();
                        for (var f = 0; f < inputs.length; f++) {
                            var fel = inputs[f];
                            if (!fel.name || fel.name === 'action' || fel.name === 'nonce') continue;
                            if (fel.type === 'radio' || fel.type === 'checkbox') { if (fel.checked) fallbackParams.set(fel.name, fel.value); }
                            else { fallbackParams.set(fel.name, fel.value); }
                        }
                        var fallbackUrl = (form.action || (window.location.origin + window.location.pathname)).split('#')[0];
                        fallbackUrl += (fallbackParams.toString() ? '?' + fallbackParams.toString() : '') + '#bs-awo-jobs';
                        window.location = fallbackUrl;
                        return;
                    }
                    var wrap = document.createElement('div');
                    wrap.innerHTML = html.trim();
                    var newContent = wrap.querySelector('.bs-awo-jobs');
                    if (newContent) {
                        container.innerHTML = newContent.innerHTML;
                    } else {
                        container.innerHTML = html;
                    }
                    var urlParams = new URLSearchParams();
                    inputs = form.querySelectorAll('input, select');
                    for (var j = 0; j < inputs.length; j++) {
                        var el2 = inputs[j];
                        if (!el2.name || el2.name === 'action' || el2.name === 'nonce') continue;
                        if (el2.type === 'radio' || el2.type === 'checkbox') {
                            if (el2.checked) urlParams.set(el2.name, el2.value);
                        } else {
                            urlParams.set(el2.name, el2.value);
                        }
                    }
                    var query = urlParams.toString();
                    var baseUrl = form.action || (window.location.origin + window.location.pathname);
                    var newUrl = query ? baseUrl + (baseUrl.indexOf('?') !== -1 ? '&' : '?') + query : baseUrl;
                    if (window.history && window.history.pushState) {
                        window.history.pushState(null, '', newUrl);
                    }
                })
                .catch(function () {
                    container.classList.remove('bs-awo-jobs--loading');
                    if (spinner && spinner.parentNode) {
                        spinner.parentNode.removeChild(spinner);
                    }
                    var fallbackParams = new URLSearchParams();
                    var inputsFallback = form.querySelectorAll('input, select');
                    for (var k = 0; k < inputsFallback.length; k++) {
                        var fel = inputsFallback[k];
                        if (!fel.name || fel.name === 'action' || fel.name === 'nonce') continue;
                        if (fel.type === 'radio' || fel.type === 'checkbox') { if (fel.checked) fallbackParams.set(fel.name, fel.value); }
                        else { fallbackParams.set(fel.name, fel.value); }
                    }
                    var fallbackUrl = (form.action || (window.location.origin + window.location.pathname)).split('#')[0];
                    fallbackUrl += (fallbackParams.toString() ? '?' + fallbackParams.toString() : '') + '#bs-awo-jobs';
                    window.location = fallbackUrl;
                })
                .then(function () {
                    container.classList.remove('bs-awo-jobs--loading');
                    if (spinner && spinner.parentNode) {
                        spinner.parentNode.removeChild(spinner);
                    }
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
})();
