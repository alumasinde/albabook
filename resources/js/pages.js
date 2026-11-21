/*
 * AlbaBook page controller registry. Each page template sets data-page="<name>"
 * on its root element; the matching controller runs after a full render or an
 * SPA swap (spa.js calls AlbaBook.pages.mount).
 */
(function () {
  'use strict';

  const AlbaBook = (window.AlbaBook = window.AlbaBook || {});
  const controllers = {};
  let cleanup = null;

  AlbaBook.pages = {
    register(name, controller) {
      controllers[name] = controller;
    },

    mount(root) {
      if (typeof cleanup === 'function') {
        try { cleanup(); } catch (e) { /* ignore */ }
        cleanup = null;
      }
      const scope = root || document;
      const marker = scope.querySelector('[data-page]');
      if (!marker) return;
      const name = marker.getAttribute('data-page');
      const controller = controllers[name];
      if (typeof controller === 'function') {
        cleanup = controller(marker) || null;
      }
    },
  };

  document.addEventListener('DOMContentLoaded', function () {
    AlbaBook.pages.mount(document);
  });
})();
