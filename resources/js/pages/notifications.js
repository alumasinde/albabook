/*
 * Notifications center: stat cards, filters, paginated queue with retry
 * and cancel actions for members holding notifications.manage.
 */
AlbaBook.pages.register('notifications', function (root) {
  const { api, ui } = AlbaBook;
  const canManage = root.dataset.canManage === '1';
  const el = ui.el;

  const body = root.querySelector('[data-ntf-body]');
  const info = root.querySelector('[data-ntf-info]');
  const pager = root.querySelector('[data-ntf-pager]');
  const statsWrap = root.querySelector('[data-ntf-stats]');
  const searchInput = root.querySelector('[data-ntf-search]');
  const channelSel = root.querySelector('[data-ntf-channel]');
  const statusSel = root.querySelector('[data-ntf-status]');

  const state = { page: 1, q: '', channel: '', status: '' };
  const PER_PAGE = 20;
  let channelsLoaded = false;
  let statusesLoaded = false;

  function statCard(label, value, icon, tone) {
    const card = el('div', 'card flex items-center gap-4 p-5');
    const wrap = el('span', 'flex h-10 w-10 items-center justify-center rounded-lg text-lg ' + tone);
    wrap.append(el('i', 'fa-solid ' + icon));
    const text = el('div');
    text.append(el('p', 'text-2xl font-semibold text-ink', String(value)));
    text.append(el('p', 'text-sm text-muted', label));
    card.append(wrap, text);
    return card;
  }

  function renderStats(stats) {
    statsWrap.replaceChildren(
      statCard('Queued', stats.queued, 'fa-hourglass-half', 'bg-primary-soft text-primary-strong'),
      statCard('Sent today', stats.sent_today, 'fa-paper-plane', 'bg-success-soft text-success-strong'),
      statCard('Failed', stats.failed, 'fa-triangle-exclamation', 'bg-danger-soft text-danger-strong'),
    );
  }

  function load() {
    const skel = el('tr');
    const skelTd = el('td');
    skelTd.colSpan = 7;
    skelTd.append(ui.skeletonRows(5));
    skel.append(skelTd);
    body.replaceChildren(skel);
    const params = new URLSearchParams({ page: state.page });
    if (state.q) params.set('q', state.q);
    if (state.channel) params.set('channel', state.channel);
    if (state.status) params.set('status', state.status);

    api.get('/api/notifications?' + params.toString())
      .then((data) => {
        renderStats(data.stats);
        if (!channelsLoaded) {
          channelsLoaded = true;
          data.channels.forEach((c) => {
            const opt = el('option', '', c.name);
            opt.value = c.code;
            channelSel.append(opt);
          });
        }
        if (!statusesLoaded) {
          statusesLoaded = true;
          data.statuses.forEach((s) => {
            const opt = el('option', '', s.replace(/_/g, ' '));
            opt.value = s;
            statusSel.append(opt);
          });
        }
        renderRows(data.rows);
        renderPager(data.total);
      })
      .catch((error) => {
        body.replaceChildren();
        const tr = el('tr');
        const td = el('td', 'px-4 py-8 text-center text-sm text-danger-strong', error.message);
        td.colSpan = 7;
        tr.append(td);
        body.append(tr);
      });
  }

  function renderRows(rows) {
    body.replaceChildren();
    if (!rows.length) {
      const tr = el('tr');
      const td = el('td', 'px-4 py-10 text-center text-sm text-muted', 'No notifications match the current filters.');
      td.colSpan = 7;
      tr.append(td);
      body.append(tr);
      return;
    }
    rows.forEach((n) => {
      const tr = el('tr');
      const event = el('td', 'text-sm text-ink', n.event || '—');
      const channel = el('td');
      channel.append(ui.badge(n.channel, n.channel === 'sms' || n.channel === 'whatsapp' ? 'info' : 'neutral'));
      const recipient = el('td', 'font-mono text-xs text-muted', n.recipient);
      const status = el('td');
      const tone = { sent: 'success', delivered: 'success', failed: 'warning', cancelled: 'neutral', pending: 'info', processing: 'info' }[n.status] || 'neutral';
      const statusCell = el('div', 'flex flex-col gap-1');
      statusCell.append(ui.badge(n.status, tone));
      if (n.last_error) statusCell.append(el('span', 'text-xs text-danger-strong', n.last_error));
      status.append(statusCell);
      const scheduled = el('td', 'text-sm text-muted', (n.scheduled_at || '').slice(0, 16));
      const sent = el('td', 'text-sm text-muted', n.sent_at ? n.sent_at.slice(0, 16) : '—');

      const actions = el('td');
      if (canManage) {
        const wrap = el('div', 'flex gap-1');
        if (n.status === 'failed') {
          const retry = el('button', 'btn btn-ghost btn-sm', 'Retry');
          retry.type = 'button';
          retry.addEventListener('click', () => act('retry', n.uuid, 'Notification requeued.'));
          wrap.append(retry);
        }
        if (n.status === 'pending' || n.status === 'processing') {
          const cancel = el('button', 'btn btn-ghost btn-sm', 'Cancel');
          cancel.type = 'button';
          cancel.addEventListener('click', () => {
            ui.confirm({
              title: 'Cancel notification?',
              message: 'The queued message will not be sent.',
              confirmLabel: 'Cancel it',
              danger: true,
            }).then((ok) => {
              if (ok) act('cancel', n.uuid, 'Notification cancelled.');
            });
          });
          wrap.append(cancel);
        }
        actions.append(wrap);
      }

      tr.append(event, channel, recipient, status, scheduled, sent, actions);
      body.append(tr);
    });
  }

  function act(action, uuid, message) {
    api.post('/api/notifications/' + encodeURIComponent(uuid) + '/' + action)
      .then(() => { AlbaBook.toast(message); load(); })
      .catch((error) => AlbaBook.toast(error.message, 'danger'));
  }

  function renderPager(total) {
    const pages = Math.max(1, Math.ceil(total / PER_PAGE));
    info.textContent = `${total} notification${total === 1 ? '' : 's'} · page ${state.page} of ${pages}`;
    pager.replaceChildren();
    const prev = el('button', 'btn btn-ghost btn-sm', 'Previous');
    prev.type = 'button';
    prev.disabled = state.page <= 1;
    prev.addEventListener('click', () => { state.page -= 1; load(); });
    const next = el('button', 'btn btn-ghost btn-sm', 'Next');
    next.type = 'button';
    next.disabled = state.page >= pages;
    next.addEventListener('click', () => { state.page += 1; load(); });
    pager.append(prev, next);
  }

  let searchTimer = null;
  searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { state.q = searchInput.value.trim(); state.page = 1; load(); }, 300);
  });
  channelSel.addEventListener('change', () => { state.channel = channelSel.value; state.page = 1; load(); });
  statusSel.addEventListener('change', () => { state.status = statusSel.value; state.page = 1; load(); });

  load();
});
