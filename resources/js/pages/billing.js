/*
 * Billing: plan card with change-plan, invoice table with M-Pesa pay and
 * payment history. Hydrates via /api/billing.
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

  function load() {
    api.get('/api/billing')
      .then((data) => {
        renderPlan(data.subscription, data.features, data.plans, data.can_manage);
        renderInvoices(data.invoices, data.can_manage);
        renderPayments(data.payments);
      })
      .catch((error) => planWrap.replaceChildren(ui.errorState(error.message, () => location.reload())));
  }
  load();

  function renderPlan(sub, features, plans, canManage) {
    planWrap.replaceChildren();
    if (!sub) {
      planWrap.append(ui.emptyState({
        title: 'No subscription',
        message: 'Your workspace has no subscription record yet.',
        icon: 'fa-credit-card',
      }));
      renderPlanPicker(plans, null, canManage);
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
    if (sub.scheduled_plan_name) fact('Switching to', sub.scheduled_plan_name);
    if (sub.cancelled_at) fact('Cancelled', String(sub.cancelled_at).slice(0, 10));
    planWrap.append(facts);

    if (['trialing', 'expired', 'past_due'].includes(sub.status)) {
      const note = el('p', 'mt-4 rounded-lg bg-warning/10 px-3 py-2 text-sm text-warning-strong',
        sub.status === 'trialing'
          ? 'Pick a plan before the trial ends to keep taking bookings.'
          : 'The workspace is limited until the plan invoice is paid.');
      planWrap.append(note);
    }

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

    renderPlanPicker(plans, sub, canManage);
  }

  function renderPlanPicker(plans, sub, canManage) {
    if (!canManage || !plans.length) return;
    const wrap = el('div', 'mt-4 border-t border-line pt-4');
    wrap.append(el('p', 'mb-2 text-sm font-medium text-ink', 'Change plan'));
    const select = el('select', 'select w-full');
    select.append(el('option', '', 'Choose a plan…'));
    select.firstChild.value = '';
    plans.forEach((p) => {
      const opt = el('option', '',
        `${p.name} — ${money(p.price_minor, p.currency)}/${p.billing_interval === 'yearly' ? 'yr' : 'mo'}`);
      opt.value = p.code;
      if (sub && p.code === sub.plan_code) opt.disabled = true;
      select.append(opt);
    });
    const btn = el('button', 'btn btn-primary btn-sm mt-3', 'Apply');
    btn.addEventListener('click', () => {
      if (!select.value) return;
      ui.setBusy(btn, true, 'Applying…');
      api.post('/api/billing/plan', { plan: select.value })
        .then((result) => {
          const msg = {
            unchanged: 'Already on that plan.',
            scheduled: `Plan change scheduled — ${result.plan} applies from the next renewal.`,
            invoice_issued: `Invoice for ${result.plan} issued — pay it below to activate.`,
            upgrade_invoiced: `Prorated ${result.plan} invoice issued — pay it below to upgrade.`,
          }[result.action] || 'Plan updated.';
          AlbaBook.toast(msg);
          load();
        })
        .catch((error) => AlbaBook.toast(error.message, 'danger'))
        .finally(() => ui.setBusy(btn, false));
    });
    wrap.append(select, btn);
    planWrap.append(wrap);
  }

  function renderInvoices(invoices, canManage) {
    invoicesWrap.replaceChildren();
    if (!invoices.length) {
      invoicesWrap.append(el('p', 'px-4 py-8 text-center text-sm text-muted', 'No invoices yet.'));
      return;
    }
    const table = el('table', 'data-table');
    const thead = el('thead');
    const headRow = el('tr');
    ['Invoice', 'Issued', 'Due', 'Total', 'Status', ''].forEach((h) => headRow.append(el('th', '', h)));
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
      const payTd = el('td', 'text-right');
      if (canManage && (inv.status === 'issued' || inv.status === 'overdue')) {
        const pay = el('button', 'btn btn-secondary btn-xs', 'Pay with M-Pesa');
        pay.addEventListener('click', () => openPay(inv));
        payTd.append(pay);
      }
      tr.append(payTd);
      tbody.append(tr);
    });
    table.append(thead, tbody);
    invoicesWrap.append(table);
  }

  function openPay(inv) {
    const shell = ui.drawerShell(`Pay ${inv.invoice_number}`, () => ui.drawer.close());
    const form = el('form', 'mt-4 space-y-4');
    const label = el('label', 'block text-sm font-medium text-ink', 'M-Pesa phone number');
    const input = el('input', 'input mt-1 w-full');
    input.type = 'tel';
    input.placeholder = '0712 345 678';
    input.required = true;
    input.autocomplete = 'tel';
    const err = el('p', 'field-error mt-1 text-sm text-danger-strong');
    const submit = el('button', 'btn btn-primary btn-md w-full', `Pay ${money(inv.total_minor, inv.currency)}`);
    submit.type = 'submit';
    form.append(label, input, err, el('p', 'text-xs text-muted',
      'A prompt is sent to this phone — approve it with your M-Pesa PIN.'), submit);
    shell.append(el('p', 'mt-1 text-sm text-muted',
      `${money(inv.total_minor, inv.currency)} to AlbaBook via M-Pesa.`));
    shell.append(form);
    ui.drawer.setContent(shell);
    ui.drawer.open();
    input.focus();

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      err.textContent = '';
      ui.setBusy(submit, true, 'Sending prompt…');
      api.post('/api/billing/pay', { invoice_uuid: inv.uuid, phone: input.value.trim() })
        .then((result) => {
          AlbaBook.toast(result.customer_message || 'Check your phone for the M-Pesa prompt.');
          pollPayment(result.payment_uuid, submit);
        })
        .catch((error) => {
          err.textContent = error.message;
          ui.setBusy(submit, false);
        });
    });
  }

  /* Poll until the prompt resolves; the callback usually wins but this
     covers it being late. */
  function pollPayment(paymentUuid, submit) {
    let attempts = 0;
    const tick = () => {
      attempts += 1;
      api.get('/api/billing/payments/' + encodeURIComponent(paymentUuid))
        .then((p) => {
          if (p.status === 'pending' && attempts < 40) {
            setTimeout(tick, 3000);
            return;
          }
          ui.drawer.close();
          ui.setBusy(submit, false);
          if (p.status === 'succeeded') {
            AlbaBook.toast('Payment received' + (p.receipt ? ` — ${p.receipt}` : '') + '. Thank you.');
          } else {
            AlbaBook.toast('The M-Pesa payment did not complete. You can try again.', 'danger');
          }
          load();
        })
        .catch(() => {
          ui.drawer.close();
          ui.setBusy(submit, false);
          load();
        });
    };
    setTimeout(tick, 3000);
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
