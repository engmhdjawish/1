/**
 * Shared catalogue search box (home + store): grouped suggestions, keyboard
 * navigation and voice input. One instance per form[data-hn-search].
 */
(() => {
  const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const icon = (name) => {
    const node = el('span', 'material-symbols-outlined', name);
    node.setAttribute('aria-hidden', 'true');
    return node;
  };
  const fold = (value) => value.toLowerCase()
    .replace(/[أإآٱ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').replace(/ؤ/g, 'و').replace(/ئ/g, 'ي');
  const highlight = (text, query) => {
    const span = el('span', 'hn-suggest__label');
    const at = query ? fold(text).indexOf(fold(query)) : -1;
    if (at < 0) {
      span.textContent = text;
      return span;
    }
    span.append(text.slice(0, at), el('mark', '', text.slice(at, at + query.length)), text.slice(at + query.length));
    return span;
  };
  const phone = () => window.matchMedia('(max-width: 767px)').matches;

  const setup = (form) => {
    const input = form.querySelector('[data-hn-search-input]');
    const suggest = form.querySelector('[data-hn-suggest]');
    if (!(input instanceof HTMLInputElement) || !suggest) return;
    const allBase = form.getAttribute('action') || '/store.php';
    let suggestTimer = 0;
    let suggestController = null;
    let activeIndex = -1;
    let liftedFor = '';

    const allHref = (query) => {
      const url = new URL(allBase, window.location.origin);
      form.querySelectorAll('input[type="hidden"]').forEach((hidden) => {
        if (hidden instanceof HTMLInputElement && hidden.name && hidden.value) url.searchParams.append(hidden.name, hidden.value);
      });
      url.searchParams.set('q', query);
      return url.pathname + url.search;
    };

    // On phones the keyboard covers the lower half; keep the list inside what is actually visible.
    const fitPanel = () => {
      if (suggest.hidden) return;
      const view = window.visualViewport;
      const visibleBottom = view ? view.offsetTop + view.height : window.innerHeight;
      const dock = document.querySelector('[data-app-dock]');
      const dockTop = dock instanceof HTMLElement && dock.getClientRects().length > 0 ? dock.getBoundingClientRect().top : Infinity;
      const top = suggest.getBoundingClientRect().top;
      const room = Math.min(visibleBottom, dockTop) - top - 10;
      form.style.setProperty('--hn-suggest-max', `${Math.max(180, Math.round(room))}px`);
    };

    const liftOnPhone = () => {
      if (!phone() || liftedFor === input.value) return;
      liftedFor = input.value;
      const header = document.querySelector('.site-header');
      const offset = (header instanceof HTMLElement ? header.offsetHeight : 0) + 8;
      const top = form.getBoundingClientRect().top;
      if (top > offset + 4) {
        window.scrollBy({ top: top - offset, behavior: 'auto' });
      }
    };

    const openPanel = () => {
      suggest.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      liftOnPhone();
      fitPanel();
    };
    const closeSuggest = () => {
      suggest.hidden = true;
      suggest.innerHTML = '';
      activeIndex = -1;
      liftedFor = '';
      form.classList.remove('is-searching');
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    };

    const allLink = (href, total) => {
      const link = el('a', 'hn-suggest__all');
      link.href = href;
      link.setAttribute('role', 'option');
      link.append(icon('search'), el('span', '', typeof total === 'number' && total > 0 ? `عرض كل النتائج (${total})` : 'عرض كل النتائج'), icon('arrow_back'));
      return link;
    };

    const renderLoading = (query) => {
      suggest.innerHTML = '';
      const head = el('p', 'hn-suggest__status');
      head.append(el('span', 'hn-suggest__spinner'), el('span', '', `جارٍ البحث عن «${query}»`));
      suggest.appendChild(head);
      const skeleton = el('div', 'hn-suggest__skeleton');
      for (let i = 0; i < 3; i += 1) skeleton.appendChild(el('span'));
      suggest.appendChild(skeleton);
      openPanel();
    };

    const renderGroups = (data, query) => {
      suggest.innerHTML = '';
      activeIndex = -1;
      const groups = Array.isArray(data.groups) ? data.groups : [];
      if (!groups.length) {
        const empty = el('div', 'hn-suggest__empty');
        empty.append(icon('search_off'), el('p', '', `لا توجد نتائج لـ «${query}»`));
        suggest.appendChild(empty);
        openPanel();
        return;
      }
      groups.forEach((group) => {
        const items = Array.isArray(group.items) ? group.items.filter((item) => item && item.href && item.label) : [];
        if (!items.length) return;
        const box = el('section', `hn-suggest__group hn-suggest__group--${group.key === 'products' ? 'products' : 'chips'}`);
        const title = el('h3', 'hn-suggest__title');
        title.append(icon(group.icon || 'category'), el('span', '', group.title || ''));
        box.appendChild(title);
        const list = el('div', 'hn-suggest__items');
        items.forEach((item) => {
          const link = el('a', group.key === 'products' ? 'hn-suggest__product' : 'hn-suggest__chip');
          link.href = item.href;
          link.setAttribute('role', 'option');
          if (group.key === 'products') {
            const thumb = el('span', 'hn-suggest__thumb');
            if (item.image) {
              const img = el('img');
              img.src = item.image;
              img.alt = '';
              img.loading = 'lazy';
              img.addEventListener('error', () => img.replaceWith(icon('checkroom')));
              thumb.appendChild(img);
            } else {
              thumb.appendChild(icon('checkroom'));
            }
            const text = el('span', 'hn-suggest__text');
            text.appendChild(highlight(item.label, query));
            const meta = [item.code, item.brand].filter(Boolean).join(' · ');
            if (meta) text.appendChild(el('span', 'hn-suggest__meta', meta));
            if (item.badge) text.appendChild(el('span', 'hn-suggest__badge', item.badge));
            link.append(thumb, text);
          } else {
            link.appendChild(highlight(item.label, query));
          }
          list.appendChild(link);
        });
        box.appendChild(list);
        suggest.appendChild(box);
      });
      suggest.appendChild(allLink(allHref(query), data.total));
      suggest.querySelectorAll('a').forEach((link, index) => {
        link.id = `${suggest.id || 'hn-suggest'}-${index}`;
      });
      openPanel();
    };

    const fetchSuggest = (query) => {
      suggestController?.abort();
      suggestController = new AbortController();
      form.classList.add('is-searching');
      fetch(`/api/home-search.php?view=groups&q=${encodeURIComponent(query)}`, {
        headers: { Accept: 'application/json' },
        signal: suggestController.signal,
      })
        .then((response) => (response.ok ? response.json() : null))
        .then((data) => {
          if (input.value.trim() !== query) return;
          form.classList.remove('is-searching');
          if (!data || !data.ok) {
            suggest.innerHTML = '';
            suggest.appendChild(allLink(allHref(query)));
            openPanel();
            return;
          }
          renderGroups(data, query);
        })
        .catch((error) => {
          if (error?.name !== 'AbortError') form.classList.remove('is-searching');
        });
    };

    const onQuery = () => {
      window.clearTimeout(suggestTimer);
      const query = input.value.trim();
      if (query.length < 2) {
        suggestController?.abort();
        closeSuggest();
        return;
      }
      renderLoading(query);
      form.classList.add('is-searching');
      suggestTimer = window.setTimeout(() => fetchSuggest(query), 220);
    };
    input.addEventListener('input', onQuery);
    input.addEventListener('focus', () => {
      if (suggest.hidden && input.value.trim().length >= 2) onQuery();
    });

    input.addEventListener('keydown', (event) => {
      if (suggest.hidden) return;
      const items = Array.from(suggest.querySelectorAll('a'));
      if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && items.length) {
        event.preventDefault();
        activeIndex = (activeIndex + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        items.forEach((item, index) => item.classList.toggle('is-active', index === activeIndex));
        items[activeIndex].scrollIntoView({ block: 'nearest' });
        input.setAttribute('aria-activedescendant', items[activeIndex].id || '');
      } else if (event.key === 'Enter' && activeIndex >= 0 && items[activeIndex]) {
        event.preventDefault();
        window.location.href = items[activeIndex].href;
      } else if (event.key === 'Escape') {
        closeSuggest();
      }
    });

    document.addEventListener('click', (event) => {
      if (!form.contains(event.target)) closeSuggest();
    });
    window.visualViewport?.addEventListener('resize', fitPanel);
    window.addEventListener('resize', fitPanel);

    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    const voice = form.querySelector('[data-hn-voice]');
    if (Recognition && voice) {
      voice.hidden = false;
      let listening = null;
      const placeholder = input.placeholder;
      voice.addEventListener('click', () => {
        if (listening) {
          listening.stop();
          return;
        }
        const recognition = new Recognition();
        recognition.lang = 'ar-SY';
        recognition.interimResults = true;
        recognition.maxAlternatives = 1;
        listening = recognition;
        form.classList.add('is-listening');
        voice.setAttribute('aria-pressed', 'true');
        input.placeholder = 'أستمع…';
        recognition.onresult = (event) => {
          const result = event.results[event.results.length - 1];
          input.value = result[0].transcript.trim();
          if (result.isFinal) recognition.stop();
        };
        recognition.onend = () => {
          listening = null;
          form.classList.remove('is-listening');
          voice.setAttribute('aria-pressed', 'false');
          input.placeholder = placeholder;
          if (input.value.trim()) {
            input.focus({ preventScroll: true });
            onQuery();
          }
        };
        recognition.onerror = recognition.onend;
        recognition.start();
      });
    }
  };

  const init = () => document.querySelectorAll('form[data-hn-search]').forEach(setup);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
