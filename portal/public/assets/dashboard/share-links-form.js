/**
 * Share link create/edit — store-style filters and exclusive accordions.
 */
(function () {
  'use strict';

  function bindExclusiveAccordions(root) {
    if (!root || root.dataset.shareLinkAccordionsInit === '1') {
      return;
    }
    root.dataset.shareLinkAccordionsInit = '1';

    const accordions = root.querySelectorAll('[data-share-link-accordion]');
    accordions.forEach((accordion) => {
      if (!(accordion instanceof HTMLDetailsElement) || accordion.dataset.shareLinkAccordionBound === '1') {
        return;
      }
      accordion.dataset.shareLinkAccordionBound = '1';
      accordion.addEventListener('toggle', () => {
        if (!accordion.open) {
          return;
        }
        accordions.forEach((other) => {
          if (other !== accordion && other instanceof HTMLDetailsElement) {
            other.open = false;
          }
        });
      });
    });
  }

  function initFilterShells(root) {
    root.querySelectorAll('[data-store-filters-root]').forEach((shell) => {
      if (shell.dataset.shareLinkFiltersInit === '1') {
        return;
      }
      shell.dataset.shareLinkFiltersInit = '1';
      if (typeof window.portalStoreFiltersInit === 'function') {
        window.portalStoreFiltersInit(shell);
      }
    });
  }

  function bindCopyButtons(root) {
    const copyText = async (text) => {
      if (!text) {
        return false;
      }
      try {
        if (navigator.clipboard?.writeText) {
          await navigator.clipboard.writeText(text);
          return true;
        }
      } catch {
        /* ignore */
      }
      const textarea = document.createElement('textarea');
      textarea.value = text;
      textarea.setAttribute('readonly', '');
      textarea.style.position = 'fixed';
      textarea.style.opacity = '0';
      document.body.appendChild(textarea);
      textarea.select();
      let ok = false;
      try {
        ok = document.execCommand('copy');
      } catch {
        /* ignore */
      }
      textarea.remove();
      return ok;
    };

    root.querySelectorAll('[data-copy-url]').forEach((button) => {
      if (button.dataset.copyBound === '1') {
        return;
      }
      button.dataset.copyBound = '1';
      button.addEventListener('click', async () => {
        const url = button.getAttribute('data-copy-url') || '';
        const ok = await copyText(url);
        const original = button.textContent;
        button.textContent = ok ? 'تم النسخ' : 'فشل';
        window.setTimeout(() => {
          button.textContent = original;
        }, 1400);
      });
    });
  }

  window.portalShareLinksFormInit = (root = document) => {
    root.querySelectorAll('[data-share-link-form-panel]').forEach((panel) => {
      bindExclusiveAccordions(panel);
      initFilterShells(panel);
      bindCopyButtons(panel);
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.portalShareLinksFormInit(document));
  } else {
    window.portalShareLinksFormInit(document);
  }
})();
