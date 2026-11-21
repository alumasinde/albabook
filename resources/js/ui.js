/*
 * AlbaBook UI primitives: busy states, skeletons, empty/error states, confirm
 * dialog, details drawer, AJAX forms and a fetch-driven data table.
 * All vanilla DOM, wired by data-* attributes; nothing is trusted for display
 * without escaping (textContent everywhere).
 */
(function () {
  'use strict';

  const AlbaBook = (window.AlbaBook = window.AlbaBook || {});
  const ui = (AlbaBook.ui = AlbaBook.ui || {});

  const STATUS_TONES = {
    pending: 'warning', confirmed: 'info', checked_in: 'info', completed: 'success',
    no_show: 'danger', cancelled: 'neutral', rejected: 'neutral', expired: 'neutral',
  };
  const PAYMENT_TONES = {
    not_required: 'neutral', unpaid: 'warning', deposit_paid: 'info',
    partially_paid: 'info', paid: 'success', partially_refunded: 'warning', refunded: 'neutral',
  };

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }
  ui.el = el;

  ui.badge = function (text, tone) {
    const badge = el('span', 'badge badge-' + (tone || 'neutral'));
    badge.append(el('span', 'badge-dot'));
    badge.append(document.createTextNode(text));
    return badge;
  };

  ui.statusBadge = function (status, label) {
    return ui.badge(label || status.replace(/_/g, ' '), STATUS_TONES[status] || 'neutral');
  };

  ui.paymentBadge = function (status, label) {
    return ui.badge(label || status.replace(/_/g, ' '), PAYMENT_TONES[status] || 'neutral');
  };

  ui.avatar = function (name) {
    const parts = String(name || '?').trim().split(/\s+/);
    const initials = parts.slice(0, 2).map((p) => p.charAt(0).toUpperCase()).join('');
    return el('span', 'avatar', initials || '?');
  };

  ui.spinner = function (label) {
    const wrap = el('div', 'flex items-center gap-3 text-sm text-muted');
    wrap.setAttribute('role', 'status');
    wrap.append(el('span', 'spinner'));
    wrap.append(el('span', '', label || 'Loading…'));
    return wrap;
  };

  ui.skeletonRows = function (count, template) {
    const wrap = document.createDocumentFragment();
    for (let i = 0; i < (count || 4); i += 1) {
      const row = el('div', 'skeleton-row');
      if (template && template === 'cards') {
        row.className = 'card p-5';
        row.append(el('div', 'skeleton h-4 w-24'), el('div', 'skeleton mt-3 h-8 w-16'), el('div', 'skeleton mt-3 h-3 w-32'));
      } else if (template && template === 'list') {
        row.className = 'flex items-center gap-3 py-3';
        row.append(el('div', 'skeleton h-10 w-10 rounded-full'), el('div', 'flex-1'), el('div', 'skeleton h-4 w-full'));
      } else {
        row.className = 'flex items-center gap-4 px-4 py-3';
        row.append(el('div', 'skeleton h-4 flex-1'), el('div', 'skeleton h-4 w-24'), el('div', 'skeleton h-6 w-16'));
      }
      wrap.append(row);
    }
    return wrap;
  };

  ui.emptyState = function ({ title, message, icon, actionLabel, onAction }) {
    const wrap = el('div', 'empty-state');
    wrap.append(el('span', 'empty-state-icon'));
    wrap.querySelector('.empty-state-icon').append(el('i', 'fa-solid ' + (icon || 'fa-inbox')));
    wrap.append(el('h3', 'mt-4 text-base font-semibold text-ink', title || 'Nothing here yet'));
    wrap.append(el('p', 'mt-1 max-w-sm text-sm text-muted', message || ''));
    if (actionLabel && onAction) {
      const holder = el('div', 'mt-5');
      const btn = el('button', 'btn btn-primary btn-md', actionLabel);
      btn.type = 'button';
      btn.addEventListener('click', onAction);
      holder.append(btn);
      wrap.append(holder);
    }
    return wrap;
  };

  ui.errorState = function (message, onRetry) {
    return ui.emptyState({
      title: 'Something went wrong',
      message: message || 'The request failed. Please try again.',
      icon: 'fa-triangle-exclamation',
      actionLabel: 'Retry',
      onAction: onRetry,
    });
  };

  ui.setBusy = function (element, busy, busyText) {
    if (!element) return;
    if (busy) {
      element.setAttribute('aria-busy', 'true');
      if (element instanceof HTMLButtonElement) {
        element.dataset.busyLabel = element.textContent;
        element.disabled = true;
        element.replaceChildren(el('span', 'spinner'), document.createTextNode(' ' + (busyText || 'Working…')));
      }
    } else {
      element.removeAttribute('aria-busy');
      if (element instanceof HTMLButtonElement && element.dataset.busyLabel !== undefined) {
        element.disabled = false;
        element.textContent = element.dataset.busyLabel;
        delete element.dataset.busyLabel;
      }
    }
  };

  /* ------------------------------------------------------ Confirm dialog */
  ui.confirm = function ({ title, message, confirmLabel, danger }) {
    return new Promise((resolve) => {
      const dialog = el('dialog', 'modal');
      const panel = el('div', 'modal-panel');
      panel.append(el('h2', 'text-lg font-semibold text-ink', title || 'Are you sure?'));
      panel.append(el('p', 'mt-2 text-sm text-muted', message || ''));
      const actions = el('div', 'mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end');
      const cancel = el('button', 'btn btn-secondary btn-md', 'Cancel');
      const ok = el('button', 'btn ' + (danger ? 'btn-danger' : 'btn-primary') + ' btn-md', confirmLabel || 'Confirm');
      cancel.type = 'button';
      ok.type = 'button';
      cancel.addEventListener('click', () => { dialog.close(); });
      ok.addEventListener('click', () => { dialog.dataset.result = 'yes'; dialog.close(); });
      actions.append(cancel, ok);
      panel.append(actions);
      dialog.append(panel);
      dialog.addEventListener('close', () => {
        const yes = dialog.dataset.result === 'yes';
        dialog.remove();
        resolve(yes);
      });
      dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
      });
      document.body.append(dialog);
      dialog.showModal();
      cancel.focus();
    });
  };

  /* ----------------------------------------------------- Details drawer */
  const drawer = (function () {
    let panel = null;
    let backdrop = null;
    function ensure() {
      if (panel) return;
      backdrop = el('div', 'details-backdrop');
      backdrop.hidden = true;
      backdrop.addEventListener('click', close);
      panel = el('aside', 'details-drawer');
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-modal', 'true');
      panel.hidden = true;
      document.body.append(backdrop, panel);
    }
    function open() {
      ensure();
      panel.hidden = false;
      backdrop.hidden = false;
      requestAnimationFrame(() => {
        panel.classList.add('is-open');
        backdrop.classList.add('is-open');
      });
    }
    function close() {
      if (!panel) return;
      panel.classList.remove('is-open');
      backdrop.classList.remove('is-open');
      setTimeout(() => { if (panel) panel.hidden = true; backdrop.hidden = true; }, 200);
    }
    function setContent(node) {
      ensure();
      panel.replaceChildren(node);
    }
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && panel && panel.classList.contains('is-open')) close();
    });
    return { open, close, setContent, isOpen: () => panel && !panel.hidden };
  })();
  ui.drawer = drawer;

  ui.drawerShell = function (titleText, onClose) {
    const wrap = el('div', 'flex h-full flex-col');
    const head = el('div', 'flex items-center justify-between gap-3 border-b border-line px-5 py-4');
    head.append(el('h2', 'text-lg font-semibold text-ink', titleText));
    const closeBtn = el('button', 'icon-button');
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Close');
    closeBtn.append(el('i', 'fa-solid fa-xmark'));
    closeBtn.addEventListener('click', () => {
      drawer.close();
      if (onClose) onClose();
    });
    head.append(closeBtn);
    const body = el('div', 'flex-1 overflow-y-auto px-5 py-4');
    wrap.append(head, body);
    return { wrap, body };
  };

  /* -------------------------------------------------------- AJAX forms */
  function fieldErrorTarget(form, name) {
    return form.querySelector('#field-' + CSS.escape(name) + '-error');
  }
  ui.clearFieldErrors = function (form) {
    form.querySelectorAll('.input.is-invalid, .select.is-invalid, textarea.is-invalid').forEach((i) => i.classList.remove('is-invalid'));
    form.querySelectorAll('.field-error').forEach((e) => { e.textContent = ''; });
  };
  ui.showFieldErrors = function (form, fields) {
    Object.entries(fields || {}).forEach(([name, message]) => {
      const input = form.querySelector('[name="' + CSS.escape(name) + '"]');
      if (input) input.classList.add('is-invalid');
      const target = fieldErrorTarget(form, name);
      if (target) target.textContent = Array.isArray(message) ? message.join(' ') : String(message);
      else if (input) {
        const err = el('p', 'field-error', Array.isArray(message) ? message.join(' ') : String(message));
        err.id = 'field-' + name + '-error';
        input.insertAdjacentElement('afterend', err);
      }
    });
    const firstInvalid = form.querySelector('.is-invalid');
    if (firstInvalid) firstInvalid.focus();
  };

  document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-ajax-form')) return;
    event.preventDefault();
    const submitButton = form.querySelector('[type="submit"]');
    ui.clearFieldErrors(form);
    ui.setBusy(submitButton, true, form.getAttribute('data-ajax-busy') || undefined);

    const data = {};
    new FormData(form).forEach((value, key) => {
      if (data[key] === undefined) data[key] = value;
      else if (Array.isArray(data[key])) data[key].push(value);
      else data[key] = [data[key], value];
    });

    const method = (form.getAttribute('method') || 'post').toUpperCase();
    const url = form.getAttribute('action') || window.location.pathname;
    const call = method === 'GET' ? AlbaBook.api.get(url, { query: data }) : AlbaBook.api.post(url, data);

    call
      .then((result) => {
        const successMessage = form.getAttribute('data-ajax-success');
        if (successMessage) AlbaBook.toast(successMessage, 'success');
        form.dispatchEvent(new CustomEvent('albabook:ajax-success', { detail: result, bubbles: true }));
        const dialog = form.closest('dialog');
        if (dialog && form.hasAttribute('data-ajax-close')) dialog.close();
        const redirect = form.getAttribute('data-ajax-redirect');
        if (redirect) AlbaBook.spa ? AlbaBook.spa.navigate(redirect) : (window.location.href = redirect);
      })
      .catch((error) => {
        if (error instanceof AlbaBook.ApiError) {
          if (error.fields && Object.keys(error.fields).length) {
            ui.showFieldErrors(form, error.fields);
          }
          AlbaBook.toast(error.message, 'danger');
        } else {
          AlbaBook.toast('Request failed. Please try again.', 'danger');
        }
        form.dispatchEvent(new CustomEvent('albabook:ajax-error', { detail: error, bubbles: true }));
      })
      .finally(() => ui.setBusy(submitButton, false));
  });
})();
