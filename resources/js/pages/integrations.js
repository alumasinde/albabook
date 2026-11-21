/*
 * Integrations: webhook endpoint manager (create/edit/toggle/delete,
 * secret shown once) plus the recent delivery log.
 */
AlbaBook.pages.register('integrations', function (root) {
  const { api, ui } = AlbaBook;
  const canManage = root.dataset.canManage === '1';
  const el = ui.el;

  const endpointsWrap = root.querySelector('[data-int-endpoints]');
  const deliveriesWrap = root.querySelector('[data-int-deliveries]');
  let eventTypes = [];

  api.get('/api/integrations')
    .then((data) => {
      eventTypes = data.event_types;
      renderEndpoints(data.endpoints);
      renderDeliveries(data.deliveries);
    })
    .catch((error) => endpointsWrap.replaceChildren(ui.errorState(error.message, () => location.reload())));

  function renderEndpoints(endpoints) {
    endpointsWrap.replaceChildren();
    if (!endpoints.length) {
      endpointsWrap.append(ui.emptyState({
        title: 'No webhooks yet',
        message: 'Add an endpoint to receive booking and payment events.',
        icon: 'fa-plug',
      }));
      return;
    }
    endpoints.forEach((ep) => {
      const card = el('div', 'rounded-xl border border-line p-4');
      const top = el('div', 'flex items-start justify-between gap-3');
      const left = el('div', 'min-w-0');
      left.append(el('p', 'truncate font-mono text-sm text-ink', ep.url));
      const meta = el('div', 'mt-1 flex flex-wrap items-center gap-2');
      meta.append(ui.badge(ep.is_active ? 'active' : 'paused', ep.is_active ? 'success' : 'neutral'));
      ep.event_types.forEach((type) => meta.append(ui.badge(type, 'info')));
      left.append(meta);
      top.append(left);

      if (canManage) {
        const actions = el('div', 'flex shrink-0 gap-1');
        const edit = iconBtn('fa-pen', 'Edit endpoint');
        const toggle = iconBtn(ep.is_active ? 'fa-pause' : 'fa-play', ep.is_active ? 'Pause' : 'Resume');
        const del = iconBtn('fa-trash', 'Delete endpoint');
        edit.addEventListener('click', () => endpointDrawer(ep));
        toggle.addEventListener('click', () => {
          api.patch('/api/integrations/' + ep.uuid, { is_active: !ep.is_active })
            .then(() => { AlbaBook.toast(ep.is_active ? 'Endpoint paused.' : 'Endpoint resumed.'); reload(); })
            .catch((error) => AlbaBook.toast(error.message, 'danger'));
        });
        del.addEventListener('click', () => {
          ui.confirm({
            title: 'Delete endpoint?',
            message: `${ep.url} will stop receiving events immediately.`,
            confirmLabel: 'Delete',
            danger: true,
          }).then((ok) => {
            if (!ok) return;
            api.del('/api/integrations/' + ep.uuid)
              .then(() => { AlbaBook.toast('Endpoint deleted.'); reload(); })
              .catch((error) => AlbaBook.toast(error.message, 'danger'));
          });
        });
        actions.append(edit, toggle, del);
        top.append(actions);
      }
      card.append(top);
      endpointsWrap.append(card);
    });
  }

  function renderDeliveries(deliveries) {
    deliveriesWrap.replaceChildren();
    if (!deliveries.length) {
      deliveriesWrap.append(el('p', 'text-sm text-muted', 'No deliveries yet.'));
      return;
    }
    const list = el('div', 'divide-y divide-line rounded-xl border border-line');
    deliveries.forEach((d) => {
      const row = el('div', 'flex items-center justify-between gap-3 px-4 py-3 text-sm');
      const left = el('div', 'min-w-0');
      left.append(el('p', 'truncate font-medium text-ink', d.event_type));
      const sub = el('p', 'text-xs text-muted', `${d.created_at.slice(0, 16)} · attempt ${d.attempt}`);
      if (d.error) sub.textContent += ` · ${d.error}`;
      left.append(sub);
      const tone = { succeeded: 'success', failed: 'warning', pending: 'info' }[d.status] || 'neutral';
      row.append(left, ui.badge(d.response_code ? `${d.status} · ${d.response_code}` : d.status, tone));
      list.append(row);
    });
    deliveriesWrap.append(list);
  }

  function endpointDrawer(ep) {
    const isNew = !ep;
    const urlInput = el('input', 'input');
    urlInput.type = 'url';
    urlInput.placeholder = 'https://example.com/hooks/albabook';
    urlInput.name = 'url';
    if (ep) urlInput.value = ep.url;

    const form = el('form', 'flex flex-col gap-4');
    const urlField = el('div', 'field');
    urlField.append(el('label', 'field-label', 'Endpoint URL'), urlInput, el('p', 'field-error'));
    form.append(urlField);

    const eventsWrap = el('div', 'field');
    eventsWrap.append(el('span', 'field-label', 'Events'));
    const boxes = [];
    const grid = el('div', 'grid gap-2 sm:grid-cols-2');
    eventTypes.forEach((type) => {
      const label = el('label', 'flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm text-ink');
      const box = el('input');
      box.type = 'checkbox';
      box.value = type;
      box.checked = ep ? ep.event_types.includes(type) : false;
      boxes.push(box);
      label.append(box, document.createTextNode(type));
      grid.append(label);
    });
    eventsWrap.append(grid);
    form.append(eventsWrap);

    const submit = el('button', 'btn btn-primary btn-md self-start', isNew ? 'Add endpoint' : 'Save endpoint');
    submit.type = 'submit';
    form.append(submit);

    const { wrap, body } = ui.drawerShell(isNew ? 'New webhook' : 'Edit webhook');
    body.append(form);
    ui.drawer.setContent(wrap);
    ui.drawer.open();

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const payload = {
        url: urlInput.value.trim(),
        event_types: boxes.filter((b) => b.checked).map((b) => b.value),
      };
      ui.setBusy(submit, true, 'Saving…');
      const request = isNew
        ? api.post('/api/integrations', payload)
        : api.patch('/api/integrations/' + ep.uuid, payload);
      request
        .then((data) => {
          ui.drawer.close();
          if (data.secret) showSecret(data.secret);
          reload();
        })
        .catch((error) => AlbaBook.toast(error.message, 'danger'))
        .finally(() => ui.setBusy(submit, false));
    });
  }

  function showSecret(secret) {
    const box = el('div', 'flex flex-col gap-3');
    box.append(el('p', 'text-sm text-ink', 'Signing secret — shown once. Store it somewhere safe.'));
    const code = el('code', 'block break-all rounded-lg bg-surface px-3 py-2 font-mono text-xs text-ink', secret);
    box.append(code);
    const { wrap, body } = ui.drawerShell('Webhook secret');
    body.append(box);
    ui.drawer.setContent(wrap);
    ui.drawer.open();
  }

  function iconBtn(icon, label) {
    const btn = el('button', 'icon-button');
    btn.type = 'button';
    btn.setAttribute('aria-label', label);
    btn.append(el('i', 'fa-solid ' + icon));
    return btn;
  }

  function reload() {
    api.get('/api/integrations')
      .then((data) => { renderEndpoints(data.endpoints); renderDeliveries(data.deliveries); })
      .catch(() => {});
  }

  const newBtn = root.querySelector('[data-int-new]');
  if (newBtn) newBtn.addEventListener('click', () => endpointDrawer(null));
});
