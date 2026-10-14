(() => {
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const icons = {
    section: 'bookmark',
    discover: 'explore',
    type: 'category',
    age: 'face',
    brand: 'factory',
    size: 'straighten',
    origin: 'public',
    product: 'inventory_2',
    search: 'search',
  };

  const normalize = (value) => value
    .trim()
    .toLowerCase()
    .replace(/[أإآٱ]/g, 'ا')
    .replace(/ة/g, 'ه')
    .replace(/ى/g, 'ي')
    .replace(/ؤ/g, 'و')
    .replace(/ئ/g, 'ي')
    .replace(/[\u064B-\u0652\u0670]/g, '')
    .replace(/[^\p{L}\p{N}]+/gu, ' ')
    .replace(/\s+/g, ' ')
    .trim();

  const rankLocal = (query, candidates) => {
    const normalizedQuery = normalize(query);
    if (!normalizedQuery) {
      return {
        guess: '',
        suggestions: candidates.slice(0, 6).map((item) => ({ ...item, kind: 'discover' })),
      };
    }
    const scored = candidates
      .map((item) => {
        const label = normalize(item.label || '');
        let score = 0;
        if (label === normalizedQuery) score = 100;
        else if (label.startsWith(normalizedQuery)) score = 86;
        else if (label.includes(normalizedQuery)) score = 68;
        return { item, score, label };
      })
      .filter((row) => row.score >= 45)
      .sort((a, b) => b.score - a.score || a.label.localeCompare(b.label, 'ar'));
    const suggestions = scored.slice(0, 7).map((row) => row.item);
    suggestions.push({
      kind: 'search',
      label: `ابحث عن «${query.trim()}»`,
      hint: 'في كل المواد',
      href: `/store.php?q=${encodeURIComponent(query.trim())}`,
    });
    const guess = scored.find((row) => row.score >= 60 && row.label !== normalizedQuery)?.item.label || '';
    return { guess, suggestions };
  };

  const initSearch = () => {
    const form = document.querySelector('[data-hl-search]');
    const input = document.querySelector('[data-hl-search-input]');
    const panel = document.querySelector('[data-hl-suggest]');
    const dictionaryNode = document.getElementById('hl-search-dictionary');
    if (!(form instanceof HTMLFormElement) || !(input instanceof HTMLInputElement) || !(panel instanceof HTMLElement)) {
      return;
    }

    let dictionary = [];
    try {
      dictionary = JSON.parse(dictionaryNode?.textContent || '[]');
    } catch {
      dictionary = [];
    }
    const ghost = form.querySelector('[data-hl-ghost]');
    const accept = form.querySelector('[data-hl-accept]');
    let suggestions = [];
    let activeIndex = -1;
    let requestId = 0;
    let remoteTimer = 0;
    let currentGuess = '';

    const safeHref = (href) => (
      typeof href === 'string' && href.startsWith('/') && !href.startsWith('//') ? href : '/store.php'
    );

    const caretAtEnd = () => {
      try {
        return input.selectionStart === input.value.length && input.selectionEnd === input.value.length;
      } catch {
        return true;
      }
    };

    const suffixFor = (value, guess) => {
      if (!value || !guess || guess === value) return '';
      if (guess.startsWith(value)) return guess.slice(value.length);
      const normalizedQuery = normalize(value);
      const normalizedGuess = normalize(guess);
      if (!normalizedQuery || !normalizedGuess.startsWith(normalizedQuery) || normalizedGuess === normalizedQuery) {
        return '';
      }
      let acc = '';
      const chars = Array.from(guess);
      for (let index = 0; index < chars.length; index += 1) {
        acc += chars[index];
        const next = normalize(acc);
        if (!next) continue;
        if (next === normalizedQuery) return chars.slice(index + 1).join('');
        if (!normalizedQuery.startsWith(next)) return '';
      }
      return '';
    };

    const splitMatch = (label, query) => {
      const value = query.trim();
      if (!value || !label) return null;
      if (label.startsWith(value)) return { hit: value, rest: label.slice(value.length) };
      const normalizedQuery = normalize(value);
      const normalizedLabel = normalize(label);
      if (!normalizedQuery || !normalizedLabel.startsWith(normalizedQuery)) return null;
      let acc = '';
      const chars = Array.from(label);
      for (let index = 0; index < chars.length; index += 1) {
        acc += chars[index];
        const next = normalize(acc);
        if (!next) continue;
        if (next === normalizedQuery) {
          return { hit: chars.slice(0, index + 1).join(''), rest: chars.slice(index + 1).join('') };
        }
        if (!normalizedQuery.startsWith(next)) return null;
      }
      return null;
    };

    const hidePanel = () => {
      panel.hidden = true;
      form.classList.remove('is-open');
      input.setAttribute('aria-expanded', 'false');
      activeIndex = -1;
    };

    const paintGuess = () => {
      const value = input.value;
      const suffix = currentGuess && caretAtEnd() ? suffixFor(value, currentGuess) : '';
      if (ghost instanceof HTMLElement) {
        ghost.replaceChildren();
        if (suffix) {
          const typed = document.createElement('span');
          typed.className = 'is-typed';
          typed.textContent = value;
          const rest = document.createElement('span');
          rest.className = 'is-rest';
          rest.textContent = suffix;
          ghost.append(typed, rest);
          ghost.hidden = false;
        } else {
          ghost.hidden = true;
        }
      }
      if (accept instanceof HTMLButtonElement) {
        const show = currentGuess !== '' && normalize(currentGuess) !== normalize(value);
        accept.hidden = !show;
        accept.setAttribute('aria-label', show ? `إكمال: ${currentGuess}` : 'إكمال');
      }
    };

    const setGuess = (label) => {
      currentGuess = label && normalize(label) !== normalize(input.value) ? label : '';
      paintGuess();
    };

    const render = (nextSuggestions, guess) => {
      suggestions = nextSuggestions;
      activeIndex = suggestions.length > 0 ? 0 : -1;
      setGuess(guess || '');
      if (suggestions.length === 0) {
        hidePanel();
        return;
      }
      panel.replaceChildren();
      const head = document.createElement('div');
      head.className = 'hl-suggest__head';
      head.textContent = input.value.trim() ? 'توقعات من المتجر' : 'اكتشف الأقسام';
      panel.append(head);
      suggestions.forEach((item, index) => {
        const link = document.createElement('a');
        link.className = `hl-suggest__item${index === activeIndex ? ' is-active' : ''}`;
        link.setAttribute('role', 'option');
        link.href = safeHref(item.href);
        if (index === activeIndex) link.setAttribute('aria-selected', 'true');
        const mark = document.createElement('span');
        mark.className = 'hl-suggest__mark';
        const icon = document.createElement('span');
        icon.className = 'material-symbols-outlined';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = icons[item.kind] || 'search';
        mark.append(icon);
        const copy = document.createElement('span');
        copy.className = 'hl-suggest__copy';
        const label = document.createElement('span');
        label.className = 'hl-suggest__label';
        const parts = splitMatch(item.label || '', input.value);
        if (parts && parts.rest) {
          const hit = document.createElement('span');
          hit.className = 'hl-suggest__hit';
          hit.textContent = parts.hit;
          label.append(hit, document.createTextNode(parts.rest));
        } else {
          label.textContent = item.label || '';
        }
        const hint = document.createElement('span');
        hint.className = 'hl-suggest__hint';
        hint.textContent = item.hint || '';
        copy.append(label, hint);
        link.append(mark, copy);
        if (currentGuess && normalize(item.label || '') === normalize(currentGuess)) {
          const badge = document.createElement('span');
          badge.className = 'hl-suggest__badge';
          badge.textContent = 'متوقع';
          link.append(badge);
        }
        const go = document.createElement('span');
        go.className = 'material-symbols-outlined hl-suggest__go';
        go.setAttribute('aria-hidden', 'true');
        go.textContent = 'chevron_left';
        link.append(go);
        panel.append(link);
      });
      panel.hidden = false;
      form.classList.add('is-open');
      input.setAttribute('aria-expanded', 'true');
    };

    const paintActive = () => {
      panel.querySelectorAll('.hl-suggest__item').forEach((node, index) => {
        const active = index === activeIndex;
        node.classList.toggle('is-active', active);
        if (active) node.setAttribute('aria-selected', 'true');
        else node.removeAttribute('aria-selected');
      });
    };

    const showForQuery = (query) => {
      const local = rankLocal(query, dictionary);
      render(local.suggestions, local.guess);
    };

    const fetchRemote = (query) => {
      const current = ++requestId;
      const url = `/api/home-search.php?q=${encodeURIComponent(query)}`;
      const timeout = window.AbortSignal?.timeout ? AbortSignal.timeout(8000) : undefined;
      fetch(url, { headers: { Accept: 'application/json' }, signal: timeout })
        .then((response) => response.json())
        .then((data) => {
          if (current !== requestId || input.value.trim() !== query) return;
          if (!data?.ok || !Array.isArray(data.suggestions)) return;
          render(data.suggestions, data.guess || '');
        })
        .catch(() => {});
    };

    const scheduleRemote = (query) => {
      window.clearTimeout(remoteTimer);
      remoteTimer = window.setTimeout(() => fetchRemote(query), 180);
    };

    input.addEventListener('focus', () => {
      showForQuery(input.value.trim());
      scheduleRemote(input.value.trim());
    });
    input.addEventListener('input', () => {
      const query = input.value.trim();
      showForQuery(query);
      scheduleRemote(query);
    });
    const applyGuess = () => {
      if (!currentGuess) return;
      input.value = currentGuess;
      input.focus();
      try {
        input.setSelectionRange(input.value.length, input.value.length);
      } catch {
        /* search inputs can reject a selection range */
      }
      showForQuery(input.value.trim());
      scheduleRemote(input.value.trim());
    };
    accept?.addEventListener('click', applyGuess);
    const revealSearch = () => {
      input.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'center' });
    };
    document.querySelectorAll('[data-hl-prompt]').forEach((button) => {
      button.addEventListener('click', () => {
        input.value = button.getAttribute('data-hl-prompt') || '';
        input.focus();
        showForQuery(input.value.trim());
        scheduleRemote(input.value.trim());
        revealSearch();
      });
    });
    document.querySelectorAll('[data-hl-focus-search]').forEach((button) => {
      button.addEventListener('click', () => {
        input.focus();
        showForQuery(input.value.trim());
        scheduleRemote(input.value.trim());
        revealSearch();
      });
    });
    input.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        if (panel.hidden || suggestions.length === 0) return;
        event.preventDefault();
        const delta = event.key === 'ArrowDown' ? 1 : -1;
        activeIndex = (activeIndex + delta + suggestions.length) % suggestions.length;
        paintActive();
        return;
      }
      if (event.key === 'Tab' && !event.shiftKey && accept instanceof HTMLButtonElement && !accept.hidden) {
        event.preventDefault();
        applyGuess();
        return;
      }
      if (event.key === 'Escape') {
        hidePanel();
        return;
      }
      if (event.key === 'Enter' && !panel.hidden && suggestions[activeIndex]?.href) {
        event.preventDefault();
        window.location.href = safeHref(suggestions[activeIndex].href);
      }
    });
    input.addEventListener('keyup', paintGuess);
    input.addEventListener('click', paintGuess);
    input.addEventListener('select', paintGuess);
    document.addEventListener('click', (event) => {
      if (!(event.target instanceof Node) || form.contains(event.target)) return;
      hidePanel();
    });

    if (!reducedMotion && dictionary.length > 1) {
      let promptIndex = 0;
      window.setInterval(() => {
        if (document.activeElement === input || input.value.trim() !== '') return;
        promptIndex = (promptIndex + 1) % Math.min(dictionary.length, 6);
        input.placeholder = `جرّب: ${dictionary[promptIndex].label}`;
      }, 2800);
    }
  };

  const initStickyNav = () => {
    const header = document.querySelector('.site-header');
    const sync = () => {
      const height = header instanceof HTMLElement ? header.offsetHeight : 0;
      document.documentElement.style.setProperty('--hl-nav-top', `${height + 8}px`);
    };
    sync();
    window.addEventListener('resize', sync);
    if (header instanceof HTMLElement && 'ResizeObserver' in window) {
      const observer = new ResizeObserver(sync);
      observer.observe(header);
    }
  };

  const initSectionWatch = () => {
    const links = Array.from(document.querySelectorAll('.home-section-nav__link'));
    if (links.length === 0 || !('IntersectionObserver' in window)) return;
    const byId = new Map(links.map((link) => [link.getAttribute('href'), link]));
    const mark = (id) => {
      const link = byId.get(`#${id}`);
      if (!link) return;
      links.forEach((item) => item.classList.toggle('is-active', item === link));
    };
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) mark(entry.target.id);
      });
    }, { rootMargin: '-35% 0px -50% 0px', threshold: 0.15 });
    document.querySelectorAll('.home-sections .home-section[id]').forEach((section) => observer.observe(section));
  };

  const init = () => {
    initStickyNav();
    initSearch();
    initSectionWatch();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
