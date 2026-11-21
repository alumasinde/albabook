/*
 * Receipt page (data-page="receipt"): binds the print trigger — kept in JS
 * because CSP forbids inline event handlers.
 */
(function () {
  'use strict';
  window.AlbaBook.pages.register('receipt', function (root) {
    const btn = root.querySelector('[data-print]');
    if (btn) btn.addEventListener('click', () => window.print());
  });
})();
