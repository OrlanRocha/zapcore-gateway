(() => {
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/service-worker.js'));
  }

  const installButton = document.querySelector('[data-pwa-install]');
  if (!installButton) return;

  let installPrompt = null;
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    installPrompt = event;
    installButton.hidden = false;
  });

  installButton.addEventListener('click', async () => {
    if (!installPrompt) return;
    installPrompt.prompt();
    await installPrompt.userChoice;
    installPrompt = null;
    installButton.hidden = true;
  });

  window.addEventListener('appinstalled', () => {
    installPrompt = null;
    installButton.hidden = true;
  });
})();
