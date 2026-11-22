/*
 * Customer self-service manage page (data-page="manage-booking", book.* host).
 * Reached via the manage token; slots come from the same public availability
 * API as booking, and every write is re-validated server-side against the
 * booking_policies cutoffs.
 */
(function () {
  'use strict';

  const AlbaBook = window.AlbaBook;
  const ui = AlbaBook.ui;
  const el = ui.el;

  AlbaBook.pages.register('manage-booking', function (root) {
    const base = root.getAttribute('data-base') || '';
    // Slugless endpoints on a custom domain: strip the /manage/{token} suffix.
    const apiBase = base.replace(/\/manage\/[^/]+$/, '');

    const panels = {
      reschedule: root.querySelector('[data-manage-reschedule-panel]'),
      cancel: root.querySelector('[data-manage-cancel-panel]'),
      done: root.querySelector('[data-manage-done]'),
    };
    const actions = root.querySelector('[data-manage-actions]');
    const dateInput = root.querySelector('[data-manage-date]');
    const slotsBox = root.querySelector('[data-manage-slots]');
    const slotsEmpty = root.querySelector('[data-manage-slots-empty]');
    const confirmBtn = root.querySelector('[data-manage-confirm-reschedule]');
    const errorEl = root.querySelector('[data-manage-error]');
    const cancelErrorEl = root.querySelector('[data-manage-cancel-error]');
    const reasonInput = root.querySelector('[data-manage-reason]');

    let chosen = null;

    function openPanel(name) {
      Object.entries(panels).forEach(([key, panel]) => {
        if (panel && key !== 'done') panel.classList.toggle('hidden', key !== name);
      });
      if (name) {
        const focusable = panels[name].querySelector('button, input');
        if (focusable) focusable.focus({ preventScroll: true });
      }
    }

    function showDone(title, body) {
      if (actions) actions.classList.add('hidden');
      openPanel(null);
      panels.done.classList.remove('hidden');
      panels.done.querySelector('[data-manage-done-title]').textContent = title;
      panels.done.querySelector('[data-manage-done-body]').textContent = body;
      panels.done.scrollIntoView({ block: 'nearest' });
    }

    function fail(target, error) {
      target.hidden = false;
      target.textContent = error && error.message ? error.message : 'Something went wrong. Please try again.';
    }

    /* ------------------------------------------------------ slots */
    function loadSlots() {
      chosen = null;
      confirmBtn.disabled = true;
      slotsBox.replaceChildren();
      slotsEmpty.hidden = true;
      errorEl.hidden = true;
      const date = dateInput.value;
      if (!date) return;

      AlbaBook.api
        .get(apiBase + '/availability', {
          query: {
            service: root.getAttribute('data-service'),
            branch: root.getAttribute('data-branch'),
            staff: root.getAttribute('data-staff') || undefined,
            date,
          },
        })
        .then((data) => {
          const slots = (data && data.slots) || [];
          if (!slots.length) {
            slotsEmpty.hidden = false;
            return;
          }
          slots.forEach((slot) => {
            const btn = el(
              'button',
              'rounded-lg border border-line px-3 py-2 text-sm text-ink hover:border-primary',
              slot.start || slot.start_time,
            );
            btn.type = 'button';
            btn.setAttribute('role', 'option');
            btn.addEventListener('click', () => {
              slotsBox.querySelectorAll('button').forEach((b) => b.classList.remove('border-primary', 'bg-primary-soft'));
              btn.classList.add('border-primary', 'bg-primary-soft');
              chosen = slot.start || slot.start_time;
              confirmBtn.disabled = false;
            });
            slotsBox.appendChild(btn);
          });
        })
        .catch((error) => fail(errorEl, error));
    }

    /* ------------------------------------------------------ wiring */
    root.querySelector('[data-manage-open-reschedule]')?.addEventListener('click', () => openPanel('reschedule'));
    root.querySelector('[data-manage-open-cancel]')?.addEventListener('click', () => openPanel('cancel'));
    root.querySelectorAll('[data-manage-close]').forEach((btn) => btn.addEventListener('click', () => openPanel(null)));
    dateInput?.addEventListener('change', loadSlots);

    confirmBtn?.addEventListener('click', () => {
      if (!chosen || !dateInput.value) return;
      ui.setBusy(confirmBtn, true);
      AlbaBook.api
        .post(base + '/reschedule', { date: dateInput.value, time: chosen })
        .then(() => showDone('Your appointment was moved', 'New time: ' + dateInput.value + ' at ' + chosen + '.'))
        .catch((error) => fail(errorEl, error))
        .finally(() => ui.setBusy(confirmBtn, false));
    });

    root.querySelector('[data-manage-confirm-cancel]')?.addEventListener('click', (event) => {
      const btn = event.currentTarget;
      ui.setBusy(btn, true);
      AlbaBook.api
        .post(base + '/cancel', { reason: reasonInput ? reasonInput.value : undefined })
        .then(() => {
          const badge = root.querySelector('[data-manage-status]');
          if (badge) badge.textContent = 'cancelled';
          showDone('Your booking was cancelled', 'The business has been notified.');
        })
        .catch((error) => fail(cancelErrorEl, error))
        .finally(() => ui.setBusy(btn, false));
    });
  });
})();
