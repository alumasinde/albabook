/*
 * Till / checkout drawer (AlbaBook.tillDrawer). The appointment is the
 * ticket: booked services are read-only lines, till lines are services,
 * products, discounts and tips added at the desk, tenders are payment
 * methods including split payments. All totals are server-computed —
 * this UI only displays and submits intents.
 */
(function () {
  'use strict';

  const AlbaBook = window.AlbaBook;
  const ui = AlbaBook.ui;
  const api = AlbaBook.api;
  const el = ui.el;

  function fmt(minor, currency) {
    try {
      return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format((minor || 0) / 100);
    } catch (e) {
      return currency + ' ' + ((minor || 0) / 100).toFixed(2);
    }
  }

  function lineRow(label, detail, amount, actions) {
    const row = el('div', 'flex items-center justify-between gap-3 rounded-lg border border-line px-3 py-2 text-sm');
    const left = el('div', 'min-w-0');
    left.append(el('div', 'font-medium text-ink', label));
    if (detail) left.append(el('div', 'text-xs text-muted', detail));
    const right = el('div', 'flex items-center gap-2 shrink-0');
    right.append(el('span', 'font-medium text-ink', amount));
    if (actions) right.append(actions);
    row.append(left, right);
    return row;
  }

  function iconBtn(icon, label, onClick) {
    const btn = el('button', 'icon-button');
    btn.type = 'button';
    btn.setAttribute('aria-label', label);
    btn.append(el('i', 'fa-solid ' + icon));
    btn.addEventListener('click', onClick);
    return btn;
  }

  AlbaBook.tillDrawer = {
    open(uuid, onChange) {
      const shell = ui.drawerShell('Till — Checkout');
      shell.body.append(ui.spinner('Loading ticket…'));
      ui.drawer.setContent(shell.wrap);
      ui.drawer.open();
      this._uuid = uuid;
      this._onChange = onChange;
      this._body = shell.body;
      this._load(shell);
    },

    _load(shell) {
      api.get('/api/appointments/' + encodeURIComponent(this._uuid) + '/ticket')
        .then((data) => this._render(data, shell.body))
        .catch((error) => shell.body.replaceChildren(ui.errorState(error.message, () => this._load(shell))));
    },

    _refresh(shell) {
      this._load(shell);
      if (this._onChange) this._onChange();
    },

    _render(t, body) {
      body.replaceChildren();
      const cur = t.appointment.currency;
      const editable = t.open && t.can.record;

      /* header */
      const head = el('div');
      head.append(el('h3', 'text-base font-semibold text-ink', t.appointment.customer_name || 'Walk-in'));
      const headRow = el('div', 'mt-2 flex flex-wrap items-center gap-2');
      headRow.append(ui.statusBadge(t.appointment.status), ui.paymentBadge(t.appointment.payment_status));
      headRow.append(el('span', 'text-xs text-muted', '#' + t.appointment.reference));
      head.append(headRow);
      body.append(head);

      /* lines */
      body.append(el('h4', 'mt-6 text-sm font-semibold text-ink', 'Items'));
      const lines = el('div', 'mt-2 space-y-2');
      (t.booked_items || []).forEach((item) => {
        lines.append(lineRow(
          item.label + (item.quantity > 1 ? ' ×' + item.quantity : ''),
          'Booked service',
          fmt(item.unit_amount_minor * item.quantity + (item.tax_amount_minor || 0) * item.quantity, cur),
        ));
      });
      (t.sale_items || []).forEach((item) => {
        const actions = editable ? el('div', 'flex items-center gap-1') : null;
        if (actions && (item.kind === 'service' || item.kind === 'product')) {
          actions.append(iconBtn('fa-minus', 'Decrease quantity', () => this._qty(t, item, -1)));
          actions.append(el('span', 'w-6 text-center text-sm text-ink', String(item.quantity)));
          actions.append(iconBtn('fa-plus', 'Increase quantity', () => this._qty(t, item, 1)));
        }
        if (actions) {
          actions.append(iconBtn('fa-trash-can', 'Remove line', () => this._remove(item)));
        }
        const kindLabel = { service: 'Added service', product: 'Product', discount: 'Discount', tip: 'Tip' }[item.kind] || item.kind;
        lines.append(lineRow(item.label, kindLabel, fmt(item.total_minor, cur), actions));
      });
      if (!t.booked_items.length && !t.sale_items.length) {
        lines.append(ui.emptyState({ icon: 'fa-receipt', title: 'Empty ticket', message: 'Add services, products or adjustments below.' }));
      }
      body.append(lines);

      /* add item */
      if (editable) body.append(this._addForm(t));

      /* totals */
      const totals = el('div', 'mt-5 space-y-1.5 border-t border-line pt-4 text-sm');
      const totalRow = (label, value, strong) => {
        const r = el('div', 'flex justify-between' + (strong ? ' font-semibold text-ink' : ' text-muted'));
        r.append(el('span', '', label), el('span', strong ? 'text-ink' : 'text-ink', value));
        return r;
      };
      totals.append(totalRow('Subtotal', fmt(t.totals.subtotal_minor, cur)));
      if (t.totals.discount_minor > 0) totals.append(totalRow('Discount', '−' + fmt(t.totals.discount_minor, cur)));
      if (t.totals.service_charge_minor > 0) totals.append(totalRow('Service charge (' + (t.service_charge_bp / 100).toFixed(2) + '%)', fmt(t.totals.service_charge_minor, cur)));
      if (t.totals.tax_minor > 0) totals.append(totalRow('VAT', fmt(t.totals.tax_minor, cur)));
      if (t.totals.tip_minor > 0) totals.append(totalRow('Tips', fmt(t.totals.tip_minor, cur)));
      totals.append(
        totalRow('Total', fmt(t.totals.total_minor, cur), true),
        totalRow('Paid', fmt(t.totals.paid_minor, cur)),
        totalRow('Balance due', fmt(Math.max(0, t.totals.balance_minor), cur), true),
      );
      body.append(totals);

      /* tenders */
      if ((t.payments || []).length || (t.refunds || []).length) {
        body.append(el('h4', 'mt-6 text-sm font-semibold text-ink', 'Payments'));
        const list = el('div', 'mt-2 space-y-2');
        (t.payments || []).forEach((p) => {
          const actions = el('div', 'flex items-center gap-2');
          actions.append(ui.badge(p.status, p.status === 'succeeded' ? 'success' : p.status === 'pending' ? 'warning' : 'danger'));
          if (t.can.refund && p.status === 'succeeded') {
            const refBtn = el('button', 'btn btn-ghost btn-sm', 'Refund');
            refBtn.type = 'button';
            refBtn.addEventListener('click', () => this._refundPrompt(t, p));
            actions.append(refBtn);
          }
          list.append(lineRow(
            (p.method || '').replace(/_/g, ' ') + (p.provider_reference ? ' · ' + p.provider_reference : ''),
            p.kind.replace(/_/g, ' '),
            fmt(p.amount_minor, cur),
            actions,
          ));
        });
        (t.refunds || []).forEach((r) => {
          list.append(lineRow('Refund', r.reason || '', '−' + fmt(r.amount_minor, cur),
            ui.badge(r.status, r.status === 'succeeded' ? 'neutral' : 'warning')));
        });
        body.append(list);
      }

      /* payment form */
      if (editable) body.append(this._payForm(t));

      /* receipt link — visible once the ticket is closed or already paid */
      if (!t.open || (t.payments || []).length) {
        const receipt = el('a', 'btn btn-secondary btn-md mt-4 w-full');
        receipt.href = t.receipt_url;
        receipt.target = '_blank';
        receipt.rel = 'noopener';
        receipt.append(el('i', 'fa-solid fa-receipt'));
        receipt.append(document.createTextNode(' View / Print Receipt'));
        body.append(receipt);
      }

      /* checkout */
      if (t.open && t.can.checkout) {
        const btn = el('button', 'btn btn-primary btn-md mt-6 w-full');
        btn.type = 'button';
        btn.append(el('i', 'fa-solid fa-flag-checkered'));
        btn.append(document.createTextNode(t.totals.balance_minor > 0
          ? (t.full_payment_required ? ' Pay ' + fmt(t.totals.balance_minor, cur) + ' & Complete' : ' Complete (pay later)')
          : ' Complete Appointment'));
        btn.addEventListener('click', () => this._checkout(t, btn));
        body.append(btn);
      } else if (!t.open) {
        body.append(el('p', 'mt-5 rounded-lg bg-surface px-3 py-2 text-center text-sm text-muted', 'This ticket is closed.'));
      }
    },

    _addForm(t) {
      const box = el('div', 'mt-4 rounded-xl border border-line p-4');
      box.append(el('h4', 'text-sm font-semibold text-ink', 'Add to ticket'));

      const kind = el('select', 'input select mt-3');
      [['service', 'Service'], ['product', 'Product'], ['discount', 'Discount'], ['tip', 'Tip']].forEach(([v, l]) => {
        const o = el('option', '', l);
        o.value = v;
        kind.append(o);
      });

      const ref = el('select', 'input select mt-2 hidden');
      const qty = el('input', 'input mt-2 hidden');
      qty.type = 'number';
      qty.min = '1';
      qty.value = '1';
      qty.placeholder = 'Quantity';
      const amount = el('input', 'input mt-2 hidden');
      amount.type = 'number';
      amount.step = '0.01';
      amount.min = '0';
      amount.placeholder = 'Amount';
      const label = el('input', 'input mt-2 hidden');
      label.placeholder = 'Reason / label';
      const error = el('p', 'mt-2 hidden text-xs text-red-600');

      const sync = () => {
        const k = kind.value;
        const priced = k === 'service' || k === 'product';
        ref.classList.toggle('hidden', !priced);
        qty.classList.toggle('hidden', !priced);
        amount.classList.toggle('hidden', priced);
        label.classList.toggle('hidden', priced);
        label.placeholder = k === 'discount' ? 'Discount reason (e.g. loyalty 10%)' : 'Tip note (optional name)';
        ref.replaceChildren();
        const items = k === 'service' ? t.pickers.services : t.pickers.products;
        (items || []).forEach((it) => {
          const o = el('option', '', it.name + ' — ' + fmt(it.price_amount_minor ?? it.min_amount_minor ?? it.price_minor, t.appointment.currency));
          o.value = it.id;
          ref.append(o);
        });
      };
      kind.addEventListener('change', sync);
      sync();

      const add = el('button', 'btn btn-secondary btn-md mt-3 w-full');
      add.type = 'button';
      add.append(el('i', 'fa-solid fa-plus'));
      add.append(document.createTextNode(' Add'));
      add.addEventListener('click', () => {
        error.classList.add('hidden');
        const k = kind.value;
        const priced = k === 'service' || k === 'product';
        const body = priced
          ? { kind: k, ref_id: Number(ref.value), quantity: Number(qty.value || 1) }
          : { kind: k, amount_minor: Math.round(Number(amount.value || 0) * 100), label: label.value.trim() };
        ui.setBusy(add, true, 'Adding…');
        api.post('/api/appointments/' + this._uuid + '/items', body)
          .then((ticket) => this._done(ticket))
          .catch((e) => {
            ui.setBusy(add, false);
            error.textContent = (e.fields && Object.values(e.fields)[0]) || e.message;
            error.classList.remove('hidden');
          });
      });
      box.append(kind, ref, qty, amount, label, error, add);
      return box;
    },

    _payForm(t) {
      const box = el('div', 'mt-4 rounded-xl border border-line p-4');
      box.append(el('h4', 'text-sm font-semibold text-ink', 'Take payment'));

      const method = el('select', 'input select mt-3');
      const meta = {};
      (t.methods || []).forEach((m) => {
        meta[m.code] = m;
        const o = el('option', '', m.name);
        o.value = m.code;
        method.append(o);
      });

      const amount = el('input', 'input mt-2');
      amount.type = 'number';
      amount.step = '0.01';
      amount.min = '0';
      amount.value = (Math.max(0, t.totals.balance_minor) / 100).toFixed(2);
      const ref = el('input', 'input mt-2 hidden');
      ref.placeholder = 'Transaction reference (e.g. QXXXX123)';
      const error = el('p', 'mt-2 hidden text-xs text-red-600');

      const syncRef = () => {
        ref.classList.toggle('hidden', !(meta[method.value] && meta[method.value].requires_reference));
      };
      method.addEventListener('change', syncRef);
      syncRef();

      const pay = el('button', 'btn btn-primary btn-md mt-3 w-full');
      pay.type = 'button';
      pay.append(el('i', 'fa-solid fa-money-bill-wave'));
      pay.append(document.createTextNode(' Record payment'));
      // One key per payment attempt window: retries/double-clicks replay the
      // same key so the server dedupes instead of double-recording the tender.
      const idemKey = (crypto.randomUUID ? crypto.randomUUID() : 'pay-' + Date.now() + '-' + Math.random().toString(36).slice(2));
      pay.addEventListener('click', () => {
        error.classList.add('hidden');
        ui.setBusy(pay, true, 'Recording…');
        api.post('/api/appointments/' + this._uuid + '/payments', {
          method: method.value,
          amount_minor: Math.round(Number(amount.value || 0) * 100),
          reference: ref.value.trim(),
          idempotency_key: idemKey,
        })
          .then((ticket) => this._done(ticket))
          .catch((e) => {
            ui.setBusy(pay, false);
            error.textContent = (e.fields && Object.values(e.fields)[0]) || e.message;
            error.classList.remove('hidden');
          });
      });
      box.append(method, amount, ref, error, pay);
      return box;
    },

    _qty(t, item, delta) {
      const q = parseInt(item.quantity, 10) + delta;
      if (q < 1) return;
      api.patch('/api/appointments/' + this._uuid + '/items/' + item.id, { quantity: q })
        .then((ticket) => this._done(ticket))
        .catch((e) => AlbaBook.toast(e.message, 'danger'));
    },

    _remove(item) {
      api.del('/api/appointments/' + this._uuid + '/items/' + item.id)
        .then((ticket) => this._done(ticket))
        .catch((e) => AlbaBook.toast(e.message, 'danger'));
    },

    async _refundPrompt(t, payment) {
      const ok = await ui.confirm({
        title: 'Refund payment',
        message: 'Refund ' + fmt(payment.amount_minor, t.appointment.currency) + ' from ' + payment.method.replace(/_/g, ' ') + '?',
        confirmLabel: 'Refund',
        danger: true,
      });
      if (!ok) return;
      api.post('/api/appointments/' + this._uuid + '/payments/' + payment.uuid + '/refund', {
        amount_minor: payment.amount_minor,
        reason: 'Till refund',
      })
        .then((ticket) => this._done(ticket))
        .catch((e) => AlbaBook.toast(e.message, 'danger'));
    },

    async _checkout(t, btn) {
      if (t.totals.balance_minor > 0 && !t.full_payment_required) {
        const ok = await ui.confirm({
          title: 'Complete with balance',
          message: 'A balance of ' + fmt(t.totals.balance_minor, t.appointment.currency) + ' remains. Complete anyway?',
          confirmLabel: 'Complete',
        });
        if (!ok) return;
      }
      ui.setBusy(btn, true, 'Completing…');
      api.post('/api/appointments/' + this._uuid + '/checkout', {})
        .then(() => {
          AlbaBook.toast('Appointment completed.', 'success');
          ui.drawer.close();
          if (this._onChange) this._onChange();
          else AlbaBook.pages.mount(document);
        })
        .catch((e) => {
          ui.setBusy(btn, false);
          AlbaBook.toast(e.message || 'Checkout failed.', 'danger');
        });
    },

    /* Re-render from a fresh ticket payload + notify the page. */
    _done(ticket) {
      this._render(ticket, this._body);
      if (this._onChange) this._onChange();
    },
  };
})();
