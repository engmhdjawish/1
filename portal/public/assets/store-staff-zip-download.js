/**
 * Staff store: open ZIP download dialog (same backend as dashboard).
 */
(function () {
  'use strict';

  const dialog = document.querySelector('[data-store-staff-zip-dialog]');
  if (!dialog) {
    return;
  }

  const openButtons = document.querySelectorAll('[data-store-staff-zip-open]');
  const closeTargets = dialog.querySelectorAll('[data-store-staff-zip-close]');

  const openDialog = () => {
    dialog.hidden = false;
    document.body.classList.add('se-zip-dialog-open');
    const firstFocus = dialog.querySelector('input, button, select, summary');
    if (firstFocus instanceof HTMLElement) {
      firstFocus.focus();
    }
    if (typeof window.portalMaterialZipDownloadInit === 'function') {
      window.portalMaterialZipDownloadInit(dialog);
    }
  };

  const closeDialog = () => {
    dialog.hidden = true;
    document.body.classList.remove('se-zip-dialog-open');
  };

  openButtons.forEach((button) => {
    button.addEventListener('click', () => {
      openDialog();
    });
  });

  closeTargets.forEach((node) => {
    node.addEventListener('click', () => {
      closeDialog();
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !dialog.hidden) {
      closeDialog();
    }
  });

  if (typeof window.portalMaterialZipDownloadInit === 'function') {
    window.portalMaterialZipDownloadInit(dialog);
  }
})();
