/*
 * Resources page: filterable list (search, branch, status), drawer detail and
 * create/edit form with branch assignment, resource type (existing or new),
 * capacity, status and a weekly availability editor. Mutations go through
 * /api/resources with CSRF; the server re-validates everything.
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
  const STATUS_LABEL = { active: 'Active', maintenance: 'Maintenance', inactive: 'Inactive' };
  const STATUS_TONE = { active: 'success', maintenance: 'warning', inactive: 'neutral' };
  const pageInfo = (page, total) =>
    total === 0 ? '' : `Showing ${(page - 1) * 20 + 1}–${Math.min(page * 20, total)} of ${total}`;

  AlbaBook.pages.register('resources', function (root) {
    const canManage = root.dataset.canManage === '1';
    const body = root.querySelector('[data-resources-body]');
    const info = root.querySelector('[data-resources-info]');
    const pager = root.querySelector('[data-resources-pager]');
    const filterInputs = root.querySelectorAll('[data-resources-filter]');
    const branchFilter = root.querySelector('[data-resources-branch-select]');
    const state = { page: 1, meta: { branches: [], types: [] } };

    api.get('/api/resources/meta')
      .then((data) => {
        state.meta = data;
        (data.branches || []).forEach((b) => {
          const opt = el('option', null, b.name);
          opt.value = b.id;
          branchFilter.append(opt);
        });
      })
      .catch(() => { state.meta = { branches: [], types: [] }; });

    function query() {
      const params = new URLSearchParams({ page: state.page });
      filterInputs.forEach((input) => {
        const v = input.value.trim();
        if (v !== '') params.set(input.dataset.resourcesFilter, v);
      });
      return '/api/resources?' + params.toString();
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

    function renderRows(rows) {
      if (!rows.length) {
        const tr = el('tr');
        const td = el('td');
        td.colSpan = 6;
        td.append(ui.emptyState({
          icon: 'fa-couch',
          title: 'No resources yet',
          message: 'Add rooms, chairs or equipment that bookings depend on.',
          actionLabel: canManage ? 'New resource' : null,
          onAction: canManage ? () => openForm(null) : null,
        }));
        tr.append(td);
        body.replaceChildren(tr);
        return;
      }

      body.replaceChildren(...rows.map((row) => {
        const tr = el('tr');
        const statusTd = el('td');
        statusTd.append(badge(STATUS_LABEL[row.status] || row.status, STATUS_TONE[row.status] || 'neutral'));
        const actions = el('td');
        const open = el('button', 'btn btn-ghost btn-sm', 'View');
        open.type = 'button';
        open.addEventListener('click', () => openDetail(row.uuid));
        actions.append(open);
        tr.append(
          el('td', null, row.name),
          el('td', 'text-muted', row.type || '—'),
          el('td', 'text-muted', row.branch || '—'),
          el('td', 'text-muted', String(row.capacity)),
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
      const { wrap, body: drawerBody } = ui.drawerShell('Resource');
      drawerBody.append(ui.spinner('Loading resource…'));
      ui.drawer.setContent(wrap);
      ui.drawer.open();

      api.get('/api/resources/' + encodeURIComponent(uuid))
        .then((data) => {
          const r = data.resource;
          drawerBody.replaceChildren();

          const facts = el('div', 'mt-2 space-y-3');
          const fact = (label, value) => {
            const row = el('div', 'flex items-center gap-3 text-sm');
            row.append(el('span', 'text-muted', label), el('span', 'ml-auto font-medium text-ink', value));
            facts.append(row);
          };
          fact('Name', r.name);
          fact('Type', r.type_name || '—');
          fact('Branch', r.branch_name || '—');
          fact('Capacity', `${r.capacity} ${r.capacity === 1 ? 'person' : 'people'}`);
          fact('Status', STATUS_LABEL[r.status] || r.status);
          fact('Linked bookings', String(r.references));
          drawerBody.append(facts);

          drawerBody.append(el('h4', 'mt-6 text-sm font-semibold text-ink', 'Availability'));
          if (r.schedule && r.schedule.length) {
            const list = el('ul', 'mt-2 space-y-2');
            const byDay = {};
            r.schedule.forEach((row) => { byDay[row.weekday] = row; });
            WEEKDAYS.forEach((label, i) => {
              const row = byDay[i + 1];
              const li = el('li', 'flex items-center justify-between gap-3 rounded-lg border border-line px-3 py-2 text-sm');
              li.append(el('span', 'text-muted', label), el('span', 'font-medium ' + (row ? 'text-ink' : 'text-muted'), row ? `${row.starts_at}–${row.ends_at}` : 'Unavailable'));
              list.append(li);
            });
            drawerBody.append(list);
          } else {
            drawerBody.append(el('p', 'mt-2 text-sm text-muted', 'Follows branch opening hours.'));
          }

          if (!canManage) return;
          const actions = el('div', 'mt-6 flex gap-2');
          const edit = el('button', 'btn btn-primary btn-md', 'Edit');
          edit.type = 'button';
          edit.addEventListener('click', () => openForm(r));
          const del = el('button', 'btn btn-danger btn-md', 'Delete');
          del.type = 'button';
          del.addEventListener('click', () => {
            ui.confirm({
              title: 'Delete resource?',
              message: `"${r.name}" will be permanently removed.`,
              confirmLabel: 'Delete',
              danger: true,
            }).then((ok) => {
              if (!ok) return;
              api.del('/api/resources/' + encodeURIComponent(r.uuid))
                .then(() => {
                  AlbaBook.toast('Resource deleted.');
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
      label.setAttribute('for', 'resource-' + name);
      input.id = 'resource-' + name;
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

    function openForm(resource) {
      const isEdit = resource !== null;
      const { wrap, body: drawerBody } = ui.drawerShell(isEdit ? 'Edit resource' : 'New resource');
      const meta = state.meta;

      const form = el('form', 'flex flex-col gap-4');
      form.noValidate = true;

      const nameInput = input('text', resource ? resource.name : '');
      nameInput.required = true;
      nameInput.maxLength = 120;

      const branchSelect = el('select', 'input select');
      (meta.branches || []).forEach((b) => {
        const opt = el('option', null, b.name);
        opt.value = b.id;
        branchSelect.append(opt);
      });
      if (resource) branchSelect.value = String(resource.branch_id);

      const typeSelect = el('select', 'input select');
      const newTypeOpt = el('option', null, '+ New type…');
      newTypeOpt.value = 'new';
      typeSelect.append(newTypeOpt);
      (meta.types || []).forEach((t) => {
        const opt = el('option', null, `${t.name} (${t.resources} in use)`);
        opt.value = t.id;
        typeSelect.append(opt);
      });
      if (resource) typeSelect.value = String(resource.resource_type_id);
      const newTypeInput = input('text', '');
      newTypeInput.maxLength = 100;
      newTypeInput.placeholder = 'e.g. Massage room, Styling chair';
      const newTypeWrap = field('New type name', newTypeInput, 'new_type');
      newTypeWrap.hidden = typeSelect.value !== 'new';
      typeSelect.addEventListener('change', () => {
        newTypeWrap.hidden = typeSelect.value !== 'new';
      });

      const capacityInput = input('number', resource ? resource.capacity : 1);
      capacityInput.min = 1;
      capacityInput.max = 1000;

      const statusSelect = el('select', 'input select');
      Object.entries(STATUS_LABEL).forEach(([v, label]) => {
        const opt = el('option', null, label);
        opt.value = v;
        statusSelect.append(opt);
      });
      statusSelect.value = resource ? resource.status : 'active';

      /* Weekly availability editor (empty = follows branch hours). */
      const schedWrap = el('div', 'field');
      schedWrap.append(el('span', 'field-label', 'Availability (leave unchecked to follow branch hours)'));
      const schedErr = el('p', 'field-error');
      schedErr.id = 'field-schedule-error';
      const schedByDay = {};
      (resource ? resource.schedule || [] : []).forEach((r) => { schedByDay[r.weekday] = r; });
      const dayControls = [];
      WEEKDAYS.forEach((label, i) => {
        const weekday = i + 1;
        const rowEl = el('div', 'flex items-center gap-3');
        const checkLabel = el('label', 'flex w-32 items-center gap-2 text-sm text-ink');
        const box = input('checkbox');
        box.className = 'checkbox';
        const existing = schedByDay[weekday];
        box.checked = existing !== undefined;
        checkLabel.append(box, el('span', null, label));
        const start = input('time', existing ? existing.starts_at : '08:00');
        const end = input('time', existing ? existing.ends_at : '18:00');
        start.disabled = end.disabled = !box.checked;
        box.addEventListener('change', () => { start.disabled = end.disabled = !box.checked; });
        dayControls.push({ weekday, box, start, end });
        rowEl.append(checkLabel, start, el('span', 'text-muted', '–'), end);
        schedWrap.append(rowEl);
      });
      schedWrap.append(schedErr);

      const submit = el('button', 'btn btn-primary btn-md', isEdit ? 'Save changes' : 'Create resource');
      submit.type = 'submit';

      form.append(
        field('Resource name', nameInput, 'name'),
        field('Branch', branchSelect, 'branch_id'),
        field('Type', typeSelect, 'resource_type_id'),
        newTypeWrap,
        field('Capacity', capacityInput, 'capacity'),
        field('Status', statusSelect, 'status'),
        schedWrap,
        submit,
      );

      form.addEventListener('submit', (event) => {
        event.preventDefault();
        ui.clearFieldErrors(form);

        const payload = {
          name: nameInput.value.trim(),
          branch_id: Number(branchSelect.value || 0),
          resource_type_id: typeSelect.value === 'new' ? 0 : Number(typeSelect.value || 0),
          new_type: typeSelect.value === 'new' ? newTypeInput.value.trim() : '',
          capacity: Number(capacityInput.value || 0),
          status: statusSelect.value,
          schedule: dayControls
            .filter((d) => d.box.checked)
            .map((d) => ({ weekday: d.weekday, starts_at: d.start.value, ends_at: d.end.value })),
        };

        ui.setBusy(submit, true, 'Saving…');
        const request = isEdit
          ? api.patch('/api/resources/' + encodeURIComponent(resource.uuid), payload)
          : api.post('/api/resources', payload);

        request
          .then(() => {
            AlbaBook.toast(isEdit ? 'Resource updated.' : 'Resource created.');
            ui.drawer.close();
            api.get('/api/resources/meta').then((m) => { state.meta = m; }).catch(() => {});
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

    const newBtn = root.querySelector('[data-resources-new]');
    if (newBtn) newBtn.addEventListener('click', () => openForm(null));

    load();
  });
})();
