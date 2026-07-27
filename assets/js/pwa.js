(() => {
  'use strict';

  const installButton = document.querySelector('[data-homestead-install]');
  let deferredInstallPrompt = null;

  const hideInstallButton = () => {
    if (installButton instanceof HTMLButtonElement) {
      installButton.hidden = true;
      installButton.disabled = false;
    }
  };

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;
    if (installButton instanceof HTMLButtonElement) {
      installButton.hidden = false;
    }
  });

  if (installButton instanceof HTMLButtonElement) {
    installButton.addEventListener('click', async () => {
      if (deferredInstallPrompt === null) {
        hideInstallButton();
        return;
      }

      installButton.disabled = true;
      deferredInstallPrompt.prompt();
      try {
        await deferredInstallPrompt.userChoice;
      } finally {
        deferredInstallPrompt = null;
        hideInstallButton();
      }
    });
  }

  window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    hideInstallButton();
  });

  if (!('serviceWorker' in navigator) || !window.isSecureContext) {
    return;
  }

  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/service-worker.js', {
      scope: '/',
      updateViaCache: 'none'
    }).then((registration) => {
      void registration.update();
    }).catch(() => {
      // Homestead remains fully usable as a normal web application.
    });
  }, { once: true });
})();
