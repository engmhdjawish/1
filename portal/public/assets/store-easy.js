(function () {
  'use strict';

  const init = () => {
    const bar = document.querySelector('[data-se-bar]');
    if (!(bar instanceof HTMLFormElement)) return;

    const pills = Array.from(bar.querySelectorAll('[data-se-pill]'));
    const submit = () => {
      bar.classList.add('is-loading');
      if (typeof bar.requestSubmit === 'function') {
        bar.requestSubmit();
      } else {
        bar.submit();
      }
    };

    pills.forEach((pill) => {
      pill.addEventListener('toggle', () => {
        if (!pill.open) {
          if (!pills.some((other) => other.open)) bar.classList.remove('has-open');
          return;
        }
        pills.forEach((other) => {
          if (other !== pill) other.open = false;
        });
        bar.classList.add('has-open');
        const find = pill.querySelector('[data-se-find]');
        if (find instanceof HTMLInputElement && window.matchMedia('(min-width: 640px)').matches) {
          find.focus();
        }
      });

      pill.querySelector('[data-se-close]')?.addEventListener('click', () => {
        pill.open = false;
      });

      const find = pill.querySelector('[data-se-find]');
      if (find instanceof HTMLInputElement) {
        const options = Array.from(pill.querySelectorAll('[data-se-opt]'));
        find.addEventListener('input', () => {
          const needle = find.value.trim().toLowerCase();
          options.forEach((option) => {
            const text = (option.getAttribute('data-se-opt') || '').toLowerCase();
            option.hidden = needle !== '' && !text.includes(needle);
          });
        });
        find.addEventListener('keydown', (event) => {
          if (event.key === 'Enter') event.preventDefault();
        });
      }

      pill.querySelectorAll('input[type="checkbox"]').forEach((box) => {
        box.addEventListener('change', () => {
          box.closest('.se-opt')?.classList.toggle('is-checked', box instanceof HTMLInputElement && box.checked);
        });
      });
    });

    bar.querySelectorAll('[data-se-autosubmit]').forEach((control) => {
      control.addEventListener('change', submit);
    });

    bar.addEventListener('submit', () => bar.classList.add('is-loading'));

    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      pills.forEach((pill) => {
        pill.open = false;
      });
    });

    document.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof Node)) return;
      pills.forEach((pill) => {
        if (pill.open && !pill.contains(target)) pill.open = false;
      });
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
