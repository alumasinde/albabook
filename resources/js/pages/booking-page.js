/*
 * Booking-page editor: settings form, section toggles, FAQ manager and
 * publish history. Talks to /api/booking-page; server re-validates.
 */
AlbaBook.pages.register('booking-page', function (root) {
  const { api, ui } = AlbaBook;

  const canManage = root.dataset.canManage === '1';
  const canPublish = root.dataset.canPublish === '1';

  const el = (tag, cls, text) => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  const field = (labelText, input, name) => {
    const wrap = el('div', 'field');
    const label = el('label', 'field-label', labelText);
    label.setAttribute('for', 'bp-' + name);
    input.id = 'bp-' + name;
    input.name = name;
    const err = el('p', 'field-error');
    err.id = 'field-' + name + '-error';
    wrap.append(label, input, err);
    return wrap;
  };
  const input = (type, value) => {
    const node = el('input', 'input');
    node.type = type;
    if (value !== undefined && value !== null) node.value = value;
    return node;
  };
  const select = (options, value) => {
    const node = el('select', 'input select');
    options.forEach(([val, label]) => {
      const opt = el('option', '', label);
      opt.value = val;
      node.append(opt);
    });
    node.value = value;
    return node;
  };
  const check = (labelText, name, checked) => {
    const label = el('label', 'flex items-center gap-2 text-sm text-ink');
    const box = input('checkbox');
    box.checked = !!checked;
    box.name = name;
    label.append(box, el('span', '', labelText));
    return { label, box };
  };
  const grid = (...children) => {
    const wrap = el('div', 'grid gap-4 sm:grid-cols-2');
    children.forEach((c) => wrap.append(c));
    return wrap;
  };
  const pretty = (value) => value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  const iconButton = (icon, label) => {
    const btn = el('button', 'icon-button');
    btn.type = 'button';
    btn.setAttribute('aria-label', label);
    btn.append(el('i', 'fa-solid ' + icon));
    return btn;
  };

  api.get('/api/booking-page')
    .then((data) => {
      renderLiveUrl(data.booking_url);
      renderForm(data.settings, data.options, data.locales);
      renderSections(data.sections);
      renderFaqs(data.faqs);
      renderVersions(data.versions);
    })
    .catch((error) => {
      root.querySelectorAll('[data-editor-form]').forEach((form) => {
        form.replaceChildren(ui.errorState(error.message, () => location.reload()));
      });
    });

  function renderLiveUrl(url) {
    const link = root.querySelector('[data-live-url]');
    if (!link || !url) return;
    link.href = url;
    link.classList.remove('hidden');
    link.classList.add('inline-flex');
    link.querySelector('[data-live-url-text]').textContent = url;
  }

  function renderForm(settings, options, locales) {
    const form = root.querySelector('[data-editor-form]');
    form.replaceChildren();

    const enabled = check('Booking page is live', 'is_enabled', settings.is_enabled);
    const indexing = check('Allow search-engine indexing', 'allow_indexing', settings.allow_indexing);
    const prices = check('Show prices', 'show_prices', settings.show_prices);
    const duration = check('Show duration', 'show_duration', settings.show_duration);

    const seoTitle = input('text', settings.seo_title);
    const seoDesc = input('text', settings.seo_description);
    const ogTitle = input('text', settings.og_title);
    const ogDesc = input('text', settings.og_description);

    const selects = {};
    [
      ['booking_flow', 'Booking flow', options.booking_flow],
      ['staff_selection', 'Staff selection', options.staff_selection],
      ['branch_selection', 'Branch selection', options.branch_selection],
      ['service_presentation', 'Services layout', options.service_presentation],
      ['calendar_presentation', 'Calendar layout', options.calendar_presentation],
      ['time_slot_presentation', 'Time slots layout', options.time_slot_presentation],
    ].forEach(([key, label, allowed]) => {
      selects[key] = select(allowed.map((v) => [v, pretty(v)]), settings[key]);
      form.append(field(label, selects[key], key));
    });

    const localeSel = select(locales.map((l) => [l, l.toUpperCase()]), settings.default_locale);
    form.append(field('Page language', localeSel, 'default_locale'));

    form.append(
      field('SEO title', seoTitle, 'seo_title'),
      field('SEO description', seoDesc, 'seo_description'),
      grid(field('Social title', ogTitle, 'og_title'), field('Social description', ogDesc, 'og_description')),
      el('div', 'grid gap-3 sm:grid-cols-2'),
    );
    const toggles = form.lastElementChild;
    [enabled, indexing, prices, duration].forEach((c) => toggles.append(c.label));

    const submit = el('button', 'btn btn-primary btn-md self-start', 'Save changes');
    submit.type = 'submit';
    if (canManage) form.append(submit);

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      ui.clearFieldErrors(form);
      const payload = {
        is_enabled: enabled.box.checked,
        allow_indexing: indexing.box.checked,
        show_prices: prices.box.checked,
        show_duration: duration.box.checked,
        seo_title: seoTitle.value.trim(),
        seo_description: seoDesc.value.trim(),
        og_title: ogTitle.value.trim(),
        og_description: ogDesc.value.trim(),
        default_locale: localeSel.value,
      };
      Object.keys(selects).forEach((key) => { payload[key] = selects[key].value; });
      ui.setBusy(submit, true, 'Saving…');
      api.patch('/api/booking-page', payload)
        .then(() => AlbaBook.toast('Booking page saved.'))
        .catch((error) => {
          if (error.fields && Object.keys(error.fields).length) {
            ui.showFieldErrors(form, error.fields);
          } else {
            AlbaBook.toast(error.message, 'danger');
          }
        })
        .finally(() => ui.setBusy(submit, false));
    });
  }

  function renderSections(sections) {
    const wrap = root.querySelector('[data-sections]');
    wrap.replaceChildren();
    if (!sections.length) {
      wrap.append(el('p', 'text-sm text-muted', 'No sections available.'));
      return;
    }
    const list = el('div', 'divide-y divide-line rounded-xl border border-line');
    sections.forEach((section) => {
      const row = el('label', 'flex items-center justify-between gap-3 px-4 py-3 text-sm text-ink');
      const nameWrap = el('span', 'flex items-center gap-2');
      nameWrap.append(el('span', 'font-medium', section.name));
      if (section.feature_code) {
        nameWrap.append(ui.badge('add-on', 'neutral'));
      }
      const box = input('checkbox');
      box.checked = !!Number(section.is_enabled);
      box.dataset.sectionKey = section.code;
      box.disabled = !canManage;
      box.addEventListener('change', saveSections);
      row.append(nameWrap, box);
      list.append(row);
    });
    wrap.append(list);

    function saveSections() {
      const payload = {
        sections: [...list.querySelectorAll('input[data-section-key]')].map((box, index) => ({
          key: box.dataset.sectionKey,
          enabled: box.checked,
          position: (index + 1) * 10,
        })),
      };
      api.patch('/api/booking-page/sections', payload)
        .then(() => AlbaBook.toast('Sections updated.'))
        .catch((error) => AlbaBook.toast(error.message, 'danger'));
    }
  }

  function renderFaqs(faqs) {
    const wrap = root.querySelector('[data-faqs]');
    wrap.replaceChildren();
    if (!faqs.length) {
      wrap.append(el('p', 'text-sm text-muted', 'No FAQs yet. Add common questions your customers ask.'));
      return;
    }
    faqs.forEach((faq) => {
      const card = el('div', 'rounded-xl border border-line p-4');
      const top = el('div', 'flex items-start justify-between gap-3');
      top.append(el('p', 'font-medium text-ink', faq.question));
      if (canManage) {
        const actions = el('div', 'flex shrink-0 gap-1');
        const edit = iconButton('fa-pen', 'Edit FAQ');
        const del = iconButton('fa-trash', 'Delete FAQ');
        edit.addEventListener('click', () => faqDrawer(faq));
        del.addEventListener('click', () => {
          ui.confirm({
            title: 'Delete FAQ?',
            message: `"${faq.question}" will be permanently removed.`,
            confirmLabel: 'Delete',
            danger: true,
          }).then((ok) => {
            if (!ok) return;
            api.del('/api/booking-page/faqs/' + faq.id)
              .then((data) => { renderFaqs(data.faqs); AlbaBook.toast('FAQ deleted.'); })
              .catch((error) => AlbaBook.toast(error.message, 'danger'));
          });
        });
        actions.append(edit, del);
        top.append(actions);
      }
      card.append(top, el('p', 'mt-2 text-sm text-muted', faq.answer));
      wrap.append(card);
    });
  }

  function faqDrawer(faq) {
    const isNew = !faq;
    const question = input('text', faq ? faq.question : '');
    const answer = el('textarea', 'input');
    answer.rows = 4;
    if (faq) answer.value = faq.answer;
    const form = el('form', 'flex flex-col gap-4');
    form.append(field('Question', question, 'question'), field('Answer', answer, 'answer'));
    const submit = el('button', 'btn btn-primary btn-md self-start', isNew ? 'Add FAQ' : 'Save FAQ');
    submit.type = 'submit';
    form.append(submit);

    const { wrap, body } = ui.drawerShell(isNew ? 'New FAQ' : 'Edit FAQ');
    body.append(form);
    ui.drawer.setContent(wrap);
    ui.drawer.open();
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      ui.clearFieldErrors(form);
      const payload = { question: question.value.trim(), answer: answer.value.trim(), sort_position: faq ? faq.sort_position : 0 };
      ui.setBusy(submit, true, 'Saving…');
      const request = isNew
        ? api.post('/api/booking-page/faqs', payload)
        : api.patch('/api/booking-page/faqs/' + faq.id, payload);
      request
        .then((data) => { ui.drawer.close(); renderFaqs(data.faqs); AlbaBook.toast('FAQ saved.'); })
        .catch((error) => {
          if (error.fields && Object.keys(error.fields).length) {
            ui.showFieldErrors(form, error.fields);
          } else {
            AlbaBook.toast(error.message, 'danger');
          }
        })
        .finally(() => ui.setBusy(submit, false));
    });
  }

  const newBtn = root.querySelector('[data-faq-new]');
  if (newBtn) newBtn.addEventListener('click', () => faqDrawer(null));

  function renderVersions(versions) {
    const wrap = root.querySelector('[data-versions]');
    wrap.replaceChildren();
    if (!versions.length) {
      wrap.append(el('p', 'text-sm text-muted', 'Not published yet. Publish snapshots what customers see.'));
      return;
    }
    const list = el('div', 'divide-y divide-line rounded-xl border border-line');
    versions.forEach((v) => {
      const row = el('div', 'flex items-center justify-between gap-3 px-4 py-3 text-sm');
      const left = el('div');
      left.append(el('p', 'font-medium text-ink', 'v' + v.version_number));
      if (v.published_at) left.append(el('p', 'text-xs text-muted', v.published_at));
      row.append(left, ui.badge(Number(v.is_active) ? 'live' : v.status, Number(v.is_active) ? 'success' : 'neutral'));
      list.append(row);
    });
    wrap.append(list);
  }

  const publishBtn = root.querySelector('[data-publish]');
  if (publishBtn && canPublish) {
    publishBtn.addEventListener('click', () => {
      ui.confirm({
        title: 'Publish booking page?',
        message: 'This snapshots the current settings, sections and FAQs as the live version.',
        confirmLabel: 'Publish',
      }).then((ok) => {
        if (!ok) return;
        ui.setBusy(publishBtn, true, 'Publishing…');
        api.post('/api/booking-page/publish')
          .then((data) => {
            renderVersions(data.versions);
            AlbaBook.toast('Published v' + data.version + '.');
          })
          .catch((error) => AlbaBook.toast(error.message, 'danger'))
          .finally(() => ui.setBusy(publishBtn, false));
      });
    });
  }
});
