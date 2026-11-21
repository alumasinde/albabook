/*
 * Branches page: searchable list, drawer detail and create/edit form with a
 * weekly business-hours editor. Mutations go through /api/branches with CSRF;
 * the server re-validates and enforces branch scope.
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

  const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

  AlbaBook.pages.register('branches', function (root) {
    const canManage = root.dataset.canManage === '1';
    const body = root.querySelector('[data-branches-body]');
    const filterInputs = root.querySelectorAll('[data-branches-filter]');

    function query() {
      const params = new URLSearchParams();
      filterInputs.forEach((input) => {
        const v = input.value.trim();
        if (v !== '') params.set(input.dataset.branchesFilter, v);
      });
      const qs = params.toString();
      return '/api/branches' + (qs === '' ? '' : '?' + qs);
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
        .then((data) => renderRows(data.rows || []))
        .catch((error) => {
          const tr = el('tr');
          const td = el('td');
          td.colSpan = 6;
          td.append(ui.errorState(error.message, load));
          tr.append(td);
          body.replaceChildren(tr);
        });
    }

    const badge = (text, tone) => el('span', 'badge badge-' + tone, text);

    function renderRows(rows) {
      if (!rows.length) {
        const tr = el('tr');
        const td = el('td');
        td.colSpan = 6;
        td.append(ui.emptyState({
          icon: 'fa-location-dot',
          title: 'No branches yet',
          message: 'Add a location so clients know where to find you.',
          actionLabel: canManage ? 'New branch' : null,
          onAction: canManage ? () => openForm(null) : null,
        }));
        tr.append(td);
        body.replaceChildren(tr);
        return;
      }

      body.replaceChildren(...rows.map((row) => {
        const tr = el('tr');
        const contact = el('td', 'text-sm text-muted');
        contact.append(el('div', null, row.phone || '—'), el('div', 'text-xs', row.email || ''));
        const statusTd = el('td');
        statusTd.append(badge(row.is_active ? 'Active' : 'Inactive', row.is_active ? 'success' : 'neutral'));
        const actions = el('td');
        const open = el('button', 'btn btn-ghost btn-sm', 'View');
        open.type = 'button';
        open.addEventListener('click', () => openDetail(row.uuid));
        actions.append(open);
        tr.append(
          el('td', null, row.name),
          el('td', 'text-muted', row.address || '—'),
          contact,
          el('td', 'text-muted', String(row.staff)),
          statusTd,
          actions,
        );
        return tr;
      }));
    }

    /* ----------------------------------------------------- detail drawer */
    function openDetail(uuid) {
      const { wrap, body: drawerBody } = ui.drawerShell('Branch');
      drawerBody.append(ui.spinner('Loading branch…'));
      ui.drawer.setContent(wrap);
      ui.drawer.open();

      api.get('/api/branches/' + encodeURIComponent(uuid))
        .then((data) => {
          const b = data.branch;
          drawerBody.replaceChildren();

          const facts = el('div', 'mt-2 space-y-3');
          const fact = (label, value) => {
            const row = el('div', 'flex items-center gap-3 text-sm');
            row.append(el('span', 'text-muted', label), el('span', 'ml-auto font-medium text-ink text-right', value));
            facts.append(row);
          };
          fact('Name', b.name);
          fact('Address', b.address || '—');
          fact('Phone', b.phone || '—');
          fact('Email', b.email || '—');
          fact('Timezone', (b.timezone || 'Workspace default').replace(/_/g, ' '));
          fact('Staff assigned', String(b.staff));
          fact('Resources', String(b.resources));
          fact('Status', b.is_active ? 'Active' : 'Inactive');
          fact('Linked records', String(b.references));
          drawerBody.append(facts);

          drawerBody.append(el('h4', 'mt-6 text-sm font-semibold text-ink', 'Opening hours'));
          if (b.hours && b.hours.length) {
            const list = el('ul', 'mt-2 space-y-2');
            const byDay = {};
            b.hours.forEach((r) => { byDay[r.weekday] = r; });
            WEEKDAYS.forEach((label, i) => {
              const r = byDay[i + 1];
              const li = el('li', 'flex items-center justify-between gap-3 rounded-lg border border-line px-3 py-2 text-sm');
              li.append(el('span', 'text-muted', label), el('span', 'font-medium ' + (r ? 'text-ink' : 'text-muted'), r ? `${r.opens_at}–${r.closes_at}` : 'Closed'));
              list.append(li);
            });
            drawerBody.append(list);
          } else {
            drawerBody.append(el('p', 'mt-2 text-sm text-muted', 'No opening hours configured.'));
          }

          if (!canManage) return;
          const actions = el('div', 'mt-6 flex gap-2');
          const edit = el('button', 'btn btn-primary btn-md', 'Edit');
          edit.type = 'button';
          edit.addEventListener('click', () => openForm(b));
          const del = el('button', 'btn btn-danger btn-md', 'Delete');
          del.type = 'button';
          del.addEventListener('click', () => {
            ui.confirm({
              title: 'Delete branch?',
              message: `"${b.name}" will be permanently removed.`,
              confirmLabel: 'Delete',
              danger: true,
            }).then((ok) => {
              if (!ok) return;
              api.del('/api/branches/' + encodeURIComponent(b.uuid))
                .then(() => {
                  AlbaBook.toast('Branch deleted.');
                  ui.drawer.close();
                  load();
                })
                .catch((error) => AlbaBook.toast(error.message, 'danger'));
            });
          });
          actions.append(edit, del);
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
      label.setAttribute('for', 'branch-' + name);
      input.id = 'branch-' + name;
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

    function openForm(branch) {
      const isEdit = branch !== null;
      const { wrap, body: drawerBody } = ui.drawerShell(isEdit ? 'Edit branch' : 'New branch');

      const form = el('form', 'flex flex-col gap-4');
      form.noValidate = true;

      const nameInput = input('text', branch ? branch.name : '');
      nameInput.required = true;
      nameInput.maxLength = 150;
      const addr1Input = input('text', branch ? branch.address_line1 : '');
      const addr2Input = input('text', branch ? branch.address_line2 : '');
      const townInput = input('text', branch ? branch.town : '');
      const countyInput = input('text', branch ? branch.county : '');
      const phoneInput = input('tel', branch ? branch.phone : '');
      const emailInput = input('email', branch ? branch.email : '');
      const tzInput = el('select', 'input select');
      const defaultOpt = el('option', null, 'Workspace default');
      defaultOpt.value = '';
      tzInput.append(defaultOpt);
      Intl.supportedValuesOf('timeZone').forEach((tz) => {
        const opt = el('option', null, tz.replace(/_/g, ' '));
        opt.value = tz;
        tzInput.append(opt);
      });
      if (branch && branch.timezone) tzInput.value = branch.timezone;

      const activeLabel = (() => {
        const label = el('label', 'flex items-center gap-2 text-sm text-ink');
        const box = input('checkbox');
        box.className = 'checkbox';
        box.id = 'branch-is_active';
        box.name = 'is_active';
        box.checked = branch ? !!branch.is_active : true;
        label.setAttribute('for', box.id);
        label.append(box, el('span', null, 'Active (visible on booking page)'));
        return { label, box };
      })();

      /* Weekly opening-hours editor. */
      const hoursWrap = el('div', 'field');
      hoursWrap.append(el('span', 'field-label', 'Opening hours'));
      const hoursErr = el('p', 'field-error');
      hoursErr.id = 'field-hours-error';
      const hoursByDay = {};
      (branch ? branch.hours || [] : []).forEach((r) => { hoursByDay[r.weekday] = r; });
      const dayControls = [];
      WEEKDAYS.forEach((label, i) => {
        const weekday = i + 1;
        const rowEl = el('div', 'flex items-center gap-3');
        const checkLabel = el('label', 'flex w-32 items-center gap-2 text-sm text-ink');
        const box = input('checkbox');
        box.className = 'checkbox';
        const existing = hoursByDay[weekday];
        box.checked = existing !== undefined || (!branch && weekday <= 6);
        checkLabel.append(box, el('span', null, label));
        const open = input('time', existing ? existing.opens_at : '08:00');
        const close = input('time', existing ? existing.closes_at : '18:00');
        open.disabled = close.disabled = !box.checked;
        box.addEventListener('change', () => { open.disabled = close.disabled = !box.checked; });
        dayControls.push({ weekday, box, open, close });
        rowEl.append(checkLabel, open, el('span', 'text-muted', '–'), close);
        hoursWrap.append(rowEl);
      });
      hoursWrap.append(hoursErr);

      const submit = el('button', 'btn btn-primary btn-md', isEdit ? 'Save changes' : 'Create branch');
      submit.type = 'submit';

      const grid1 = el('div', 'grid grid-cols-2 gap-4');
      grid1.append(field('Town', townInput, 'town'), field('County', countyInput, 'county'));
      const grid2 = el('div', 'grid grid-cols-2 gap-4');
      grid2.append(field('Phone', phoneInput, 'phone'), field('Email', emailInput, 'email'));

      form.append(
        field('Branch name', nameInput, 'name'),
        field('Address line 1', addr1Input, 'address_line1'),
        field('Address line 2', addr2Input, 'address_line2'),
        grid1,
        grid2,
        field('Timezone', tzInput, 'timezone'),
        hoursWrap,
        activeLabel.label,
        submit,
      );

      form.addEventListener('submit', (event) => {
        event.preventDefault();
        ui.clearFieldErrors(form);

        const payload = {
          name: nameInput.value.trim(),
          address_line1: addr1Input.value.trim(),
          address_line2: addr2Input.value.trim(),
          town: townInput.value.trim(),
          county: countyInput.value.trim(),
          phone: phoneInput.value.trim(),
          email: emailInput.value.trim(),
          timezone: tzInput.value,
          is_active: activeLabel.box.checked,
          hours: dayControls
            .filter((d) => d.box.checked)
            .map((d) => ({ weekday: d.weekday, opens_at: d.open.value, closes_at: d.close.value })),
        };

        ui.setBusy(submit, true, 'Saving…');
        const request = isEdit
          ? api.patch('/api/branches/' + encodeURIComponent(branch.uuid), payload)
          : api.post('/api/branches', payload);

        request
          .then(() => {
            AlbaBook.toast(isEdit ? 'Branch updated.' : 'Branch created.');
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
      input.addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(load, 300);
      });
    });

    const newBtn = root.querySelector('[data-branches-new]');
    if (newBtn) newBtn.addEventListener('click', () => openForm(null));

    load();
  });
})();
