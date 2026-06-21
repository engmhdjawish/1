/**
 * About page content editor — toolbar, tabs, and live preview.
 */
(function () {
  'use strict';

  const escapeHtml = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  const formatInline = (text) => {
    let safe = escapeHtml(text);
    safe = safe.replace(/\*\*(.+?)\*\*/g, '<strong class="font-extrabold text-slate-900">$1</strong>');
    safe = safe.replace(/(?<!\*)\*([^*]+?)\*(?!\*)/g, '<em class="text-slate-600">$1</em>');
    return safe;
  };

  const parseAboutContent = (text) => {
    const normalized = String(text || '').trim().replace(/\r\n/g, '\n');
    if (!normalized) {
      return { intro_paragraphs: [], sections: [] };
    }

    const chunks = normalized.split(/\n(?=##\s+)/);
    const introChunk = (chunks.shift() || '').trim();
    const introParagraphs = introChunk
      ? introChunk.split(/\n\s*\n/).map((part) => part.trim()).filter(Boolean)
      : [];

    const sections = [];
    chunks.forEach((chunk) => {
      const body = chunk.replace(/^##\s+/, '').trim();
      if (!body) return;

      const lines = body.split('\n');
      const title = (lines.shift() || '').trim();
      const section = {
        title,
        subtitle: '',
        cards: [],
        paragraphs: [],
        quote: '',
        list_items: [],
      };

      let pendingCardTitle = null;
      let paragraphBuffer = [];

      const flushParagraph = () => {
        if (!paragraphBuffer.length) return;
        section.paragraphs.push(paragraphBuffer.join('\n').trim());
        paragraphBuffer = [];
      };

      lines.forEach((rawLine) => {
        const line = rawLine.trim();
        if (!line) {
          if (pendingCardTitle !== null) {
            section.cards.push({ title: pendingCardTitle, body: '' });
            pendingCardTitle = null;
          }
          flushParagraph();
          return;
        }

        if (line === '---') {
          if (pendingCardTitle !== null) {
            section.cards.push({ title: pendingCardTitle, body: '' });
            pendingCardTitle = null;
          }
          flushParagraph();
          return;
        }

        if (line.startsWith('### ')) {
          if (pendingCardTitle !== null) {
            section.cards.push({ title: pendingCardTitle, body: '' });
            pendingCardTitle = null;
          }
          flushParagraph();
          section.subtitle = line.slice(4).trim();
          return;
        }

        if (line.startsWith('> ')) {
          if (pendingCardTitle !== null) {
            section.cards.push({ title: pendingCardTitle, body: '' });
            pendingCardTitle = null;
          }
          flushParagraph();
          section.quote = line.slice(2).trim();
          return;
        }

        const cardTitleMatch = line.match(/^\*\s*(.+?)\s*\*$/);
        if (cardTitleMatch) {
          flushParagraph();
          pendingCardTitle = cardTitleMatch[1].trim();
          return;
        }

        if (line.startsWith('- ')) {
          flushParagraph();
          const itemText = line.slice(2).trim();
          const colonPos = itemText.indexOf(':');
          if (colonPos !== -1 && colonPos < 80) {
            section.cards.push({
              title: itemText.slice(0, colonPos).trim(),
              body: itemText.slice(colonPos + 1).trim(),
            });
          } else {
            section.list_items.push(itemText);
          }
          return;
        }

        if (pendingCardTitle !== null) {
          section.cards.push({ title: pendingCardTitle, body: line });
          pendingCardTitle = null;
          return;
        }

        paragraphBuffer.push(line);
      });

      if (pendingCardTitle !== null) {
        section.cards.push({ title: pendingCardTitle, body: '' });
      }
      flushParagraph();

      if (section.title) {
        sections.push(section);
      }
    });

    return { intro_paragraphs: introParagraphs, sections };
  };

  const renderPreviewHtml = (content, pageTitle) => {
    const icons = ['category', 'verified', 'schedule', 'handshake', 'inventory_2', 'support_agent'];
    let html = '<div class="about-page-preview space-y-4 text-sm">';

    if (content.intro_paragraphs.length) {
      html += '<section class="rounded-2xl bg-gradient-to-l from-primary to-red-600 text-white p-4">';
      html += `<p class="text-xs font-bold text-white/75 mb-1">${escapeHtml(pageTitle || 'من نحن')}</p>`;
      html += `<p class="leading-7">${formatInline(content.intro_paragraphs[0])}</p>`;
      html += '</section>';
      content.intro_paragraphs.slice(1).forEach((paragraph) => {
        html += `<p class="rounded-xl border border-slate-200 bg-white p-3 leading-7 text-slate-700">${formatInline(paragraph)}</p>`;
      });
    }

    content.sections.forEach((section, sectionIndex) => {
      const hasQuoteOnly = section.quote && !section.cards.length && !section.paragraphs.length && !section.list_items.length;
      html += `<section class="${hasQuoteOnly ? '' : 'rounded-2xl border border-slate-200 bg-white overflow-hidden'}">`;

      if (!hasQuoteOnly && section.title) {
        html += '<header class="px-4 py-3 border-b border-slate-100 bg-slate-50">';
        html += `<h3 class="font-extrabold text-slate-900">${escapeHtml(section.title)}</h3>`;
        if (section.subtitle) {
          html += `<p class="text-xs text-slate-500 mt-1">${formatInline(section.subtitle)}</p>`;
        }
        html += '</header>';
      }

      if (section.cards.length) {
        html += '<div class="p-4 space-y-4">';
        section.cards.forEach((card, cardIndex) => {
          const icon = icons[cardIndex % icons.length];
          html += '<article class="grid grid-cols-[2.5rem_1fr] gap-3">';
          html += `<span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-primary/10 text-primary text-xs font-bold">${String(cardIndex + 1).padStart(2, '0')}</span>`;
          html += '<div>';
          if (card.title) {
            html += `<h4 class="font-extrabold text-slate-900 mb-1">${escapeHtml(card.title)}</h4>`;
          }
          if (card.body) {
            html += `<p class="text-slate-600 leading-7">${formatInline(card.body)}</p>`;
          }
          html += `<p class="text-[10px] text-slate-400 mt-1" dir="ltr">${escapeHtml(icon)}</p>`;
          html += '</div></article>';
        });
        html += '</div>';
      }

      section.paragraphs.forEach((paragraph) => {
        html += `<p class="px-4 pb-3 text-slate-700 leading-7">${formatInline(paragraph)}</p>`;
      });

      if (section.list_items.length) {
        html += '<ul class="px-4 pb-4 space-y-2">';
        section.list_items.forEach((item) => {
          html += `<li class="flex gap-2"><span class="mt-2 h-1.5 w-1.5 rounded-full bg-primary shrink-0"></span><span>${formatInline(item)}</span></li>`;
        });
        html += '</ul>';
      }

      if (section.quote) {
        html += '<blockquote class="m-4 rounded-xl border border-primary/15 bg-primary/5 px-4 py-4">';
        if (hasQuoteOnly && section.title) {
          html += `<p class="text-xs font-bold text-primary mb-2">${escapeHtml(section.title)}</p>`;
        }
        html += `<p class="font-bold text-slate-800 leading-8">${formatInline(section.quote)}</p>`;
        html += '</blockquote>';
      }

      html += '</section>';
    });

    if (!content.intro_paragraphs.length && !content.sections.length) {
      html += '<p class="text-slate-500 text-center py-8">لا يوجد محتوى بعد.</p>';
    }

    html += '</div>';
    return html;
  };

  const resolveEditor = (root) => {
    if (!root) return null;
    if (root.matches && root.matches('[data-about-editor]')) {
      return root;
    }
    return root.querySelector('[data-about-editor]');
  };

  const bindEditor = (root) => {
    const editor = resolveEditor(root);
    if (!editor || editor.dataset.bound === '1') return;
    editor.dataset.bound = '1';

    const textarea = editor.querySelector('[data-about-input]');
    const preview = editor.querySelector('[data-about-preview]');
    const panelsWrap = editor.querySelector('[data-about-panels]');
    const editPanel = editor.querySelector('[data-panel="edit"]');
    const previewPanel = editor.querySelector('[data-panel="preview"]');
    const tabButtons = editor.querySelectorAll('.about-editor-tab');
    const defaultButton = editor.querySelector('[data-about-load-default]');
    const pageTitleInput = document.querySelector('input[name="about_us_title_ar"]');
    let previewTimer = null;

    const updatePreview = () => {
      if (!textarea || !preview) return;
      const content = parseAboutContent(textarea.value);
      const pageTitle = pageTitleInput?.value?.trim() || 'من نحن';
      preview.innerHTML = renderPreviewHtml(content, pageTitle);
    };

    const schedulePreview = () => {
      if (previewTimer) clearTimeout(previewTimer);
      previewTimer = setTimeout(updatePreview, 180);
    };

    const setTab = (mode) => {
      tabButtons.forEach((button) => {
        const active = button.getAttribute('data-tab') === mode;
        button.classList.toggle('bg-primary', active);
        button.classList.toggle('text-white', active);
        button.classList.toggle('text-slate-600', !active);
      });

      if (mode === 'edit') {
        panelsWrap.className = 'grid grid-cols-1 min-h-[22rem]';
        editPanel.classList.remove('hidden');
        previewPanel.classList.add('hidden');
        textarea.rows = 16;
        return;
      }

      if (mode === 'preview') {
        panelsWrap.className = 'grid grid-cols-1 min-h-[22rem]';
        editPanel.classList.add('hidden');
        previewPanel.classList.remove('hidden');
        updatePreview();
        return;
      }

      panelsWrap.className = 'grid grid-cols-1 lg:grid-cols-2 min-h-[22rem]';
      editPanel.classList.remove('hidden');
      previewPanel.classList.remove('hidden');
      textarea.rows = 16;
      updatePreview();
    };

    const insertAtCursor = (snippet, wrapToken) => {
      const start = textarea.selectionStart;
      const end = textarea.selectionEnd;
      const value = textarea.value;
      const selected = value.slice(start, end);

      let nextValue;
      let cursorStart;
      let cursorEnd;

      if (wrapToken) {
        const wrapped = `${wrapToken}${selected || 'نص'}${wrapToken}`;
        nextValue = value.slice(0, start) + wrapped + value.slice(end);
        cursorStart = start + wrapToken.length;
        cursorEnd = cursorStart + (selected || 'نص').length;
      } else {
        nextValue = value.slice(0, start) + snippet + value.slice(end);
        cursorStart = start + snippet.length;
        cursorEnd = cursorStart;
      }

      textarea.value = nextValue;
      textarea.focus();
      textarea.setSelectionRange(cursorStart, cursorEnd);
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
      schedulePreview();
    };

    editor.querySelectorAll('[data-insert]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        insertAtCursor(button.getAttribute('data-insert') || '');
      });
    });

    editor.querySelectorAll('[data-wrap]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        insertAtCursor('', button.getAttribute('data-wrap') || '');
      });
    });

    editor.querySelector('[data-insert-card]')?.addEventListener('click', (event) => {
      event.preventDefault();
      insertAtCursor('\n\n*عنوان البطاقة*\nاكتب وصف البطاقة هنا...\n');
    });

    tabButtons.forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        setTab(button.getAttribute('data-tab') || 'edit');
      });
    });

    textarea?.addEventListener('input', schedulePreview);
    pageTitleInput?.addEventListener('input', schedulePreview);

    defaultButton?.addEventListener('click', (event) => {
      event.preventDefault();
      const sample = editor.getAttribute('data-default-content') || '';
      if (!sample || !window.confirm('استبدال المحتوى الحالي بالنموذج الافتراضي؟')) return;
      textarea.value = sample;
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
      setTab('split');
    });

    setTab('split');
  };

  window.portalAboutEditorInit = function portalAboutEditorInit(root = document) {
    root.querySelectorAll('[data-about-editor]').forEach((node) => bindEditor(node));
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.portalAboutEditorInit());
  } else {
    window.portalAboutEditorInit();
  }
})();
