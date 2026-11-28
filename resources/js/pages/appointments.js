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
    rejected: { label: 'Decline Request', tone: 'secondary', icon: 'fa-circle-xmark' },
    checked_in: { label: 'Check In', tone: 'primary', icon: 'fa-user-check' },
    in_service: { label: 'Start Service', tone: 'primary', icon: 'fa-play' },
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
          const right = el('span', 'text-muted', [item.staff, item.resources, item.duration_minutes + ' min'].filter(Boolean).join(' · '));
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

      if (['confirmed', 'checked_in', 'in_service', 'completed'].includes(d.status)) {
        const till = el('button', 'btn btn-secondary btn-md mt-5 w-full');
        till.type = 'button';
        till.append(el('i', 'fa-solid fa-cash-register'));
        till.append(document.createTextNode(' Till / Checkout'));
        till.addEventListener('click', () => AlbaBook.tillDrawer.open(d.uuid, onChange));
        body.append(till);
      }

      if (d.allowed_transitions && d.allowed_transitions.length) {
        const actions = el('div', 'mt-6 space-y-2');
        d.allowed_transitions.forEach((to) => {
          const spec = TRANSITIONS[to];
          if (!spec) return;
          const state = d.transition_states && d.transition_states[to];
          const btn = el('button', 'btn btn-' + spec.tone + ' btn-md w-full');
          btn.type = 'button';
          btn.append(el('i', 'fa-solid ' + spec.icon));
          btn.append(document.createTextNode(' ' + spec.label));
          if (state && state.ok === false) {
            btn.disabled = true;
            btn.title = state.reason || 'Not available right now.';
            actions.append(btn);
            if (state.reason) {
              actions.append(el('p', '-mt-1 text-xs text-muted', state.reason));
            }
            return;
          }
          if (state && state.requires_confirmation && state.reason) {
            btn.title = state.reason;
            actions.append(el('p', '-mt-1 mb-1 text-xs text-warning-strong', state.reason));
          }
          btn.addEventListener('click', () => appointmentDrawer._transition(d, to, btn, body, onChange));
          actions.append(btn);
        });
        body.append(actions);
      }

      if (d.can_manage_link) {
        const link = el('button', 'btn btn-outline btn-md mt-3 w-full');
        link.type = 'button';
        link.append(el('i', 'fa-solid fa-link'));
        link.append(document.createTextNode(' Copy manage link'));
        link.addEventListener('click', () => {
          ui.setBusy(link, true);
          AlbaBook.api
            .post('/api/appointments/' + encodeURIComponent(d.uuid) + '/manage-link', {})
            .then((data) => {
              const url = data.url || '';
              if (navigator.clipboard && url) {
                navigator.clipboard.writeText(url).catch(() => {});
              }
              AlbaBook.toast('Manage link copied — share it with the customer.', 'success');
            })
            .catch((error) => AlbaBook.toast(error.message || 'Could not create the link.', 'danger'))
            .finally(() => ui.setBusy(link, false));
        });
        body.append(link);
      }

      if (d.can_reschedule) {
        const wrap = el('div', 'mt-4');
        const btn = el('button', 'btn btn-secondary btn-md w-full');
        btn.type = 'button';
        btn.append(el('i', 'fa-regular fa-calendar'));
        btn.append(document.createTextNode(' Reschedule'));
        wrap.append(btn);
        body.append(wrap);
        btn.addEventListener('click', () => {
          btn.remove();
          wrap.append(appointmentDrawer._rescheduleForm(d, body, onChange));
        });
      }

      if (d.history && d.history.length) {
        // Status-colored event timeline: who did what, when, and any
        // reason / timing-override / reschedule detail the event carries.
        const DOT = {
          cancelled: 'bg-danger', no_show: 'bg-danger', expired: 'bg-warning', rejected: 'bg-danger',
          completed: 'bg-success',
        };
        body.append(el('h4', 'mt-6 text-sm font-semibold text-ink', 'History'));
        const list = el('ol', 'mt-3');
        d.history.forEach((event, i) => {
          const li = el('li', 'relative flex gap-3' + (i < d.history.length - 1 ? ' pb-4' : ''));
          if (i < d.history.length - 1) {
            li.append(el('span', 'absolute left-[4.5px] top-3.5 h-full w-px bg-line'));
          }
          li.append(el('span', 'relative mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ' + (DOT[event.status] || 'bg-primary')));
          const text = el('div', 'min-w-0 flex-1');
          text.append(el('p', 'text-sm font-medium text-ink', event.label));
          const meta = [event.at, event.actor].filter(Boolean).join(' · ');
          if (meta) text.append(el('p', 'text-xs text-muted', meta));
          if (event.detail) text.append(el('p', 'mt-0.5 text-xs text-warning-strong', event.detail));
          if (event.reason) text.append(el('p', 'mt-0.5 text-xs italic text-muted', '“' + event.reason + '”'));
          li.append(text);
          list.append(li);
        });
        body.append(list);
      }
    },

    async _transition(d, to, btn, body, onChange, force, extra) {
      const spec = TRANSITIONS[to];
      if (to === 'cancelled' && !extra) {
        // Structured reason capture — replaces the bare confirm so cancels
        // feed the cancellations-by-reason report.
        btn.disabled = true;
        const actions = btn.parentElement;
        actions.append(appointmentDrawer._cancelForm(d, actions, onChange, btn));
        return;
      }
      if (to === 'no_show' || to === 'rejected') {
        const yes = await ui.confirm({
          title: spec.label,
          message: 'This changes the appointment status to ' + to.replace(/_/g, ' ') + '.',
          confirmLabel: spec.label,
          danger: false,
        });
        if (!yes) return;
      }
      ui.setBusy(btn, true, 'Working…');
      AlbaBook.api
        .post('/api/appointments/' + encodeURIComponent(d.uuid) + '/transition',
          Object.assign({ to, version: d.version, force: !!force }, extra || {}))
        .then(() => {
          AlbaBook.toast('Appointment updated.', 'success');
          ui.drawer.close();
          if (onChange) onChange();
          else AlbaBook.pages.mount(document);
        })
        .catch(async (error) => {
          // Soft guardrail: warn-mode tenants get a confirmable warning;
          // confirming retries the same transition with force.
          if (error.code === 'TIMING_WARNING') {
            ui.setBusy(btn, false);
            const yes = await ui.confirm({
              title: spec.label,
              message: error.message || 'This is outside the appointment window. Proceed anyway?',
              confirmLabel: 'Proceed anyway',
            });
            if (yes) appointmentDrawer._transition(d, to, btn, body, onChange, true, extra);
            return;
          }
          ui.setBusy(btn, false);
          AlbaBook.toast(error.message || 'Update failed.', 'danger');
          if (error.code === 'STALE_APPOINTMENT') appointmentDrawer.open(d.uuid, onChange);
        });
    },

    /* Cancel reasons — mirrors AppointmentTransitionService::CANCEL_REASON_CODES. */
    CANCEL_REASONS: [
      ['illness', 'Illness / emergency'],
      ['schedule_conflict', 'Schedule conflict'],
      ['price', 'Price'],
      ['found_alternative', 'Found another provider'],
      ['no_longer_needed', 'No longer needed'],
      ['other', 'Other'],
    ],

    /* Inline cancel form: reason dropdown (required) + optional note. Mounted
     * under the action buttons like the reschedule form. */
    _cancelForm(d, actions, onChange, cancelBtn) {
      const form = el('form', 'mt-2 space-y-3 rounded-lg border border-line p-4');
      form.noValidate = true;

      const reasonSel = el('select', 'input w-full');
      reasonSel.append(el('option', '', 'Reason for cancelling…'));
      appointmentDrawer.CANCEL_REASONS.forEach(([code, label]) => {
        const opt = el('option', '', label);
        opt.value = code;
        reasonSel.appendChild(opt);
      });

      const noteInput = el('input', 'input w-full');
      noteInput.type = 'text';
      noteInput.placeholder = 'Note (optional)';
      noteInput.maxLength = 255;

      const errorEl = el('p', 'text-sm text-danger hidden');
      const buttons = el('div', 'flex gap-2');
      const confirmBtn = el('button', 'btn btn-danger btn-md flex-1');
      confirmBtn.type = 'submit';
      confirmBtn.append(document.createTextNode('Confirm cancel'));
      const backBtn = el('button', 'btn btn-ghost btn-md');
      backBtn.type = 'button';
      backBtn.append(document.createTextNode('Back'));
      buttons.append(confirmBtn, backBtn);
      form.append(reasonSel, noteInput, errorEl, buttons);

      backBtn.addEventListener('click', () => { form.remove(); cancelBtn.disabled = false; });
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        if (!reasonSel.value) {
          errorEl.textContent = 'Pick a reason — it feeds the cancellation report.';
          errorEl.classList.remove('hidden');
          return;
        }
        appointmentDrawer._transition(d, 'cancelled', cancelBtn, actions.parentElement, onChange, false, {
          reason_code: reasonSel.value,
          reason: noteInput.value.trim() || undefined,
        });
      });
      return form;
    },

    /* Reschedule form — same dependent-dropdown model as the create modal:
     * staff filtered to the appointment's branch + service, an optional
     * room pick when the service requires one. Server re-validates all of
     * it inside the reschedule transaction. */
    _rescheduleForm(d, body, onChange) {
      const form = el('form', 'mt-4 space-y-3 rounded-lg border border-line p-4');
      form.noValidate = true;

      const staffSel = el('select', 'input w-full');
      staffSel.name = 'staff_id';
      const dateInput = el('input', 'input w-full');
      dateInput.type = 'date';
      dateInput.name = 'date';
      const timeInput = el('input', 'input w-full');
      timeInput.type = 'time';
      timeInput.name = 'time';
      const resourceWrap = el('div', 'hidden');
      const resourceSel = el('select', 'input w-full');
      resourceSel.name = 'resource_id';
      const errorEl = el('p', 'text-sm text-danger hidden');

      AlbaBook.api.get('/api/filters').then((f) => {
        const staff = (f.staff || []).filter(
          (s) => (s.branch_ids || []).includes(d.branch_id) && (s.service_ids || []).includes(d.service_id),
        );
        staffSel.replaceChildren();
        staff.forEach((s) => {
          const opt = el('option', '', s.name);
          opt.value = String(s.id);
          if (s.id === d.staff_id) opt.selected = true;
          staffSel.appendChild(opt);
        });

        const service = (f.services || []).find((s) => s.id === d.service_id);
        const typeId = service && service.required_resource_type_id;
        const rooms = typeId
          ? (f.resources || []).filter((r) => r.branch_id === d.branch_id && r.resource_type_id === typeId)
          : [];
        if (rooms.length) {
          resourceSel.replaceChildren();
          const auto = el('option', '', 'Auto-assign a room');
          auto.value = '';
          resourceSel.appendChild(auto);
          rooms.forEach((r) => {
            const opt = el('option', '', r.name);
            opt.value = String(r.id);
            resourceSel.appendChild(opt);
          });
          resourceWrap.classList.remove('hidden');
        }
      }).catch(() => {
        staffSel.replaceChildren();
        const opt = el('option', '', 'Could not load staff');
        opt.value = '';
        staffSel.appendChild(opt);
      });

      form.append(
        el('label', 'block text-sm font-medium text-ink', ['Staff member', staffSel]),
        el('label', 'block text-sm font-medium text-ink', ['New date', dateInput]),
        el('label', 'block text-sm font-medium text-ink', ['New time', timeInput]),
        resourceWrap,
        errorEl,
      );
      const roomLabel = el('label', 'block text-sm font-medium text-ink', ['Room / resource', resourceSel]);
      resourceWrap.append(roomLabel);

      const actions = el('div', 'flex gap-2');
      const submit = el('button', 'btn-primary flex-1');
      submit.type = 'submit';
      submit.textContent = 'Save new time';
      const cancel = el('button', 'btn-outline');
      cancel.type = 'button';
      cancel.textContent = 'Back';
      cancel.addEventListener('click', () => appointmentDrawer.open(d.uuid, onChange));
      actions.append(submit, cancel);
      form.append(actions);

      form.addEventListener('submit', (event) => {
        event.preventDefault();
        errorEl.classList.add('hidden');
        ui.setBusy(submit, true);
        AlbaBook.api
          .post('/api/appointments/' + encodeURIComponent(d.uuid) + '/reschedule', {
            date: dateInput.value,
            time: timeInput.value,
            staff_id: staffSel.value ? parseInt(staffSel.value, 10) : undefined,
            resource_id: resourceSel.value ? parseInt(resourceSel.value, 10) : undefined,
            version: d.version,
          })
          .then(() => {
            AlbaBook.toast('Appointment rescheduled.', 'success');
            ui.drawer.close();
            if (onChange) onChange();
            else AlbaBook.pages.mount(document);
          })
          .catch((error) => {
            errorEl.hidden = false;
            errorEl.classList.remove('hidden');
            errorEl.textContent = error.message || 'Reschedule failed.';
            if (error.code === 'STALE_APPOINTMENT') appointmentDrawer.open(d.uuid, onChange);
          })
          .finally(() => ui.setBusy(submit, false));
      });

      return form;
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

    if (AlbaBook.appointmentModal) {
      AlbaBook.appointmentModal.init(root, {
        onSuccess: () => {
          AlbaBook.toast('Appointment created.', 'success');
          reload();
        },
      });
    }

    reload();
  });
})();
