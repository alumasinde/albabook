/*
 * Appointments page + shared appointment details drawer (data-page="appointments").
 * List, filters, pagination and actions are fetch-driven through the JSON API;
 * the server re-checks permissions on every call.
 */
(function () {
  'use strict';

  const AlbaBook = window.AlbaBook;
  const ui = AlbaBook.ui;
  const el = ui.el;

  const TRANSITIONS = {
    confirmed: { label: 'Confirm Appointment', tone: 'primary', icon: 'fa-circle-check' },
    checked_in: { label: 'Check In', tone: 'primary', icon: 'fa-user-check' },
    completed: { label: 'Mark Completed', tone: 'primary', icon: 'fa-flag-checkered' },
    no_show: { label: 'Mark No-Show', tone: 'secondary', icon: 'fa-user-xmark' },
    cancelled: { label: 'Cancel Appointment', tone: 'danger', icon: 'fa-ban' },
  };

  /* ---------------------------------------------- shared details drawer */
  const appointmentDrawer = (AlbaBook.appointmentDrawer = {
    open(uuid, onChange) {
      const shell = ui.drawerShell('Appointment Details');
      shell.body.append(ui.spinner('Loading appointment…'));
      ui.drawer.setContent(shell.wrap);
      ui.drawer.open();
      AlbaBook.api
        .get('/api/appointments/' + encodeURIComponent(uuid))
        .then((detail) => appointmentDrawer._render(detail, shell.body, onChange))
        .catch((error) => {
          shell.body.replaceChildren(ui.errorState(error.message, () => appointmentDrawer.open(uuid, onChange)));
        });
    },

    _render(d, body, onChange) {
      body.replaceChildren();
      const head = el('div', 'flex items-start gap-4');
      head.append(ui.avatar(d.customer && d.customer.name));
      const headText = el('div', 'min-w-0 flex-1');
      headText.append(el('h3', 'text-base font-semibold text-ink', d.customer && d.customer.name));
      headText.append(el('p', 'text-sm text-muted', [d.customer && d.customer.phone, d.customer && d.customer.email].filter(Boolean).join(' · ')));
      head.append(headText);
      body.append(head);

      const statusRow = el('div', 'mt-3 flex flex-wrap items-center gap-2');
      statusRow.append(ui.statusBadge(d.status), ui.paymentBadge(d.payment_status));
      statusRow.append(el('span', 'text-xs text-muted', '#' + d.reference));
      body.append(statusRow);

      const rows = [
        ['fa-calendar-day', 'Date', d.date_label],
        ['fa-clock', 'Time', d.time_label],
        ['fa-location-dot', 'Branch', d.branch],
      ];
      const facts = el('div', 'mt-5 space-y-3');
      rows.forEach(([icon, label, value]) => {
        const row = el('div', 'flex items-center gap-3 text-sm');
        row.append(el('i', 'fa-solid ' + icon + ' w-4 text-muted'));
        row.append(el('span', 'text-muted', label));
        row.append(el('span', 'ml-auto font-medium text-ink', value || '—'));
        facts.append(row);
      });
      body.append(facts);

      if (d.items && d.items.length) {
        body.append(el('h4', 'mt-6 text-sm font-semibold text-ink', 'Services'));
        const list = el('ul', 'mt-2 space-y-2');
        d.items.forEach((item) => {
          const li = el('li', 'flex items-center justify-between gap-3 rounded-lg border border-line px-3 py-2 text-sm');
          const left = el('span', 'text-ink', item.service + (item.variant ? ' · ' + item.variant : ''));
          const right = el('span', 'text-muted', [item.staff, item.duration_minutes + ' min'].filter(Boolean).join(' · '));
          li.append(left, right);
          list.append(li);
        });
        body.append(list);
      }

      if (d.total_label) {
        const total = el('div', 'mt-4 flex items-center justify-between text-sm');
        total.append(el('span', 'text-muted', 'Total'));
        total.append(el('span', 'font-semibold text-ink', d.total_label));
        body.append(total);
      }
      if (d.notes) {
        const notes = el('div', 'mt-4 rounded-lg bg-surface px-3 py-2 text-sm text-muted', d.notes);
        body.append(notes);
      }

      if (d.allowed_transitions && d.allowed_transitions.length) {
        const actions = el('div', 'mt-6 space-y-2');
        d.allowed_transitions.forEach((to) => {
          const spec = TRANSITIONS[to];
          if (!spec) return;
          const btn = el('button', 'btn btn-' + spec.tone + ' btn-md w-full');
          btn.type = 'button';
          btn.append(el('i', 'fa-solid ' + spec.icon));
          btn.append(document.createTextNode(' ' + spec.label));
          btn.addEventListener('click', () => appointmentDrawer._transition(d, to, btn, body, onChange));
          actions.append(btn);
        });
        body.append(actions);
      }

      if (d.history && d.history.length) {
        body.append(el('h4', 'mt-6 text-sm font-semibold text-ink', 'History'));
        const list = el('ul', 'mt-2 space-y-1.5 text-sm text-muted');
        d.history.forEach((event) => {
          const li = el('li', 'flex gap-2');
          li.append(el('span', 'text-ink', event.label));
          li.append(el('span', 'ml-auto shrink-0 text-xs', event.at));
          list.append(li);
        });
        body.append(list);
      }
    },

    async _transition(d, to, btn, body, onChange) {
      const spec = TRANSITIONS[to];
      if (to === 'cancelled' || to === 'no_show') {
        const yes = await ui.confirm({
          title: spec.label,
          message: 'This changes the appointment status to ' + to.replace(/_/g, ' ') + '.',
          confirmLabel: spec.label,
          danger: to === 'cancelled',
        });
        if (!yes) return;
      }
      ui.setBusy(btn, true, 'Working…');
      AlbaBook.api
        .post('/api/appointments/' + encodeURIComponent(d.uuid) + '/transition', { to, version: d.version })
        .then(() => {
          AlbaBook.toast('Appointment updated.', 'success');
          ui.drawer.close();
          if (onChange) onChange();
          else AlbaBook.pages.mount(document);
        })
        .catch((error) => {
          ui.setBusy(btn, false);
          AlbaBook.toast(error.message || 'Update failed.', 'danger');
          if (error.code === 'STALE_APPOINTMENT') appointmentDrawer.open(d.uuid, onChange);
        });
    },
  });

  /* --------------------------------------------------- appointments page */
  AlbaBook.pages.register('appointments', function (root) {
    const state = { q: '', status: '', service: '', staff: '', branch: '', date: '', page: 1 };
    const tableBody = root.querySelector('[data-appt-body]');
    const pager = root.querySelector('[data-appt-pager]');
    const info = root.querySelector('[data-appt-info]');
    const statsHost = root.querySelector('[data-appt-stats]');
    const filters = {};
    ['q', 'status', 'service', 'staff', 'branch', 'date'].forEach((key) => {
      filters[key] = root.querySelector('[data-appt-filter="' + key + '"]');
    });

    function renderStats(stats) {
      if (!statsHost || !stats) return;
      const defs = [
        ['today', "Today's Appointments", 'fa-calendar-day', 'primary'],
        ['confirmed', 'Confirmed', 'fa-circle-check', 'success'],
        ['pending', 'Pending', 'fa-clock', 'warning'],
        ['cancelled', 'Cancelled', 'fa-ban', 'danger'],
      ];
      statsHost.replaceChildren(
        ...defs.map(([key, label, icon, tone]) => {
          const card = el('div', 'card p-5');
          const top = el('div', 'flex items-start justify-between gap-3');
          top.append(el('p', 'text-sm font-medium text-muted', label));
          const iconWrap = el('span', 'stat-icon');
          iconWrap.append(el('i', 'fa-solid ' + icon));
          top.append(iconWrap);
          card.append(top);
          card.append(el('p', 'mt-2 text-3xl font-semibold tracking-tight text-ink', String(stats[key] ?? 0)));
          return card;
        }),
      );
    }

    function renderRows(rows) {
      if (!rows.length) {
        const tr = el('tr');
        const td = el('td');
        td.colSpan = 8;
        td.append(ui.emptyState({ title: 'No appointments', message: 'Try adjusting the filters, or create a new appointment.', icon: 'fa-calendar-xmark' }));
        tr.append(td);
        return [tr];
      }
      return rows.map((row) => {
        const tr = el('tr');
        const cells = [];
        const when = el('td', '', row.time_label);
        when.append(el('span', 'block text-xs text-muted', row.date_label));
        when.dataset.label = 'Date & Time';
        cells.push(when);

        const cust = el('td');
        cust.dataset.label = 'Customer';
        const custRow = el('div', 'flex items-center gap-2');
        custRow.append(ui.avatar(row.customer));
        const custText = el('div');
        custText.append(el('span', 'block font-medium text-ink', row.customer));
        if (row.phone) custText.append(el('span', 'block text-xs text-muted', row.phone));
        custRow.append(custText);
        cust.append(custRow);
        cells.push(cust);

        const svc = el('td', '', row.service);
        svc.dataset.label = 'Service';
        if (row.duration_minutes) svc.append(el('span', 'block text-xs text-muted', row.duration_minutes + ' min'));
        cells.push(svc);

        const staff = el('td', '', row.staff || '—');
        staff.dataset.label = 'Staff';
        cells.push(staff);

        const branch = el('td', '', row.branch || '—');
        branch.dataset.label = 'Branch';
        cells.push(branch);

        const status = el('td');
        status.dataset.label = 'Status';
        status.append(ui.statusBadge(row.status));
        cells.push(status);

        const pay = el('td');
        pay.dataset.label = 'Payment';
        pay.append(ui.paymentBadge(row.payment_status));
        cells.push(pay);

        const actions = el('td');
        actions.dataset.label = 'Actions';
        const view = el('button', 'icon-button');
        view.type = 'button';
        view.setAttribute('aria-label', 'View details');
        view.append(el('i', 'fa-regular fa-eye'));
        view.addEventListener('click', () => appointmentDrawer.open(row.uuid, reload));
        actions.append(view);
        cells.push(actions);

        tr.append(...cells);
        return tr;
      });
    }

    function renderPager(page, pages, total) {
      if (info) info.textContent = total ? 'Showing ' + total + ' appointment' + (total === 1 ? '' : 's') : '';
      if (!pager) return;
      pager.replaceChildren();
      if (pages <= 1) return;
      const makeBtn = (label, target, opts = {}) => {
        const b = el('button', 'btn btn-secondary btn-sm' + (opts.active ? ' is-current' : ''), label);
        b.type = 'button';
        if (opts.disabled) b.disabled = true;
        b.addEventListener('click', () => { state.page = target; reload(); });
        return b;
      };
      pager.append(
        makeBtn('‹', state.page - 1, { disabled: page <= 1 }),
        el('span', 'px-2 text-sm text-muted', 'Page ' + page + ' of ' + pages),
        makeBtn('›', state.page + 1, { disabled: page >= pages }),
      );
    }

    function reload() {
      if (tableBody) tableBody.replaceChildren(ui.skeletonRows(5));
      AlbaBook.api
        .get('/api/appointments', { query: state })
        .then((result) => {
          renderStats(result.stats);
          if (tableBody) tableBody.replaceChildren(...renderRows(result.rows || []));
          renderPager(result.page || 1, result.pages || 1, result.total || 0);
        })
        .catch((error) => {
          if (tableBody) {
            const tr = el('tr');
            const td = el('td');
            td.colSpan = 8;
            td.append(ui.errorState(error.message, reload));
            tr.append(td);
            tableBody.replaceChildren(tr);
          }
        });
    }

    let debounce = null;
    Object.entries(filters).forEach(([key, input]) => {
      if (!input) return;
      const handler = () => {
        state[key] = input.value;
        state.page = 1;
        reload();
      };
      if (input.tagName === 'SELECT' || input.type === 'date') input.addEventListener('change', handler);
      else
        input.addEventListener('input', () => {
          clearTimeout(debounce);
          debounce = setTimeout(handler, 300);
        });
    });

    const filterBtn = root.querySelector('[data-appt-apply]');
    if (filterBtn) filterBtn.addEventListener('click', reload);

    // Populate filter dropdowns from the live filter endpoint.
    AlbaBook.api.get('/api/filters').then((f) => {
      const fill = (key, items, labelFn) => {
        const select = filters[key];
        if (!select || !items) return;
        items.forEach((item) => {
          const option = el('option', '', labelFn(item));
          option.value = item.id;
          select.append(option);
        });
      };
      fill('service', f.services, (s) => s.name);
      fill('staff', f.staff, (s) => s.name);
      fill('branch', f.branches, (b) => b.name);
    }).catch(() => {});

    reload();
  });
})();
