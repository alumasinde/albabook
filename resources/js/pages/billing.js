/*
 * Billing: plan card, invoice table and payment history — read-only until
 * a billing provider is wired.
 */
AlbaBook.pages.register('billing', function (root) {
  const { api, ui } = AlbaBook;
  const el = ui.el;

  const planWrap = root.querySelector('[data-bill-plan]');
  const invoicesWrap = root.querySelector('[data-bill-invoices]');
  const paymentsWrap = root.querySelector('[data-bill-payments]');

  const money = (minor, currency) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'KES' })
      .format((minor || 0) / 100);
  const pretty = (value) => String(value || '').replace(/_/g, ' ');

  api.get('/api/billing')
    .then((data) => {
      renderPlan(data.subscription, data.features);
      renderInvoices(data.invoices);
      renderPayments(data.payments);
    })
    .catch((error) => planWrap.replaceChildren(ui.errorState(error.message, () => location.reload())));

  function renderPlan(sub, features) {
    planWrap.replaceChildren();
    if (!sub) {
      planWrap.append(ui.emptyState({
        title: 'No subscription',
        message: 'Your workspace has no subscription record yet.',
        icon: 'fa-credit-card',
      }));
      return;
    }
    const head = el('div', 'flex items-center justify-between gap-3');
    head.append(el('p', 'text-xl font-semibold text-ink', sub.plan_name));
    const tone = { active: 'success', trialing: 'info', past_due: 'warning', cancelled: 'neutral', expired: 'neutral' }[sub.status] || 'neutral';
    head.append(ui.badge(pretty(sub.status), tone));
    planWrap.append(head);
    planWrap.append(el('p', 'mt-1 text-sm text-muted',
      `${money(sub.price_minor, sub.currency)} / ${sub.billing_interval === 'yearly' ? 'year' : 'month'}`));
    if (sub.plan_description) planWrap.append(el('p', 'mt-2 text-sm text-muted', sub.plan_description));

    const facts = el('dl', 'mt-4 space-y-2 text-sm');
    const fact = (label, value) => {
      const row = el('div', 'flex justify-between gap-4');
      row.append(el('dt', 'text-muted', label), el('dd', 'font-medium text-ink', value));
      facts.append(row);
    };
    fact('Period', `${String(sub.current_period_start).slice(0, 10)} → ${String(sub.current_period_end).slice(0, 10)}`);
    if (sub.trial_ends_at) fact('Trial ends', String(sub.trial_ends_at).slice(0, 10));
    if (sub.cancelled_at) fact('Cancelled', String(sub.cancelled_at).slice(0, 10));
    planWrap.append(facts);

    if (features.length) {
      const list = el('ul', 'mt-4 space-y-1.5 border-t border-line pt-4 text-sm text-ink');
      features.forEach((f) => {
        const li = el('li', 'flex items-center gap-2');
        li.append(el('i', 'fa-solid fa-check text-success'));
        li.append(document.createTextNode(pretty(f.feature_key) + (f.limit_value !== null ? ` (limit ${f.limit_value})` : '')));
        list.append(li);
      });
      planWrap.append(list);
    }
  }

  function renderInvoices(invoices) {
    invoicesWrap.replaceChildren();
    if (!invoices.length) {
      invoicesWrap.append(el('p', 'px-4 py-8 text-center text-sm text-muted', 'No invoices yet.'));
      return;
    }
    const table = el('table', 'data-table');
    const thead = el('thead');
    const headRow = el('tr');
    ['Invoice', 'Issued', 'Due', 'Total', 'Status'].forEach((h) => headRow.append(el('th', '', h)));
    thead.append(headRow);
    const tbody = el('tbody');
    invoices.forEach((inv) => {
      const tr = el('tr');
      tr.append(
        el('td', 'font-mono text-sm text-ink', inv.invoice_number),
        el('td', 'text-sm text-muted', inv.issued_at ? inv.issued_at.slice(0, 10) : '—'),
        el('td', 'text-sm text-muted', inv.due_at ? inv.due_at.slice(0, 10) : '—'),
        el('td', 'text-sm font-medium text-ink', money(inv.total_minor, inv.currency)),
      );
      const statusTd = el('td');
      const tone = { paid: 'success', issued: 'info', overdue: 'warning', void: 'neutral', draft: 'neutral' }[inv.status] || 'neutral';
      statusTd.append(ui.badge(inv.status, tone));
      tr.append(statusTd);
      tbody.append(tr);
    });
    table.append(thead, tbody);
    invoicesWrap.append(table);
  }

  function renderPayments(payments) {
    paymentsWrap.replaceChildren();
    if (!payments.length) {
      paymentsWrap.append(el('p', 'text-sm text-muted', 'No subscription payments recorded yet.'));
      return;
    }
    const list = el('div', 'divide-y divide-line rounded-xl border border-line');
    payments.forEach((p) => {
      const row = el('div', 'flex items-center justify-between gap-3 px-4 py-3 text-sm');
      const left = el('div');
      left.append(el('p', 'font-medium text-ink', `${money(p.amount_minor, p.currency)} · ${p.invoice_number}`));
      left.append(el('p', 'text-xs text-muted',
        `${p.paid_at ? p.paid_at.slice(0, 16) : 'unpaid'}${p.provider_reference ? ' · ref ' + p.provider_reference : ''}`));
      const tone = { succeeded: 'success', failed: 'warning', pending: 'info', cancelled: 'neutral' }[p.status] || 'neutral';
      row.append(left, ui.badge(p.status, tone));
      list.append(row);
    });
    paymentsWrap.append(list);
  }
});
