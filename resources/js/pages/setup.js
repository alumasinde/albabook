/*
 * Guided setup wizard (update.md 2.2): location & opening hours → first
 * service → who takes bookings → working hours → booking rules → go live.
 * One GET /api/setup hydrates every step; each step saves through the same
 * JSON APIs the dashboard uses, so progress is real data — the wizard can
 * be left and resumed at any time. SetupReadiness decides what is missing.
 */
(function () {
  const AlbaBook = window.AlbaBook;
  const api = AlbaBook.api;
  const ui = AlbaBook.ui;
  const el = ui.el;
  const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

  function field(label, input, name, hint) {
    const wrap = el('div', 'field');
    const lab = el('label', 'field-label', label);
    lab.setAttribute('for', 'su-' + name);
    input.id = 'su-' + name;
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
    if (value !== undefined && value !== null && value !== '') s.value = String(value);
    return s;
  }
  function card(title, subtitle) {
    const c = el('section', 'card p-6');
    c.append(el('h3', 'text-base font-semibold text-ink', title));
    if (subtitle) c.append(el('p', 'mt-1 text-sm text-muted', subtitle));
    return c;
  }
  /* Radio cards (same look as Settings → booking rules). */
  function radioCards(name, options, value) {
    const wrap = el('div', 'mt-2 grid gap-3 sm:grid-cols-2');
    options.forEach(([v, title, hint]) => {
      const lab = el('label', 'rule-option');
      const box = input('radio');
      box.className = 'radio mt-1';
      box.name = name;
      box.value = v;
      box.checked = String(v) === String(value);
      const text = el('span', 'min-w-0');
      text.append(el('span', 'block text-sm font-semibold text-ink', title));
      if (hint) text.append(el('span', 'mt-0.5 block text-xs text-muted', hint));
      lab.append(box, text);
      wrap.append(lab);
    });
    return wrap;
  }
  const radioValue = (form, name) => {
    const box = form.querySelector('input[name="' + name + '"]:checked');
    return box ? box.value : '';
  };
  /* Checkbox-per-weekday editor shared by opening hours and staff schedule.
     rows: [{weekday, from, to}]; precheck(weekday) decides defaults. */
  function weekEditor(label, errName, rows, precheck) {
    const wrap = el('div', 'field mt-2');
    wrap.append(el('span', 'field-label', label));
    const byDay = {};
    (rows || []).forEach((r) => { byDay[r.weekday] = r; });
    const controls = [];
    DAYS.forEach((day, i) => {
      const weekday = i + 1;
      const row = el('div', 'flex items-center gap-3');
      const lab = el('label', 'flex w-28 items-center gap-2 text-sm text-ink sm:w-32');
      const box = input('checkbox');
      box.className = 'checkbox';
      const existing = byDay[weekday];
      box.checked = existing !== undefined || precheck(weekday);
      lab.append(box, el('span', null, day));
      const from = input('time', existing ? existing.from : '08:00');
      const to = input('time', existing ? existing.to : '18:00');
      from.disabled = to.disabled = !box.checked;
      box.addEventListener('change', () => { from.disabled = to.disabled = !box.checked; });
      controls.push({ weekday, box, from, to });
      row.append(lab, from, el('span', 'text-muted', '–'), to);
      wrap.append(row);
    });
    const err = el('p', 'field-error');
    err.id = 'field-' + errName + '-error';
    wrap.append(err);
    return { node: wrap, controls };
  }
  function navActions(back, next) {
    const row = el('div', 'mt-6 flex flex-wrap items-center justify-between gap-3');
    const left = el('div');
    if (back) {
      const b = el('button', 'btn btn-ghost btn-md', back.label);
      b.type = 'button';
      b.addEventListener('click', back.onClick);
      left.append(b);
    }
    row.append(left, next);
    return row;
  }

  AlbaBook.pages.register('setup', function (root) {
    const stepsEl = root.querySelector('[data-setup-steps]');
    const body = root.querySelector('[data-setup-body]');
    let data = null;
    let step = 0;

    const STEPS = [
      { key: 'place', label: 'Location & hours' },
      { key: 'service', label: 'First service' },
      { key: 'team', label: 'Who takes bookings' },
      { key: 'hours', label: 'Working hours' },
      { key: 'rules', label: 'Booking rules' },
      { key: 'live', label: 'Go live' },
    ];

    const flag = (key) => {
      const item = (data.readiness.items || []).find((i) => i.key === key);
      return !!(item && item.done);
    };
    const stepDone = [
      () => flag('branch') && flag('hours'),
      () => flag('service'),
      () => data.staff.length > 0,
      () => flag('staff'),
      () => data.rules_saved,
      () => data.page.is_enabled,
    ];
    function firstIncomplete() {
      if (!(flag('branch') && flag('hours'))) return 0;
      if (!flag('service')) return 1;
      if (data.staff.length === 0) return 2;
      if (!flag('staff')) return 3;
      if (!data.rules_saved) return 4;
      return 5;
    }

    const branch = () => data.branches[0] || null;
    const service = () => data.services[0] || null;
    const member = () => data.staff[0] || null;
    const branchHoursRows = () => (branch() ? branch().hours : []).map((h) => ({ weekday: h.weekday, from: h.opens_at, to: h.closes_at }));
    const scheduleFromHours = () => branchHoursRows().map((h) => ({ weekday: h.weekday, starts_at: h.from, ends_at: h.to }));

    function load() {
      body.replaceChildren(ui.spinner('Loading your setup…'));
      return api.get('/api/setup')
        .then((d) => { data = d; })
        .catch((error) => body.replaceChildren(ui.errorState(error.message, load)));
    }
    const reload = () => api.get('/api/setup').then((d) => { data = d; });

    function go(next) {
      step = next;
      render();
      const h = body.querySelector('h3');
      if (h) h.setAttribute('tabindex', '-1'), h.focus();
    }
    function save(form, button, request, done) {
      ui.clearFieldErrors(form);
      ui.setBusy(button, true, 'Saving…');
      request
        .then((res) => reload().then(() => done(res)))
        .catch((error) => {
          if (error.fields) ui.showFieldErrors(form, error.fields);
          AlbaBook.toast(error.message, 'danger');
        })
        .finally(() => ui.setBusy(button, false));
    }

    function render() {
      /* Step pills: completed steps and the next one are clickable. */
      const reachable = Math.max(firstIncomplete(), step);
      stepsEl.replaceChildren(
        ...STEPS.map((s, i) => {
          const li = el('li');
          const btn = el('button', 'step-dot');
          btn.type = 'button';
          const dot = el('span', null, stepDone[i]() ? '✓' : String(i + 1));
          btn.append(dot, document.createTextNode(s.label));
          btn.classList.toggle('is-active', i === step);
          btn.classList.toggle('is-done', stepDone[i]() && i !== step);
          btn.disabled = i > reachable;
          if (i > reachable) btn.classList.add('opacity-50');
          btn.addEventListener('click', () => { if (i <= reachable) go(i); });
          li.append(btn);
          return li;
        }),
      );
      stepsEl.className = 'step-dots mb-6 list-none';

      const view = [renderPlace, renderService, renderTeam, renderHours, renderRules, renderLive][step];
      body.replaceChildren(view());
    }

    /* ------------------------------------------------- 1: location+hours */
    function renderPlace() {
      const b = branch();
      const c = card('Where do customers find you?', 'Your first location. You can add more branches later from Branches.');
      const form = el('form', 'mt-4 space-y-4');
      form.noValidate = true;

      const name = input('text', b ? b.name : data.tenant.name);
      const town = input('text', b ? b.town : '');
      town.placeholder = 'e.g. Westlands, Nakuru CBD';
      const county = input('text', b ? b.county : '');
      county.placeholder = 'e.g. Nairobi';
      const phone = input('tel', b ? b.phone : '');
      phone.placeholder = '+254…';
      const places = el('div', 'grid gap-4 sm:grid-cols-2');
      places.append(field('Town / area', town, 'town'), field('County', county, 'county'));

      const hours = weekEditor('Opening hours', 'hours', branchHoursRows(), (w) => !b && w <= 6);
      const submit = el('button', 'btn btn-primary btn-md', 'Save & continue');
      submit.type = 'submit';
      form.append(field('Location name', name, 'name'), places, field('Public phone (optional)', phone, 'phone'), hours.node, navActions(null, submit));
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        const payload = {
          name: name.value.trim(),
          address_line1: b ? b.address_line1 : '',
          address_line2: b ? b.address_line2 : '',
          town: town.value.trim(),
          county: county.value.trim(),
          phone: phone.value.trim(),
          email: b ? b.email : '',
          timezone: (b && b.timezone) || data.tenant.timezone || 'Africa/Nairobi',
          is_active: true,
          hours: hours.controls.filter((d) => d.box.checked).map((d) => ({ weekday: d.weekday, opens_at: d.from.value, closes_at: d.to.value })),
        };
        save(form, submit, b ? api.patch('/api/branches/' + encodeURIComponent(b.uuid), payload) : api.post('/api/branches', payload), () => go(1));
      });
      c.append(form);
      return c;
    }

    /* ----------------------------------------------------- 2: first service */
    function renderService() {
      const s = service();
      const c = card('Your first service', 'The one customers book most. Add the rest later from Services.');
      const form = el('form', 'mt-4 space-y-4');
      form.noValidate = true;

      const name = input('text', s ? s.name : '');
      name.placeholder = 'e.g. Hair retouch, Fade cut, Manicure';
      const duration = input('number', s ? s.duration_minutes : 60);
      duration.min = '5'; duration.max = '600'; duration.step = '5';
      const price = input('number', s && s.price_amount_minor !== null ? s.price_amount_minor / 100 : '');
      price.min = '0'; price.step = '0.01';
      price.placeholder = 'e.g. 1500';

      const grid = el('div', 'grid gap-4 sm:grid-cols-2');
      grid.append(field('Duration (minutes)', duration, 'duration_minutes'), field('Price (' + data.tenant.currency + ')', price, 'price'));

      form.append(field('Service name', name, 'name'), grid);
      if (data.categories.length) {
        const category = select([['', '— No category —']].concat(data.categories.map((cat) => [cat.id, cat.name])), s ? s.category_id : '');
        form.append(field('Category (optional)', category, 'category_id'));
        form.dataset.hasCategory = '1';
        form._category = category;
      }
      const submit = el('button', 'btn btn-primary btn-md', 'Save & continue');
      submit.type = 'submit';
      form.append(navActions({ label: 'Back', onClick: () => go(0) }, submit));
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        const payload = {
          name: name.value.trim(),
          description: s ? s.description : '',
          category_id: form._category && form._category.value !== '' ? Number(form._category.value) : 0,
          duration_minutes: Number(duration.value),
          buffer_before_minutes: s ? s.buffer_before_minutes : 0,
          buffer_after_minutes: s ? s.buffer_after_minutes : 0,
          price_type: s ? s.price_type : 'fixed',
          price: price.value === '' ? null : Number(price.value),
          min_price: s && s.min_amount_minor !== null ? s.min_amount_minor / 100 : null,
          max_price: s && s.max_amount_minor !== null ? s.max_amount_minor / 100 : null,
          branch_ids: branch() ? [branch().id] : (s ? s.branch_ids : []),
          is_active: true,
          is_bookable_online: true,
          sort_position: s ? s.sort_position : 0,
        };
        save(form, submit, s ? api.patch('/api/services/' + encodeURIComponent(s.uuid), payload) : api.post('/api/services', payload), () => go(2));
      });
      c.append(form);
      return c;
    }

    /* ------------------------------------------- 3: who takes bookings */
    function renderTeam() {
      const m = member();
      const c = card('Who does the work?', 'The person customers book. You can invite more of the team later from Staff.');
      const form = el('form', 'mt-4 space-y-4');
      form.noValidate = true;

      const mode = m && m.tenant_user_id === data.me.tenant_user_id ? 'self' : (m ? 'member' : 'self');
      const modeField = el('div', 'field');
      modeField.append(el('span', 'field-label', 'Who takes bookings?'));
      const modeCards = radioCards('work_mode', [
        ['self', 'I work alone', 'You are the one customers book — your account is linked so your bookings appear under “mine”.'],
        ['member', 'A team member', 'Someone else does the work. They do not need an account yet.'],
      ], mode);
      modeField.append(modeCards);
      form.append(modeField);

      const first = input('text', m ? m.first_name : data.me.first_name);
      const last = input('text', m ? m.last_name : data.me.last_name);
      const names = el('div', 'grid gap-4 sm:grid-cols-2');
      names.append(field('First name', first, 'first_name'), field('Last name', last, 'last_name'));
      const display = input('text', m ? m.display_name : (data.me.first_name + ' ' + data.me.last_name).trim());
      const phone = input('tel', m ? m.phone_e164 : '');
      phone.placeholder = '+254…';
      const email = input('email', m ? m.email : '');
      const contact = el('div', 'grid gap-4 sm:grid-cols-2');
      contact.append(field('Phone (optional)', phone, 'phone_e164'), field('Email (optional)', email, 'email'));
      form.append(names, field('Name shown to customers', display, 'display_name', 'e.g. “Wanjiku” or “Mama Tina” — how the booking page lists them.'), contact);

      form.addEventListener('change', (e) => {
        if (e.target.name === 'work_mode' && e.target.value === 'self' && !m) {
          if (!first.value) first.value = data.me.first_name;
          if (!last.value) last.value = data.me.last_name;
          if (!display.value) display.value = (data.me.first_name + ' ' + data.me.last_name).trim();
        }
      });

      const submit = el('button', 'btn btn-primary btn-md', 'Save & continue');
      submit.type = 'submit';
      form.append(navActions({ label: 'Back', onClick: () => go(1) }, submit));
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        const payload = {
          first_name: first.value.trim(),
          last_name: last.value.trim(),
          display_name: display.value.trim(),
          email: email.value.trim(),
          phone_e164: phone.value.trim(),
          bio: m ? m.bio : '',
          commission_pct: m ? m.commission_pct : null,
          booking_priority: m ? m.booking_priority : 0,
          tenant_user_id: radioValue(form, 'work_mode') === 'self' ? data.me.tenant_user_id : 0,
          status: 'active',
          is_bookable_online: true,
          branch_ids: branch() ? [branch().id] : (m ? m.branch_ids : []),
          service_ids: service() ? [service().id] : (m ? m.service_ids : []),
          schedule: m && m.schedule.length ? m.schedule : scheduleFromHours(),
        };
        save(form, submit, m ? api.patch('/api/staff/' + encodeURIComponent(m.uuid), payload) : api.post('/api/staff', payload), () => go(3));
      });
      c.append(form);
      return c;
    }

    /* --------------------------------------------------- 4: working hours */
    function renderHours() {
      const m = member();
      const c = card('When is ' + (m ? m.display_name : 'the team member') + ' bookable?', 'Opening hours are when the doors are open — these are the times customers can actually book this person.');
      const form = el('form', 'mt-4');
      form.noValidate = true;

      const rows = (m ? m.schedule : []).map((s) => ({ weekday: s.weekday, from: s.starts_at, to: s.ends_at }));
      const hours = weekEditor('Working days', 'schedule', rows.length ? rows : branchHoursRows(), () => false);

      const copy = el('button', 'btn btn-ghost btn-sm', 'Same as opening hours');
      copy.type = 'button';
      copy.addEventListener('click', () => {
        const byDay = {};
        branchHoursRows().forEach((h) => { byDay[h.weekday] = h; });
        hours.controls.forEach((d) => {
          const h = byDay[d.weekday];
          d.box.checked = h !== undefined;
          if (h) { d.from.value = h.from; d.to.value = h.to; }
          d.from.disabled = d.to.disabled = !d.box.checked;
        });
      });

      const copyRow = el('div', 'mt-3');
      copyRow.append(copy);
      const submit = el('button', 'btn btn-primary btn-md', 'Save & continue');
      submit.type = 'submit';
      form.append(hours.node, copyRow, navActions({ label: 'Back', onClick: () => go(2) }, submit));
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        if (!m) { go(2); return; }
        const payload = {
          first_name: m.first_name,
          last_name: m.last_name,
          display_name: m.display_name,
          email: m.email || '',
          phone_e164: m.phone_e164 || '',
          bio: m.bio || '',
          commission_pct: m.commission_pct,
          booking_priority: m.booking_priority,
          tenant_user_id: m.tenant_user_id || 0,
          status: m.status === 'inactive' ? 'inactive' : 'active',
          is_bookable_online: true,
          branch_ids: m.branch_ids.length ? m.branch_ids : (branch() ? [branch().id] : []),
          service_ids: m.service_ids.length ? m.service_ids : (service() ? [service().id] : []),
          schedule: hours.controls.filter((d) => d.box.checked).map((d) => ({ weekday: d.weekday, starts_at: d.from.value, ends_at: d.to.value })),
        };
        save(form, submit, api.patch('/api/staff/' + encodeURIComponent(m.uuid), payload), () => go(4));
      });
      c.append(form);
      return c;
    }

    /* ---------------------------------------------------- 5: booking rules */
    function renderRules() {
      const r = data.rules;
      const c = card('How are bookings confirmed?', 'You can change these any time under Settings → Booking rules.');
      const form = el('form', 'mt-4 space-y-4');
      form.noValidate = true;

      const modeField = el('div', 'field');
      modeField.append(el('span', 'field-label', 'New online bookings'));
      const err = el('p', 'field-error');
      err.id = 'field-confirmation_mode-error';
      modeField.append(radioCards('confirmation_mode', [
        ['auto', 'Confirmed automatically', 'The customer gets an SMS/email confirmation right away. Recommended to start.'],
        ['manual', 'I approve each request', 'Bookings arrive as requests you confirm or decline on the dashboard.'],
      ], r.confirmation_mode), err);
      form.append(modeField);

      const notice = input('number', r.min_notice_minutes);
      notice.min = '0'; notice.max = '20160';
      const cutoff = select([
        ['', 'Any time before the visit'], ['120', '2 hours before'], ['240', '4 hours before'],
        ['720', '12 hours before'], ['1440', '24 hours before'], ['2880', '48 hours before'],
      ], r.cancellation_cutoff_minutes === null ? '' : r.cancellation_cutoff_minutes);
      const advance = select([
        ['', 'No limit'], ['30', '30 days'], ['60', '60 days'], ['90', '90 days'], ['180', '6 months'],
      ], r.max_advance_days === null ? '' : r.max_advance_days);
      const grid = el('div', 'grid gap-4 sm:grid-cols-3');
      grid.append(
        field('Minimum notice (minutes)', notice, 'min_notice_minutes', 'e.g. 30 = no bookings less than 30 min ahead.'),
        field('Customers can cancel until', cutoff, 'cancellation_cutoff_minutes'),
        field('Book at most', advance, 'max_advance_days', 'How far ahead the page shows.'),
      );
      form.append(grid);

      const submit = el('button', 'btn btn-primary btn-md', 'Save & continue');
      submit.type = 'submit';
      const skip = el('button', 'btn btn-ghost btn-md', 'Keep recommended — skip');
      skip.type = 'button';
      skip.addEventListener('click', () => go(5));
      const right = el('div', 'flex flex-wrap gap-3');
      right.append(skip, submit);
      form.append(navActions({ label: 'Back', onClick: () => go(3) }, right));
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        const payload = Object.assign({}, r, {
          confirmation_mode: radioValue(form, 'confirmation_mode') || 'auto',
          min_notice_minutes: notice.value === '' ? r.min_notice_minutes : Number(notice.value),
          cancellation_cutoff_minutes: cutoff.value === '' ? null : Number(cutoff.value),
          max_advance_days: advance.value === '' ? null : Number(advance.value),
        });
        save(form, submit, api.patch('/api/settings/booking-rules', payload), () => go(5));
      });
      c.append(form);
      return c;
    }

    /* --------------------------------------------------------- 6: go live */
    function renderLive() {
      if (data.page.is_enabled) return renderLiveSuccess();
      const c = card('Ready to go live', 'Check the list, then turn on your booking page. You can turn it off again any time from Booking page.');

      const list = el('ul', 'mt-4 grid gap-2 sm:grid-cols-2');
      data.readiness.items.forEach((item) => {
        const li = el('li', 'flex items-start gap-3 rounded-lg border border-line p-3');
        li.append(el('i', 'fa-solid mt-0.5 ' + (item.done ? 'fa-circle-check text-success' : 'fa-circle text-line')));
        const text = el('div', 'min-w-0');
        text.append(el('p', 'text-sm font-medium ' + (item.done ? 'text-muted line-through' : 'text-ink'), item.label));
        if (!item.done) text.append(el('p', 'text-xs text-muted', item.hint + (item.blocking ? '' : ' (optional)')));
        li.append(text);
        list.append(li);
      });
      c.append(list);

      const missing = data.readiness.items.filter((i) => i.blocking && !i.done);
      const emailItem = missing.find((i) => i.key === 'email');
      if (emailItem) {
        const alert = el('div', 'alert alert-warning mt-4');
        const text = el('span', 'alert-text', 'Your sign-up e-mail is not verified yet — the page cannot go live until it is.');
        const resend = el('button', 'btn btn-sm btn-secondary', 'Resend verification e-mail');
        resend.type = 'button';
        resend.addEventListener('click', () => {
          ui.setBusy(resend, true, 'Sending…');
          api.post('/verify-email/resend', {})
            .then(() => AlbaBook.toast('Verification e-mail sent. Check your inbox.'))
            .catch((error) => { ui.setBusy(resend, false); AlbaBook.toast(error.message, 'danger'); });
        });
        alert.append(text, resend);
        c.append(alert);
      }

      const urlRow = el('div', 'mt-4 rounded-lg bg-surface px-4 py-3');
      urlRow.append(el('p', 'text-xs font-medium uppercase tracking-wide text-muted', 'Your booking link'));
      urlRow.append(el('p', 'mt-1 break-all font-mono text-sm text-ink', data.booking_url));
      c.append(urlRow);

      const goBtn = el('button', 'btn btn-primary btn-lg', 'Turn on my booking page');
      goBtn.type = 'button';
      goBtn.disabled = missing.length > 0 || !(data.can.page && data.can.publish);
      if (!(data.can.page && data.can.publish)) {
        c.append(el('p', 'mt-3 text-sm text-muted', 'Only someone who can manage the booking page can switch it on.'));
      }
      const errBox = el('div', 'mt-3');
      goBtn.addEventListener('click', () => {
        errBox.replaceChildren();
        ui.setBusy(goBtn, true, 'Switching on…');
        const settings = Object.assign({}, data.page, { is_enabled: true });
        api.patch('/api/booking-page', settings)
          .then(() => api.post('/api/booking-page/publish', {}).catch(() => null))
          .then(() => reload().then(() => render()))
          .catch((error) => {
            ui.setBusy(goBtn, false);
            AlbaBook.toast(error.message, 'danger');
            if (error.fields && error.fields.missing) {
              const box = el('div', 'alert alert-danger');
              const ul = el('ul', 'list-inside list-disc');
              (Array.isArray(error.fields.missing) ? error.fields.missing : [error.fields.missing]).forEach((m) => ul.append(el('li', null, m)));
              box.append(el('span', 'alert-text', 'Still missing:'), ul);
              errBox.append(box);
            }
          });
      });
      c.append(navActions({ label: 'Back', onClick: () => go(4) }, goBtn), errBox);
      return c;
    }

    function renderLiveSuccess() {
      const c = el('section', 'card p-6 text-center');
      const iconWrap = el('span', 'inline-flex h-14 w-14 items-center justify-center rounded-full bg-success-soft text-2xl text-success');
      iconWrap.append(el('i', 'fa-solid fa-check'));
      c.append(iconWrap);
      c.append(el('h3', 'mt-4 text-lg font-semibold text-ink', 'Your booking page is live'));
      c.append(el('p', 'mt-1 text-sm text-muted', 'Share the link on WhatsApp, Instagram or a QR code — customers book without an account.'));

      const link = el('div', 'mx-auto mt-5 flex max-w-md items-center gap-2 rounded-lg border border-line bg-surface px-4 py-3');
      link.append(el('span', 'min-w-0 flex-1 truncate text-left font-mono text-sm text-ink', data.booking_url));
      const copyBtn = el('button', 'btn btn-sm btn-secondary shrink-0', 'Copy');
      copyBtn.type = 'button';
      copyBtn.addEventListener('click', () => {
        (navigator.clipboard ? navigator.clipboard.writeText(data.booking_url) : Promise.reject())
          .then(() => AlbaBook.toast('Link copied.'))
          .catch(() => AlbaBook.toast('Copy failed — select the link and copy it.', 'danger'));
      });
      link.append(copyBtn);
      c.append(link);

      const actions = el('div', 'mt-6 flex flex-wrap justify-center gap-3');
      const open = el('a', 'btn btn-secondary btn-md', 'Open booking page');
      open.href = data.booking_url;
      open.target = '_blank';
      open.rel = 'noopener';
      const share = el('a', 'btn btn-secondary btn-md', 'Share on WhatsApp');
      share.href = 'https://wa.me/?text=' + encodeURIComponent('Book an appointment with ' + data.tenant.name + ': ' + data.booking_url);
      share.target = '_blank';
      share.rel = 'noopener';
      const dash = el('a', 'btn btn-primary btn-md', 'Go to dashboard');
      dash.href = '/dashboard';
      actions.append(open, share, dash);
      c.append(actions);
      c.append(el('p', 'mt-4 text-xs text-muted', 'Everything is editable later — services, hours, team and the page itself.'));
      return c;
    }

    load().then(() => {
      if (!data) return;
      step = firstIncomplete();
      render();
    });
  });
})();
