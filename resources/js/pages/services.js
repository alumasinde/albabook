/*
 * Services catalogue page: filterable list, drawer create/edit form,
 * delete with in-use guard. All mutations go through /api/services with
 * CSRF; the server re-validates everything.
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

  const PER_PAGE_INFO = (page, total) =>
    total === 0 ? '' : `Showing ${(page - 1) * 20 + 1}–${Math.min(page * 20, total)} of ${total}`;

  AlbaBook.pages.register('services', function (root) {
    const canManage = root.dataset.canManage === '1';
    const body = root.querySelector('[data-svc-body]');
    const info = root.querySelector('[data-svc-info]');
    const pager = root.querySelector('[data-svc-pager]');
    const filterInputs = root.querySelectorAll('[data-svc-filter]');
    const state = { page: 1, meta: null };

    /* ------------------------------------------------------------- meta */
    api.get('/api/services/meta')
      .then((data) => {
        state.meta = data;
        const select = root.querySelector('[data-svc-filter="category"]');
        (data.categories || []).forEach((c) => {
          const opt = el('option', null, c.name);
          opt.value = c.id;
          select.append(opt);
        });
      })
      .catch(() => { /* filters degrade gracefully */ });

    /* ------------------------------------------------------------- list */
    function filters() {
      const out = {};
      filterInputs.forEach((input) => {
        const v = input.value.trim();
        if (v !== '') out[input.dataset.svcFilter] = v;
      });
      return out;
    }

    function query() {
      const params = new URLSearchParams(Object.assign({ page: state.page }, filters()));
      return '/api/services?' + params.toString();
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
          info.textContent = PER_PAGE_INFO(data.page, data.total);
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

    function badge(text, tone) {
      return el('span', 'badge badge-' + tone, text);
    }

    function renderRows(rows) {
      if (!rows.length) {
        const tr = el('tr');
        const td = el('td');
        td.colSpan = 7;
        td.append(ui.emptyState({
          icon: 'fa-scissors',
          title: 'No services yet',
          message: 'Add your first service so clients can book it.',
          actionLabel: canManage ? 'New service' : null,
          onAction: canManage ? () => openForm(null) : null,
        }));
        tr.append(td);
        body.replaceChildren(tr);
        return;
      }

      body.replaceChildren(...rows.map((row) => {
        const tr = el('tr');
        const onlineTd = el('td');
        onlineTd.append(row.is_bookable_online ? badge('Online', 'success') : el('span', 'text-muted', '—'));
        const statusTd = el('td');
        statusTd.append(badge(row.is_active ? 'Active' : 'Inactive', row.is_active ? 'success' : 'neutral'));
        tr.append(
          el('td', null, row.name),
          el('td', 'text-muted', row.category || '—'),
          el('td', 'text-muted', row.duration_label),
          el('td', null, row.price_label),
          onlineTd,
          statusTd,
        );

        const actions = el('td');
        const open = el('button', 'btn btn-ghost btn-sm', 'View');
        open.type = 'button';
        open.addEventListener('click', () => openDetail(row.uuid));
        actions.append(open);
        tr.append(actions);
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
      const { wrap, body: drawerBody } = ui.drawerShell('Service');
      drawerBody.append(ui.spinner('Loading service…'));
      ui.drawer.setContent(wrap);
      ui.drawer.open();

      api.get('/api/services/' + encodeURIComponent(uuid))
        .then((data) => {
          const s = data.service;
          drawerBody.replaceChildren();

          const facts = el('div', 'mt-2 space-y-3');
          const fact = (label, value) => {
            const row = el('div', 'flex items-center gap-3 text-sm');
            row.append(el('span', 'text-muted', label), el('span', 'ml-auto font-medium text-ink', value));
            facts.append(row);
          };
          fact('Name', s.name);
          fact('Category', s.category || '—');
          fact('Duration', s.duration_label);
          fact('Buffers', `+${s.buffer_before_minutes}m / +${s.buffer_after_minutes}m`);
          fact('Price', s.price_label);
          fact('Status', s.is_active ? 'Active' : 'Inactive');
          fact('Online booking', s.is_bookable_online ? 'Enabled' : 'Disabled');
          fact('Appointments', String(s.appointments));
          drawerBody.append(facts);
          if (s.description) {
            drawerBody.append(el('h4', 'mt-6 text-sm font-semibold text-ink', 'Description'));
            drawerBody.append(el('p', 'mt-2 text-sm text-muted', s.description));
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
              title: 'Delete service?',
              message: `"${s.name}" will be permanently removed.`,
              confirmLabel: 'Delete',
              danger: true,
            }).then((ok) => {
              if (!ok) return;
              api.del('/api/services/' + encodeURIComponent(s.uuid))
                .then(() => {
                  AlbaBook.toast('Service deleted.');
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
      label.setAttribute('for', 'svc-' + name);
      input.id = 'svc-' + name;
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

    function select(options, value) {
      const s = el('select', 'input select');
      options.forEach(([v, label]) => {
        const o = el('option', null, label);
        o.value = v;
        s.append(o);
      });
      if (value !== undefined && value !== null) s.value = String(value);
      return s;
    }

    function openForm(service) {
      const isEdit = service !== null;
      const { wrap, body: drawerBody } = ui.drawerShell(isEdit ? 'Edit service' : 'New service');
      const meta = state.meta || { categories: [], branches: [] };

      const form = el('form', 'flex flex-col gap-4');
      form.noValidate = true;

      const nameInput = input('text', service ? service.name : '');
      nameInput.maxLength = 150;
      nameInput.required = true;

      const categorySelect = select(
        [['', 'No category']].concat((meta.categories || []).map((c) => [String(c.id), c.name])),
        service ? service.category_id : '',
      );

      const descInput = el('textarea', 'input');
      descInput.rows = 3;
      descInput.value = service && service.description ? service.description : '';

      const durationInput = input('number', service ? service.duration_minutes : 30);
      durationInput.min = 5;
      durationInput.max = 600;
      durationInput.required = true;

      const bufferBefore = input('number', service ? service.buffer_before_minutes : 0);
      bufferBefore.min = 0;
      const bufferAfter = input('number', service ? service.buffer_after_minutes : 0);
      bufferAfter.min = 0;

      const priceType = select(
        [['fixed', 'Fixed price'], ['from', 'From (starting price)'], ['range', 'Price range']],
        service ? service.price_type : 'fixed',
      );

      const toMajor = (minor) => (minor === null || minor === undefined ? '' : (minor / 100).toFixed(2));
      const priceInput = input('number', service ? toMajor(service.price_amount_minor) : '');
      priceInput.min = 0;
      priceInput.step = '0.01';
      const minInput = input('number', service ? toMajor(service.min_amount_minor) : '');
      minInput.min = 0;
      minInput.step = '0.01';
      const maxInput = input('number', service ? toMajor(service.max_amount_minor) : '');
      maxInput.min = 0;
      maxInput.step = '0.01';

      const priceField = field('Price', priceInput, 'price');
      const minField = field('Minimum price', minInput, 'min_price');
      const maxField = field('Maximum price', maxInput, 'max_price');

      function syncPriceFields() {
        priceField.hidden = priceType.value !== 'fixed';
        minField.hidden = priceType.value === 'fixed';
        maxField.hidden = priceType.value !== 'range';
      }
      priceType.addEventListener('change', syncPriceFields);
      syncPriceFields();

      const sortInput = input('number', service ? service.sort_position : 0);
      sortInput.min = 0;

      const resourceSelect = select(
        [['', 'No resource needed']].concat((meta.resource_types || []).map((t) => [String(t.id), t.name])),
        service && service.required_resource_type_id ? String(service.required_resource_type_id) : '',
      );

      // Branch checkboxes
      const branchWrap = el('div', 'field');
      branchWrap.append(el('span', 'field-label', 'Offered at branches'));
      const selectedBranches = new Set(service ? (service.branch_ids || []).map(Number) : (meta.branches || []).map((b) => b.id));
      (meta.branches || []).forEach((b) => {
        const id = 'svc-branch-' + b.id;
        const label = el('label', 'flex items-center gap-2 text-sm text-ink');
        const box = input('checkbox');
        box.className = 'checkbox';
        box.id = id;
        box.name = 'branch_ids';
        box.value = b.id;
        box.checked = selectedBranches.has(b.id);
        label.setAttribute('for', id);
        label.append(box, el('span', null, b.name));
        branchWrap.append(label);
      });
      const branchErr = el('p', 'field-error');
      branchErr.id = 'field-branch_ids-error';
      branchWrap.append(branchErr);

      const flag = (name, checked, labelText) => {
        const label = el('label', 'flex items-center gap-2 text-sm text-ink');
        const box = input('checkbox');
        box.className = 'checkbox';
        box.id = 'svc-' + name;
        box.name = name;
        box.checked = checked;
        label.setAttribute('for', box.id);
        label.append(box, el('span', null, labelText));
        return { label, box };
      };
      const activeFlag = flag('is_active', service ? !!service.is_active : true, 'Active (visible and bookable)');
      const onlineFlag = flag('is_bookable_online', service ? !!service.is_bookable_online : true, 'Bookable on the public page');

      const submit = el('button', 'btn btn-primary btn-md', isEdit ? 'Save changes' : 'Create service');
      submit.type = 'submit';

      const buffersGrid = el('div', 'grid grid-cols-2 gap-4');
      buffersGrid.append(
        field('Buffer before (min)', bufferBefore, 'buffer_before_minutes'),
        field('Buffer after (min)', bufferAfter, 'buffer_after_minutes'),
      );
      const flagsWrap = el('div', 'flex flex-col gap-2');
      flagsWrap.append(activeFlag.label, onlineFlag.label);

      form.append(
        field('Name', nameInput, 'name'),
        field('Category', categorySelect, 'category_id'),
        field('Description', descInput, 'description'),
        field('Duration (minutes)', durationInput, 'duration_minutes'),
        buffersGrid,
        field('Price type', priceType, 'price_type'),
        priceField, minField, maxField,
        field('Sort position', sortInput, 'sort_position'),
        field('Requires resource', resourceSelect, 'required_resource_type_id'),
        branchWrap,
        flagsWrap,
        submit,
      );

      form.addEventListener('submit', (event) => {
        event.preventDefault();
        ui.clearFieldErrors(form);

        const branchIds = [...form.querySelectorAll('input[name="branch_ids"]:checked')].map((b) => Number(b.value));
        const payload = {
          name: nameInput.value.trim(),
          category_id: categorySelect.value === '' ? 0 : Number(categorySelect.value),
          description: descInput.value.trim(),
          duration_minutes: Number(durationInput.value),
          buffer_before_minutes: Number(bufferBefore.value || 0),
          buffer_after_minutes: Number(bufferAfter.value || 0),
          price_type: priceType.value,
          price: priceInput.value === '' ? null : Number(priceInput.value),
          min_price: minInput.value === '' ? null : Number(minInput.value),
          max_price: maxInput.value === '' ? null : Number(maxInput.value),
          sort_position: Number(sortInput.value || 0),
          required_resource_type_id: resourceSelect.value === '' ? 0 : Number(resourceSelect.value),
          branch_ids: branchIds,
          is_active: activeFlag.box.checked,
          is_bookable_online: onlineFlag.box.checked,
        };

        ui.setBusy(submit, true, 'Saving…');
        const request = isEdit
          ? api.patch('/api/services/' + encodeURIComponent(service.uuid), payload)
          : api.post('/api/services', payload);

        request
          .then(() => {
            AlbaBook.toast(isEdit ? 'Service updated.' : 'Service created.');
            ui.drawer.close();
            load();
          })
          .catch((error) => {
            if (error.fields) {
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

    /* ------------------------------------------------------------ wiring */
    let debounce = null;
    filterInputs.forEach((input) => {
      const event = input.tagName === 'SELECT' ? 'change' : 'input';
      input.addEventListener(event, () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => { state.page = 1; load(); }, 300);
      });
    });

    const newBtn = root.querySelector('[data-svc-new]');
    if (newBtn) newBtn.addEventListener('click', () => openForm(null));

    /* ---------------------------------------------------------- products */
    const prdBody = root.querySelector('[data-prd-body]');
    const prdQ = root.querySelector('[data-prd-q]');
    const prdStatus = root.querySelector('[data-prd-status]');
    const prdMoney = (minor, currency) => {
      try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'KES' }).format((minor || 0) / 100);
      } catch (e) {
        return ((minor || 0) / 100).toFixed(2);
      }
    };

    function renderProductRows(rows) {
      if (!rows.length) {
        const tr = el('tr');
        const td = el('td');
        td.colSpan = 6;
        td.append(ui.emptyState({ icon: 'fa-box', title: 'No products', message: 'Retail items you sell at the till will appear here.' }));
        tr.append(td);
        return [tr];
      }
      return rows.map((p) => {
        const tr = el('tr');
        tr.append(
          el('td', null, p.name),
          el('td', null, p.sku || '—'),
          el('td', null, prdMoney(p.price_minor, p.currency)),
          el('td', null, p.stock_quantity === null ? '—' : String(p.stock_quantity)),
          el('td'),
          el('td'),
        );
        tr.children[4].append(badge(p.is_active ? 'Active' : 'Inactive', p.is_active ? 'success' : 'neutral'));
        if (canManage) {
          const edit = el('button', 'icon-button');
          edit.type = 'button';
          edit.setAttribute('aria-label', 'Edit product');
          edit.append(el('i', 'fa-regular fa-pen-to-square'));
          edit.addEventListener('click', () => openProductForm(p));
          const del = el('button', 'icon-button');
          del.type = 'button';
          del.setAttribute('aria-label', 'Delete product');
          del.append(el('i', 'fa-regular fa-trash-can'));
          del.addEventListener('click', async () => {
            const yes = await ui.confirm({
              title: 'Delete product',
              message: 'Remove "' + p.name + '" from the catalogue?',
              confirmLabel: 'Delete',
              danger: true,
            });
            if (!yes) return;
            AlbaBook.api.del('/api/products/' + p.uuid)
              .then(loadProducts)
              .catch((error) => AlbaBook.toast(error.message, 'danger'));
          });
          const cell = el('div', 'flex items-center gap-1');
          cell.append(edit, del);
          tr.children[5].append(cell);
        }
        return tr;
      });
    }

    function loadProducts() {
      prdBody.replaceChildren(ui.skeletonRows(3));
      const params = new URLSearchParams();
      if (prdQ.value.trim()) params.set('q', prdQ.value.trim());
      if (prdStatus.value) params.set('status', prdStatus.value);
      AlbaBook.api.get('/api/products?' + params.toString())
        .then((data) => prdBody.replaceChildren(...renderProductRows(data.rows || [])))
        .catch((error) => {
          const tr = el('tr');
          const td = el('td');
          td.colSpan = 6;
          td.append(ui.errorState(error.message, loadProducts));
          tr.append(td);
          prdBody.replaceChildren(tr);
        });
    }

    function openProductForm(product) {
      const isEdit = !!product;
      const { wrap, body: drawerBody } = ui.drawerShell(isEdit ? 'Edit product' : 'New product');
      const form = el('form', 'mt-4 space-y-4');
      form.noValidate = true;

      const nameInput = input('text', product ? product.name : '');
      const skuInput = input('text', product ? product.sku || '' : '');
      const priceInput = input('number', product ? (product.price_minor / 100).toFixed(2) : '');
      priceInput.min = 0;
      priceInput.step = '0.01';
      const taxInput = input('number', product ? (product.tax_rate_bp / 100).toFixed(2) : '0');
      taxInput.min = 0;
      taxInput.step = '0.01';
      const stockInput = input('number', product && product.stock_quantity !== null ? String(product.stock_quantity) : '');
      stockInput.min = 0;
      stockInput.step = '1';
      const activeInput = el('input', 'checkbox');
      activeInput.type = 'checkbox';
      activeInput.checked = product ? !!Number(product.is_active) : true;

      form.append(
        field('Name', nameInput, 'name'),
        field('SKU (optional)', skuInput, 'sku'),
        field('Price', priceInput, 'price_minor'),
        field('Tax rate %', taxInput, 'tax_rate_bp'),
        field('Stock on hand (leave empty to skip tracking)', stockInput, 'stock_quantity'),
      );
      const activeLabel = el('label', 'checkbox-label');
      activeLabel.append(activeInput, document.createTextNode(' Active'));
      form.append(activeLabel);

      const submit = el('button', 'btn btn-primary btn-md w-full', isEdit ? 'Save changes' : 'Create product');
      submit.type = 'submit';
      form.append(submit);

      form.addEventListener('submit', (event) => {
        event.preventDefault();
        ui.clearFieldErrors(form);
        ui.setBusy(submit, true, 'Saving…');
        const body = {
          name: nameInput.value.trim(),
          sku: skuInput.value.trim(),
          price_minor: Math.round(Number(priceInput.value || 0) * 100),
          tax_rate_bp: Math.round(Number(taxInput.value || 0) * 100),
          currency: 'KES',
          stock_quantity: stockInput.value === '' ? null : Number(stockInput.value),
          is_active: activeInput.checked ? 1 : 0,
        };
        const call = isEdit
          ? AlbaBook.api.patch('/api/products/' + product.uuid, body)
          : AlbaBook.api.post('/api/products', body);
        call
          .then(() => {
            AlbaBook.toast(isEdit ? 'Product updated.' : 'Product created.', 'success');
            ui.drawer.close();
            loadProducts();
          })
          .catch((error) => {
            if (error.fields) ui.showFieldErrors(form, error.fields);
            else AlbaBook.toast(error.message, 'danger');
          })
          .finally(() => ui.setBusy(submit, false));
      });

      drawerBody.append(form);
      ui.drawer.setContent(wrap);
      ui.drawer.open();
    }

    if (prdBody) {
      let prdDebounce = null;
      prdQ.addEventListener('input', () => {
        clearTimeout(prdDebounce);
        prdDebounce = setTimeout(loadProducts, 300);
      });
      prdStatus.addEventListener('change', loadProducts);
      const prdNew = root.querySelector('[data-prd-new]');
      if (prdNew) prdNew.addEventListener('click', () => openProductForm(null));
      loadProducts();
    }

    load();
  });
})();
