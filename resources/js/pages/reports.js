/*
 * Reports: tabbed workspace over a shared date range. Overview keeps the
 * metric cards; the other tabs render server-computed tabular data
 * (appointments, sales, no-shows, resource utilization) with totals rows.
 * The active tab is kept in the URL (?tab=) for browser navigation.
 */
AlbaBook.pages.register('reports', function (root) {
  const { api, ui } = AlbaBook;
  const el = ui.el;
  const canFinancial = root.dataset.canFinancial === '1';

  const fromInput = root.querySelector('[data-rep-from]');
  const toInput = root.querySelector('[data-rep-to]');
  const cards = root.querySelector('[data-rep-cards]');
  const daily = root.querySelector('[data-rep-daily]');
  const services = root.querySelector('[data-rep-services]');
  const financial = root.querySelector('[data-rep-financial]');
  const tabButtons = [...root.querySelectorAll('[data-rep-tab]')];
  const panels = [...root.querySelectorAll('[data-rep-panel]')];

  const fmtDate = (d) => d.toISOString().slice(0, 10);
  const money = (minor, currency) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'KES' })
      .format((minor || 0) / 100);

  function setRange(days) {
    const to = new Date();
    const from = new Date();
    from.setDate(from.getDate() - (days - 1));
    fromInput.value = fmtDate(from);
    toInput.value = fmtDate(to);
  }

  function card(label, value, hint) {
    const c = el('div', 'card p-5');
    c.append(el('p', 'text-sm text-muted', label));
    c.append(el('p', 'mt-1 text-2xl font-semibold text-ink', String(value)));
    if (hint) c.append(el('p', 'mt-1 text-xs text-muted', hint));
    return c;
  }

  /* ------------------------------------------------------------- tabs */

  function selectTab(name, pushState) {
    tabButtons.forEach((btn) => btn.classList.toggle('is-active', btn.dataset.repTab === name));
    panels.forEach((panel) => { panel.hidden = panel.dataset.repPanel !== name; });
    if (pushState !== false) {
      const url = new URL(window.location.href);
      if (name === 'overview') url.searchParams.delete('tab');
      else url.searchParams.set('tab', name);
      window.history.replaceState({}, '', url);
    }
  }
  tabButtons.forEach((btn) => btn.addEventListener('click', () => selectTab(btn.dataset.repTab)));
  {
    const initial = new URLSearchParams(window.location.search).get('tab') || 'overview';
    const known = tabButtons.some((b) => b.dataset.repTab === initial);
    selectTab(known ? initial : 'overview', false);
  }

  /* ----------------------------------------------------------- tables */

  function dataTable(headers, rows, totalsRow, emptyText) {
    if (!rows.length) {
      return el('p', 'text-sm text-muted', emptyText || 'Nothing in this range.');
    }
    const table = el('table', 'data-table');
    const thead = el('thead');
    const htr = el('tr');
    headers.forEach((h) => htr.append(el('th', null, h)));
    thead.append(htr);
    const tbody = el('tbody');
    rows.forEach((cells) => {
      const tr = el('tr');
      cells.forEach((cell) => tr.append(el('td', null, cell)));
      tbody.append(tr);
    });
    table.append(thead, tbody);
    if (totalsRow) {
      const tfoot = el('tfoot');
      const tr = el('tr', 'font-semibold');
      totalsRow.forEach((cell) => tr.append(el('td', null, cell)));
      tfoot.append(tr);
      table.append(tfoot);
    }
    const wrap = el('div', 'overflow-x-auto');
    wrap.append(table);
    return wrap;
  }

  /* ------------------------------------------------------------- data */

  function load() {
    if (!fromInput.value || !toInput.value) return;
    const params = new URLSearchParams({ from: fromInput.value, to: toInput.value });

    const exportApts = root.querySelector('[data-rep-export-appointments]');
    if (exportApts) exportApts.href = '/api/reports/export?type=appointments&' + params.toString();
    const exportSales = root.querySelector('[data-rep-export-sales]');
    if (exportSales) exportSales.href = '/api/reports/export?type=sales&' + params.toString();

    cards.replaceChildren(ui.spinner('Loading metrics…'));
    api.get('/api/reports?' + params.toString())
      .then((data) => {
        renderCards(data.summary);
        renderDaily(data.daily);
        renderServices(data.top_services);
        if (financial && data.financial) renderFinancial(data.financial, data.currency);
        renderAppointments(data.appointments_by_day || []);
        renderByService(data.by_service || [], data.currency);
        renderNoShows(data.noshows_by_service || []);
        renderResources(data.resource_utilization || []);
        if (data.sales_by_day) renderSales(data.sales_by_day, data.currency);
      })
      .catch((error) => {
        cards.replaceChildren(ui.errorState(error.message, load));
      });
  }

  function renderCards(s) {
    cards.replaceChildren(
      card('Appointments', s.appointments_total, `${s.appointments_active} upcoming or in progress`),
      card('Completed', s.appointments_completed),
      card('Cancelled', s.appointments_cancelled, `${s.appointments_no_show} no-shows`),
      card('New customers', s.new_customers),
    );
  }

  function renderDaily(rows) {
    daily.replaceChildren();
    if (!rows.length) {
      daily.append(el('p', 'text-sm text-muted', 'No appointments in this range.'));
      return;
    }
    const max = Math.max(...rows.map((r) => Number(r.count)), 1);
    const list = el('div', 'space-y-2');
    rows.forEach((r) => {
      const row = el('div', 'flex items-center gap-3 text-sm');
      row.append(el('span', 'w-24 shrink-0 text-muted', r.day));
      const barWrap = el('div', 'h-2 flex-1 overflow-hidden rounded-full bg-surface');
      const bar = el('div', 'h-full rounded-full bg-primary');
      bar.style.width = Math.round((Number(r.count) / max) * 100) + '%';
      barWrap.append(bar);
      row.append(barWrap, el('span', 'w-8 text-right font-medium text-ink', String(r.count)));
      list.append(row);
    });
    daily.append(list);
  }

  function renderServices(rows) {
    services.replaceChildren();
    if (!rows.length) {
      services.append(el('p', 'text-sm text-muted', 'No bookings in this range.'));
      return;
    }
    const list = el('ol', 'space-y-2');
    rows.forEach((r, i) => {
      const row = el('li', 'flex items-center justify-between gap-3 text-sm');
      const left = el('span', 'flex items-center gap-2 text-ink');
      left.append(el('span', 'w-5 text-xs font-semibold text-muted', `${i + 1}.`));
      left.append(document.createTextNode(r.name));
      row.append(left, ui.badge(`${r.bookings} bookings`, 'neutral'));
      list.append(row);
    });
    services.append(list);
  }

  let lastFinancial = null;

  function renderFinancial(f, currency) {
    lastFinancial = f;
    financial.replaceChildren();
    const grid = el('div', 'grid gap-4 sm:grid-cols-3');
    grid.append(
      card('Collected', money(f.collected_minor, currency), `${f.payments_count} payments`),
      card('Booked value', money(f.booked_value_minor, currency)),
      card('Outstanding', money(Math.max(0, f.booked_value_minor - f.collected_minor), currency)),
    );
    financial.append(grid);
  }

  /* ------------------------------------------------- tabular sections */

  function renderAppointments(rows) {
    const host = root.querySelector('[data-rep-appointments-table]');
    host.replaceChildren(dataTable(
      ['Day', 'Booked', 'Completed', 'Active', 'Cancelled', 'No-shows'],
      rows.map((r) => [r.day, r.total, r.completed, r.active, r.cancelled, r.no_shows]),
      ['Total',
        rows.reduce((n, r) => n + Number(r.total), 0),
        rows.reduce((n, r) => n + Number(r.completed), 0),
        rows.reduce((n, r) => n + Number(r.active), 0),
        rows.reduce((n, r) => n + Number(r.cancelled), 0),
        rows.reduce((n, r) => n + Number(r.no_shows), 0)],
      'No appointments in this range.',
    ));
  }

  function renderByService(rows, currency) {
    const host = root.querySelector('[data-rep-service-table]');
    host.replaceChildren(dataTable(
      ['Service', 'Bookings', 'Completed', 'Cancelled', 'No-shows', 'Revenue'],
      rows.map((r) => [
        r.service, r.bookings, r.completed, r.cancelled, r.no_shows,
        money(r.revenue_minor, r.currency || currency),
      ]),
      rows.length ? ['Total',
        rows.reduce((n, r) => n + Number(r.bookings), 0),
        rows.reduce((n, r) => n + Number(r.completed), 0),
        rows.reduce((n, r) => n + Number(r.cancelled), 0),
        rows.reduce((n, r) => n + Number(r.no_shows), 0),
        money(rows.reduce((n, r) => n + Number(r.revenue_minor), 0), currency)] : null,
      'No bookings in this range.',
    ));
  }

  function renderSales(rows, currency) {
    const host = root.querySelector('[data-rep-sales-table]');
    if (!host) return;
    host.replaceChildren(dataTable(
      ['Day', 'Payments', 'Gross', 'Refunded', 'Net'],
      rows.map((r) => [
        r.day, r.payments_count,
        money(r.gross_minor, currency),
        money(r.refunded_minor, currency),
        money(Number(r.gross_minor) - Number(r.refunded_minor), currency),
      ]),
      rows.length ? ['Total',
        rows.reduce((n, r) => n + Number(r.payments_count), 0),
        money(rows.reduce((n, r) => n + Number(r.gross_minor), 0), currency),
        money(rows.reduce((n, r) => n + Number(r.refunded_minor), 0), currency),
        money(rows.reduce((n, r) => n + Number(r.gross_minor) - Number(r.refunded_minor), 0), currency)] : null,
      'No payments in this range.',
    ));

    const methodHost = root.querySelector('[data-rep-method-table]');
    if (methodHost && lastFinancial) {
      methodHost.replaceChildren(dataTable(
        ['Method', 'Payments', 'Amount'],
        (lastFinancial.by_method || []).map((m) => [
          m.method.replace(/_/g, ' '), m.count, money(m.amount_minor, currency),
        ]),
        null,
        'No payments in this range.',
      ));
    }
  }

  function renderNoShows(rows) {
    const host = root.querySelector('[data-rep-noshows-table]');
    host.replaceChildren(dataTable(
      ['Service', 'Booked', 'No-shows', 'No-show rate'],
      rows.map((r) => [
        r.service, r.booked, r.no_shows,
        Number(r.booked) > 0 ? Math.round((Number(r.no_shows) / Number(r.booked)) * 100) + '%' : '—',
      ]),
      rows.length ? ['Total',
        rows.reduce((n, r) => n + Number(r.booked), 0),
        rows.reduce((n, r) => n + Number(r.no_shows), 0),
        (() => {
          const booked = rows.reduce((n, r) => n + Number(r.booked), 0);
          const ns = rows.reduce((n, r) => n + Number(r.no_shows), 0);
          return booked > 0 ? Math.round((ns / booked) * 100) + '%' : '—';
        })()] : null,
      'No completed or past bookings in this range.',
    ));
  }

  function renderResources(rows) {
    const host = root.querySelector('[data-rep-resources-table]');
    const hours = (mins) => (Number(mins) / 60).toFixed(1) + ' h';
    host.replaceChildren(dataTable(
      ['Resource', 'Type', 'Branch', 'Bookings', 'Occupied time'],
      rows.map((r) => [r.resource, r.type, r.branch, r.bookings, hours(r.occupied_minutes)]),
      rows.length ? ['Total', '', '',
        rows.reduce((n, r) => n + Number(r.bookings), 0),
        hours(rows.reduce((n, r) => n + Number(r.occupied_minutes), 0))] : null,
      'No resource bookings in this range.',
    ));
  }

  root.querySelectorAll('[data-rep-range]').forEach((btn) => {
    btn.addEventListener('click', () => { setRange(Number(btn.dataset.repRange)); load(); });
  });
  root.querySelector('[data-rep-apply]').addEventListener('click', load);

  setRange(30);
  load();
});
