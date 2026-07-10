/* Admin Theme Toggle — persists via localStorage */
(function() {
  const DARK  = 'dark';
  const LIGHT = 'light';
  const KEY   = 'cdti_admin_theme';

  function applyTheme(mode) {
    if (mode === LIGHT) {
      document.body.classList.add('light-mode');
    } else {
      document.body.classList.remove('light-mode');
    }
    localStorage.setItem(KEY, mode);
    // Update toggle button label if present
    const btn = document.getElementById('themeToggleBtn');
    if (btn) {
      btn.innerHTML = mode === LIGHT
        ? '<span class="toggle-icon">🌙</span> Dark'
        : '<span class="toggle-icon">☀️</span> Light';
    }
  }

  function toggle() {
    const current = localStorage.getItem(KEY) || DARK;
    applyTheme(current === DARK ? LIGHT : DARK);
  }

  // Apply saved theme immediately (before paint). This script is loaded in
  // <head>, before <body> exists, so document.body is null here — queue the
  // class add for the earliest possible moment body is available instead of
  // crashing (which used to abort the rest of this script, including the
  // DOMContentLoaded listener below, for any user with the light theme saved).
  const saved = localStorage.getItem(KEY) || DARK;
  if (saved === LIGHT) {
    if (document.body) {
      document.body.classList.add('light-mode');
    } else {
      document.addEventListener('DOMContentLoaded', function () {
        document.body.classList.add('light-mode');
      }, { once: true });
    }
  }

  // Wire up button after DOM ready
  document.addEventListener('DOMContentLoaded', function() {
    applyTheme(localStorage.getItem(KEY) || DARK);
    const btn = document.getElementById('themeToggleBtn');
    if (btn) btn.addEventListener('click', toggle);
  });
})();
