/*
 * Calendar page (data-page="calendar"). Week/day grid, month density view,
 * branch/staff filters, details drawer, manual booking modal and a 60s
 * poll so receptionists see new public bookings (dashboard.md §3).
 */
(function () {
  'use strict';

  const AlbaBook = window.AlbaBook;
  const ui = AlbaBook.ui;
  const el = ui.el;

  const STATUS_COLORS = {
    confirmed: 'cal-confirmed', pending: 'cal-pending', checked_in: 'cal-checked_in',
    completed: 'cal-completed', cancelled: 'cal-cancelled', no_show: 'cal-no_show',
  };
  const POLL_MS = 60000;

  function toIso(date) {
    const pad = (n) => String(n).padStart(2, '0');
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
  }
  function startOfWeek(date) {
    const d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const day = (d.getDay() + 6) % 7; // Monday = 0
    d.setDate(d.getDate() - day);
    return d;
  }
  function addDays(date, n) {
    const d = new Date(date);
    d.setDate(d.getDate() + n);
    return d;
  }
  function monthLabel(date) {
    return date.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
  }
  function timeToMinutes(value) {
    const [h, m] = String(value).split(':').map(Number);
    return h * 60 + (m || 0);
  }

  AlbaBook.pages.register('calendar', function (root) {
    const grid = root.querySelector('[data-cal-grid]');
    const label = root.querySelector('[data-cal-label]');
    const branchSel = root.querySelector('[data-cal-branch]');
    const staffSel = root.querySelector('[data-cal-staff]');
    const dayOfWeek = startOfWeek(new Date());
    const state = { view: 'week', anchor: dayOfWeek, branch: '', staff: '' };
    let timer = null;
    let filters = { branches: [], staff: [], services: [] };

    /* ------------------------------------------------ view switching */
    root.querySelectorAll('[data-cal-view]').forEach((btn) => {
      btn.addEventListener('click', () => {
        state.view = btn.getAttribute('data-cal-view');
        root.querySelectorAll('[data-cal-view]').forEach((b) => b.classList.toggle('is-active', b === btn));
        reload();
      });
    });
    root.querySelector('[data-cal-prev]').addEventListener('click', () => {
      state.anchor = addDays(state.anchor, state.view === 'month' ? -30 : state.view === 'day' ? -1 : -7);
      if (state.view === 'month') state.anchor = new Date(state.anchor.getFullYear(), state.anchor.getMonth() - 1, 1);
      reload();
    });
    root.querySelector('[data-cal-next]').addEventListener('click', () => {
      if (state.view === 'month') state.anchor = new Date(state.anchor.getFullYear(), state.anchor.getMonth() + 1, 1);
      else state.anchor = addDays(state.anchor, state.view === 'day' ? 1 : 7);
      reload();
    });
    if (branchSel) branchSel.addEventListener('change', () => { state.branch = branchSel.value; reload(); });
    if (staffSel) staffSel.addEventListener('change', () => { state.staff = staffSel.value; reload(); });

    /* ------------------------------------------------ range + fetch */
    function range() {
      if (state.view === 'day') {
        const from = toIso(state.anchor);
        return { from, to: from };
      }
      if (state.view === 'month') {
        const first = new Date(state.anchor.getFullYear(), state.anchor.getMonth(), 1);
        const last = new Date(state.anchor.getFullYear(), state.anchor.getMonth() + 1, 0);
        return { from: toIso(first), to: toIso(last) };
      }
      const weekStart = startOfWeek(state.anchor);
      return { from: toIso(weekStart), to: toIso(addDays(weekStart, 6)) };
    }

    function reload() {
      const { from, to } = range();
      if (grid) grid.replaceChildren(ui.spinner('Loading calendar…'));
      AlbaBook.api
        .get('/api/calendar/events', { query: { from, to, branch: state.branch, staff: state.staff } })
        .then((result) => {
          if (state.view === 'month') renderMonth(result, from);
          else renderGrid(result, from);
        })
        .catch((error) => {
          if (grid) grid.replaceChildren(ui.errorState(error.message, reload));
        });
    }

    /* ------------------------------------------------ week/day grid */
    function renderGrid(result, fromIso) {
      const days = [];
      const start = new Date(fromIso + 'T00:00:00');
      const dayCount = state.view === 'day' ? 1 : 7;
      for (let i = 0; i < dayCount; i += 1) days.push(addDays(start, i));
      if (label) {
        label.textContent = state.view === 'day'
          ? start.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })
          : start.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) + ' – ' +
            addDays(start, dayCount - 1).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
      }

      const events = result.events || [];
      let minMin = 8 * 60;
      let maxMin = 17 * 60;
      events.forEach((e) => {
        minMin = Math.min(minMin, timeToMinutes(e.start_time));
        maxMin = Math.max(maxMin, timeToMinutes(e.end_time));
      });
      minMin = Math.floor(minMin / 60) * 60;
      maxMin = Math.ceil(maxMin / 60) * 60;
      const span = Math.max(60, maxMin - minMin);
      const HOUR_PX = 72;

      const wrap = el('div', 'cal-wrap');
      const header = el('div', 'cal-header', '');
      header.style.setProperty('--cal-cols', String(dayCount));
      header.append(el('div', 'cal-gutter-head'));
      const todayIso = toIso(new Date());
      days.forEach((day) => {
        const iso = toIso(day);
        const cell = el('div', 'cal-day-head' + (iso === todayIso ? ' is-today' : ''));
        cell.append(el('span', 'block text-xs text-muted', day.toLocaleDateString('en-GB', { weekday: 'short' })));
        cell.append(el('span', 'block text-sm font-semibold text-ink', day.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })));
        header.append(cell);
      });

      const body = el('div', 'cal-body');
      body.style.setProperty('--cal-cols', String(dayCount));
      body.style.height = (span / 60) * HOUR_PX + 'px';

      const gutter = el('div', 'cal-gutter');
      for (let m = minMin; m < maxMin; m += 60) {
        const tick = el('div', 'cal-tick', String(Math.floor(m / 60)).padStart(2, '0') + ':00');
        tick.style.top = ((m - minMin) / span) * 100 + '%';
        gutter.append(tick);
      }
      body.append(gutter);

      const cols = el('div', 'cal-cols');
      days.forEach((day) => {
        const iso = toIso(day);
        const col = el('div', 'cal-col' + (iso === todayIso ? ' is-today' : ''));
        for (let m = minMin; m < maxMin; m += 60) {
          const line = el('div', 'cal-hour-line');
          line.style.top = ((m - minMin) / span) * 100 + '%';
          col.append(line);
        }
        events
          .filter((e) => e.date === iso)
          .forEach((e) => {
            const top = ((timeToMinutes(e.start_time) - minMin) / span) * 100;
            const height = Math.max(3.5, ((timeToMinutes(e.end_time) - timeToMinutes(e.start_time)) / span) * 100);
            const card = el('button', 'cal-event ' + (STATUS_COLORS[e.status] || 'cal-confirmed'));
            card.type = 'button';
            card.style.top = top + '%';
            card.style.height = 'calc(' + height + '% - 2px)';
            card.append(el('span', 'block truncate font-medium', e.customer));
            card.append(el('span', 'block truncate text-xs', e.service));
            card.append(el('span', 'block truncate text-xs', e.start_time + ' – ' + e.end_time + (e.staff ? ' · ' + e.staff : '')));
            card.addEventListener('click', () => AlbaBook.appointmentDrawer.open(e.uuid, reload));
            col.append(card);
          });
        cols.append(col);
      });
      body.append(cols);
      wrap.append(header, body);
      grid.replaceChildren(wrap);
    }

    /* ---------------------------------------------------- month view */
    function renderMonth(result, fromIso) {
      const first = new Date(fromIso + 'T00:00:00');
      if (label) label.textContent = monthLabel(first);
      const counts = {};
      (result.events || []).forEach((e) => {
        counts[e.date] = (counts[e.date] || 0) + 1;
      });
      const wrap = el('div', 'cal-month');
      ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].forEach((d) =>
        wrap.append(el('div', 'cal-month-head', d)),
      );
      const monthStart = new Date(first.getFullYear(), first.getMonth(), 1);
      const lead = (monthStart.getDay() + 6) % 7;
      const daysInMonth = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
      for (let i = 0; i < lead; i += 1) wrap.append(el('div', 'cal-month-cell is-blank'));
      for (let d = 1; d <= daysInMonth; d += 1) {
        const iso = toIso(new Date(first.getFullYear(), first.getMonth(), d));
        const cell = el('button', 'cal-month-cell' + (iso === toIso(new Date()) ? ' is-today' : ''));
        cell.type = 'button';
        cell.append(el('span', 'text-sm font-medium text-ink', String(d)));
        if (counts[iso]) {
          const chip = el('span', 'cal-month-count', counts[iso] + (counts[iso] === 1 ? ' booking' : ' bookings'));
          cell.append(chip);
        }
        cell.addEventListener('click', () => {
          state.view = 'week';
          state.anchor = new Date(first.getFullYear(), first.getMonth(), d);
          root.querySelectorAll('[data-cal-view]').forEach((b) => b.classList.toggle('is-active', b.getAttribute('data-cal-view') === 'week'));
          reload();
        });
        wrap.append(cell);
      }
      grid.replaceChildren(wrap);
    }

    /* -------------------------------------------- new appointment modal */
    AlbaBook.api.get('/api/filters').then((f) => {
      filters = f;
      const fill = (select, items, allLabel) => {
        if (!select) return;
        select.replaceChildren(el('option', '', allLabel));
        (items || []).forEach((item) => {
          const option = el('option', '', item.name);
          option.value = item.id;
          select.append(option);
        });
      };
      fill(branchSel, filters.branches, 'All Branches');
      fill(staffSel, filters.staff, 'All Staff');
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
    timer = setInterval(() => {
      if (document.visibilityState === 'visible') reload();
    }, POLL_MS);

    return () => clearInterval(timer);
  });
})();
