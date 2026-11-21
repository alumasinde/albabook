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
      }
    }
    tabs.forEach((t) => t.addEventListener('click', () => activate(t.dataset.settingsTab)));
    activate('profile');

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

    /* -------------------------------------------------------- notifications */
    const notifPrefs = root.querySelector('[data-notif-prefs]');
    const notifAccounts = root.querySelector('[data-channel-accounts]');

    function loadNotifications() {
      notifPrefs.replaceChildren(ui.spinner('Loading preferences…'));
      notifAccounts.replaceChildren(ui.spinner('Loading accounts…'));
      api.get('/api/settings/notifications')
        .then((data) => { state.notif = data; renderNotifAccounts(); renderNotifPrefs(); })
        .catch((error) => {
          notifPrefs.replaceChildren(ui.errorState(error.message, loadNotifications));
          notifAccounts.replaceChildren();
        });
    }

    function renderNotifAccounts() {
      const accounts = state.notif.accounts || [];
      if (!accounts.length) {
        notifAccounts.replaceChildren(ui.emptyState({
          icon: 'fa-tower-broadcast',
          title: 'No channels connected',
          message: 'Connect an SMS or WhatsApp provider to reach customers beyond email.',
          actionLabel: canUpdate ? 'Connect channel' : null,
          onAction: canUpdate ? () => openAccountForm() : null,
        }));
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
      const hasSms = (state.notif.accounts || []).some((a) => a.channel === 'sms' && a.status === 'active');
      const hasWa = (state.notif.accounts || []).some((a) => a.channel === 'whatsapp' && a.status === 'active');
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
      hint.textContent = 'SMS and WhatsApp toggles unlock once a provider account is active.';
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
      senderInput.placeholder = 'e.g. ALBABOOK';
      const credInput = el('textarea', 'input');
      credInput.rows = 3;
      credInput.placeholder = '{"api_key":"…","username":"…"}';

      const submit = submitBtn('Connect');
      form.append(
        field('Provider', providerSelect, 'provider_id'),
        field('Sender ID (optional)', senderInput, 'sender_id'),
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
    const policySave = root.querySelector('[data-policy-save]');

    function loadPayments() {
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
      const credInput = el('textarea', 'input');
      credInput.rows = 3;
      credInput.placeholder = '{"consumer_key":"…","consumer_secret":"…","passkey":"…"}';

      const submit = submitBtn('Add account');
      form.append(
        field('Provider', providerSelect, 'provider'),
        field('Account type', typeSelect, 'account_type'),
        field('Shortcode', shortcodeInput, 'shortcode'),
        field('Label', nameInput, 'display_name'),
        field('Branch', branchSelect, 'branch_id'),
        field('Credentials', credInput, 'credentials'),
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
          credentials: credInput.value.trim(),
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
