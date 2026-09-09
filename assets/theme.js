(function () {
  function getStoredTheme() {
    try {
      return localStorage.getItem('theme');
    } catch (e) {
      return null;
    }
  }

  function getPreferredTheme() {
    var stored = getStoredTheme();
    if (stored === 'light' || stored === 'dark') return stored;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
  }

  function updateToggleIcon(theme) {
    var btn = document.getElementById('themeToggle');
    if (btn) btn.textContent = theme === 'dark' ? '☀️' : '🌙';
  }

  // Applied immediately (before first paint) to avoid a light-mode flash.
  applyTheme(getPreferredTheme());

  window.toggleTheme = function () {
    var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    applyTheme(next);
    try {
      localStorage.setItem('theme', next);
    } catch (e) {}
    updateToggleIcon(next);
  };

  document.addEventListener('DOMContentLoaded', function () {
    updateToggleIcon(document.documentElement.getAttribute('data-theme'));
  });
})();
