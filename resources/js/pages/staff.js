/*
 * Staff directory page: filterable list, drawer create/edit form with branch
 * assignment, service skills, member link and a weekly schedule editor.
 * All mutations go through /api/staff with CSRF; the server re-validates.
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
  const pageInfo = (page, total) =>
    total === 0 ? '' : `Showing ${(page - 1) * 20 + 1}–${Math.min(page * 20, total)} of ${total}`;

  AlbaBook.pages.register('staff', function (root) {
    const canManage = root.dataset.canManage === '1';
    const body = root.querySelector('[data-staff-body]');
    const info = root.querySelector('[data-staff-info]');
    const pager = root.querySelector('[data-staff-pager]');
    const filterInputs = root.querySelectorAll('[data-staff-filter]');
    const state = { page: 1, meta: null };

    api.get('/api/staff/meta')
      .then((data) => { state.meta = data; })
      .catch(() => { state.meta = { branches: [], services: [], members: [] }; });

    function query() {
      const params = new URLSearchParams({ page: state.page });
      filterInputs.forEach((input) => {
        const v = input.value.trim();
        if (v !== '') params.set(input.dataset.staffFilter, v);
      });
      return '/api/staff?' + params.toString();
    }

    function skeleton() {
      const tr = el('tr');
      const td = el('td');
      td.colSpan = 7;
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
          td.colSpan = 7;
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
        td.colSpan = 7;
        td.append(ui.emptyState({
          icon: 'fa-user-group',
          title: 'No staff yet',
          message: 'Add your first team member so clients can book with them.',
          actionLabel: canManage ? 'New staff member' : null,
          onAction: canManage ? () => openForm(null) : null,
        }));
        tr.append(td);
        body.replaceChildren(tr);
        return;
      }

      body.replaceChildren(...rows.map((row) => {
        const tr = el('tr');
        const contact = el('td', 'text-sm text-muted');
        contact.append(
          el('div', null, row.email || '—'),
          el('div', 'text-xs', row.phone || ''),
        );
        const onlineTd = el('td');
        onlineTd.append(row.is_bookable_online ? badge('Online', 'success') : el('span', 'text-muted', '—'));
        const statusTd = el('td');
        statusTd.append(badge(row.status === 'active' ? 'Active' : 'Inactive', row.status === 'active' ? 'success' : 'neutral'));
        const actions = el('td');
        const open = el('button', 'btn btn-ghost btn-sm', 'View');
        open.type = 'button';
        open.addEventListener('click', () => openDetail(row.uuid));
        actions.append(open);
        tr.append(
          el('td', null, row.display_name),
          contact,
          el('td', 'text-muted', row.branches || '—'),
          el('td', 'text-muted', String(row.services)),
          onlineTd,
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
      const { wrap, body: drawerBody } = ui.drawerShell('Staff member');
      drawerBody.append(ui.spinner('Loading staff member…'));
      ui.drawer.setContent(wrap);
      ui.drawer.open();

      api.get('/api/staff/' + encodeURIComponent(uuid))
        .then((data) => {
          const s = data.staff;
          drawerBody.replaceChildren();

          const facts = el('div', 'mt-2 space-y-3');
          const fact = (label, value) => {
            const row = el('div', 'flex items-center gap-3 text-sm');
            row.append(el('span', 'text-muted', label), el('span', 'ml-auto font-medium text-ink', value));
            facts.append(row);
          };
          fact('Name', `${s.first_name} ${s.last_name}`);
          fact('Display name', s.display_name);
          fact('Email', s.email || '—');
          fact('Phone', s.phone || '—');
          fact('Services', String(s.services));
          fact('Priority', String(s.booking_priority));
          fact('Status', s.status === 'active' ? 'Active' : 'Inactive');
          fact('Online booking', s.is_bookable_online ? 'Enabled' : 'Disabled');
          fact('Linked bookings', String(s.references));
          drawerBody.append(facts);

          if (s.schedule && s.schedule.length) {
            drawerBody.append(el('h4', 'mt-6 text-sm font-semibold text-ink', 'Weekly schedule'));
            const list = el('ul', 'mt-2 space-y-2');
            s.schedule.forEach((r) => {
              const li = el('li', 'flex items-center justify-between gap-3 rounded-lg border border-line px-3 py-2 text-sm');
              li.append(el('span', 'text-muted', WEEKDAYS[r.weekday - 1]), el('span', 'font-medium text-ink', `${r.starts_at}–${r.ends_at}`));
              list.append(li);
            });
            drawerBody.append(list);
          }
          if (s.bio) {
            drawerBody.append(el('h4', 'mt-6 text-sm font-semibold text-ink', 'Bio'));
            drawerBody.append(el('p', 'mt-2 text-sm text-muted', s.bio));
          }

          if (!canManage) return;
          const actions = el('div', 'mt-6 flex gap-2');
          const edit = el('button', 'btn btn-primary btn-md', 'Edit');
          edit.type = 'button';
          edit.addEventListener('click', () => openForm(s));
          const del = el('button', 'btn btn-danger btn-md', 'Delete');
          del.type = 'button';
          del.addEventListener('click', () => {
            ui.confirm({
              title: 'Delete staff member?',
              message: `"${s.display_name}" will be permanently removed.`,
              confirmLabel: 'Delete',
              danger: true,
            }).then((ok) => {
              if (!ok) return;
              api.del('/api/staff/' + encodeURIComponent(s.uuid))
                .then(() => {
                  AlbaBook.toast('Staff member deleted.');
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
      label.setAttribute('for', 'staff-' + name);
      input.id = 'staff-' + name;
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

    function checkboxGroup(name, labelText, items, selected, labelKey) {
      const wrap = el('div', 'field');
      wrap.append(el('span', 'field-label', labelText));
      const chosen = new Set((selected || []).map(Number));
      items.forEach((item) => {
        const id = `staff-${name}-${item.id}`;
        const label = el('label', 'flex items-center gap-2 text-sm text-ink');
        const box = input('checkbox');
        box.className = 'checkbox';
        box.id = id;
        box.name = name;
        box.value = item.id;
        box.checked = chosen.has(item.id);
        label.setAttribute('for', id);
        label.append(box, el('span', null, item[labelKey]));
        wrap.append(label);
      });
      const err = el('p', 'field-error');
      err.id = 'field-' + name + '-error';
      wrap.append(err);
      return wrap;
    }

    function openForm(member) {
      const isEdit = member !== null;
      const { wrap, body: drawerBody } = ui.drawerShell(isEdit ? 'Edit staff member' : 'New staff member');
      const meta = state.meta || { branches: [], services: [], members: [] };

      const form = el('form', 'flex flex-col gap-4');
      form.noValidate = true;

      const firstInput = input('text', member ? member.first_name : '');
      firstInput.required = true;
      firstInput.maxLength = 100;
      const lastInput = input('text', member ? member.last_name : '');
      lastInput.required = true;
      lastInput.maxLength = 100;
      const displayInput = input('text', member ? member.display_name : '');
      displayInput.required = true;
      displayInput.maxLength = 150;
      const emailInput = input('email', member ? member.email : '');
      const phoneInput = input('tel', member ? member.phone : '');
      phoneInput.placeholder = '+254712345678';
      const bioInput = el('textarea', 'input');
      bioInput.rows = 3;
      bioInput.value = member && member.bio ? member.bio : '';
      const priorityInput = input('number', member ? member.booking_priority : 0);
      priorityInput.min = 0;

      const memberSelect = el('select', 'input select');
      memberSelect.append(el('option', null, 'No login link'));
      memberSelect.options[0].value = '';
      (meta.members || []).forEach((m) => {
        const opt = el('option', null, `${m.name} (${m.email})`);
        opt.value = m.id;
        memberSelect.append(opt);
      });
      if (member && member.tenant_user_id) memberSelect.value = String(member.tenant_user_id);

      const statusSelect = el('select', 'input select');
      [['active', 'Active'], ['inactive', 'Inactive']].forEach(([v, label]) => {
        const o = el('option', null, label);
        o.value = v;
        statusSelect.append(o);
      });
      statusSelect.value = member ? member.status : 'active';

      const nameGrid = el('div', 'grid grid-cols-2 gap-4');
      nameGrid.append(field('First name', firstInput, 'first_name'), field('Last name', lastInput, 'last_name'));

      const onlineFlag = (() => {
        const label = el('label', 'flex items-center gap-2 text-sm text-ink');
        const box = input('checkbox');
        box.className = 'checkbox';
        box.id = 'staff-is_bookable_online';
        box.name = 'is_bookable_online';
        box.checked = member ? !!member.is_bookable_online : true;
        label.setAttribute('for', box.id);
        label.append(box, el('span', null, 'Bookable on the public page'));
        return { label, box };
      })();

      /* Weekly schedule editor: checkbox per weekday + start/end times. */
      const scheduleWrap = el('div', 'field');
      scheduleWrap.append(el('span', 'field-label', 'Working days'));
      const scheduleErr = el('p', 'field-error');
      scheduleErr.id = 'field-schedule-error';
      const scheduleByDay = {};
      (member ? member.schedule || [] : []).forEach((r) => { scheduleByDay[r.weekday] = r; });
      const dayControls = [];
      WEEKDAYS.forEach((label, i) => {
        const weekday = i + 1;
        const rowEl = el('div', 'flex items-center gap-3');
        const checkLabel = el('label', 'flex w-32 items-center gap-2 text-sm text-ink');
        const box = input('checkbox');
        box.className = 'checkbox';
        const existing = scheduleByDay[weekday];
        box.checked = existing !== undefined || (!member && weekday <= 5);
        checkLabel.append(box, el('span', null, label));
        const start = input('time', existing ? existing.starts_at : '08:00');
        const end = input('time', existing ? existing.ends_at : '18:00');
        start.disabled = end.disabled = !box.checked;
        box.addEventListener('change', () => { start.disabled = end.disabled = !box.checked; });
        dayControls.push({ weekday, box, start, end });
        rowEl.append(checkLabel, start, el('span', 'text-muted', '–'), end);
        scheduleWrap.append(rowEl);
      });
      scheduleWrap.append(scheduleErr);

      const submit = el('button', 'btn btn-primary btn-md', isEdit ? 'Save changes' : 'Create staff member');
      submit.type = 'submit';

      form.append(
        nameGrid,
        field('Display name', displayInput, 'display_name'),
        field('Email', emailInput, 'email'),
        field('Phone', phoneInput, 'phone_e164'),
        field('Team member account (for own-appointments view)', memberSelect, 'tenant_user_id'),
        field('Bio', bioInput, 'bio'),
        field('Booking priority (lower shows first)', priorityInput, 'booking_priority'),
        field('Status', statusSelect, 'status'),
        checkboxGroup('branch_ids', 'Works at branches', meta.branches || [], member ? member.branch_ids : (meta.branches || []).map((b) => b.id), 'name'),
        checkboxGroup('service_ids', 'Services offered', meta.services || [], member ? member.service_ids : [], 'name'),
        scheduleWrap,
        onlineFlag.label,
        submit,
      );

      form.addEventListener('submit', (event) => {
        event.preventDefault();
        ui.clearFieldErrors(form);

        const checked = (name) => [...form.querySelectorAll(`input[name="${name}"]:checked`)].map((b) => Number(b.value));
        const payload = {
          first_name: firstInput.value.trim(),
          last_name: lastInput.value.trim(),
          display_name: displayInput.value.trim(),
          email: emailInput.value.trim(),
          phone_e164: phoneInput.value.trim(),
          bio: bioInput.value.trim(),
          tenant_user_id: memberSelect.value === '' ? 0 : Number(memberSelect.value),
          booking_priority: Number(priorityInput.value || 0),
          status: statusSelect.value,
          is_bookable_online: onlineFlag.box.checked,
          branch_ids: checked('branch_ids'),
          service_ids: checked('service_ids'),
          schedule: dayControls
            .filter((d) => d.box.checked)
            .map((d) => ({ weekday: d.weekday, starts_at: d.start.value, ends_at: d.end.value })),
        };

        ui.setBusy(submit, true, 'Saving…');
        const request = isEdit
          ? api.patch('/api/staff/' + encodeURIComponent(member.uuid), payload)
          : api.post('/api/staff', payload);

        request
          .then(() => {
            AlbaBook.toast(isEdit ? 'Staff member updated.' : 'Staff member created.');
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

    const newBtn = root.querySelector('[data-staff-new]');
    if (newBtn) newBtn.addEventListener('click', () => openForm(null));

    load();
  });
})();
