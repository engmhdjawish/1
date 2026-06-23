(() => {
  const root = document.querySelector('[data-home-ad-carousel]');
  if (!root) return;
  const slides = Array.from(root.querySelectorAll('.home-ad-slide'));
  if (slides.length <= 1) return;
  const dots = Array.from(root.querySelectorAll('[data-ad-dot]'));
  const prevBtn = root.querySelector('[data-ad-prev]');
  const nextBtn = root.querySelector('[data-ad-next]');
  let index = 0;
  let timer = null;
  const intervalMs = 5500;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const show = (next) => {
    index = (next + slides.length) % slides.length;
    slides.forEach((slide, i) => {
      const active = i === index;
      slide.classList.toggle('is-active', active);
      slide.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
    dots.forEach((dot, i) => {
      const active = i === index;
      dot.classList.toggle('is-active', active);
      dot.setAttribute('aria-selected', active ? 'true' : 'false');
    });
  };

  const stop = () => {
    if (timer !== null) {
      clearInterval(timer);
      timer = null;
    }
  };

  const start = () => {
    if (reducedMotion) return;
    stop();
    timer = setInterval(() => show(index + 1), intervalMs);
  };

  dots.forEach((dot) => {
    dot.addEventListener('click', () => {
      show(Number.parseInt(dot.getAttribute('data-ad-dot') || '0', 10));
      start();
    });
  });
  prevBtn?.addEventListener('click', () => { show(index - 1); start(); });
  nextBtn?.addEventListener('click', () => { show(index + 1); start(); });

  root.addEventListener('mouseenter', stop);
  root.addEventListener('mouseleave', start);
  root.addEventListener('focusin', stop);
  root.addEventListener('focusout', (event) => {
    if (!root.contains(event.relatedTarget)) start();
  });

  show(0);
  start();
})();
