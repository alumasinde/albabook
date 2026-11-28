/*
 * Dashboard overview (data-page="dashboard"). KPI cards, today's schedule,
 * bookings chart and alerts load from /api/dashboard/summary; KPI values are
 * computed server-side and only formatted here.
 */
(function () {
  'use strict';

  const AlbaBook = window.AlbaBook;
  const ui = AlbaBook.ui;
  const el = ui.el;

  const TONE_TREND = { up: 'trend-up', down: 'trend-down', flat: 'trend-flat' };
  const TONE_ICON = { up: 'fa-arrow-trend-up', down: 'fa-arrow-trend-down', flat: 'fa-minus' };

  function renderCards(host, cards) {
    host.replaceChildren(
      ...cards.map((card) => {
        const wrap = el('div', 'card p-5');
        const top = el('div', 'flex items-start justify-between gap-3');
        top.append(el('p', 'text-sm font-medium text-muted', card.label));
        if (card.icon) {
          const iconWrap = el('span', 'stat-icon');
          iconWrap.append(el('i', 'fa-solid ' + card.icon));
          top.append(iconWrap);
        }
        wrap.append(top, el('p', 'mt-2 text-3xl font-semibold tracking-tight text-ink', card.value));
        if (card.trend) {
          const trend = el('p', 'mt-2 text-sm ' + (TONE_TREND[card.direction] || 'trend-flat'));
          trend.append(el('i', 'fa-solid ' + (TONE_ICON[card.direction] || 'fa-minus')));
          trend.append(document.createTextNode(' ' + card.trend));
          wrap.append(trend);
        } else if (card.hint) {
          wrap.append(el('p', 'mt-2 text-sm text-muted', card.hint));
        }
        return wrap;
      }),
    );
  }

  function renderSchedule(host, rows) {
    if (!rows.length) {
      host.replaceChildren(
        ui.emptyState({
          title: 'No appointments today',
          message: 'Confirmed bookings for today will appear here.',
          icon: 'fa-calendar-check',
        }),
      );
      return;
    }
    const list = el('ul', 'divide-y divide-line');
    rows.forEach((row) => {
      const li = el('li', 'flex items-center gap-3 py-3');
      li.append(el('span', 'w-16 shrink-0 text-sm font-semibold text-ink', row.time_label));
      li.append(ui.avatar(row.customer));
      const text = el('div', 'min-w-0 flex-1');
      text.append(el('span', 'block truncate text-sm font-medium text-ink', row.customer));
      text.append(el('span', 'block truncate text-xs text-muted', [row.service, row.staff].filter(Boolean).join(' · ')));
      li.append(text, ui.statusBadge(row.status));
      const view = el('button', 'btn btn-ghost btn-sm', 'View');
      view.type = 'button';
      view.addEventListener('click', () => AlbaBook.appointmentDrawer.open(row.uuid, () => AlbaBook.pages.mount(document)));
      li.append(view);
      list.append(li);
    });
    host.replaceChildren(list);
  }

  function renderChart(canvas, chart) {
    if (!(canvas instanceof HTMLCanvasElement) || !chart || !(chart.series || []).length) {
      if (canvas) {
        const wrap = canvas.closest('[data-chart-wrap]');
        if (wrap) wrap.replaceChildren(ui.emptyState({ title: 'Not enough data', message: 'Booking trends appear once appointments exist.', icon: 'fa-chart-line' }));
      }
      return;
    }
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    const W = rect.width;
    const H = rect.height;
    const pad = { l: 34, r: 10, t: 12, b: 24 };
    const values = chart.series;
    const max = Math.max(1, ...values);
    const x = (i) => pad.l + (i / Math.max(1, values.length - 1)) * (W - pad.l - pad.r);
    const y = (v) => pad.t + (1 - v / max) * (H - pad.t - pad.b);

    ctx.clearRect(0, 0, W, H);
    const primary = getComputedStyle(document.documentElement).getPropertyValue('--color-primary') || '109 92 232';
    const line = 'rgb(' + primary.trim() + ')';
    const fill = 'rgb(' + primary.trim() + ' / 0.12)';

    ctx.strokeStyle = 'rgb(227 229 236)';
    ctx.fillStyle = 'rgb(91 97 117)';
    ctx.font = '10px Inter, sans-serif';
    for (let g = 0; g <= 3; g += 1) {
      const gy = pad.t + (g / 3) * (H - pad.t - pad.b);
      ctx.beginPath();
      ctx.moveTo(pad.l, gy);
      ctx.lineTo(W - pad.r, gy);
      ctx.stroke();
      const labelValue = Math.round(max * (1 - g / 3));
      ctx.fillText(String(labelValue), 4, gy + 3);
    }

    ctx.beginPath();
    values.forEach((v, i) => (i ? ctx.lineTo(x(i), y(v)) : ctx.moveTo(x(i), y(v))));
    ctx.strokeStyle = line;
    ctx.lineWidth = 2;
    ctx.stroke();

    ctx.lineTo(x(values.length - 1), H - pad.b);
    ctx.lineTo(x(0), H - pad.b);
    ctx.closePath();
    ctx.fillStyle = fill;
    ctx.fill();

    ctx.fillStyle = line;
    values.forEach((v, i) => {
      ctx.beginPath();
      ctx.arc(x(i), y(v), 3, 0, Math.PI * 2);
      ctx.fill();
    });

    ctx.fillStyle = 'rgb(91 97 117)';
    (chart.labels || []).forEach((text, i) => {
      ctx.fillText(String(text), x(i) - ctx.measureText(String(text)).width / 2, H - 8);
    });
  }

  function renderAlerts(host, alerts) {
    if (!host) return;
    if (!(alerts || []).length) {
      host.replaceChildren();
      return;
    }
    host.replaceChildren(
      ...alerts.map((alert) => {
        const box = el('div', 'alert alert-' + (alert.tone || 'info'));
        box.append(el('span', 'alert-text', alert.text));
        return box;
      }),
    );
  }

  /* Setup checklist: what is missing before the page can take bookings. */
  function renderSetup(section, setup) {
    if (!section) return;
    if (!setup) {
      section.hidden = true;
      return;
    }
    section.hidden = false;
    const summary = section.querySelector('[data-setup-summary]');
    summary.textContent = setup.ready
      ? 'You can take bookings. ' + (setup.total - setup.done) + ' optional step' + (setup.total - setup.done === 1 ? '' : 's') + ' left.'
      : setup.done + ' of ' + setup.total + ' done. Finish the required steps to start taking online bookings.';
    const bar = section.querySelector('[data-setup-bar]');
    bar.style.width = Math.round((setup.done / Math.max(1, setup.total)) * 100) + '%';
    const cta = section.querySelector('[data-setup-cta]');
    if (cta) cta.hidden = setup.ready;
    const list = section.querySelector('[data-setup-list]');
    list.replaceChildren(
      ...setup.items.map((item) => {
        const li = el('li');
        const link = el('a', 'flex items-start gap-3 rounded-lg border border-line p-3 hover:border-primary');
        link.href = item.href;
        const icon = el('i', 'fa-solid mt-0.5 ' + (item.done ? 'fa-circle-check text-success' : 'fa-circle text-line'));
        icon.setAttribute('aria-hidden', 'true');
        const text = el('span', 'min-w-0');
        const title = el('span', 'block text-sm font-medium ' + (item.done ? 'text-muted line-through' : 'text-ink'), item.label);
        text.append(title);
        if (!item.done) text.append(el('span', 'block text-xs text-muted', item.hint + (item.blocking || item.key === 'live' ? '' : ' (optional)')));
        const sr = el('span', 'sr-only', item.done ? ' (done)' : ' (to do)');
        link.append(icon, text, sr);
        li.append(link);
        return li;
      }),
    );
  }

  /* Booking requests awaiting approval (manual confirmation mode). */
  function renderApprovals(section, items, reload) {
    if (!section) return;
    if (!items || !items.length) {
      section.hidden = true;
      return;
    }
    section.hidden = false;
    const count = section.querySelector('[data-approvals-count]');
    if (count) count.textContent = String(items.length);
    const list = section.querySelector('[data-approvals-list]');
    list.replaceChildren(
      ...items.map((item) => {
        const li = el('li', 'flex flex-wrap items-center justify-between gap-3 py-3');
        const info = el('button', 'min-w-0 flex-1 text-left');
        info.type = 'button';
        info.append(
          el('p', 'truncate text-sm font-semibold text-ink', item.customer + ' · ' + item.service),
          el('p', 'text-xs text-muted', item.when_label + (item.staff ? ' with ' + item.staff : '') + ' · requested ' + item.requested_label),
        );
        info.addEventListener('click', () => AlbaBook.appointmentDrawer.open(item.uuid, reload));

        const act = (to, label, cls, icon) => {
          const btn = el('button', 'btn btn-sm ' + cls);
          btn.type = 'button';
          const i = el('i', 'fa-solid ' + icon);
          i.setAttribute('aria-hidden', 'true');
          btn.append(i, document.createTextNode(' ' + label));
          btn.setAttribute('aria-label', label + ' ' + item.customer + ', ' + item.when_label);
          btn.addEventListener('click', async () => {
            if (to === 'rejected') {
              const yes = await ui.confirm({
                title: 'Decline this request?',
                message: item.customer + ' will be told the booking was declined and the slot is released.',
                confirmLabel: 'Decline',
                danger: true,
              });
              if (!yes) return;
            }
            ui.setBusy(btn, true, to === 'confirmed' ? 'Confirming…' : 'Declining…');
            AlbaBook.api
              .post('/api/appointments/' + encodeURIComponent(item.uuid) + '/transition', { to, version: item.version })
              .then(() => {
                AlbaBook.toast(to === 'confirmed' ? 'Booking confirmed. The customer has been notified.' : 'Request declined.', 'success');
                reload();
              })
              .catch((error) => {
                ui.setBusy(btn, false);
                AlbaBook.toast(error.message || 'Update failed.', 'danger');
                if (error.code === 'STALE_APPOINTMENT' || error.code === 'INVALID_TRANSITION') reload();
              });
          });
          return btn;
        };
        const buttons = el('div', 'flex shrink-0 gap-2');
        buttons.append(
          act('rejected', 'Decline', 'btn-secondary', 'fa-xmark'),
          act('confirmed', 'Confirm', 'btn-primary', 'fa-check'),
        );
        li.append(info, buttons);
        return li;
      }),
    );
  }

  AlbaBook.pages.register('dashboard', function (root) {
    const cards = root.querySelector('[data-kpi-cards]');
    const schedule = root.querySelector('[data-today-schedule]');
    const alerts = root.querySelector('[data-alerts]');
    const approvals = root.querySelector('[data-approvals]');
    const setupBox = root.querySelector('[data-setup]');
    const reload = () => AlbaBook.pages.mount(document);
    const canvas = root.querySelector('[data-chart]');
    const greeting = root.querySelector('[data-greeting]');

    if (cards) cards.replaceChildren(ui.skeletonRows(4, 'cards'));
    if (schedule) schedule.replaceChildren(ui.spinner('Loading today\u2019s schedule…'));

    AlbaBook.api
      .get('/api/dashboard/summary')
      .then((summary) => {
        if (greeting && summary.greeting) greeting.textContent = summary.greeting;
        if (cards) renderCards(cards, summary.cards || []);
        if (schedule) renderSchedule(schedule, summary.today_schedule || []);
        renderAlerts(alerts, summary.alerts || []);
        renderApprovals(approvals, summary.approvals, reload);
        renderSetup(setupBox, summary.setup);
        renderChart(canvas, summary.chart);
      })
      .catch((error) => {
        if (cards) cards.replaceChildren();
        if (schedule) schedule.replaceChildren(ui.errorState(error.message, () => AlbaBook.pages.mount(document)));
      });
  });
})();
