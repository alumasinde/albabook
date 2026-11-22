/*
 * Public booking page wizard (data-page="booking", book.* host).
 * Steps: service → date & time → details → confirm. Slots come from the
 * public availability API; the booking write is server-authoritative
 * (availability is re-checked inside the transaction).
 */
(function () {
  'use strict';

  const AlbaBook = window.AlbaBook;
  const ui = AlbaBook.ui;
  const el = ui.el;

  AlbaBook.pages.register('booking', function (root) {
    const slug = root.getAttribute('data-slug');
    const base = root.getAttribute('data-api-base') || '/' + encodeURIComponent(slug || '');

    const state = { service: null, branch: '', staff: '', date: '', slot: null, idempotencyKey: null };
    const steps = ['service', 'datetime', 'details', 'confirm'];
    let step = 0;

    const panels = {
      service: root.querySelector('[data-step="service"]'),
      datetime: root.querySelector('[data-step="datetime"]'),
      details: root.querySelector('[data-step="details"]'),
      confirm: root.querySelector('[data-step="confirm"]'),
      success: root.querySelector('[data-step="success"]'),
    };
    const dots = Array.from(root.querySelectorAll('[data-step-dot]'));

    function showStep(index) {
      step = index;
      const name = steps[index] || 'success';
      Object.entries(panels).forEach(([key, panel]) => {
        if (panel) panel.hidden = key !== name;
      });
      dots.forEach((dot, i) => {
        dot.classList.toggle('is-active', i === index);
        dot.classList.toggle('is-done', i < index);
      });
      const first = panels[name];
      if (first) {
        const focusable = first.querySelector('button, input, select');
        if (focusable) focusable.focus({ preventScroll: true });
      }
    }

    /* --------------------------------------------------- service step */
    function loadStaff() {
      if (!staffSel || !state.service) return;
      AlbaBook.api
        .get(base + '/staff', { query: { service: state.service.id, branch: state.branch } })
        .then((result) => {
          const staff = result.staff || [];
          staffSel.replaceChildren(el('option', '', staffSel.getAttribute('data-empty-label') || 'Any available staff'));
          staff.forEach((member) => {
            const option = el('option', '', member.name);
            option.value = member.id;
            staffSel.append(option);
          });
        })
        .catch(() => {});
    }

    root.querySelectorAll('[data-service-card]').forEach((card) => {
      card.addEventListener('click', () => {
        state.service = {
          id: card.getAttribute('data-service-id'),
          name: card.getAttribute('data-service-name'),
          price: card.getAttribute('data-service-price'),
          duration: card.getAttribute('data-service-duration'),
        };
        root.querySelectorAll('[data-service-card]').forEach((c) => c.classList.toggle('is-selected', c === card));
        const selected = root.querySelector('[data-selected-service]');
        if (selected) {
          selected.replaceChildren(
            el('span', 'block text-sm font-semibold text-ink', state.service.name),
            el('span', 'block text-xs text-muted', state.service.price + ' · ' + state.service.duration + ' min'),
          );
        }
        loadStaff();
        showStep(1);
      });
    });

    /* ------------------------------------------------- datetime step */
    const dateInput = root.querySelector('[data-booking-date]');
    const branchSel = root.querySelector('[data-booking-branch]');
    const staffSel = root.querySelector('[data-booking-staff]');
    const slotsHost = root.querySelector('[data-slot-list]');
    const continueBtn = root.querySelector('[data-to-details]');
    const backToService = root.querySelector('[data-back="service"]');

    function loadSlots() {
      if (!slotsHost) return;
      state.slot = null;
      if (continueBtn) continueBtn.disabled = true;
      if (!state.date || !state.service) {
        slotsHost.replaceChildren(el('p', 'text-sm text-muted', 'Choose a date to see available times.'));
        return;
      }
      slotsHost.replaceChildren(ui.spinner('Finding available times…'));
      AlbaBook.api
        .get(base + '/availability', {
          query: { service: state.service.id, branch: state.branch, staff: state.staff, date: state.date },
        })
        .then((result) => {
          const slots = result.slots || [];
          if (!slots.length) {
            slotsHost.replaceChildren(
              ui.emptyState({ title: 'No times available', message: 'Try another date or a different staff member.', icon: 'fa-calendar-xmark' }),
            );
            return;
          }
          const list = el('div', 'slot-list');
          slots.forEach((slot) => {
            const btn = el('button', 'slot-chip', slot.start_time);
            btn.type = 'button';
            btn.addEventListener('click', () => {
              list.querySelectorAll('.slot-chip').forEach((b) => b.classList.remove('is-selected'));
              btn.classList.add('is-selected');
              state.slot = slot;
              if (continueBtn) continueBtn.disabled = false;
            });
            list.append(btn);
          });
          slotsHost.replaceChildren(list);
        })
        .catch((error) => {
          slotsHost.replaceChildren(ui.errorState(error.message, loadSlots));
        });
    }

    if (dateInput) {
      dateInput.addEventListener('change', () => {
        state.date = dateInput.value;
        loadSlots();
      });
    }
    if (branchSel) {
      branchSel.addEventListener('change', () => {
        state.branch = branchSel.value;
        loadSlots();
      });
    }
    if (staffSel) {
      staffSel.addEventListener('change', () => {
        state.staff = staffSel.value;
        loadSlots();
      });
    }
    if (continueBtn) {
      continueBtn.addEventListener('click', () => {
        if (state.slot) renderDetails();
        showStep(2);
      });
    }
    if (backToService) backToService.addEventListener('click', () => showStep(0));

    /* --------------------------------------------------- details step */
    const form = root.querySelector('[data-booking-form]');
    const backToTime = root.querySelector('[data-back="datetime"]');
    const reviewHost = root.querySelector('[data-review]');

    function renderDetails() {
      if (!reviewHost) return;
      const rows = [
        ['Service', state.service.name],
        ['When', state.date + ' · ' + state.slot.start_time],
        ['Staff', state.slot.staff_name || 'First available'],
        ['Price', state.service.price],
      ];
      reviewHost.replaceChildren(
        ...rows.map(([k, v]) => {
          const row = el('div', 'flex items-center justify-between gap-4 py-1.5 text-sm');
          row.append(el('span', 'text-muted', k));
          row.append(el('span', 'font-medium text-ink', v || '—'));
          return row;
        }),
      );
    }
    if (backToTime) backToTime.addEventListener('click', () => showStep(1));

    if (form) {
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        ui.clearFieldErrors(form);
        const submit = form.querySelector('[type="submit"]');
        ui.setBusy(submit, true, 'Booking…');

        const fields = {};
        new FormData(form).forEach((v, k) => { fields[k] = v; });
        // One key per booking attempt: a retry/double-click replays to the
        // same appointment instead of double-booking (exactly-once).
        if (!state.idempotencyKey) {
          state.idempotencyKey = window.crypto && crypto.randomUUID
            ? crypto.randomUUID()
            : 'bk-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
        }
        AlbaBook.api
          .post(base + '/book', {
            idempotency_key: state.idempotencyKey,
            service: state.service.id,
            branch: state.branch || undefined,
            staff: state.slot && state.slot.staff_id ? state.slot.staff_id : state.staff || undefined,
            date: state.date,
            time: state.slot.start_time,
            name: fields.name,
            phone: fields.phone,
            email: fields.email || undefined,
            notes: fields.notes || undefined,
            locale: document.documentElement.lang || 'en',
            source: root.dataset.embed === '1' ? 'widget' : 'public_page',
          })
          .then((result) => {
            showSuccess(result);
            state.idempotencyKey = null;
          })
          .catch((error) => {
            if (error instanceof AlbaBook.ApiError && error.fields && Object.keys(error.fields).length) {
              ui.showFieldErrors(form, error.fields);
            }
            AlbaBook.toast(error.message || 'Booking failed. Please try again.', 'danger');
            if (error.code === 'SLOT_UNAVAILABLE') showStep(1) || loadSlots();
          })
          .finally(() => ui.setBusy(submit, false));
      });
    }

    /* --------------------------------------------------- success step */
    function showSuccess(result) {
      dots.forEach((dot) => dot.classList.add('is-done'));
      const host = panels.success;
      if (host) {
        const refEl = host.querySelector('[data-booking-reference]');
        if (refEl) refEl.textContent = result.reference;
        const whenEl = host.querySelector('[data-booking-when]');
        if (whenEl) whenEl.textContent = state.date + ' · ' + state.slot.start_time;
        const svcEl = host.querySelector('[data-booking-service]');
        if (svcEl) svcEl.textContent = state.service.name;
        const manageEl = host.querySelector('[data-booking-manage]');
        if (manageEl && result.manage_url) {
          manageEl.href = result.manage_url;
          manageEl.hidden = false;
        }
      }
      Object.entries(panels).forEach(([key, panel]) => {
        if (panel) panel.hidden = key !== 'success';
      });
    }

    showStep(0);
  });
})();
