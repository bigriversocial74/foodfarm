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

  const scriptElement = document.currentScript
    ?? Array.from(document.scripts).find((script) => script.src.includes('/assets/js/pwa.js'));
  if (!(scriptElement instanceof HTMLScriptElement) || scriptElement.src === '') {
    return;
  }

  const scriptUrl = new URL(scriptElement.src, document.baseURI);
  const appBaseUrl = new URL('../../', scriptUrl);
  const serviceWorkerUrl = new URL('service-worker.js', appBaseUrl);

  window.addEventListener('load', () => {
    navigator.serviceWorker.register(serviceWorkerUrl.pathname, {
      scope: appBaseUrl.pathname,
      updateViaCache: 'none'
    }).then((registration) => {
      void registration.update();
    }).catch(() => {
      // Homestead remains fully usable as a normal web application.
    });
  }, { once: true });
})();
