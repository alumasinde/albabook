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

  AlbaBook.pages.register('dashboard', function (root) {
    const cards = root.querySelector('[data-kpi-cards]');
    const schedule = root.querySelector('[data-today-schedule]');
    const alerts = root.querySelector('[data-alerts]');
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
        renderChart(canvas, summary.chart);
      })
      .catch((error) => {
        if (cards) cards.replaceChildren();
        if (schedule) schedule.replaceChildren(ui.errorState(error.message, () => AlbaBook.pages.mount(document)));
      });
  });
})();
