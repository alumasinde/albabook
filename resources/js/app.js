/*
 * AlbaBook shared UI behaviour. Vanilla JS, no framework (plan.md §3.3).
 * Everything is wired through data-* attributes: no inline handlers, so the
 * strict Content-Security-Policy (script-src 'self') holds.
 *
 * The page must work without this file; it only enhances.
 */
(function () {
  'use strict';

  const AlbaBook = (window.AlbaBook = window.AlbaBook || {});

  /** CSRF token for fetch() calls (CsrfMiddleware accepts X-CSRF-Token). */
  AlbaBook.csrfToken = function () {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  };

  /** fetch() wrapper that sends the CSRF header and asks for JSON. */
  AlbaBook.fetch = function (url, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (options.method && options.method.toUpperCase() !== 'GET') {
      headers.set('X-CSRF-Token', AlbaBook.csrfToken());
    }
    return fetch(url, Object.assign({}, options, { headers, credentials: 'same-origin' }));
  };

  /** Show a toast. tone: success | danger | info */
  AlbaBook.toast = function (message, tone = 'success') {
    const region = document.getElementById('toast-region');
    if (!region) return;
    const icons = { success: 'fa-circle-check', danger: 'fa-circle-xmark', info: 'fa-circle-info' };
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
    const icon = document.createElement('i');
    icon.className = 'fa-solid ' + (icons[tone] || icons.info);
    icon.setAttribute('aria-hidden', 'true');
    const text = document.createElement('span');
    text.textContent = message; // textContent: never parse HTML from messages
    toast.append(icon, text);
    region.append(toast);
    setTimeout(() => toast.remove(), 5000);
  };

  function setDrawer(name, open) {
    const drawer = document.querySelector('[data-drawer="' + name + '"]');
    const backdrop = document.querySelector('[data-drawer-backdrop="' + name + '"]');
    if (!drawer) return;
    drawer.classList.toggle('is-open', open);
    if (backdrop) backdrop.hidden = !open;
    document.querySelectorAll('[data-drawer-toggle="' + name + '"]').forEach((el) => el.setAttribute('aria-expanded', String(open)));
    if (open) {
      const first = drawer.querySelector('a, button');
      if (first) first.focus();
    }
  }

  document.addEventListener('click', function (event) {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const toggle = target.closest('[data-drawer-toggle]');
    if (toggle) {
      const name = toggle.getAttribute('data-drawer-toggle');
      const drawer = document.querySelector('[data-drawer="' + name + '"]');
      setDrawer(name, !(drawer && drawer.classList.contains('is-open')));
      return;
    }
    const close = target.closest('[data-drawer-close]');
    if (close) {
      setDrawer(close.getAttribute('data-drawer-close'), false);
      return;
    }
    const backdrop = target.closest('[data-drawer-backdrop]');
    if (backdrop) {
      setDrawer(backdrop.getAttribute('data-drawer-backdrop'), false);
      return;
    }

    const passwordToggle = target.closest('[data-password-toggle]');
    if (passwordToggle) {
      const input = document.getElementById(passwordToggle.getAttribute('data-password-toggle'));
      if (!input) return;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      passwordToggle.setAttribute('aria-pressed', String(show));
      const icon = passwordToggle.querySelector('i');
      if (icon) icon.className = show ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
      return;
    }

    const dismiss = target.closest('[data-dismiss="alert"]');
    if (dismiss) {
      const alert = dismiss.closest('[data-alert]');
      if (alert) alert.remove();
      return;
    }

    const modalOpen = target.closest('[data-modal-open]');
    if (modalOpen) {
      const dialog = document.getElementById(modalOpen.getAttribute('data-modal-open'));
      if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
      return;
    }
    const modalClose = target.closest('[data-modal-close]');
    if (modalClose) {
      const dialog = modalClose.closest('dialog');
      if (dialog) dialog.close();
      return;
    }

    const toastDemo = target.closest('[data-toast-demo]');
    if (toastDemo) {
      AlbaBook.toast(toastDemo.getAttribute('data-toast-demo'));
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      document.querySelectorAll('[data-drawer].is-open').forEach((d) => setDrawer(d.getAttribute('data-drawer'), false));
    }
  });

  // Close a <dialog> when its backdrop is clicked.
  document.addEventListener('click', function (event) {
    if (event.target instanceof HTMLDialogElement) event.target.close();
  });

  // <form data-confirm="Message" data-confirm-label="Deactivate"> asks before
  // submitting (CSP forbids inline onsubmit handlers).
  document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm')) return;
    if (form.dataset.confirmed === '1') {
      delete form.dataset.confirmed;
      return;
    }
    event.preventDefault();
    AlbaBook.ui.confirm({
      title: form.getAttribute('data-confirm-title') || 'Are you sure?',
      message: form.getAttribute('data-confirm'),
      confirmLabel: form.getAttribute('data-confirm-label') || 'Confirm',
      danger: form.hasAttribute('data-confirm-danger'),
    }).then(function (yes) {
      if (!yes) return;
      form.dataset.confirmed = '1';
      form.requestSubmit();
    });
  }, true);

  // Server-rendered toasts: <template data-toast data-tone="success">Message</template>
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('template[data-toast]').forEach(function (tpl) {
      AlbaBook.toast(tpl.content.textContent.trim(), tpl.getAttribute('data-tone') || 'success');
      tpl.remove();
    });
  });
})();
