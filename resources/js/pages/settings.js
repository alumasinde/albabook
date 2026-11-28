/*
 * Settings hub: tabbed panels for workspace profile, service categories,
 * booking-page theme, notification channels and payments. All mutations go
 * through /api/settings with CSRF; the server re-validates everything.
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
  const badge = (text, tone) => el('span', 'badge badge-' + tone, text);
  const money = (minor, currency) =>
    new Intl.NumberFormat('en-KE', { style: 'currency', currency: currency || 'KES', currencyDisplay: 'narrowSymbol' })
      .format((minor || 0) / 100);

  AlbaBook.pages.register('settings', function (root) {
    const canUpdate = root.dataset.canUpdate === '1';
    const featureThemes = root.dataset.featureThemes === '1';
    const featureSenderId = root.dataset.featureSenderId === '1';
    const upgradeNote = (text) => {
      const box = el('div', 'rounded-xl border border-line bg-surface p-5 flex items-center gap-3');
      const icon = el('i', 'fa-solid fa-lock text-muted');
      icon.setAttribute('aria-hidden', 'true');
      const body = el('div');
      body.append(el('p', 'text-sm text-ink', text));
      const link = el('a', 'text-sm font-semibold text-brand hover:underline', 'View plans');
      link.href = '/billing';
      body.append(link);
      box.append(icon, body);
      return box;
    };
    const state = { meta: null, categories: [], notif: null, pay: null };

    /* ------------------------------------------------------------- tabs */
    const tabs = root.querySelectorAll('[data-settings-tab]');
    const panels = root.querySelectorAll('[data-settings-panel]');
    const loaded = {};
    function activate(name) {
      tabs.forEach((t) => t.classList.toggle('is-active', t.dataset.settingsTab === name));
      panels.forEach((p) => { p.hidden = p.dataset.settingsPanel !== name; });
      if (!loaded[name]) {
        loaded[name] = true;
        if (name === 'notifications') loadNotifications();
        if (name === 'payments') loadPayments();
        if (name === 'receipt') loadReceipt();
        if (name === 'booking-rules') loadBookingRules();
      }
    }
    tabs.forEach((t) => t.addEventListener('click', () => activate(t.dataset.settingsTab)));
    // /settings#booking-rules opens that tab directly (setup checklist links).
    const hashTab = (window.location.hash || '').replace('#', '');
    activate(hashTab && root.querySelector('[data-settings-tab="' + hashTab + '"]') ? hashTab : 'profile');

    /* ------------------------------------------------------ form helpers */
    function field(labelText, input, name) {
      const wrap = el('div', 'field');
      const label = el('label', 'field-label', labelText);
      label.setAttribute('for', 'settings-' + name);
      input.id = 'settings-' + name;
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
    function select(options, current, labelFn, valueFn) {
      const sel = el('select', 'input select');
      options.forEach((o) => {
        const opt = el('option', null, labelFn(o));
        opt.value = valueFn(o);
        sel.append(opt);
      });
      if (current !== undefined && current !== null) sel.value = String(current);
      return sel;
    }
    function checkLabel(id, text, checked) {
      const label = el('label', 'flex items-center gap-2 text-sm text-ink');
      const box = input('checkbox');
      box.className = 'checkbox';
      box.id = id;
      box.checked = !!checked;
      label.setAttribute('for', id);
      label.append(box, el('span', null, text));
      return { label, box };
    }
    const submitBtn = (label) => {
      const b = el('button', 'btn btn-primary btn-md', label);
      b.type = 'submit';
      b.disabled = !canUpdate;
      return b;
    };

    /* ------------------------------------------------------------- profile */
    const profileForm = root.querySelector('[data-settings-profile]');
    function renderProfile(data) {
      const p = data.profile || {};
      const prefs = data.preferences || {};
      profileForm.replaceChildren();

      const nameInput = input('text', p.name || '');
      nameInput.required = true;
      const tzInput = select(
        Intl.supportedValuesOf('timeZone').map((tz) => ({ tz })),
        p.timezone || 'Africa/Nairobi',
        (o) => o.tz.replace(/_/g, ' '),
        (o) => o.tz,
      );
      const currencyInput = input('text', p.currency || 'KES');
      currencyInput.maxLength = 3;
      currencyInput.style.textTransform = 'uppercase';
      const localeInput = select(data.locales || [], p.default_locale || 'en', (o) => o.name, (o) => o.code);
      const kraInput = input('text', p.kra_pin || '');
      kraInput.maxLength = 20;
      const taxInput = input('number', Math.round((p.tax_rate_bp || 0) / 100 * 100) / 100);
      taxInput.min = 0;
      taxInput.max = 100;
      taxInput.step = '0.01';
      const vat = checkLabel('settings-vat_registered', 'VAT registered', p.vat_registered);
      const dateFormatInput = select(
        (data.date_formats || []).map((f) => ({ f })),
        prefs.date_format || 'd/m/Y',
        (o) => o.f,
        (o) => o.f,
      );
      const dashViewInput = select(
        (data.dashboard_views || []).map((v) => ({ v, label: v[0].toUpperCase() + v.slice(1) })),
        prefs.default_dashboard_view || 'overview',
        (o) => o.label,
        (o) => o.v,
      );

      const slug = el('p', 'text-xs text-muted', `Slug: ${p.slug || ''} · Public page: book.albabook/${p.slug || ''}`);
      const submit = submitBtn('Save settings');

      const grid = (a, b) => { const g = el('div', 'grid grid-cols-2 gap-4'); g.append(a, b); return g; };
      profileForm.append(
        grid(field('Workspace name', nameInput, 'name'), field('Timezone', tzInput, 'timezone')),
        grid(field('Currency', currencyInput, 'currency'), field('Default language', localeInput, 'default_locale')),
        grid(field('Date format', dateFormatInput, 'date_format'), field('Default dashboard view', dashViewInput, 'default_dashboard_view')),
        grid(field('KRA PIN (optional)', kraInput, 'kra_pin'), field('Tax rate %', taxInput, 'tax_rate_bp')),
        vat.label,
        slug,
      );
      if (canUpdate) profileForm.append(submit);

      profileForm.addEventListener('submit', (event) => {
        event.preventDefault();
        ui.clearFieldErrors(profileForm);
        const payload = {
          name: nameInput.value.trim(),
          timezone: tzInput.value,
          currency: currencyInput.value.trim(),
          default_locale: localeInput.value,
          kra_pin: kraInput.value.trim(),
          vat_registered: vat.box.checked,
          tax_rate_bp: Math.round(parseFloat(taxInput.value || '0') * 100),
          date_format: dateFormatInput.value,
          default_dashboard_view: dashViewInput.value,
        };
        ui.setBusy(submit, true, 'Saving…');
        api.patch('/api/settings', payload)
          .then(() => AlbaBook.toast('Settings saved.'))
          .catch((error) => {
            if (error.fields && Object.keys(error.fields).length) {
              ui.showFieldErrors(profileForm, error.fields);
            } else {
              AlbaBook.toast(error.message, 'danger');
            }
          })
          .finally(() => ui.setBusy(submit, false));
      });
    }

    /* ---------------------------------------------------------- categories */
    const categoriesBody = root.querySelector('[data-categories-body]');
    function renderCategories() {
      const rows = state.categories;
      if (!rows.length) {
        const tr = el('tr');
        const td = el('td');
        td.colSpan = 4;
        td.append(ui.emptyState({
          icon: 'fa-tags',
          title: 'No categories yet',
          message: 'Categories group your services on the booking page.',
          actionLabel: canUpdate ? 'New category' : null,
          onAction: canUpdate ? () => openCategoryForm(null) : null,
        }));
        tr.append(td);
        categoriesBody.replaceChildren(tr);
        return;
      }
      categoriesBody.replaceChildren(...rows.map((cat) => {
        const tr = el('tr');
        const nameTd = el('td');
        nameTd.append(el('div', 'font-medium text-ink', cat.name));
        if (cat.description) nameTd.append(el('div', 'text-xs text-muted', cat.description));
        const statusTd = el('td');
        statusTd.append(badge(cat.is_active ? 'Active' : 'Inactive', cat.is_active ? 'success' : 'neutral'));
        const actions = el('td');
        const edit = el('button', 'btn btn-ghost btn-sm', 'Edit');
        edit.type = 'button';
        edit.addEventListener('click', () => openCategoryForm(cat));
        actions.append(edit);
        if (canUpdate) {
          const del = el('button', 'btn btn-ghost btn-sm text-danger', 'Delete');
          del.type = 'button';
          del.addEventListener('click', () => {
            ui.confirm({
              title: 'Delete category?',
              message: `"${cat.name}" will be permanently removed.`,
              confirmLabel: 'Delete',
              danger: true,
            }).then((ok) => {
              if (!ok) return;
              api.del('/api/settings/categories/' + encodeURIComponent(cat.uuid))
                .then(() => { AlbaBook.toast('Category deleted.'); loadCategories(); })
                .catch((error) => AlbaBook.toast(error.message, 'danger'));
            });
          });
          actions.append(del);
        }
        tr.append(nameTd, el('td', 'text-muted', String(cat.services)), statusTd, actions);
        return tr;
      }));
    }
    function loadCategories() {
      const tr = el('tr');
      const td = el('td');
      td.colSpan = 4;
      td.append(ui.skeletonRows(4));
      tr.append(td);
      categoriesBody.replaceChildren(tr);
      api.get('/api/settings/categories')
        .then((data) => { state.categories = data.categories || []; renderCategories(); })
        .catch((error) => {
          const errTr = el('tr');
          const errTd = el('td');
          errTd.colSpan = 4;
          errTd.append(ui.errorState(error.message, loadCategories));
          errTr.append(errTd);
          categoriesBody.replaceChildren(errTr);
        });
    }
    function openCategoryForm(cat) {
      const isEdit = cat !== null;
      const { wrap, body: drawerBody } = ui.drawerShell(isEdit ? 'Edit category' : 'New category');
      const form = el('form', 'flex flex-col gap-4');
      form.noValidate = true;

      const nameInput = input('text', cat ? cat.name : '');
      nameInput.required = true;
      nameInput.maxLength = 120;
      const descInput = el('textarea', 'input');
      descInput.rows = 2;
      descInput.maxLength = 500;
      descInput.value = cat && cat.description ? cat.description : '';
      const sortInput = input('number', cat ? cat.sort_position : state.categories.length + 1);
      sortInput.min = 0;
      const active = checkLabel('category-is_active', 'Active', cat ? cat.is_active : true);
      const submit = submitBtn(isEdit ? 'Save changes' : 'Create category');

      form.append(
        field('Category name', nameInput, 'cat_name'),
        field('Description', descInput, 'cat_description'),
        field('Sort position', sortInput, 'cat_sort_position'),
        active.label,
        submit,
      );
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        ui.clearFieldErrors(form);
        const payload = {
          name: nameInput.value.trim(),
          description: descInput.value.trim(),
          sort_position: Number(sortInput.value || 0),
          is_active: active.box.checked,
        };
        ui.setBusy(submit, true, 'Saving…');
        (isEdit
          ? api.patch('/api/settings/categories/' + encodeURIComponent(cat.uuid), payload)
          : api.post('/api/settings/categories', payload))
          .then((data) => {
            AlbaBook.toast(isEdit ? 'Category updated.' : 'Category created.');
            state.categories = data.categories || state.categories;
            renderCategories();
            ui.drawer.close();
          })
          .catch((error) => {
            if (error.fields && Object.keys(error.fields).length) {
              const mapped = {};
              Object.entries(error.fields).forEach(([k, v]) => { mapped['cat_' + k] = v; });
              ui.showFieldErrors(form, mapped);
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

    /* --------------------------------------------------------------- theme */
    const themeForm = root.querySelector('[data-settings-theme]');
    function renderTheme(data) {
      const b = data.branding || {};
      themeForm.replaceChildren();
      if (!featureThemes) {
        themeForm.append(upgradeNote('Custom themes are not included in your plan.'));
        return;
      }

      const colors = [
        ['primary_color', 'Primary colour', 'Buttons, links and highlights'],
        ['secondary_color', 'Secondary colour', 'Headings and footer'],
        ['accent_color', 'Accent colour', 'Badges and success highlights'],
        ['background_color', 'Background', 'Booking page background'],
        ['text_color', 'Text colour', 'Body text on the booking page'],
      ];
      const pickers = {};
      const grid = el('div', 'grid gap-4 sm:grid-cols-2');
      colors.forEach(([key, label, hint]) => {
        const hex = (b[key] || '#000000').toUpperCase();
        const wrap = el('div', 'field');
        const row = el('div', 'flex items-center gap-3');
        const picker = input('color', hex);
        picker.className = 'h-10 w-14 cursor-pointer rounded-lg border border-line bg-white p-1';
        const text = input('text', hex);
        text.maxLength = 7;
        text.style.textTransform = 'uppercase';
        text.className = 'input font-mono';
        picker.addEventListener('input', () => { text.value = picker.value.toUpperCase(); });
        text.addEventListener('input', () => {
          if (/^#[0-9a-fA-F]{6}$/.test(text.value)) picker.value = text.value;
        });
        row.append(picker, text);
        const labelEl = el('label', 'field-label', label);
        labelEl.setAttribute('for', 'theme-' + key);
        text.id = 'theme-' + key;
        text.name = key;
        const err = el('p', 'field-error');
        err.id = 'field-' + key + '-error';
        const hintEl = el('p', 'text-xs text-muted mt-1', hint);
        wrap.append(labelEl, row, err, hintEl);
        grid.append(wrap);
        pickers[key] = { text, picker };
      });

      const appearanceSelect = select(
        [{ v: 'light', label: 'Light' }, { v: 'dark', label: 'Dark' }],
        b.appearance || 'light',
        (o) => o.label,
        (o) => o.v,
      );

      /* Live preview card. */
      const preview = el('div', 'overflow-hidden rounded-xl border border-line');
      const render = () => {
        const c = (k) => pickers[k].text.value.toUpperCase();
        preview.innerHTML = '';
        preview.style.background = c('background_color');
        const inner = el('div', 'p-5');
        inner.style.color = c('text_color');
        const title = el('div', 'text-lg font-semibold', 'Your booking page');
        title.style.color = c('secondary_color');
        const chip = el('span', 'mt-2 inline-block rounded-full px-3 py-1 text-xs font-semibold', 'Verified studio');
        chip.style.background = c('accent_color');
        chip.style.color = '#ffffff';
        const btn = el('button', 'mt-3 block rounded-lg px-4 py-2 text-sm font-semibold', 'Book now');
        btn.type = 'button';
        btn.style.background = c('primary_color');
        btn.style.color = '#ffffff';
        inner.append(title, el('p', 'mt-1 text-sm', 'This is how colours look on your public page.'), chip, btn);
        preview.append(inner);
      };
      Object.values(pickers).forEach((p) => {
        p.text.addEventListener('input', render);
        p.picker.addEventListener('input', () => { p.text.value = p.picker.value.toUpperCase(); render(); });
      });
      render();

      const submit = submitBtn('Save theme');
      const note = el('p', 'text-xs text-muted', 'Theme applies to your public booking page. Dashboard colours stay consistent for all businesses.');

      themeForm.append(grid, field('Appearance', appearanceSelect, 'appearance'), preview, note);
      if (canUpdate) themeForm.append(submit);

      themeForm.addEventListener('submit', (event) => {
        event.preventDefault();
        ui.clearFieldErrors(themeForm);
        const payload = { appearance: appearanceSelect.value };
        Object.entries(pickers).forEach(([k, p]) => { payload[k] = p.text.value.trim().toUpperCase(); });
        ui.setBusy(submit, true, 'Saving…');
        api.patch('/api/settings/branding', payload)
          .then(() => AlbaBook.toast('Theme saved. It now applies to your booking page.'))
          .catch((error) => {
            if (error.fields && Object.keys(error.fields).length) {
              ui.showFieldErrors(themeForm, error.fields);
            } else {
              AlbaBook.toast(error.message, 'danger');
            }
          })
          .finally(() => ui.setBusy(submit, false));
      });
    }

    /* -------------------------------------------------------- booking rules */
    const rulesForm = root.querySelector('[data-settings-booking-rules]');
    function loadBookingRules() {
      if (!rulesForm) return;
      rulesForm.replaceChildren(ui.spinner('Loading booking rules…'));
      api.get('/api/settings/booking-rules')
        .then((data) => renderBookingRules(data))
        .catch((error) => rulesForm.replaceChildren(ui.errorState(error.message, loadBookingRules)));
    }

    function renderBookingRules(data) {
      const r = data.rules;
      const editable = !!data.can_update;
      const number = (value, min, max, placeholder) => {
        const i = input('number', value === null || value === undefined ? '' : value);
        i.min = String(min);
        i.max = String(max);
        i.step = '1';
        i.inputMode = 'numeric';
        if (placeholder) i.placeholder = placeholder;
        i.disabled = !editable;
        return i;
      };
      const hint = (wrap, text) => {
        wrap.insertBefore(el('p', 'mt-1 text-xs text-muted', text), wrap.querySelector('.field-error'));
        return wrap;
      };

      // Confirmation mode as two radio cards: the choice that matters most.
      const modes = [
        ['auto', 'Confirm automatically', 'Online bookings are confirmed instantly. Best for most businesses.'],
        ['manual', 'I approve each booking', 'Online bookings arrive as requests. You confirm or decline them from the dashboard.'],
      ];
      const modeSet = el('fieldset', 'grid gap-3 sm:grid-cols-2');
      modeSet.append(el('legend', 'mb-2 text-sm font-semibold text-ink', 'Confirming online bookings'));
      const radios = {};
      modes.forEach(([value, title, body]) => {
        const label = el('label', 'rule-option');
        const radio = el('input', 'mt-1');
        radio.type = 'radio';
        radio.name = 'confirmation_mode';
        radio.value = value;
        radio.checked = r.confirmation_mode === value;
        radio.disabled = !editable;
        radios[value] = radio;
        const text = el('span');
        text.append(el('span', 'block text-sm font-semibold text-ink', title), el('span', 'mt-1 block text-xs text-muted', body));
        label.append(radio, text);
        modeSet.append(label);
      });
      const modeErr = el('p', 'field-error');
      modeErr.id = 'field-confirmation_mode-error';

      // Phone verification by SMS code (stops bookings under someone else's number).
      const verify = select([
        { v: 'none', l: 'No — book straight away' },
        { v: 'otp_phone', l: 'Yes — text a 6-digit code before booking' },
      ], r.customer_verification, (o) => o.l, (o) => o.v);
      verify.disabled = !editable || (!data.sms_available && r.customer_verification !== 'otp_phone');
      const verifyWrap = hint(field('Confirm the customer\u2019s phone', verify, 'customer_verification'),
        data.sms_available
          ? 'Customers enter a code we text them. Stops fake bookings and bookings under someone else\u2019s number. Each code uses one SMS from your allowance.'
          : 'Needs SMS: connect an SMS account under Notifications, or upgrade to a plan that includes SMS.');
      verifyWrap.classList.add('mt-4', 'max-w-sm');

      const expiry = number(r.pending_expiry_minutes, 15, 10080);
      const expiryWrap = hint(field('Unanswered requests expire after (minutes)', expiry, 'pending_expiry_minutes'),
        'The slot is released and the customer told. Requests also expire once the visit time arrives. 720 = 12 hours.');
      expiryWrap.classList.add('mt-4', 'max-w-sm');
      const syncExpiry = () => { expiryWrap.hidden = !radios.manual.checked; };
      Object.values(radios).forEach((radio) => radio.addEventListener('change', syncExpiry));
      syncExpiry();

      const notice = number(r.min_notice_minutes, 0, 20160);
      const advance = number(r.max_advance_days, 1, 730, 'No limit');
      const interval = select([5, 10, 15, 20, 30, 45, 60, 90, 120].map((v) => ({ v })), r.slot_interval_minutes, (o) => o.v + ' minutes', (o) => o.v);
      interval.disabled = !editable;
      const timing = el('div', 'mt-6 grid gap-4 border-t border-line pt-5 sm:grid-cols-3');
      timing.append(
        hint(field('Minimum notice (minutes)', notice, 'min_notice_minutes'), 'e.g. 60 stops bookings less than an hour away.'),
        hint(field('Book up to (days ahead)', advance, 'max_advance_days'), 'Leave empty for no limit.'),
        hint(field('Time slots every', interval, 'slot_interval_minutes'), 'Start times offered on your booking page.'),
      );

      const cancel = number(r.cancellation_cutoff_minutes, 0, 20160, 'Any time');
      const resched = number(r.reschedule_cutoff_minutes, 0, 20160, 'Any time');
      const maxRe = number(r.max_reschedules, 0, 20, 'Unlimited');
      const changes = el('div', 'mt-6 grid gap-4 border-t border-line pt-5 sm:grid-cols-3');
      changes.append(
        hint(field('Customers can cancel until (min before)', cancel, 'cancellation_cutoff_minutes'), 'e.g. 1440 = 24 hours. Empty = any time.'),
        hint(field('Customers can reschedule until (min before)', resched, 'reschedule_cutoff_minutes'), 'Empty = any time.'),
        hint(field('Reschedules allowed per booking', maxRe, 'max_reschedules'), 'Empty = unlimited.'),
      );

      const note = el('p', 'mt-4 text-xs text-muted', 'Bookings your team makes from the dashboard or at the front desk are always confirmed straight away.');
      const actions = el('div', 'mt-6 flex flex-wrap gap-3');
      const submit = submitBtn('Save booking rules');
      submit.disabled = !editable;
      actions.append(submit);
      rulesForm.replaceChildren(modeSet, modeErr, expiryWrap, verifyWrap, timing, changes, note, actions);

      rulesForm.onsubmit = (event) => {
        event.preventDefault();
        ui.clearFieldErrors(rulesForm);
        const val = (i) => (i.value.trim() === '' ? null : i.value.trim());
        const payload = {
          confirmation_mode: radios.manual.checked ? 'manual' : 'auto',
          customer_verification: verify.value,
          pending_expiry_minutes: val(expiry) ?? String(r.pending_expiry_minutes),
          min_notice_minutes: val(notice),
          max_advance_days: val(advance),
          slot_interval_minutes: interval.value,
          cancellation_cutoff_minutes: val(cancel),
          reschedule_cutoff_minutes: val(resched),
          max_reschedules: val(maxRe),
        };
        ui.setBusy(submit, true, 'Saving…');
        api.patch('/api/settings/booking-rules', payload)
          .then((saved) => {
            AlbaBook.toast('Booking rules saved.');
            renderBookingRules(saved);
          })
          .catch((error) => {
            if (error.fields) ui.showFieldErrors(rulesForm, error.fields);
            AlbaBook.toast(error.message, 'danger');
          })
          .finally(() => ui.setBusy(submit, false));
      };
    }

    /* -------------------------------------------------------------- receipt */
    const receiptForm = root.querySelector('[data-settings-receipt]');

    function loadReceipt() {
      if (!receiptForm) return;
      receiptForm.replaceChildren(ui.spinner('Loading receipt design…'));
      api.get('/api/settings/payments')
        .then((data) => renderReceipt(data.receipt))
        .catch((error) => receiptForm.replaceChildren(ui.errorState(error.message, loadReceipt)));
    }

    function renderReceipt(receipt) {
      const s = receipt.settings;
      const entries = (obj) => Object.entries(obj).map(([value, label]) => ({ value, label }));

      const logo = select(entries(receipt.logos), s.logo, (o) => o.label, (o) => o.value);
      const paper = select(entries(receipt.paper_sizes), s.paper, (o) => o.label, (o) => o.value);
      const tagline = input('text', s.tagline);
      tagline.maxLength = 60;
      tagline.placeholder = 'e.g. Look good • Feel great (leave empty to hide)';
      const heading = input('text', s.heading);
      heading.maxLength = 30;

      const top = el('div', 'grid gap-4 sm:grid-cols-2');
      top.append(
        field('Logo', logo, 'logo'),
        field('Paper width', paper, 'paper'),
        field('Tagline', tagline, 'tagline'),
        field('Receipt heading', heading, 'heading'),
      );

      const toggles = [
        ['show_address', 'Branch address'],
        ['show_phone', 'Branch phone'],
        ['show_email', 'Branch email'],
        ['show_kra_pin', 'KRA PIN'],
        ['show_customer', 'Customer name'],
        ['show_staff', 'Staff member'],
        ['show_levies', 'VAT and service charge lines'],
        ['show_qr', 'Verification QR code'],
        ['mask_references', 'Mask payment references (e.g. RYH3••••D4)'],
      ];
      if (receipt.can_hide_powered_by) toggles.push(['show_powered_by', 'Show “Powered by AlbaBook”']);

      const boxes = {};
      const grid = el('div', 'grid gap-3 sm:grid-cols-2');
      toggles.forEach(([key, text]) => {
        const { label, box } = checkLabel('receipt-' + key, text, s[key]);
        box.disabled = !canUpdate;
        boxes[key] = box;
        grid.append(label);
      });
      const toggleSection = el('fieldset', 'mt-6 border-t border-line pt-5');
      const legend = el('legend', 'text-sm font-semibold text-ink', 'Print on the receipt');
      toggleSection.append(legend, el('div', 'mt-3'), grid);

      [logo, paper, tagline, heading].forEach((i) => { i.disabled = !canUpdate; });

      const note = el('p', 'mt-4 text-xs text-muted', 'The thank-you message at the bottom uses the receipt note from Payments → Tax & checkout.');
      const actions = el('div', 'mt-6 flex flex-wrap gap-3');
      const submit = submitBtn('Save receipt design');
      actions.append(submit);

      receiptForm.replaceChildren(top, toggleSection, note, actions);
      receiptForm.onsubmit = (event) => {
        event.preventDefault();
        ui.clearFieldErrors(receiptForm);
        const payload = { logo: logo.value, paper: paper.value, tagline: tagline.value.trim(), heading: heading.value.trim() };
        Object.entries(boxes).forEach(([key, box]) => { payload[key] = box.checked; });
        if (!receipt.can_hide_powered_by) payload.show_powered_by = true;
        ui.setBusy(submit, true, 'Saving…');
        api.patch('/api/settings/receipt', payload)
          .then((saved) => {
            AlbaBook.toast('Receipt design saved. Open the preview to see it.');
            renderReceipt(saved);
          })
          .catch((error) => {
            if (error.fields) ui.showFieldErrors(receiptForm, error.fields);
            AlbaBook.toast(error.message, 'danger');
          })
          .finally(() => ui.setBusy(submit, false));
      };
    }

    /* ------------------------------------------------------------ whatsapp */
    const waBox = root.querySelector('[data-whatsapp]');
    const WA_EVENTS = {
      booking_received: 'Booking received', booking_confirmed: 'Booking confirmed', booking_rejected: 'Booking not accepted',
      appointment_reminder_24h: '24-hour reminder', appointment_reminder_2h: '2-hour reminder',
      appointment_rescheduled: 'Rescheduled', appointment_cancelled: 'Cancelled', appointment_expired: 'Expired',
      appointment_completed: 'Visit completed', payment_update: 'Payment update', manage_link: 'Manage-booking link',
    };

    function renderWhatsApp(wa) {
      if (!waBox || !wa) return;
      const sender = select(
        [
          { v: 'platform', label: 'AlbaBook’s verified number (recommended — no setup)' },
          { v: 'business', label: wa.can_use_own_number ? 'Our own WhatsApp Business number' : 'Our own number (Growth / Pro plans)' },
        ],
        wa.sender_requested, (o) => o.label, (o) => o.v,
      );
      if (!wa.can_use_own_number) sender.querySelector('option[value="business"]').disabled = true;
      const mode = select(
        [
          { v: 'off', label: 'Off — use SMS for phone numbers' },
          { v: 'instead_of_sms', label: 'Use WhatsApp instead of SMS' },
          { v: 'alongside_sms', label: 'Send both WhatsApp and SMS' },
        ],
        wa.customer_mode, (o) => o.label, (o) => o.v,
      );
      [sender, mode].forEach((i) => { i.disabled = !canUpdate; });

      const notes = el('div', 'mt-3 space-y-1 text-xs text-muted');
      if (wa.sender === 'platform') {
        notes.append(el('p', null, wa.platform_available
          ? (wa.plan_includes_platform ? 'Messages go from AlbaBook’s number and always name your business. Uses your plan’s WhatsApp allowance.' : 'Your plan does not include WhatsApp messages from the AlbaBook number.')
          : 'The AlbaBook WhatsApp number is not configured yet on this platform.'));
      } else {
        notes.append(el('p', null, wa.own_account_connected
          ? 'Messages go from your connected WhatsApp Business account, using the templates you map below.'
          : 'Connect your WhatsApp Business account under Channels above, then map your approved templates below.'));
      }

      const form = el('form', 'grid gap-4 sm:grid-cols-2');
      form.noValidate = true;
      const save = submitBtn('Save WhatsApp settings');
      form.append(field('Send from', sender, 'sender'), field('Use for customers', mode, 'customer_mode'), el('div', 'sm:col-span-2', null));
      form.lastChild.append(notes, save);
      form.onsubmit = (event) => {
        event.preventDefault();
        ui.clearFieldErrors(form);
        ui.setBusy(save, true, 'Saving…');
        api.patch('/api/settings/whatsapp', { sender: sender.value, customer_mode: mode.value })
          .then((next) => { AlbaBook.toast('WhatsApp settings saved.'); renderWhatsApp(next); })
          .catch((error) => { if (error.fields) ui.showFieldErrors(form, error.fields); AlbaBook.toast(error.message, 'danger'); })
          .finally(() => ui.setBusy(save, false));
      };

      const parts = [form];
      if (wa.sender_requested === 'business' && wa.can_use_own_number) parts.push(renderWaTemplates(wa));
      waBox.replaceChildren(...parts);
    }

    /** Own-number mode: map each event to the business's approved template. */
    function renderWaTemplates(wa) {
      const wrap = el('form', 'mt-6 border-t border-line pt-5');
      wrap.noValidate = true;
      wrap.append(
        el('h4', 'text-sm font-semibold text-ink', 'Your approved templates'),
        el('p', 'mt-1 text-xs text-muted', 'Enter each template name exactly as approved in WhatsApp Manager (or a Twilio Content SID “HX…”), and the variables in the order of {{1}}, {{2}}…. Leave a name empty to skip that message. Available variables: ' + wa.param_keys.join(', ') + '.'),
      );
      const own = Object.fromEntries((wa.templates || []).map((t) => [t.event_key, t]));
      const platform = Object.fromEntries((wa.platform_templates || []).map((t) => [t.event_key, t]));
      const rows = [];
      const table = el('div', 'mt-4 space-y-3');
      Object.entries(WA_EVENTS).forEach(([event, label]) => {
        const current = own[event] || {};
        const name = input('text', current.name || '');
        name.placeholder = 'template_name';
        name.maxLength = 512;
        const lang = input('text', current.language || 'en');
        lang.maxLength = 6;
        lang.className += ' w-20';
        const params = input('text', (current.params || (platform[event] && platform[event].params) || []).join(', '));
        params.placeholder = 'business_name, reference, start_at';
        [name, lang, params].forEach((i) => { i.disabled = !canUpdate; });
        const row = el('div', 'grid gap-2 rounded-lg border border-line p-3 sm:grid-cols-[1fr_1.4fr_auto_1.6fr] sm:items-center');
        row.append(el('span', 'text-sm font-medium text-ink', label), name, lang, params);
        if (platform[event]) row.title = 'Suggested wording: ' + platform[event].body;
        table.append(row);
        rows.push({ event, name, lang, params });
      });
      const save = submitBtn('Save templates');
      wrap.append(table, el('div', 'mt-4'));
      wrap.lastChild.append(save);
      wrap.onsubmit = (event) => {
        event.preventDefault();
        ui.setBusy(save, true, 'Saving…');
        api.patch('/api/settings/whatsapp/templates', {
          templates: rows.map((r) => ({
            event_key: r.event,
            name: r.name.value.trim(),
            language: r.lang.value.trim() || 'en',
            params: r.params.value.split(',').map((s) => s.trim()).filter(Boolean),
          })),
        })
          .then((next) => { AlbaBook.toast('Templates saved.'); renderWhatsApp(next); })
          .catch((error) => AlbaBook.toast(error.fields ? Object.values(error.fields)[0] : error.message, 'danger'))
          .finally(() => ui.setBusy(save, false));
      };
      return wrap;
    }

    /* --------------------------------------------------------------- eTIMS */
    const etimsBox = root.querySelector('[data-etims]');

    function loadEtims() {
      if (!etimsBox) return;
      etimsBox.replaceChildren(ui.spinner('Loading eTIMS…'));
      api.get('/api/settings/etims')
        .then(renderEtims)
        .catch((error) => etimsBox.replaceChildren(ui.errorState(error.message, loadEtims)));
    }

    function renderEtims(e) {
      const env = select([{ v: 'sandbox', label: 'Sandbox (KRA test)' }, { v: 'production', label: 'Production (live)' }], e.environment, (o) => o.label, (o) => o.v);
      const bhf = input('text', e.bhf_id || '00');
      bhf.maxLength = 2;
      const serial = input('text', e.device_serial || '');
      serial.placeholder = 'Device serial issued by KRA';
      const cls = input('text', e.item_class_code || '');
      cls.inputMode = 'numeric';
      cls.placeholder = 'KRA item classification code';
      const pkg = input('text', e.pkg_unit || 'NT');
      pkg.maxLength = 3;
      const qty = input('text', e.qty_unit || 'U');
      qty.maxLength = 3;
      const zero = select([{ v: 'A', label: 'A — Exempt' }, { v: 'C', label: 'C — Zero-rated' }], e.zero_rate_tax_type, (o) => o.label, (o) => o.v);
      const on = checkLabel('etims-enabled', 'Issue eTIMS invoices at checkout', e.enabled);
      [env, bhf, serial, cls, pkg, qty, zero, on.box].forEach((i) => { i.disabled = !canUpdate; });

      const status = el('div', 'mb-4 flex flex-wrap gap-2');
      status.append(
        badge(e.tin ? 'KRA PIN ' + e.tin : 'No KRA PIN — add it under Profile', e.tin ? 'neutral' : 'warning'),
        badge(e.initialized ? 'Device initialized' : 'Device not initialized', e.initialized ? 'success' : 'warning'),
        badge(e.enabled ? 'eTIMS on' : 'eTIMS off', e.enabled ? 'success' : 'neutral'),
      );
      const counts = e.invoice_counts || {};
      if (counts.pending || counts.failed) {
        status.append(badge((counts.pending || 0) + ' pending · ' + (counts.failed || 0) + ' failed', counts.failed ? 'danger' : 'warning'));
      }

      const form = el('form', 'grid gap-4 sm:grid-cols-2');
      form.noValidate = true;
      form.append(
        field('Environment', env, 'environment'), field('KRA branch id', bhf, 'bhf_id'),
        field('Device serial', serial, 'device_serial'), field('Item classification code', cls, 'item_class_code'),
        field('Packaging unit code', pkg, 'pkg_unit'), field('Quantity unit code', qty, 'qty_unit'),
        field('0% VAT lines are', zero, 'zero_rate_tax_type'),
      );
      const onWrap = el('div', 'sm:col-span-2');
      onWrap.append(on.label);
      const err = el('p', 'field-error');
      err.id = 'field-enabled-error';
      onWrap.append(err);
      const note = el('p', 'sm:col-span-2 text-xs text-muted', 'Order: save your details → Initialize with KRA → switch eTIMS on. The service charge must be 0% and VAT 16%, 8% or 0% while eTIMS is on. Invoices are sent in the background and retried automatically.');
      const actions = el('div', 'sm:col-span-2 flex flex-wrap gap-3');
      const save = submitBtn('Save eTIMS settings');
      const init = el('button', 'btn btn-secondary btn-md', 'Initialize with KRA');
      init.type = 'button';
      init.disabled = !canUpdate;
      actions.append(save, init);
      form.append(onWrap, note, actions);

      const payload = () => ({
        environment: env.value, bhf_id: bhf.value.trim(), device_serial: serial.value.trim(),
        item_class_code: cls.value.trim(), pkg_unit: pkg.value.trim(), qty_unit: qty.value.trim(),
        zero_rate_tax_type: zero.value, enabled: on.box.checked,
      });
      form.onsubmit = (event) => {
        event.preventDefault();
        ui.clearFieldErrors(form);
        ui.setBusy(save, true, 'Saving…');
        api.patch('/api/settings/etims', payload())
          .then((next) => { AlbaBook.toast('eTIMS settings saved.'); renderEtims(next); })
          .catch((error) => { if (error.fields) ui.showFieldErrors(form, error.fields); AlbaBook.toast(error.message, 'danger'); })
          .finally(() => ui.setBusy(save, false));
      };
      init.addEventListener('click', () => {
        ui.setBusy(init, true, 'Contacting KRA…');
        api.patch('/api/settings/etims', Object.assign(payload(), { enabled: false }))
          .then(() => api.post('/api/settings/etims/initialize', {}))
          .then((next) => { AlbaBook.toast('Device initialized with KRA. You can now switch eTIMS on.'); renderEtims(next); })
          .catch((error) => AlbaBook.toast(error.message, 'danger'))
          .finally(() => ui.setBusy(init, false));
      });

      etimsBox.replaceChildren(status, form);
    }

    /* -------------------------------------------------------- notifications */
    const notifPrefs = root.querySelector('[data-notif-prefs]');
    const notifAccounts = root.querySelector('[data-channel-accounts]');

    /* Business alerts: optional SMS phone for new bookings / cancellations. */
    const alertsForm = root.querySelector('[data-business-alerts]');
    function renderBusinessAlerts(alerts) {
      if (!alertsForm) return;
      const phone = input('tel', alerts.phone || '');
      phone.placeholder = 'e.g. 0712 345 678 (optional)';
      phone.autocomplete = 'tel';
      phone.disabled = !canUpdate;
      const wrap = field('Alert phone (SMS)', phone, 'phone');
      wrap.classList.add('max-w-sm');
      const actions = el('div', 'mt-4 flex flex-wrap gap-3');
      const submit = submitBtn('Save alert phone');
      actions.append(submit);
      alertsForm.replaceChildren(wrap, actions);
      alertsForm.onsubmit = (event) => {
        event.preventDefault();
        ui.clearFieldErrors(alertsForm);
        ui.setBusy(submit, true, 'Saving…');
        api.patch('/api/settings/business-alerts', { phone: phone.value.trim() })
          .then((saved) => {
            AlbaBook.toast(saved.phone ? 'Alerts will also be sent by SMS to ' + saved.phone + '.' : 'SMS alerts turned off.');
            renderBusinessAlerts(saved);
          })
          .catch((error) => {
            if (error.fields) ui.showFieldErrors(alertsForm, error.fields);
            AlbaBook.toast(error.message, 'danger');
          })
          .finally(() => ui.setBusy(submit, false));
      };
    }

    function loadNotifications() {
      notifPrefs.replaceChildren(ui.spinner('Loading preferences…'));
      notifAccounts.replaceChildren(ui.spinner('Loading accounts…'));
      api.get('/api/settings/notifications')
        .then((data) => { state.notif = data; renderNotifAccounts(); renderNotifPrefs(); renderWhatsApp(data.whatsapp); renderBusinessAlerts(data.business_alerts || {}); })
        .catch((error) => {
          notifPrefs.replaceChildren(ui.errorState(error.message, loadNotifications));
          notifAccounts.replaceChildren();
        });
    }

    function renderNotifAccounts() {
      const accounts = state.notif.accounts || [];
      const fallback = state.notif.platform_fallback || {};
      const sharedNote = (fallback.sms || fallback.whatsapp)
        ? el('p', 'text-xs text-muted mb-3', 'Nothing connected — messages send through AlbaBook\'s shared account. Connect your own provider to use your own sender ID and billing.')
        : null;
      if (!accounts.length) {
        const empty = el('div');
        if (sharedNote) empty.append(sharedNote);
        empty.append(ui.emptyState({
          icon: 'fa-tower-broadcast',
          title: 'No channels connected',
          message: 'Connect an SMS or WhatsApp provider to reach customers beyond email.',
          actionLabel: canUpdate ? 'Connect channel' : null,
          onAction: canUpdate ? () => openAccountForm() : null,
        }));
        notifAccounts.replaceChildren(empty);
        return;
      }
      notifAccounts.replaceChildren(...accounts.map((a) => {
        const row = el('div', 'flex items-center gap-3 rounded-xl border border-line p-4');
        const main = el('div', 'min-w-0 flex-1');
        main.append(
          el('div', 'font-medium text-ink', a.provider_name),
          el('div', 'text-xs text-muted', `${a.channel.toUpperCase()}${a.sender_id ? ' · ' + a.sender_id : ''}${a.has_credentials ? ' · credentials saved' : ''}`),
        );
        const status = badge(a.status === 'active' ? 'Active' : 'Disabled', a.status === 'active' ? 'success' : 'neutral');
        const actions = el('div', 'flex items-center gap-2');
        if (canUpdate) {
          const toggle = el('button', 'btn btn-ghost btn-sm', a.status === 'active' ? 'Disable' : 'Enable');
          toggle.type = 'button';
          toggle.addEventListener('click', () => {
            api.patch('/api/settings/notifications/accounts/' + a.id, { status: a.status === 'active' ? 'disabled' : 'active' })
              .then(() => { a.status = a.status === 'active' ? 'disabled' : 'active'; renderNotifAccounts(); AlbaBook.toast('Channel updated.'); })
              .catch((error) => AlbaBook.toast(error.message, 'danger'));
          });
          actions.append(toggle);
        }
        row.append(main, status, actions);
        return row;
      }));
    }

    function renderNotifPrefs() {
      const { events = [], channels = [], prefs = {} } = state.notif;
      const usable = channels.filter((c) => ['email', 'sms', 'whatsapp'].includes(c.code));
      const fallback = state.notif.platform_fallback || {};
      const hasSms = (state.notif.accounts || []).some((a) => a.channel === 'sms' && a.status === 'active') || fallback.sms;
      const hasWa = (state.notif.accounts || []).some((a) => a.channel === 'whatsapp' && a.status === 'active') || fallback.whatsapp;
      const channelReady = (code) => code === 'email' || (code === 'sms' && hasSms) || (code === 'whatsapp' && hasWa);

      const table = el('table', 'data-table');
      const thead = el('thead');
      const headRow = el('tr');
      headRow.append(el('th', null, 'Event'));
      usable.forEach((c) => headRow.append(el('th', null, c.name)));
      thead.append(headRow);
      const tbody = el('tbody');

      events.forEach((e) => {
        const tr = el('tr');
        const nameTd = el('td');
        nameTd.append(el('div', 'font-medium text-ink', e.name), el('div', 'text-xs text-muted', e.audience === 'business' ? 'To your team' : 'To customers'));
        tr.append(nameTd);
        usable.forEach((c) => {
          const td = el('td');
          const key = c.code + '|' + e.code;
          const box = input('checkbox');
          box.className = 'checkbox';
          box.checked = prefs[key] !== false;
          box.disabled = !canUpdate;
          if (!channelReady(c.code)) {
            box.disabled = true;
            box.title = 'Connect a ' + c.name + ' account first';
          }
          box.addEventListener('change', () => {
            box.disabled = true;
            api.patch('/api/settings/notifications/preferences', { channel: c.code, event_key: e.code, is_enabled: box.checked })
              .then(() => { prefs[key] = box.checked; })
              .catch((error) => { box.checked = !box.checked; AlbaBook.toast(error.message, 'danger'); })
              .finally(() => { box.disabled = !canUpdate && !channelReady(c.code); });
          });
          td.append(box);
          tr.append(td);
        });
        tbody.append(tr);
      });
      table.append(thead, tbody);
      const wrap = el('div', 'overflow-x-auto rounded-xl border border-line');
      wrap.append(table);
      notifPrefs.replaceChildren(wrap);
      const hint = el('p', 'mt-3 text-xs text-muted');
      hint.textContent = (fallback.sms || fallback.whatsapp)
        ? 'SMS and WhatsApp send through AlbaBook\'s shared account unless you connect your own provider.'
        : 'SMS and WhatsApp toggles unlock once a provider account is active.';
      notifPrefs.append(hint);
    }

    function openAccountForm() {
      const { wrap, body: drawerBody } = ui.drawerShell('Connect channel');
      const form = el('form', 'flex flex-col gap-4');
      form.noValidate = true;

      const providerSelect = select(
        state.notif.providers || [],
        null,
        (o) => `${o.name} (${o.channel.toUpperCase()})`,
        (o) => o.id,
      );
      const senderInput = input('text', '');
      senderInput.maxLength = 64;
      senderInput.placeholder = featureSenderId ? 'e.g. ALBABOOK' : 'Not included in your plan';
      senderInput.disabled = !featureSenderId;
      const credInput = el('textarea', 'input');
      credInput.rows = 3;
      credInput.placeholder = '{"api_key":"…","username":"…"}';

      const submit = submitBtn('Connect');
      form.append(
        field('Provider', providerSelect, 'provider_id'),
        field(featureSenderId ? 'Sender ID (optional)' : 'Sender ID — premium feature', senderInput, 'sender_id'),
        field('Credentials', credInput, 'credentials'),
        submit,
      );
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        ui.clearFieldErrors(form);
        ui.setBusy(submit, true, 'Saving…');
        api.post('/api/settings/notifications/accounts', {
          provider_id: Number(providerSelect.value || 0),
          sender_id: senderInput.value.trim(),
          credentials: credInput.value.trim(),
        })
          .then(() => {
            AlbaBook.toast('Channel connected.');
            ui.drawer.close();
            loadNotifications();
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

    /* ------------------------------------------------------------ payments */
    const payAccounts = root.querySelector('[data-payment-accounts]');
    const payLedger = root.querySelector('[data-payment-ledger]');
    const policyCard = root.querySelector('[data-payment-policy]');
    const policyToggle = root.querySelector('[data-checkout-full-pay]');
    const taxRateInput = root.querySelector('[data-tax-rate]');
    const serviceChargeInput = root.querySelector('[data-service-charge]');
    const receiptNoteInput = root.querySelector('[data-receipt-note]');
    const visitEarlyInput = root.querySelector('[data-visit-early]');
    const visitLateInput = root.querySelector('[data-visit-late]');
    const noShowGraceInput = root.querySelector('[data-no-show-grace]');
    const visitWindowModeInput = root.querySelector('[data-visit-window-mode]');
    const requireCheckInInput = root.querySelector('[data-require-check-in]');
    const policySave = root.querySelector('[data-policy-save]');

    function loadPayments() {
      loadEtims();
      payAccounts.replaceChildren(ui.spinner('Loading accounts…'));
      api.get('/api/settings/payments')
        .then((data) => {
          state.pay = data;
          policyCard.hidden = false;
          if (policyToggle) {
            policyToggle.checked = !!data.checkout_full_payment_required;
          }
          if (taxRateInput) taxRateInput.value = (Number(data.tax_rate_bp || 0) / 100).toFixed(2);
          if (serviceChargeInput) serviceChargeInput.value = (Number(data.service_charge_bp || 0) / 100).toFixed(2);
          if (receiptNoteInput) receiptNoteInput.value = data.receipt_footer_note || '';
          if (visitEarlyInput) visitEarlyInput.value = Number(data.visit_early_minutes ?? 60);
          if (visitLateInput) visitLateInput.value = Number(data.visit_late_minutes ?? 240);
          if (noShowGraceInput) noShowGraceInput.value = Number(data.no_show_grace_minutes ?? 15);
          if (visitWindowModeInput) visitWindowModeInput.value = data.visit_window_mode === 'warn' ? 'warn' : 'strict';
          if (requireCheckInInput) requireCheckInInput.checked = data.require_check_in !== false;
          renderPayAccounts();
          renderLedger();
        })
        .catch((error) => payAccounts.replaceChildren(ui.errorState(error.message, loadPayments)));
    }

    if (policySave) {
      policySave.addEventListener('click', () => {
        api.patch('/api/settings/payments/policy', {
          checkout_full_payment_required: policyToggle && policyToggle.checked ? 1 : 0,
          tax_rate_bp: taxRateInput ? Math.round(Number(taxRateInput.value || 0) * 100) : 0,
          service_charge_bp: serviceChargeInput ? Math.round(Number(serviceChargeInput.value || 0) * 100) : 0,
          receipt_footer_note: receiptNoteInput ? receiptNoteInput.value.trim() : '',
          visit_early_minutes: visitEarlyInput ? Math.round(Number(visitEarlyInput.value || 60)) : undefined,
          visit_late_minutes: visitLateInput ? Math.round(Number(visitLateInput.value || 240)) : undefined,
          no_show_grace_minutes: noShowGraceInput ? Math.round(Number(noShowGraceInput.value || 15)) : undefined,
          visit_window_mode: visitWindowModeInput ? visitWindowModeInput.value : undefined,
          require_check_in: requireCheckInInput ? (requireCheckInInput.checked ? 1 : 0) : undefined,
        })
          .then(() => AlbaBook.toast('Tax, charge and checkout settings saved.'))
          .catch((error) => AlbaBook.toast(error.message, 'danger'));
      });
    }

    const payStatusTone = { active: 'success', pending_golive: 'warning', disabled: 'neutral' };
    const payStatusLabel = { active: 'Live', pending_golive: 'Pending go-live', disabled: 'Disabled' };

    function renderPayAccounts() {
      const accounts = state.pay.accounts || [];
      if (!accounts.length) {
        payAccounts.replaceChildren(ui.emptyState({
          icon: 'fa-mobile-screen-button',
          title: 'No payment accounts',
          message: 'Add an M-Pesa till or paybill to take deposits and balances.',
          actionLabel: canUpdate ? 'Add M-Pesa account' : null,
          onAction: canUpdate ? () => openPaymentForm() : null,
        }));
        return;
      }
      payAccounts.replaceChildren(...accounts.map((a) => {
        const row = el('div', 'flex items-center gap-3 rounded-xl border border-line p-4');
        const main = el('div', 'min-w-0 flex-1');
        main.append(
          el('div', 'font-medium text-ink', a.display_name),
          el('div', 'text-xs text-muted', `${a.provider === 'mpesa_daraja' ? 'M-Pesa' : a.provider} · ${a.account_type} ${a.shortcode}${a.branch_name ? ' · ' + a.branch_name : ''}`),
        );
        const status = badge(payStatusLabel[a.status] || a.status, payStatusTone[a.status] || 'neutral');
        const actions = el('div', 'flex items-center gap-2');
        if (canUpdate) {
          const toggle = el('button', 'btn btn-ghost btn-sm', a.status === 'active' ? 'Disable' : 'Enable');
          toggle.type = 'button';
          toggle.addEventListener('click', () => {
            api.patch('/api/settings/payments/accounts/' + a.uuid, { status: a.status === 'active' ? 'disabled' : 'active' })
              .then(() => { a.status = a.status === 'active' ? 'disabled' : 'active'; renderPayAccounts(); AlbaBook.toast('Account updated.'); })
              .catch((error) => AlbaBook.toast(error.message, 'danger'));
          });
          actions.append(toggle);
        }
        row.append(main, status, actions);
        return row;
      }));
    }

    function renderLedger() {
      if (!payLedger) return;
      const payments = state.pay.recent_payments || [];
      if (!payments.length) {
        payLedger.replaceChildren(ui.emptyState({
          icon: 'fa-receipt',
          title: 'No payments yet',
          message: 'Customer payments will appear here.',
        }));
        return;
      }
      const list = el('div', 'space-y-2');
      payments.forEach((p) => {
        const row = el('div', 'flex items-center gap-3 rounded-lg border border-line px-3 py-2 text-sm');
        row.append(
          el('span', 'min-w-0 flex-1 truncate text-ink', p.customer_name),
          el('span', 'text-muted', p.method),
          el('span', 'font-medium text-ink', money(p.amount_minor, p.currency)),
          badge(p.status, p.status === 'succeeded' ? 'success' : (p.status === 'failed' ? 'danger' : 'neutral')),
        );
        list.append(row);
      });
      payLedger.replaceChildren(list);
    }

    function openPaymentForm() {
      const { wrap, body: drawerBody } = ui.drawerShell('Add payment account');
      const form = el('form', 'flex flex-col gap-4');
      form.noValidate = true;

      const providerSelect = select(
        state.pay.providers || [],
        null,
        (o) => o.name,
        (o) => o.code,
      );
      const typeSelect = select(
        [{ v: 'till', label: 'Buy Goods till' }, { v: 'paybill', label: 'Paybill' }],
        'till',
        (o) => o.label,
        (o) => o.v,
      );
      const shortcodeInput = input('text', '');
      shortcodeInput.maxLength = 20;
      shortcodeInput.inputMode = 'numeric';
      shortcodeInput.placeholder = 'e.g. 567890';
      const nameInput = input('text', '');
      nameInput.maxLength = 120;
      nameInput.placeholder = 'e.g. Main till — Westlands';
      const branchSelect = select(
        state.pay.branches || [],
        null,
        (o) => o.name,
        (o) => o.id,
      );
      const allOpt = el('option', null, 'All branches (workspace account)');
      allOpt.value = '';
      branchSelect.prepend(allOpt);
      // Daraja (Lipa na M-Pesa Online) credentials for STK push. Stored
      // encrypted; never shown again after saving.
      const envSelect = select(
        [{ v: 'sandbox', label: 'Sandbox (testing)' }, { v: 'production', label: 'Production (live)' }],
        'sandbox',
        (o) => o.label,
        (o) => o.v,
      );
      const secretInput = (placeholder) => {
        const i = input('password', '');
        i.autocomplete = 'off';
        i.placeholder = placeholder;
        return i;
      };
      const keyInput = secretInput('Consumer key');
      const secretKeyInput = secretInput('Consumer secret');
      const passkeyInput = secretInput('Lipa na M-Pesa Online passkey');
      const storeInput = input('text', '');
      storeInput.inputMode = 'numeric';
      storeInput.maxLength = 10;
      storeInput.placeholder = 'Head-office / store number';
      const storeField = field('Store number (Buy Goods only)', storeInput, 'store_number');
      const syncStore = () => { storeField.hidden = typeSelect.value !== 'till'; };
      typeSelect.addEventListener('change', syncStore);
      syncStore();
      const help = el('p', 'text-xs text-muted', 'Find these in your Safaricom Daraja app (Go Live → Lipa na M-Pesa Online). Buy Goods tills sign prompts with the store number; the till number receives the money.');

      const submit = submitBtn('Add account');
      form.append(
        field('Provider', providerSelect, 'provider'),
        field('Account type', typeSelect, 'account_type'),
        field('Shortcode (till or paybill number)', shortcodeInput, 'shortcode'),
        storeField,
        field('Label', nameInput, 'display_name'),
        field('Branch', branchSelect, 'branch_id'),
        field('Environment', envSelect, 'environment'),
        field('Consumer key', keyInput, 'consumer_key'),
        field('Consumer secret', secretKeyInput, 'consumer_secret'),
        field('Passkey', passkeyInput, 'passkey'),
        help,
        submit,
      );
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        ui.clearFieldErrors(form);
        ui.setBusy(submit, true, 'Saving…');
        api.post('/api/settings/payments/accounts', {
          provider: providerSelect.value,
          account_type: typeSelect.value,
          shortcode: shortcodeInput.value.trim(),
          display_name: nameInput.value.trim(),
          branch_id: branchSelect.value === '' ? 0 : Number(branchSelect.value),
          environment: envSelect.value,
          consumer_key: keyInput.value.trim(),
          consumer_secret: secretKeyInput.value.trim(),
          passkey: passkeyInput.value.trim(),
          store_number: typeSelect.value === 'till' ? storeInput.value.trim() : '',
        })
          .then(() => {
            AlbaBook.toast('Payment account added.');
            ui.drawer.close();
            loadPayments();
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

    /* ------------------------------------------------------------ buttons */
    const catNew = root.querySelector('[data-category-new]');
    if (catNew) { catNew.disabled = !canUpdate; catNew.addEventListener('click', () => openCategoryForm(null)); }
    const chNew = root.querySelector('[data-channel-account-new]');
    if (chNew) { chNew.disabled = !canUpdate; chNew.addEventListener('click', () => openAccountForm()); }
    const payNew = root.querySelector('[data-payment-account-new]');
    if (payNew) { payNew.disabled = !canUpdate; payNew.addEventListener('click', () => openPaymentForm()); }

    /* -------------------------------------------------------------- load */
    profileForm.append(ui.spinner('Loading settings…'));
    themeForm.append(ui.spinner('Loading theme…'));
    api.get('/api/settings')
      .then((data) => { state.meta = data; renderProfile(data); renderTheme(data); })
      .catch((error) => {
        profileForm.replaceChildren(ui.errorState(error.message, () => location.reload()));
        themeForm.replaceChildren();
      });
    loadCategories();
  });
})();
