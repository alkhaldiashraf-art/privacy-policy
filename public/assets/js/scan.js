(function () {
    'use strict';

    document.querySelectorAll('.scan-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.scan-tab').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            var target = tab.getAttribute('data-tab');
            document.querySelectorAll('.scan-panel').forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-panel') !== target;
            });
        });
    });

    document.querySelectorAll('.copy-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            var card = button.closest('.copy-group');
            var textarea = card ? card.querySelector('.fix-prompt-text') : null;
            if (!textarea) {
                return;
            }
            var text = textarea.value;
            var originalLabel = button.textContent;

            var done = function (ok) {
                button.textContent = ok ? 'Copied!' : 'Copy failed';
                window.setTimeout(function () { button.textContent = originalLabel; }, 1500);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
                return;
            }

            textarea.focus();
            textarea.select();
            var ok = false;
            try {
                ok = document.execCommand('copy');
            } catch (e) {
                ok = false;
            }
            done(ok);
        });
    });
})();
