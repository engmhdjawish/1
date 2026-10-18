(() => {
  const root = document.querySelector('[data-hn]');
  if (!root) return;

  const store = {
    get(key, fallback) {
      try {
        const value = JSON.parse(window.localStorage.getItem(key) || 'null');
        return value ?? fallback;
      } catch {
        return fallback;
      }
    },
    set(key, value) {
      try {
        window.localStorage.setItem(key, JSON.stringify(value));
      } catch {
        // Private mode: nothing to keep.
      }
    },
  };

  const header = document.querySelector('.site-header');
  const syncTop = () => {
    const height = header instanceof HTMLElement ? header.offsetHeight : 0;
    document.documentElement.style.setProperty('--hn-top', `${height}px`);
  };
  syncTop();
  window.addEventListener('resize', syncTop);
  if (header instanceof HTMLElement && 'ResizeObserver' in window) {
    new ResizeObserver(syncTop).observe(header);
  }

  /* Search suggestions */
  const form = root.querySelector('[data-hn-search]');
  const input = root.querySelector('[data-hn-search-input]');
  const suggest = root.querySelector('[data-hn-suggest]');
  let suggestTimer = 0;
  let suggestController = null;
  let activeIndex = -1;

  const closeSuggest = () => {
    if (!suggest) return;
    suggest.hidden = true;
    suggest.innerHTML = '';
    activeIndex = -1;
    input?.setAttribute('aria-expanded', 'false');
  };

  const renderSuggest = (items) => {
    if (!suggest || !input) return;
    suggest.innerHTML = '';
    items.slice(0, 7).forEach((item, index) => {
      const link = document.createElement('a');
      link.className = 'hn-suggest__item';
      link.href = item.href;
      link.id = `hn-suggest-${index}`;
      link.setAttribute('role', 'option');
      const label = document.createElement('span');
      label.className = 'hn-suggest__label';
      label.textContent = item.label;
      link.appendChild(label);
      if (item.hint) {
        const hint = document.createElement('span');
        hint.className = 'hn-suggest__hint';
        hint.textContent = item.hint;
        link.appendChild(hint);
      }
      suggest.appendChild(link);
    });
    suggest.hidden = items.length === 0;
    input.setAttribute('aria-expanded', items.length ? 'true' : 'false');
  };

  const fetchSuggest = (query) => {
    suggestController?.abort();
    suggestController = new AbortController();
    fetch(`/api/home-search.php?q=${encodeURIComponent(query)}&surface=lab`, {
      headers: { Accept: 'application/json' },
      signal: suggestController.signal,
    })
      .then((response) => (response.ok ? response.json() : null))
      .then((data) => {
        if (!data || input?.value.trim() !== query) return;
        renderSuggest(Array.isArray(data.suggestions) ? data.suggestions.filter((item) => item && item.href && item.label) : []);
      })
      .catch(() => {});
  };

  input?.addEventListener('input', () => {
    window.clearTimeout(suggestTimer);
    const query = input.value.trim();
    if (query.length < 2) {
      closeSuggest();
      return;
    }
    suggestTimer = window.setTimeout(() => fetchSuggest(query), 160);
  });

  input?.addEventListener('keydown', (event) => {
    if (!suggest || suggest.hidden) return;
    const items = Array.from(suggest.querySelectorAll('.hn-suggest__item'));
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      activeIndex = (activeIndex + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
      items.forEach((item, index) => item.classList.toggle('is-active', index === activeIndex));
      input.setAttribute('aria-activedescendant', items[activeIndex]?.id || '');
    } else if (event.key === 'Enter' && activeIndex >= 0 && items[activeIndex]) {
      event.preventDefault();
      window.location.href = items[activeIndex].href;
    } else if (event.key === 'Escape') {
      closeSuggest();
    }
  });

  document.addEventListener('click', (event) => {
    if (form && !form.contains(event.target)) closeSuggest();
  });

  /* Voice search */
  const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  const voice = root.querySelector('[data-hn-voice]');
  if (Recognition && voice && input && form) {
    voice.hidden = false;
    let listening = null;
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
      voice.classList.add('is-listening');
      voice.setAttribute('aria-pressed', 'true');
      recognition.onresult = (event) => {
        const result = event.results[event.results.length - 1];
        input.value = result[0].transcript.trim();
        if (result.isFinal && input.value) {
          form.requestSubmit ? form.requestSubmit() : form.submit();
        }
      };
      recognition.onend = () => {
        listening = null;
        voice.classList.remove('is-listening');
        voice.setAttribute('aria-pressed', 'false');
      };
      recognition.onerror = recognition.onend;
      recognition.start();
    });
  }

  const focusSearch = () => {
    if (!input) return;
    const top = input.getBoundingClientRect().top + window.scrollY;
    const offset = (document.querySelector('.site-header')?.offsetHeight || 0) + 16;
    window.scrollTo({ top: Math.max(0, top - offset), behavior: 'smooth' });
    input.focus({ preventScroll: true });
  };

  /* Stories: grey ring once opened */
  const SEEN_KEY = 'hn-seen-stories';
  const seen = new Set(store.get(SEEN_KEY, []));
  root.querySelectorAll('[data-hn-story]').forEach((story) => {
    if (seen.has(story.getAttribute('data-hn-story'))) story.classList.add('is-seen');
  });
  root.addEventListener('click', (event) => {
    const story = event.target instanceof Element ? event.target.closest('[data-hn-story]') : null;
    if (!story) return;
    seen.add(story.getAttribute('data-hn-story'));
    store.set(SEEN_KEY, Array.from(seen).slice(-60));
  });

  /* Offer countdown */
  const daysLabel = (days) => {
    if (days <= 1) return 'يوم واحد';
    if (days === 2) return 'يومين';
    if (days <= 10) return `${days} أيام`;
    return `${days} يوماً`;
  };
  const pad = (n) => String(n).padStart(2, '0');
  const timers = Array.from(root.querySelectorAll('[data-hn-countdown]')).map((node) => {
    const raw = (node.getAttribute('data-hn-countdown') || '').trim().replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00');
    return { node, end: Date.parse(raw), text: node.querySelector('[data-hn-countdown-text]') };
  }).filter((timer) => Number.isFinite(timer.end) && timer.text);
  const tick = () => {
    const now = Date.now();
    timers.forEach((timer) => {
      const left = Math.floor((timer.end - now) / 1000);
      if (left <= 0) {
        timer.node.hidden = true;
        return;
      }
      timer.text.textContent = left >= 86400
        ? `ينتهي خلال ${daysLabel(Math.floor(left / 86400))}`
        : `ينتهي خلال ${pad(Math.floor(left / 3600))}:${pad(Math.floor((left % 3600) / 60))}:${pad(left % 60)}`;
      timer.node.classList.toggle('is-urgent', left < 86400);
    });
  };
  if (timers.length) {
    tick();
    window.setInterval(tick, 1000);
  }

  /* Step-by-step picker */
  const guide = root.querySelector('[data-hn-guide]');
  if (guide) {
    const steps = Array.from(guide.querySelectorAll('[data-hn-guide-step]'));
    const dots = Array.from(guide.querySelectorAll('[data-hn-guide-dot]'));
    const result = guide.querySelector('[data-hn-guide-result]');
    const chosenBox = guide.querySelector('[data-hn-guide-chosen]');
    const back = guide.querySelector('[data-hn-guide-back]');
    const show = guide.querySelector('[data-hn-guide-show]');
    const available = guide.querySelector('[data-hn-guide-available]');
    const action = guide.getAttribute('data-action') || '/store-lab.php';
    const choices = new Map();
    let current = 0;

    const buildUrl = () => {
      const params = new URLSearchParams();
      choices.forEach((value, param) => {
        if (value) params.append(`${param}[]`, value);
      });
      if (available instanceof HTMLInputElement && available.checked) params.set('isAvailable', '1');
      const query = params.toString();
      return query ? `${action}?${query}` : action;
    };

    const render = () => {
      steps.forEach((step, index) => { step.hidden = index !== current; });
      dots.forEach((dot, index) => {
        dot.classList.toggle('is-current', index === current);
        dot.classList.toggle('is-done', index < current || current >= steps.length);
      });
      if (result) result.hidden = current < steps.length;
      if (back) back.hidden = current === 0;
      if (show) {
        show.hidden = choices.size === 0;
        show.href = buildUrl();
      }
      if (chosenBox && current >= steps.length) {
        chosenBox.innerHTML = '';
        steps.forEach((step, index) => {
          const chip = document.createElement('button');
          chip.type = 'button';
          chip.className = 'hn-guide__chip';
          const question = step.querySelector('.hn-guide__question')?.textContent || '';
          chip.textContent = `${question} ${choices.get(step.getAttribute('data-param')) || 'الكل'}`;
          chip.addEventListener('click', () => {
            current = index;
            render();
          });
          chosenBox.appendChild(chip);
        });
      }
      const target = current < steps.length ? steps[current] : result;
      target?.querySelectorAll('.hn-guide__option').forEach((option) => {
        const param = steps[current]?.getAttribute('data-param');
        option.classList.toggle('is-picked', param !== undefined && choices.has(param) && choices.get(param) === option.getAttribute('data-hn-guide-value'));
      });
    };

    guide.addEventListener('click', (event) => {
      const option = event.target instanceof Element ? event.target.closest('[data-hn-guide-value]') : null;
      if (option) {
        event.preventDefault();
        const step = option.closest('[data-hn-guide-step]');
        choices.set(step.getAttribute('data-param'), option.getAttribute('data-hn-guide-value') || '');
        current = Math.min(steps.length, steps.indexOf(step) + 1);
        render();
        guide.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        return;
      }
      if (event.target instanceof Element && event.target.closest('[data-hn-guide-back]')) {
        current = Math.max(0, current - 1);
        render();
      }
    });
    available?.addEventListener('change', render);
    render();
  }

  /* Endless feed of all products */
  const feed = root.querySelector('[data-hn-feed]');
  if (feed) {
    const grid = feed.querySelector('[data-hn-feed-grid]');
    const skeleton = feed.querySelector('[data-hn-feed-skeleton]');
    const moreBtn = feed.querySelector('[data-hn-feed-more]');
    const storeLink = feed.querySelector('[data-hn-feed-store]');
    const sentinel = feed.querySelector('[data-hn-feed-sentinel]');
    const AUTO_PAGES = 3;
    let nextUrl = feed.getAttribute('data-src') || '/store-lab.php';
    let pages = 0;
    let busy = false;

    const finish = () => {
      if (skeleton) skeleton.hidden = true;
      if (moreBtn) moreBtn.hidden = !nextUrl || pages < AUTO_PAGES;
      if (storeLink) storeLink.hidden = Boolean(nextUrl) && pages < AUTO_PAGES;
    };

    const load = async () => {
      if (busy || !nextUrl || !grid) return;
      busy = true;
      feed.classList.add('is-loading');
      try {
        const response = await fetch(nextUrl, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
        if (!response.ok) throw new Error(String(response.status));
        const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
        const live = doc.querySelector('[data-se-live]');
        const cards = live ? Array.from(live.querySelectorAll('.se-grid > *')) : [];
        const fragment = document.createDocumentFragment();
        cards.forEach((card) => fragment.appendChild(document.importNode(card, true)));
        grid.appendChild(fragment);
        nextUrl = live?.getAttribute('data-se-next') || '';
        pages += 1;
        window.StoreCart?.bindAddForms?.();
        window.StoreCart?.bindQtySteppers?.(grid);
        window.StoreImageZoom?.seedLoadedImages?.(grid);
        if (cards.length === 0 && pages === 1) feed.hidden = true;
      } catch {
        nextUrl = '';
      } finally {
        busy = false;
        feed.classList.remove('is-loading');
        finish();
      }
      if (nextUrl && pages < AUTO_PAGES && sentinel && sentinel.getBoundingClientRect().top < window.innerHeight + 700) {
        window.setTimeout(load, 50);
      }
    };

    moreBtn?.addEventListener('click', () => {
      pages = 0;
      if (moreBtn) moreBtn.hidden = true;
      load();
    });

    if ('IntersectionObserver' in window && sentinel) {
      const observer = new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting) && pages < AUTO_PAGES) load();
      }, { rootMargin: '700px 0px' });
      observer.observe(sentinel);
    } else {
      load();
    }
  }

  /* Category browser sheet */
  const sheet = document.querySelector('[data-hn-sheet]');
  let sheetReturn = null;
  const openSheet = (trigger) => {
    if (!sheet) return;
    sheetReturn = trigger;
    sheet.hidden = false;
    document.documentElement.classList.add('hn-sheet-open');
    sheet.querySelector('[data-hn-sheet-close]')?.focus();
  };
  const closeSheet = () => {
    if (!sheet || sheet.hidden) return;
    sheet.hidden = true;
    document.documentElement.classList.remove('hn-sheet-open');
    sheetReturn?.focus?.();
  };

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    const opener = target.closest('[data-hn-sheet-open]');
    if (opener) {
      openSheet(opener);
      return;
    }
    if (target.closest('[data-hn-sheet-close]') || (sheet && !sheet.hidden && !sheet.contains(target))) {
      closeSheet();
      return;
    }
    const paneTab = target.closest('[data-hn-pane-tab]');
    if (paneTab && sheet) {
      sheet.querySelectorAll('[data-hn-pane-tab]').forEach((tab) => {
        const active = tab === paneTab;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        const pane = document.getElementById(tab.getAttribute('aria-controls') || '');
        if (pane) pane.hidden = !active;
      });
      sheet.querySelector('.hn-sheet__panes')?.scrollTo({ top: 0 });
      return;
    }
    if (target.closest('[data-hn-focus-search]')) {
      focusSearch();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeSheet();
  });

  /* Larger text */
  const sizeBtn = root.querySelector('[data-hn-text-size]');
  const sizeLabel = root.querySelector('[data-hn-text-size-label]');
  const syncSize = () => {
    const large = document.documentElement.classList.contains('hn-text-lg');
    sizeBtn?.setAttribute('aria-pressed', large ? 'true' : 'false');
    if (sizeLabel) sizeLabel.textContent = large ? 'خط عادي' : 'تكبير الخط';
  };
  sizeBtn?.addEventListener('click', () => {
    const large = !document.documentElement.classList.contains('hn-text-lg');
    document.documentElement.classList.toggle('hn-text-lg', large);
    try {
      window.localStorage.setItem('hn-text', large ? 'lg' : '');
    } catch {
      // Ignore.
    }
    syncSize();
  });
  syncSize();
})();
