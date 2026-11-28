/*
 * Time off & closures: weekly breaks, time off (with approval), branch
 * closures / special hours, and blocked time. One GET hydrates all tabs;
 * each form posts to /api/availability/* and the server re-validates
 * permissions, branch scope and times.
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
  const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const today = () => new Date().toISOString().slice(0, 10);

  function field(label, input, name, hint) {
    const wrap = el('div', 'field');
    const lab = el('label', 'field-label', label);
    lab.setAttribute('for', 'av-' + name);
    input.id = 'av-' + name;
    input.name = name;
    wrap.append(lab, input);
    if (hint) wrap.append(el('p', 'mt-1 text-xs text-muted', hint));
    const err = el('p', 'field-error');
    err.id = 'field-' + name + '-error';
    wrap.append(err);
    return wrap;
  }
  function input(type, value) {
    const i = el('input', 'input');
    i.type = type;
    if (value !== undefined && value !== null) i.value = value;
    return i;
  }
  function select(options, value) {
    const s = el('select', 'input select');
    options.forEach(([v, label]) => {
      const o = el('option', null, label);
      o.value = String(v);
      s.append(o);
    });
    if (value !== undefined && value !== null) s.value = String(value);
    return s;
  }
  function checks(name, options, label) {
    const set = el('fieldset', 'field');
    set.append(el('legend', 'field-label', label));
    const grid = el('div', 'mt-1 flex flex-wrap gap-3');
    options.forEach(([v, text]) => {
      const lab = el('label', 'flex items-center gap-2 text-sm text-ink');
      const box = input('checkbox');
      box.className = 'checkbox';
      box.name = name;
      box.value = String(v);
      lab.append(box, el('span', null, text));
      grid.append(lab);
    });
    const err = el('p', 'field-error');
    err.id = 'field-' + name.replace('[]', '') + '-error';
    set.append(grid, err);
    return set;
  }
  const checked = (form, name) => Array.from(form.querySelectorAll('input[name="' + name + '"]:checked')).map((b) => b.value);
  function card(title, subtitle) {
    const c = el('section', 'card p-6');
    c.append(el('h3', 'text-base font-semibold text-ink', title));
    if (subtitle) c.append(el('p', 'mt-1 text-sm text-muted', subtitle));
    return c;
  }
  function submitRow(label) {
    const row = el('div', 'mt-4 flex flex-wrap gap-3');
    const b = el('button', 'btn btn-primary btn-md', label);
    b.type = 'submit';
    row.append(b);
    return [row, b];
  }
  function list(items, render, emptyText) {
    if (!items.length) return el('p', 'mt-4 text-sm text-muted', emptyText);
    const ul = el('ul', 'mt-4 divide-y divide-line');
    items.forEach((item) => ul.append(render(item)));
    return ul;
  }
  function row(main, sub, actions) {
    const li = el('li', 'flex flex-wrap items-center justify-between gap-3 py-3');
    const text = el('div', 'min-w-0');
    text.append(el('p', 'text-sm font-medium text-ink', main));
    if (sub) text.append(el('p', 'text-xs text-muted', sub));
    const act = el('div', 'flex shrink-0 gap-2');
    (actions || []).forEach((a) => act.append(a));
    li.append(text, act);
    return li;
  }
  function smallBtn(label, cls, onClick) {
    const b = el('button', 'btn btn-sm ' + cls, label);
    b.type = 'button';
    b.addEventListener('click', () => onClick(b));
    return b;
  }
  /* Whole-day vs part-of-day window inputs shared by time off and blocks. */
  function windowInputs(prefixAllDay) {
    const allDay = input('checkbox');
    allDay.className = 'checkbox';
    allDay.checked = prefixAllDay;
    allDay.name = 'all_day';
    allDay.id = 'av-all_day';
    const allDayLabel = el('label', 'flex items-center gap-2 text-sm text-ink');
    allDayLabel.setAttribute('for', 'av-all_day');
    allDayLabel.append(allDay, el('span', null, 'Whole day(s)'));

    const date = input('date', today());
    date.min = today();
    const toDate = input('date', '');
    toDate.min = today();
    const start = input('time', '09:00');
    const end = input('time', '13:00');
    const grid = el('div', 'mt-3 grid gap-4 sm:grid-cols-3');
    const toWrap = field('Last day', toDate, 'to_date', 'Leave empty for one day.');
    const startWrap = field('From', start, 'start_time');
    const endWrap = field('To', end, 'end_time');
    grid.append(field('Date', date, 'date'), toWrap, startWrap, endWrap);
    const sync = () => {
      toWrap.hidden = !allDay.checked;
      startWrap.hidden = allDay.checked;
      endWrap.hidden = allDay.checked;
    };
    allDay.addEventListener('change', sync);
    sync();
    const wrap = el('div', 'mt-4');
    wrap.append(allDayLabel, grid);
    return {
      node: wrap,
      values: () => ({
        all_day: allDay.checked,
        date: date.value,
        to_date: toDate.value || date.value,
        start_time: start.value,
        end_time: end.value,
      }),
    };
  }

  AlbaBook.pages.register('availability', function (root) {
    const body = root.querySelector('[data-av-body]');
    const tabs = root.querySelectorAll('[data-av-tab]');
    let current = (window.location.hash || '').replace('#', '') || 'leave';
    let data = null;

    tabs.forEach((t) => t.addEventListener('click', () => {
      current = t.dataset.avTab;
      history.replaceState(null, '', '#' + current);
      render();
    }));

    function load() {
      body.replaceChildren(ui.spinner('Loading schedules…'));
      api.get('/api/availability')
        .then((d) => { data = d; render(); })
        .catch((error) => body.replaceChildren(ui.errorState(error.message, load)));
    }

    function submit(form, button, url, payload, done) {
      ui.clearFieldErrors(form);
      ui.setBusy(button, true, 'Saving…');
      api.post(url, payload)
        .then((res) => { done(res); load(); })
        .catch((error) => {
          if (error.fields) ui.showFieldErrors(form, error.fields);
          AlbaBook.toast(error.message, 'danger');
        })
        .finally(() => ui.setBusy(button, false));
    }
    async function remove(url, question, button) {
      const yes = await ui.confirm({ title: 'Remove?', message: question, confirmLabel: 'Remove', danger: true });
      if (!yes) return;
      ui.setBusy(button, true, 'Removing…');
      api.del(url)
        .then(() => { AlbaBook.toast('Removed.'); load(); })
        .catch((error) => { ui.setBusy(button, false); AlbaBook.toast(error.message, 'danger'); });
    }
    const overlapNote = (n) => (n > 0 ? ' ' + n + ' existing booking' + (n === 1 ? ' overlaps' : 's overlap') + ' — reschedule or cancel ' + (n === 1 ? 'it' : 'them') + ' from the calendar.' : '');
    const staffOptions = () => data.staff.map((s) => [s.id, s.name]);

    function render() {
      tabs.forEach((t) => t.classList.toggle('is-active', t.dataset.avTab === current));
      if (!data) return;
      const view = { leave: renderLeave, breaks: renderBreaks, closures: renderClosures, blocks: renderBlocks }[current] || renderLeave;
      body.replaceChildren(view());
    }

    /* ---------------------------------------------------------- time off */
    function renderLeave() {
      const wrap = el('div', 'grid gap-6 lg:grid-cols-5');
      const manager = data.can.manage_staff;
      const canAdd = manager || data.own_staff_id !== null;
      const pending = data.leave.filter((l) => l.status === 'requested');
      const approved = data.leave.filter((l) => l.status === 'approved');

      const listCard = card('Upcoming time off', 'Approved time off is closed on the booking page automatically.');
      listCard.classList.add('lg:col-span-3');
      if (pending.length) {
        listCard.append(el('h4', 'mt-4 text-sm font-semibold text-warning-strong', 'Waiting for approval'));
        listCard.append(list(pending, (l) => row(l.staff_name + ' · ' + l.type, l.label + (l.reason ? ' — ' + l.reason : ''), [
          ...(manager ? [
            smallBtn('Decline', 'btn-secondary', (b) => setLeave(l.id, 'rejected', b)),
            smallBtn('Approve', 'btn-primary', (b) => setLeave(l.id, 'approved', b)),
          ] : (l.staff_id === data.own_staff_id ? [smallBtn('Withdraw', 'btn-secondary', (b) => setLeave(l.id, 'cancelled', b))] : [])),
        ]), ''));
      }
      listCard.append(list(approved, (l) => row(l.staff_name + ' · ' + l.type, l.label + (l.reason ? ' — ' + l.reason : ''),
        manager ? [smallBtn('Cancel', 'btn-secondary', (b) => setLeave(l.id, 'cancelled', b))] : []), 'No upcoming time off.'));

      wrap.append(listCard);
      if (canAdd) {
        const formCard = card(manager ? 'Add time off' : 'Request time off', manager ? 'Recorded as approved.' : 'Your manager approves it before it applies.');
        formCard.classList.add('lg:col-span-2', 'h-fit');
        const form = el('form', 'mt-2');
        form.noValidate = true;
        const staff = manager ? select(staffOptions(), data.own_staff_id ?? undefined) : null;
        const type = select(data.leave_types.map((t) => [t.id, t.name]));
        const win = windowInputs(true);
        const reason = input('text', '');
        reason.maxLength = 255;
        reason.placeholder = 'Optional';
        const [actions, button] = submitRow(manager ? 'Add time off' : 'Send request');
        if (staff) form.append(field('Team member', staff, 'staff_id'));
        form.append(field('Type', type, 'leave_type_id'), win.node, field('Note', reason, 'reason'), actions);
        form.onsubmit = (e) => {
          e.preventDefault();
          submit(form, button, '/api/availability/leave', Object.assign({
            staff_id: staff ? Number(staff.value) : data.own_staff_id,
            leave_type_id: Number(type.value),
            reason: reason.value.trim(),
          }, win.values()), (res) => {
            AlbaBook.toast((res.status === 'approved' ? 'Time off added.' : 'Request sent for approval.') + overlapNote(res.overlapping_appointments), res.overlapping_appointments ? 'info' : 'success');
          });
        };
        formCard.append(form);
        wrap.append(formCard);
      }
      return wrap;
    }
    function setLeave(id, status, button) {
      ui.setBusy(button, true, 'Saving…');
      api.post('/api/availability/leave/' + id + '/status', { status })
        .then(() => { AlbaBook.toast({ approved: 'Time off approved.', rejected: 'Request declined.', cancelled: 'Time off cancelled.' }[status]); load(); })
        .catch((error) => { ui.setBusy(button, false); AlbaBook.toast(error.message, 'danger'); });
    }

    /* ------------------------------------------------------------ breaks */
    function renderBreaks() {
      const wrap = el('div', 'grid gap-6 lg:grid-cols-5');
      const manager = data.can.manage_staff;
      const listCard = card('Weekly breaks', 'Repeating breaks such as lunch. Times are in ' + data.timezone + '.');
      listCard.classList.add('lg:col-span-3');
      listCard.append(list(data.breaks, (b) => row(b.staff_name, DAYS[b.weekday - 1] + ' · ' + b.starts_at + '–' + b.ends_at,
        manager ? [smallBtn('Remove', 'btn-secondary', (btn) => remove('/api/availability/breaks/' + b.id, 'Remove this break? The time becomes bookable again.', btn))] : []),
        'No breaks yet.'));
      wrap.append(listCard);
      if (manager) {
        const formCard = card('Add a break');
        formCard.classList.add('lg:col-span-2', 'h-fit');
        const form = el('form', 'mt-2 space-y-4');
        form.noValidate = true;
        const staff = select(staffOptions());
        const start = input('time', '13:00');
        const end = input('time', '14:00');
        const times = el('div', 'grid gap-4 sm:grid-cols-2');
        times.append(field('From', start, 'starts_at'), field('To', end, 'ends_at'));
        const days = checks('weekdays[]', DAYS.map((d, i) => [i + 1, d]), 'Days');
        const [actions, button] = submitRow('Add break');
        form.append(field('Team member', staff, 'staff_id'), days, times, actions);
        form.onsubmit = (e) => {
          e.preventDefault();
          submit(form, button, '/api/availability/breaks', {
            staff_id: Number(staff.value),
            weekdays: checked(form, 'weekdays[]').map(Number),
            starts_at: start.value,
            ends_at: end.value,
          }, (res) => AlbaBook.toast('Break added on ' + res.added + ' day' + (res.added === 1 ? '' : 's') + '.'));
        };
        formCard.append(form);
        wrap.append(formCard);
      }
      return wrap;
    }

    /* ---------------------------------------------------------- closures */
    function renderClosures() {
      const wrap = el('div', 'grid gap-6 lg:grid-cols-5');
      const manager = data.can.manage_branches;
      const listCard = card('Closures & special hours', 'Public holidays, closed days and shorter or longer opening hours.');
      listCard.classList.add('lg:col-span-3');
      listCard.append(list(data.closures, (c) => row(c.branch_name + ' · ' + c.date,
        (c.closed ? 'Closed all day' : 'Open ' + c.opens_at + '–' + c.closes_at) + (c.reason ? ' — ' + c.reason : ''),
        manager ? [smallBtn('Remove', 'btn-secondary', (btn) => remove('/api/availability/closures/' + c.id, 'Remove this closure? Normal opening hours apply again.', btn))] : []),
        'No upcoming closures.'));
      wrap.append(listCard);
      if (manager) {
        const formCard = card('Add a closure', 'Saving a date that already has one replaces it.');
        formCard.classList.add('lg:col-span-2', 'h-fit');
        const form = el('form', 'mt-2 space-y-4');
        form.noValidate = true;
        const branches = checks('branch_ids[]', data.branches.map((b) => [b.id, b.name]), 'Branches');
        if (data.branches.length === 1) branches.querySelector('input').checked = true;
        const date = input('date', today());
        date.min = today();
        const toDate = input('date', '');
        toDate.min = today();
        const dates = el('div', 'grid gap-4 sm:grid-cols-2');
        dates.append(field('First day', date, 'date'), field('Last day', toDate, 'to_date', 'Leave empty for one day.'));
        const mode = select([['closed', 'Closed all day'], ['hours', 'Open with special hours']]);
        const opens = input('time', '09:00');
        const closes = input('time', '14:00');
        const hours = el('div', 'grid gap-4 sm:grid-cols-2');
        hours.append(field('Opens', opens, 'opens_at'), field('Closes', closes, 'closes_at'));
        const syncMode = () => { hours.hidden = mode.value === 'closed'; };
        mode.addEventListener('change', syncMode);
        syncMode();
        const reason = input('text', '');
        reason.maxLength = 255;
        reason.placeholder = 'e.g. Jamhuri Day';
        const [actions, button] = submitRow('Save closure');
        form.append(branches, dates, field('Opening', mode, 'mode'), hours, field('Reason', reason, 'reason'), actions);
        form.onsubmit = (e) => {
          e.preventDefault();
          submit(form, button, '/api/availability/closures', {
            branch_ids: checked(form, 'branch_ids[]').map(Number),
            date: date.value,
            to_date: toDate.value || date.value,
            closed: mode.value === 'closed',
            opens_at: opens.value,
            closes_at: closes.value,
            reason: reason.value.trim(),
          }, (res) => AlbaBook.toast('Saved for ' + res.days + ' day' + (res.days === 1 ? '' : 's') + '.'));
        };
        formCard.append(form);
        wrap.append(formCard);
      }
      return wrap;
    }

    /* ------------------------------------------------------ blocked time */
    function renderBlocks() {
      const wrap = el('div', 'grid gap-6 lg:grid-cols-5');
      const can = data.can.block_time;
      const listCard = card('Blocked time', 'One-off times nobody can book: meetings, training, maintenance.');
      listCard.classList.add('lg:col-span-3');
      listCard.append(list(data.blocks, (p) => row(p.target, p.label + (p.reason ? ' — ' + p.reason : ''),
        can ? [smallBtn('Remove', 'btn-secondary', (btn) => remove('/api/availability/blocks/' + p.id, 'Remove this block? The time becomes bookable again.', btn))] : []),
        'No upcoming blocked time.'));
      wrap.append(listCard);
      if (can) {
        const formCard = card('Block time');
        formCard.classList.add('lg:col-span-2', 'h-fit');
        const form = el('form', 'mt-2 space-y-4');
        form.noValidate = true;
        const target = select([['staff', 'A team member'], ['branch', 'A whole branch']]);
        const staff = select(staffOptions());
        const branch = select(data.branches.map((b) => [b.id, b.name]));
        const staffWrap = field('Team member', staff, 'staff_id');
        const branchWrap = field('Branch', branch, 'branch_id');
        const syncTarget = () => {
          staffWrap.hidden = target.value !== 'staff';
          branchWrap.hidden = target.value !== 'branch';
        };
        target.addEventListener('change', syncTarget);
        syncTarget();
        const win = windowInputs(false);
        const reason = input('text', '');
        reason.maxLength = 255;
        reason.placeholder = 'e.g. Team meeting';
        const [actions, button] = submitRow('Block time');
        form.append(field('Block', target, 'target'), staffWrap, branchWrap, win.node, field('Reason', reason, 'reason'), actions);
        form.onsubmit = (e) => {
          e.preventDefault();
          submit(form, button, '/api/availability/blocks', Object.assign({
            target: target.value,
            staff_id: Number(staff.value),
            branch_id: Number(branch.value),
            reason: reason.value.trim(),
          }, win.values()), (res) => {
            AlbaBook.toast('Time blocked.' + overlapNote(res.overlapping_appointments), res.overlapping_appointments ? 'info' : 'success');
          });
        };
        formCard.append(form);
        wrap.append(formCard);
      }
      return wrap;
    }

    render();
    load();
  });
})();
