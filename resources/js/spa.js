/*
 * AlbaBook SPA navigation. Intercepts same-origin GET links inside the app
 * shell, fetches an X-SPA fragment, swaps <main id="main"> and manages history.
 * The server renders the same Twig pages either way — JS only enhances, so a
 * fragment failure falls back to a normal navigation.
 */
(function () {
  'use strict';

  const AlbaBook = (window.AlbaBook = window.AlbaBook || {});
  const spa = (AlbaBook.spa = AlbaBook.spa || {});
  const SHELL_SELECTOR = '.sidebar, main, .topbar';
  let currentAbort = null;
  let progress = null;

  function progressBar() {
    if (!progress) {
      progress = document.createElement('div');
      progress.id = 'spa-progress';
      progress.setAttribute('aria-hidden', 'true');
      document.body.append(progress);
    }
    return progress;
  }
  function setLoading(on) {
    const bar = progressBar();
    if (on) {
      bar.classList.add('is-active');
      const main = document.getElementById('main');
      if (main) main.setAttribute('aria-busy', 'true');
    } else {
      bar.classList.remove('is-active');
      const main = document.getElementById('main');
      if (main) main.removeAttribute('aria-busy');
    }
  }

  function isNavigable(link) {
    if (!link || link.target === '_blank' || link.hasAttribute('download')) return false;
    if (link.hasAttribute('data-no-spa')) return false;
    const href = link.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:')) return false;
    const url = new URL(href, window.location.href);
    if (url.origin !== window.location.origin) return false;
    return !!link.closest(SHELL_SELECTOR);
  }

  function applyFragment(html, url, push) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const title = doc.querySelector('template[data-spa-title]');
    const main = document.getElementById('main');
    // A full document contains its own #main — refuse to nest it inside ours.
    if (!main || doc.getElementById('main')) return false;

    // Everything outside the title template is page content.
    const fragment = document.createDocumentFragment();
    doc.body.childNodes.forEach((node) => {
      if (node instanceof HTMLTemplateElement) return;
      fragment.append(node.cloneNode(true));
    });

    main.replaceChildren(fragment);
    if (title) document.title = title.content.textContent.trim();
    if (push !== false) history.pushState({ spa: true }, '', url);
    updateNav(url);
    main.scrollTo(0, 0);
    window.scrollTo(0, 0);
    AlbaBook.pages && AlbaBook.pages.mount(main);
    return true;
  }

  function updateNav(url) {
    const path = new URL(url, window.location.href).pathname;
    document.querySelectorAll('.sidebar-link').forEach((link) => {
      const href = link.getAttribute('href') || '';
      const active = href !== '#' && href !== '/' && (path === href || path.startsWith(href + '/'))
        || href === '/dashboard' && (path === '/' || path === '/dashboard');
      link.classList.toggle('is-active', active);
      if (active) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    });
  }

  async function navigate(url, { push = true } = {}) {
    if (currentAbort) currentAbort.abort();
    const abort = (currentAbort = new AbortController());
    setLoading(true);
    try {
      const response = await fetch(url, {
        headers: { 'X-SPA': 'fragment', Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: abort.signal,
      });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const html = await response.text();
      if (!applyFragment(html, url, push)) {
        window.location.href = url;
        return;
      }
    } catch (error) {
      if (error && error.name === 'AbortError') return;
      window.location.href = url;
      return;
    } finally {
      if (currentAbort === abort) currentAbort = null;
      setLoading(false);
    }
  }
  spa.navigate = navigate;

  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
    if (!isNavigable(link)) return;
    event.preventDefault();
    navigate(link.href);
  });

  window.addEventListener('popstate', function () {
    navigate(window.location.href, { push: false });
  });

  // First page of a session is a full render: seed history so back() works.
  if (!history.state || !history.state.spa) {
    history.replaceState({ spa: true }, '', window.location.href);
  }
})();
