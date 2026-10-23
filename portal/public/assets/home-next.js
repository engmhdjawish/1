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

  /* Search: grouped results */
  const form = root.querySelector('[data-hn-search]');
  const input = root.querySelector('[data-hn-search-input]');
  const suggest = root.querySelector('[data-hn-suggest]');
  let suggestTimer = 0;
  let suggestController = null;
  let activeIndex = -1;

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

  const openPanel = () => {
    if (!suggest || !input) return;
    suggest.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  };
  const closeSuggest = () => {
    if (!suggest) return;
    suggest.hidden = true;
    suggest.innerHTML = '';
    activeIndex = -1;
    form?.classList.remove('is-searching');
    input?.setAttribute('aria-expanded', 'false');
    input?.removeAttribute('aria-activedescendant');
  };

  const allLink = (href, total) => {
    const link = el('a', 'hn-suggest__all');
    link.href = href;
    link.setAttribute('role', 'option');
    link.append(icon('search'), el('span', '', typeof total === 'number' && total > 0 ? `عرض كل النتائج (${total})` : 'عرض كل النتائج'), icon('arrow_back'));
    return link;
  };

  const renderLoading = (query) => {
    if (!suggest) return;
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
    if (!suggest || !input) return;
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
    suggest.appendChild(allLink(data.all || `/store-lab.php?q=${encodeURIComponent(query)}`, data.total));
    suggest.querySelectorAll('a').forEach((link, index) => {
      link.id = `hn-suggest-${index}`;
    });
    openPanel();
  };

  const fetchSuggest = (query) => {
    suggestController?.abort();
    suggestController = new AbortController();
    form?.classList.add('is-searching');
    fetch(`/api/home-search.php?view=groups&q=${encodeURIComponent(query)}`, {
      headers: { Accept: 'application/json' },
      signal: suggestController.signal,
    })
      .then((response) => (response.ok ? response.json() : null))
      .then((data) => {
        if (input?.value.trim() !== query) return;
        form?.classList.remove('is-searching');
        if (!data || !data.ok) {
          suggest.innerHTML = '';
          suggest.appendChild(allLink(`/store-lab.php?q=${encodeURIComponent(query)}`));
          openPanel();
          return;
        }
        renderGroups(data, query);
      })
      .catch((error) => {
        if (error?.name !== 'AbortError') form?.classList.remove('is-searching');
      });
  };

  const onQuery = () => {
    if (!input) return;
    window.clearTimeout(suggestTimer);
    const query = input.value.trim();
    if (query.length < 2) {
      suggestController?.abort();
      closeSuggest();
      return;
    }
    renderLoading(query);
    form?.classList.add('is-searching');
    suggestTimer = window.setTimeout(() => fetchSuggest(query), 220);
  };
  input?.addEventListener('input', onQuery);
  input?.addEventListener('focus', () => {
    if (suggest && suggest.hidden && input.value.trim().length >= 2) onQuery();
  });

  input?.addEventListener('keydown', (event) => {
    if (!suggest || suggest.hidden) return;
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
    if (form && !form.contains(event.target)) closeSuggest();
  });

  /* Voice search: fills the box and shows results; the customer picks or presses search. */
  const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  const voice = root.querySelector('[data-hn-voice]');
  if (Recognition && voice && input && form) {
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

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const isRtl = (node) => getComputedStyle(node).direction === 'rtl';

  /* Horizontal strips: edge fades and arrows show that more items sit off screen. */
  root.querySelectorAll('[data-hn-scroller]').forEach((scroller) => {
    const track = scroller.querySelector('[data-hn-scroller-track]');
    if (!(track instanceof HTMLElement)) return;
    const prev = scroller.querySelector('[data-hn-scroller-prev]');
    const next = scroller.querySelector('[data-hn-scroller-next]');
    const sync = () => {
      const max = track.scrollWidth - track.clientWidth;
      const at = Math.abs(track.scrollLeft);
      scroller.classList.toggle('has-prev', max > 4 && at > 4);
      scroller.classList.toggle('has-next', max > 4 && at < max - 4);
    };
    const step = (dir) => {
      const amount = Math.max(160, track.clientWidth * 0.8) * dir * (isRtl(track) ? -1 : 1);
      track.scrollBy({ left: amount, behavior: reduceMotion ? 'auto' : 'smooth' });
    };
    prev?.addEventListener('click', () => step(-1));
    next?.addEventListener('click', () => step(1));
    let raf = 0;
    track.addEventListener('scroll', () => {
      window.cancelAnimationFrame(raf);
      raf = window.requestAnimationFrame(sync);
    }, { passive: true });
    window.addEventListener('resize', sync);
    sync();
    const NUDGE_KEY = 'hn-strip-nudged';
    if (!reduceMotion && scroller.classList.contains('has-next') && !store.get(NUDGE_KEY, false) && 'IntersectionObserver' in window) {
      const observer = new IntersectionObserver((entries) => {
        if (!entries.some((entry) => entry.isIntersecting)) return;
        observer.disconnect();
        store.set(NUDGE_KEY, true);
        const dir = isRtl(track) ? -1 : 1;
        window.setTimeout(() => {
          track.scrollBy({ left: 56 * dir, behavior: 'smooth' });
          window.setTimeout(() => track.scrollBy({ left: -56 * dir, behavior: 'smooth' }), 650);
        }, 500);
      }, { threshold: 0.8 });
      observer.observe(track);
    }
  });

  /* Pages that swipe left and right (step picker, shop-by). */
  const pagerOf = (wrap) => wrap?.querySelector('[data-hn-pager]');
  const pageWidth = (pager) => {
    const pages = Array.from(pager.children);
    return pages.length > 1 ? Math.abs(pages[1].offsetLeft - pages[0].offsetLeft) || pager.clientWidth : pager.clientWidth;
  };
  const syncPager = (wrap) => {
    const pager = pagerOf(wrap);
    if (!pager || pager.children.length < 2 || !pager.clientWidth) return;
    const width = pageWidth(pager);
    const visible = Math.max(1, Math.round(pager.clientWidth / width));
    const maxIndex = Math.max(0, pager.children.length - visible);
    const index = Math.min(maxIndex, Math.round(Math.abs(pager.scrollLeft) / width));
    wrap.querySelectorAll('[data-hn-pager-goto]').forEach((dot, i) => {
      dot.classList.toggle('is-active', i >= index && i < index + visible);
      if (i === index) dot.setAttribute('aria-current', 'true');
      else dot.removeAttribute('aria-current');
    });
    const prev = wrap.querySelector('[data-hn-pager-prev]');
    const nextPage = wrap.querySelector('[data-hn-pager-next]');
    if (prev) prev.disabled = index <= 0;
    if (nextPage) nextPage.disabled = index >= maxIndex;
    wrap.dataset.page = String(index);
  };
  const goToPage = (wrap, index) => {
    const pager = pagerOf(wrap);
    if (!pager || pager.children.length < 2) return;
    pager.scrollTo({ left: (isRtl(pager) ? -1 : 1) * index * pageWidth(pager), behavior: reduceMotion ? 'auto' : 'smooth' });
  };
  const pageWraps = Array.from(root.querySelectorAll('[data-hn-pages]'));
  pageWraps.forEach((wrap) => {
    const pager = pagerOf(wrap);
    let raf = 0;
    pager?.addEventListener('scroll', () => {
      window.cancelAnimationFrame(raf);
      raf = window.requestAnimationFrame(() => syncPager(wrap));
    }, { passive: true });
    syncPager(wrap);
  });
  window.addEventListener('resize', () => pageWraps.forEach(syncPager));
  root.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const wrap = target?.closest('[data-hn-pages]');
    if (!wrap) return;
    const goto = target.closest('[data-hn-pager-goto]');
    if (goto) {
      goToPage(wrap, Number(goto.getAttribute('data-hn-pager-goto')) || 0);
      return;
    }
    const prev = target.closest('[data-hn-pager-prev]');
    const nextPage = target.closest('[data-hn-pager-next]');
    if (prev || nextPage) goToPage(wrap, (Number(wrap.dataset.page) || 0) + (nextPage ? 1 : -1));
  });

  /* Shop-by tabs */
  root.querySelectorAll('[data-hn-shop]').forEach((shop) => {
    const tabs = Array.from(shop.querySelectorAll('[data-hn-shop-tab]'));
    const select = (tab, focus) => {
      tabs.forEach((other) => {
        const on = other === tab;
        other.classList.toggle('is-active', on);
        other.setAttribute('aria-selected', on ? 'true' : 'false');
        other.tabIndex = on ? 0 : -1;
        const panel = document.getElementById(other.getAttribute('aria-controls') || '');
        if (panel) panel.hidden = !on;
        if (on && panel) panel.querySelectorAll('[data-hn-pages]').forEach(syncPager);
      });
      if (focus) tab.focus();
      tab.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    };
    tabs.forEach((tab, index) => {
      tab.addEventListener('click', () => select(tab, false));
      tab.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        const forward = (event.key === 'ArrowLeft') === isRtl(tab);
        select(tabs[(index + (forward ? 1 : -1) + tabs.length) % tabs.length], true);
      });
    });
  });

  /* Step-by-step picker */
  const guide = root.querySelector('[data-hn-guide]');
  if (guide) {
    const steps = Array.from(guide.querySelectorAll('[data-hn-guide-step]'));
    const dots = Array.from(guide.querySelectorAll('[data-hn-guide-dot]'));
    const result = guide.querySelector('[data-hn-guide-result]');
    const chosenBox = guide.querySelector('[data-hn-guide-chosen]');
    const back = guide.querySelector('[data-hn-guide-back]');
    const next = guide.querySelector('[data-hn-guide-next]');
    const nextLabel = guide.querySelector('[data-hn-guide-next-label]');
    const show = guide.querySelector('[data-hn-guide-show]');
    const available = guide.querySelector('[data-hn-guide-available]');
    const seasonOnly = guide.querySelector('[data-hn-guide-season]');
    const action = guide.getAttribute('data-action') || '/store-lab.php';
    const choices = new Map(steps.map((step) => [step.getAttribute('data-param'), new Set()]));
    let current = 0;

    const query = () => {
      const params = new URLSearchParams();
      choices.forEach((values, param) => {
        values.forEach((value) => params.append(`${param}[]`, value));
      });
      if (available instanceof HTMLInputElement && available.checked) params.set('isAvailable', '1');
      if (seasonOnly instanceof HTMLInputElement && seasonOnly.checked) params.set('season', '1');
      return params.toString();
    };

    const render = () => {
      steps.forEach((step, index) => {
        step.hidden = index !== current;
        const picked = choices.get(step.getAttribute('data-param')) || new Set();
        step.querySelectorAll('[data-hn-guide-value]').forEach((option) => {
          const on = picked.has(option.getAttribute('data-hn-guide-value') || '');
          option.classList.toggle('is-picked', on);
          option.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        const badge = step.querySelector('[data-hn-guide-picked]');
        if (badge) {
          badge.hidden = picked.size === 0;
          badge.textContent = `اخترت ${picked.size}`;
        }
      });
      dots.forEach((dot, index) => {
        dot.classList.toggle('is-current', index === current);
        dot.classList.toggle('is-done', index < current || current >= steps.length);
      });
      const atResult = current >= steps.length;
      if (result) result.hidden = !atResult;
      if (back) back.hidden = current === 0;
      if (next) next.hidden = atResult;
      if (nextLabel && !atResult) {
        const picked = choices.get(steps[current].getAttribute('data-param'));
        nextLabel.textContent = picked && picked.size > 0 ? 'التالي' : 'الكل';
      }
      if (show) {
        show.hidden = !atResult;
        const qs = query();
        show.href = qs ? `${action}?${qs}` : action;
      }
      if (chosenBox && atResult) {
        chosenBox.innerHTML = '';
        steps.forEach((step, index) => {
          const picked = Array.from(choices.get(step.getAttribute('data-param')) || []);
          const chip = document.createElement('button');
          chip.type = 'button';
          chip.className = 'hn-guide__chip';
          const question = step.querySelector('.hn-guide__question')?.textContent || '';
          chip.textContent = `${question} ${picked.length ? picked.join('، ') : 'الكل'}`;
          chip.addEventListener('click', () => {
            current = index;
            render();
          });
          chosenBox.appendChild(chip);
        });
      }
      if (!atResult) window.requestAnimationFrame(() => syncPager(steps[current]));
    };

    const move = (to) => {
      current = Math.max(0, Math.min(steps.length, to));
      render();
      const top = guide.getBoundingClientRect().top;
      if (top < 0 || top > window.innerHeight * 0.4) {
        guide.scrollIntoView({ block: 'start', behavior: reduceMotion ? 'auto' : 'smooth' });
      }
    };

    guide.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;
      const option = target.closest('[data-hn-guide-value]');
      if (option) {
        const step = option.closest('[data-hn-guide-step]');
        const picked = choices.get(step?.getAttribute('data-param'));
        const value = option.getAttribute('data-hn-guide-value') || '';
        if (picked) {
          if (picked.has(value)) picked.delete(value);
          else picked.add(value);
        }
        render();
        return;
      }
      if (target.closest('[data-hn-guide-next]')) {
        move(current + 1);
        return;
      }
      if (target.closest('[data-hn-guide-back]')) {
        move(current - 1);
      }
    });
    available?.addEventListener('change', render);
    seasonOnly?.addEventListener('change', render);
    render();
  }

  /* All products: one batch from the trial store, loaded when the section comes near. */
  const feed = root.querySelector('[data-hn-feed]');
  if (feed) {
    const grid = feed.querySelector('[data-hn-feed-grid]');
    const skeleton = feed.querySelector('[data-hn-feed-skeleton]');
    const limit = Math.max(1, Number(feed.getAttribute('data-limit')) || 12);
    let started = false;

    const load = async () => {
      if (started || !grid) return;
      started = true;
      feed.classList.add('is-loading');
      try {
        const response = await fetch(feed.getAttribute('data-src') || '/store-lab.php', { credentials: 'same-origin', headers: { Accept: 'text/html' } });
        if (!response.ok) throw new Error(String(response.status));
        const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
        const live = doc.querySelector('[data-se-live]');
        const cards = live ? Array.from(live.querySelectorAll('.se-grid > *')).slice(0, limit) : [];
        const fragment = document.createDocumentFragment();
        cards.forEach((card) => fragment.appendChild(document.importNode(card, true)));
        grid.appendChild(fragment);
        window.StoreCart?.bindAddForms?.();
        window.StoreCart?.bindQtySteppers?.(grid);
        window.StoreImageZoom?.seedLoadedImages?.(grid);
        if (cards.length === 0) feed.hidden = true;
      } catch {
        feed.hidden = true;
      } finally {
        feed.classList.remove('is-loading');
        if (skeleton) skeleton.hidden = true;
      }
    };

    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver((entries) => {
        if (!entries.some((entry) => entry.isIntersecting)) return;
        observer.disconnect();
        load();
      }, { rootMargin: '600px 0px' });
      observer.observe(feed);
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

  if (window.location.hash === '#hn-sections') {
    history.replaceState(null, '', window.location.pathname + window.location.search);
    openSheet(null);
  }

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
