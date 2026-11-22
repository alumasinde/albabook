/*
 * Marketing site page controller (data-page="marketing"). Runs on a full
 * render and after every SPA swap (pages.js). Pure enhancement: the site is
 * complete without it.
 *   - scroll reveal: fades sections in as they enter the viewport
 *   - demo form: swaps to the thank-you panel after an AJAX success
 */
(function () {
  'use strict';

  const AlbaBook = (window.AlbaBook = window.AlbaBook || {});

  function reveal(root) {
    const items = Array.from(root.querySelectorAll('.reveal'));
    const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!('IntersectionObserver' in window) || reduced) {
      items.forEach((el) => el.classList.add('is-visible'));
      return null;
    }
    // Anything already on screen stays visible — no flash of hidden hero.
    const fold = window.innerHeight;
    items.forEach((el) => {
      if (el.getBoundingClientRect().top < fold) el.classList.add('is-visible');
    });
    document.documentElement.classList.add('js-reveal');
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    items.forEach((el) => observer.observe(el));
    return () => observer.disconnect();
  }

  function demoForm(root) {
    const form = root.querySelector('[data-demo-form]');
    const done = root.querySelector('[data-demo-done]');
    if (!form || !done) return null;
    const onSuccess = () => {
      form.hidden = true;
      done.hidden = false;
      done.focus();
      // Keep the URL shareable and refresh-safe (server shows the same panel).
      history.replaceState(history.state, '', '/contact?sent=1');
    };
    form.addEventListener('albabook:ajax-success', onSuccess);
    return () => form.removeEventListener('albabook:ajax-success', onSuccess);
  }

  function register() {
    if (!AlbaBook.pages) return;
    AlbaBook.pages.register('marketing', function (root) {
      const cleanups = [reveal(root), demoForm(root)].filter(Boolean);
      return () => cleanups.forEach((fn) => fn());
    });
  }

  register();
})();
