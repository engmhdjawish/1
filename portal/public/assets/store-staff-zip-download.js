/**
 * Staff store: ZIP download dialog (same backend as dashboard).
 * Uses delegation so buttons stay wired after store-easy live navigation.
 */
(function () {
  'use strict';

  const getDialog = () => document.querySelector('[data-store-staff-zip-dialog]');

  const openDialog = () => {
    const dialog = getDialog();
    if (!dialog) {
      return;
    }
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
    const dialog = getDialog();
    if (!dialog) {
      return;
    }
    dialog.hidden = true;
    document.body.classList.remove('se-zip-dialog-open');
  };

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }
    if (target.closest('[data-store-staff-zip-open]')) {
      event.preventDefault();
      openDialog();
      return;
    }
    if (target.closest('[data-store-staff-zip-close]')) {
      closeDialog();
    }
  });

  document.addEventListener('keydown', (event) => {
    const dialog = getDialog();
    if (event.key === 'Escape' && dialog && !dialog.hidden) {
      closeDialog();
    }
  });

  window.portalStoreStaffZipDownloadInit = () => {
    const dialog = getDialog();
    if (dialog && typeof window.portalMaterialZipDownloadInit === 'function') {
      window.portalMaterialZipDownloadInit(dialog);
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.portalStoreStaffZipDownloadInit());
  } else {
    window.portalStoreStaffZipDownloadInit();
  }
})();
