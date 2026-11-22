/*
 * Shared "New appointment" modal behaviour (components/appointments/new-modal.twig).
 * Hydrates branch/staff/service options from /api/filters and keeps them
 * consistent: staff are filtered to those assigned to the chosen branch and
 * linked to the chosen service (and vice versa), so the modal never offers
 * a combination the booking rules would reject. The room select appears
 * only when the chosen service requires a resource type — "Auto-assign"
 * keeps the server's first-free allocation, a specific room is validated
 * against the same freedom rules on submit.
 */
(function () {
  'use strict';

  const AlbaBook = (window.AlbaBook = window.AlbaBook || {});
  const ui = AlbaBook.ui;
  const el = ui.el;

  function refill(select, items, placeholder, label) {
    const current = select.value;
    select.replaceChildren(el('option', '', placeholder));
    items.forEach((item) => {
      const option = el('option', '', label(item));
      option.value = item.id;
      select.append(option);
    });
    if (current && items.some((item) => String(item.id) === current)) {
      select.value = current;
    } else {
      select.value = '';
    }
  }

  /**
   * @param {HTMLElement} root    page root containing the modal
   * @param {object}      opts    { filters?, onSuccess? }
   *   filters   — pre-fetched /api/filters payload (skips a second request)
   *   onSuccess — called after the appointment is created (toast + reload)
   */
  function init(root, opts) {
    const branchSel = root.querySelector('#new-branch');
    const staffSel = root.querySelector('#new-staff');
    const serviceSel = root.querySelector('#new-service');
    const roomWrap = root.querySelector('#new-room-wrap');
    const roomSel = root.querySelector('#new-room');
    const walkIn = root.querySelector('#new-walkin');
    const dateWrap = root.querySelector('#new-date-wrap');
    const timeWrap = root.querySelector('#new-time-wrap');
    const dateInput = root.querySelector('#new-date');
    const timeInput = root.querySelector('#new-time');
    const form = root.querySelector('#new-appointment-form');
    if (!branchSel || !staffSel || !serviceSel || !form) return;

    let data = opts.filters || null;
    const ready = data
      ? Promise.resolve(data)
      : AlbaBook.api.get('/api/filters').catch(() => null);

    const bind = (filters) => {
      if (!filters) return;
      data = filters;
      const staff = filters.staff || [];
      const services = filters.services || [];
      const resources = filters.resources || [];

      const refresh = () => {
        const branchId = Number(branchSel.value) || 0;
        const serviceId = Number(serviceSel.value) || 0;
        const staffId = Number(staffSel.value) || 0;
        const pickedStaff = staff.find((s) => s.id === staffId);
        const pickedService = services.find((s) => s.id === serviceId);

        const staffOk = staff.filter((s) =>
          (!branchId || (s.branch_ids || []).includes(branchId))
          && (!serviceId || (s.service_ids || []).includes(serviceId)));
        const serviceOk = services.filter((s) =>
          (!branchId || !s.branch_ids || s.branch_ids.length === 0 || s.branch_ids.includes(branchId))
          && (!pickedStaff || (pickedStaff.service_ids || []).includes(s.id)));

        refill(staffSel, staffOk, staffSel.querySelector('option')?.textContent || 'Choose staff', (s) => s.name);
        refill(serviceSel, serviceOk, serviceSel.querySelector('option')?.textContent || 'Choose a service', (s) => `${s.name} · ${s.duration_minutes} min`);

        if (roomWrap && roomSel) {
          const typeId = pickedService ? pickedService.required_resource_type_id : null;
          const rooms = typeId && branchId
            ? resources.filter((r) => r.branch_id === branchId && r.resource_type_id === typeId)
            : [];
          if (typeId) {
            roomWrap.hidden = false;
            refill(roomSel, rooms, roomSel.querySelector('option')?.textContent || 'Auto-assign', (r) => r.name);
          } else {
            roomWrap.hidden = true;
            roomSel.value = '';
          }
        }
      };

      refill(branchSel, filters.branches || [], branchSel.querySelector('option')?.textContent || 'Choose a branch', (b) => b.name);
      refresh();
      branchSel.addEventListener('change', refresh);
      staffSel.addEventListener('change', refresh);
      serviceSel.addEventListener('change', refresh);
    };

    ready.then(bind);

    // Walk-in: the server stamps start_at = now — date/time are ignored.
    if (walkIn && dateWrap && timeWrap && dateInput && timeInput) {
      const syncWalkIn = () => {
        const on = walkIn.checked;
        dateWrap.hidden = on;
        timeWrap.hidden = on;
        dateInput.required = !on;
        timeInput.required = !on;
      };
      walkIn.addEventListener('change', syncWalkIn);
      syncWalkIn();
    }

    if (typeof opts.onSuccess === 'function') {
      form.addEventListener('albabook:ajax-success', () => opts.onSuccess());
    }
  }

  AlbaBook.appointmentModal = { init };
})();
