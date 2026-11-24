/*
 * Customer directory page: filterable list, drawer create/edit form with
 * contact details, preferred staff/service, and privileged notes. Mutations
 * go through /api/customers with CSRF; the server re-validates everything.
 */
(function () {
  const AlbaBook = window.AlbaBook;
  const api = AlbaBook.api;
  const ui = AlbaBook.ui;
  const el = (tag, cls, text) => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  };

  const pageInfo = (page, total) =>
    total === 0 ? '' : `Showing ${(page - 1) * 20 + 1}–${Math.min(page * 20, total)} of ${total}`;

  AlbaBook.pages.register('customers', function (root) {
    const canCreate = root.dataset.canCreate === '1';
    const canUpdate = root.dataset.canUpdate === '1';
    const canDelete = root.dataset.canDelete === '1';
    const canNotes = root.dataset.canNotes === '1';
    const body = root.querySelector('[data-customers-body]');
    const info = root.querySelector('[data-customers-info]');
    const pager = root.querySelector('[data-customers-pager]');
    const filterInputs = root.querySelectorAll('[data-customers-filter]');
    const state = { page: 1, meta: null };

    api.get('/api/customers/meta')
      .then((data) => { state.meta = data; })
      .catch(() => { state.meta = { staff: [], services: [] }; });

    /* Possible duplicates: same phone or email. "Keep" folds every other
     * member of the group into the chosen customer (sequential merges). */
    const dupPanel = root.querySelector('[data-dup-panel]');

    function loadDuplicates() {
      api.get('/api/customers/duplicates')
        .then((data) => renderDuplicates(data.groups || []))
        .catch(() => {});
    }

    function renderDuplicates(groups) {
      dupPanel.replaceChildren();
      dupPanel.hidden = groups.length === 0;
      groups.forEach((g) => {
        const card = el('div', 'card mb-4 border-l-4 border-warning p-4');
        const head = el('div', 'mb-2 flex items-center gap-2 text-sm text-muted');
        head.append(ui.badge(g.match_type === 'phone' ? 'same phone' : 'same email', 'warning'));
        head.append(el('span', 'font-mono text-xs', g.key));
        card.append(head);
        const wrap = el('div', 'flex flex-wrap items-center gap-2');
        g.customers.forEach((c) => {
          const chip = el('div', 'flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm');
          chip.append(el('span', 'font-medium text-ink', c.name));
          chip.append(el('span', 'text-xs text-muted',
            `${c.appointment_count} bookings · since ${c.created_at}`));
          if (canUpdate) {
            const keep = el('button', 'btn btn-ghost btn-sm text-xs');
            keep.type = 'button';
            keep.append(document.createTextNode('Keep this one'));
            keep.addEventListener('click', () => mergeGroup(g, c));
            chip.append(keep);
          }
          wrap.append(chip);
        });
        card.append(wrap);
        dupPanel.append(card);
      });
    }

    async function mergeGroup(group, winner) {
      const losers = group.customers.filter((c) => c.uuid !== winner.uuid);
      const names = losers.map((c) => c.name).join(', ');
      const yes = await ui.confirm({
        title: 'Merge customers?',
        message: `${names} will be merged into ${winner.name}. Their booking and payment history moves over; the merged record cannot be edited afterwards.`,
        confirmLabel: 'Merge',
      });
      if (!yes) return;
      try {
        for (const loser of losers) {
          await api.post(`/api/customers/${loser.uuid}/merge`, { into: winner.uuid });
        }
        AlbaBook.toast('Customers merged.', 'success');
      } catch (error) {
        AlbaBook.toast(error.message || 'Merge failed.', 'danger');
      }
      state.page = 1;
      load();
      loadDuplicates();
    }

    loadDuplicates();

    function query() {
      const params = new URLSearchParams({ page: state.page });
      filterInputs.forEach((input) => {
        const v = input.value.trim();
        if (v !== '') params.set(input.dataset.customersFilter, v);
      });
      return '/api/customers?' + params.toString();
    }

    function skeleton() {
      const tr = el('tr');
      const td = el('td');
      td.colSpan = 6;
      td.append(ui.skeletonRows(6));
      tr.append(td);
      body.replaceChildren(tr);
    }

    function load() {
      skeleton();
      api.get(query())
        .then((data) => {
          renderRows(data.rows || []);
          info.textContent = pageInfo(data.page, data.total);
          renderPager(data.page, data.pages);
        })
        .catch((error) => {
          const tr = el('tr');
          const td = el('td');
          td.colSpan = 6;
          td.append(ui.errorState(error.message, load));
          tr.append(td);
          body.replaceChildren(tr);
          info.textContent = '';
          pager.replaceChildren();
        });
    }

    const badge = (text, tone) => el('span', 'badge badge-' + tone, text);
    const statusLabel = { active: 'Active', merged: 'Merged', anonymised: 'Anonymised' };
    const statusTone = { active: 'success', merged: 'neutral', anonymised: 'warning' };

    function renderRows(rows) {
      if (!rows.length) {
        const tr = el('tr');
        const td = el('td');
        td.colSpan = 6;
        td.append(ui.emptyState({
          icon: 'fa-users',
          title: 'No customers yet',
          message: 'Customers appear here after their first booking — or add one manually.',
          actionLabel: canCreate ? 'New customer' : null,
          onAction: canCreate ? () => openForm(null) : null,
        }));
        tr.append(td);
        body.replaceChildren(tr);
        return;
      }

      body.replaceChildren(...rows.map((row) => {
        const tr = el('tr');
        const statusTd = el('td');
        statusTd.append(badge(statusLabel[row.status] || row.status, statusTone[row.status] || 'neutral'));
        const actions = el('td');
        const open = el('button', 'btn btn-ghost btn-sm', 'View');
        open.type = 'button';
        open.addEventListener('click', () => openDetail(row.uuid));
        actions.append(open);
        tr.append(
          el('td', null, row.name),
          el('td', 'text-muted', row.phone || '—'),
          el('td', 'text-muted', row.email || '—'),
          el('td', 'text-muted', String(row.appointments)),
          statusTd,
          actions,
        );
        return tr;
      }));
    }

    function renderPager(page, pages) {
      pager.replaceChildren();
      if (pages <= 1) return;
      const prev = el('button', 'btn btn-ghost btn-sm', 'Previous');
      prev.type = 'button';
      prev.disabled = page <= 1;
      prev.addEventListener('click', () => { state.page--; load(); });
      const next = el('button', 'btn btn-ghost btn-sm', 'Next');
      next.type = 'button';
      next.disabled = page >= pages;
      next.addEventListener('click', () => { state.page++; load(); });
      pager.append(prev, el('span', 'text-sm text-muted', `Page ${page} of ${pages}`), next);
    }

    /* ----------------------------------------------------- detail drawer */
    function openDetail(uuid) {
      const { wrap, body: drawerBody } = ui.drawerShell('Customer');
      drawerBody.append(ui.spinner('Loading customer…'));
      ui.drawer.setContent(wrap);
      ui.drawer.open();

      api.get('/api/customers/' + encodeURIComponent(uuid))
        .then((data) => {
          const c = data.customer;
          drawerBody.replaceChildren();

          const facts = el('div', 'mt-2 space-y-3');
          const fact = (label, value) => {
            const row = el('div', 'flex items-center gap-3 text-sm');
            row.append(el('span', 'text-muted', label), el('span', 'ml-auto font-medium text-ink', value));
            facts.append(row);
          };
          fact('Name', c.name);
          fact('Phone', c.phone || '—');
          fact('Email', c.email || '—');
          fact('Preferred staff', c.preferred_staff_name || '—');
          fact('Preferred service', c.preferred_service_name || '—');
          fact('Appointments', String(c.appointments));
          fact('Status', statusLabel[c.status] || c.status);
          fact('Customer since', c.created_at ? String(c.created_at).slice(0, 10) : '—');
          drawerBody.append(facts);

          if (canNotes) {
            drawerBody.append(el('h4', 'mt-6 text-sm font-semibold text-ink', 'Notes'));
            drawerBody.append(el('p', 'mt-2 text-sm text-muted', c.notes || 'No notes recorded.'));
          }

          if (!canUpdate && !canDelete) return;
          const actions = el('div', 'mt-6 flex gap-2');
          if (canUpdate) {
            const edit = el('button', 'btn btn-primary btn-md', 'Edit');
            edit.type = 'button';
            edit.addEventListener('click', () => openForm(c));
            actions.append(edit);
          }
          if (canDelete) {
            const del = el('button', 'btn btn-danger btn-md', 'Delete');
            del.type = 'button';
            del.addEventListener('click', () => {
              ui.confirm({
                title: 'Delete customer?',
                message: `"${c.name}" will be permanently removed.`,
                confirmLabel: 'Delete',
                danger: true,
              }).then((ok) => {
                if (!ok) return;
                api.del('/api/customers/' + encodeURIComponent(c.uuid))
                  .then(() => {
                    AlbaBook.toast('Customer deleted.');
                    ui.drawer.close();
                    load();
                  })
                  .catch((error) => AlbaBook.toast(error.message, 'danger'));
              });
            });
            actions.append(del);
          }
          drawerBody.append(actions);
        })
        .catch((error) => {
          drawerBody.replaceChildren(ui.errorState(error.message, () => openDetail(uuid)));
        });
    }

    /* -------------------------------------------------------- form drawer */
    function field(labelText, input, name) {
      const wrap = el('div', 'field');
      const label = el('label', 'field-label', labelText);
      label.setAttribute('for', 'customer-' + name);
      input.id = 'customer-' + name;
      input.name = name;
      const err = el('p', 'field-error');
      err.id = 'field-' + name + '-error';
      wrap.append(label, input, err);
      return wrap;
    }

    function input(type, value) {
      const i = el('input', 'input');
      i.type = type;
      if (value !== undefined && value !== null) i.value = value;
      return i;
    }

    function openForm(customer) {
      const isEdit = customer !== null;
      const { wrap, body: drawerBody } = ui.drawerShell(isEdit ? 'Edit customer' : 'New customer');
      const meta = state.meta || { staff: [], services: [] };

      const form = el('form', 'flex flex-col gap-4');
      form.noValidate = true;

      const firstInput = input('text', customer ? customer.first_name : '');
      firstInput.required = true;
      firstInput.maxLength = 100;
      const middleInput = input('text', customer ? customer.middle_name : '');
      middleInput.maxLength = 100;
      const lastInput = input('text', customer ? customer.last_name : '');
      lastInput.required = true;
      lastInput.maxLength = 100;
      const phoneInput = input('tel', customer ? customer.phone : '');
      phoneInput.placeholder = '+254712345678';
      const emailInput = input('email', customer ? customer.email : '');
      const notesInput = el('textarea', 'input');
      notesInput.rows = 3;
      notesInput.value = customer && customer.notes ? customer.notes : '';

      const select = (options, current, placeholder, labelKey) => {
        const sel = el('select', 'input select');
        const none = el('option', null, placeholder);
        none.value = '';
        sel.append(none);
        options.forEach((o) => {
          const opt = el('option', null, o[labelKey]);
          opt.value = o.id;
          sel.append(opt);
        });
        if (current) sel.value = String(current);
        return sel;
      };
      const staffSelect = select(meta.staff || [], customer ? customer.preferred_staff_id : null, 'No preference', 'display_name');
      const serviceSelect = select(meta.services || [], customer ? customer.preferred_service_id : null, 'No preference', 'name');

      const submit = el('button', 'btn btn-primary btn-md', isEdit ? 'Save changes' : 'Create customer');
      submit.type = 'submit';

      const nameGrid = el('div', 'grid grid-cols-2 gap-4');
      nameGrid.append(field('First name', firstInput, 'first_name'), field('Last name', lastInput, 'last_name'));

      form.append(
        nameGrid,
        field('Middle name (optional)', middleInput, 'middle_name'),
        field('Phone', phoneInput, 'phone_e164'),
        field('Email', emailInput, 'email'),
        field('Preferred staff', staffSelect, 'preferred_staff_id'),
        field('Preferred service', serviceSelect, 'preferred_service_id'),
      );
      if (canNotes) {
        form.append(field('Notes (visible to staff only)', notesInput, 'notes'));
      }
      form.append(submit);

      form.addEventListener('submit', (event) => {
        event.preventDefault();
        ui.clearFieldErrors(form);

        const payload = {
          first_name: firstInput.value.trim(),
          middle_name: middleInput.value.trim(),
          last_name: lastInput.value.trim(),
          phone_e164: phoneInput.value.trim(),
          email: emailInput.value.trim(),
          preferred_staff_id: staffSelect.value === '' ? 0 : Number(staffSelect.value),
          preferred_service_id: serviceSelect.value === '' ? 0 : Number(serviceSelect.value),
        };
        if (canNotes) payload.notes = notesInput.value.trim();

        ui.setBusy(submit, true, 'Saving…');
        const request = isEdit
          ? api.patch('/api/customers/' + encodeURIComponent(customer.uuid), payload)
          : api.post('/api/customers', payload);

        request
          .then(() => {
            AlbaBook.toast(isEdit ? 'Customer updated.' : 'Customer added.');
            ui.drawer.close();
            load();
          })
          .catch((error) => {
            if (error.fields && Object.keys(error.fields).length) {
              ui.showFieldErrors(form, error.fields);
            } else {
              AlbaBook.toast(error.message, 'danger');
            }
          })
          .finally(() => ui.setBusy(submit, false));
      });

      drawerBody.append(form);
      ui.drawer.setContent(wrap);
      ui.drawer.open();
    }

    let debounce = null;
    filterInputs.forEach((input) => {
      const event = input.tagName === 'SELECT' ? 'change' : 'input';
      input.addEventListener(event, () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => { state.page = 1; load(); }, 300);
      });
    });

    const newBtn = root.querySelector('[data-customers-new]');
    if (newBtn) newBtn.addEventListener('click', () => openForm(null));

    load();
  });
})();
