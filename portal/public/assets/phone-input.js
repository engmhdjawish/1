/**
 * Phone fields: numeric keyboard on mobile + Arabic/Persian digit normalization.
 */
(function () {
  'use strict';

  const ARABIC_DIGITS = /[\u0660-\u0669]/g;
  const PERSIAN_DIGITS = /[\u06f0-\u06f9]/g;

  function toWesternDigits(value) {
    return String(value)
      .replace(ARABIC_DIGITS, (digit) => String(digit.charCodeAt(0) - 0x0660))
      .replace(PERSIAN_DIGITS, (digit) => String(digit.charCodeAt(0) - 0x06f0));
  }

  function normalizePhoneInput(input) {
    if (!input) return;
    const before = input.value;
    const after = toWesternDigits(before);
    if (after === before) {
      return;
    }
    const start = input.selectionStart;
    const end = input.selectionEnd;
    input.value = after;
    if (start != null && end != null) {
      try {
        input.setSelectionRange(start, end);
      } catch {
        // Some input types may reject selection ranges.
      }
    }
  }

  function bindPhoneInput(input) {
    if (!input || input.dataset.phoneInputBound === '1') {
      return;
    }
    input.dataset.phoneInputBound = '1';

    if (!input.getAttribute('type')) {
      input.type = 'tel';
    }
    if (!input.getAttribute('inputmode')) {
      input.setAttribute('inputmode', 'tel');
    }
    if (!input.getAttribute('autocomplete')) {
      input.setAttribute('autocomplete', 'tel');
    }
    if (!input.getAttribute('dir')) {
      input.setAttribute('dir', 'ltr');
    }
    if (!input.classList.contains('text-left')) {
      input.classList.add('text-left');
    }

    input.addEventListener('input', () => normalizePhoneInput(input));
    input.addEventListener('change', () => normalizePhoneInput(input));
    input.addEventListener('paste', () => {
      window.requestAnimationFrame(() => normalizePhoneInput(input));
    });
    normalizePhoneInput(input);
  }

  function phoneInputSelector() {
    return [
      'input[data-phone-input]',
      'input[type="tel"]',
      'input[name="phone"]',
      'input[name="guest_phone"]',
      'input[name="company_phone"]',
      'input[name="company_mobile"]',
    ].join(',');
  }

  window.portalPhoneInputInit = function portalPhoneInputInit(root = document) {
    root.querySelectorAll(phoneInputSelector()).forEach(bindPhoneInput);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.portalPhoneInputInit());
  } else {
    window.portalPhoneInputInit();
  }
})();
