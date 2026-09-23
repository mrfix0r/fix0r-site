/* Load in <head> without defer so the saved palette is applied before paint. */
(() => {
  'use strict';
  const key = 'fc-site-theme';
  const root = document.documentElement;
  const valid = value => value === 'forest' ? 'forest' : 'tavern';
  let theme = 'tavern';
  try { theme = valid(localStorage.getItem(key)); } catch (_) { /* Private storage may be unavailable. */ }

  function apply(value) {
    theme = valid(value);
    root.dataset.theme = theme;
    document.querySelectorAll('[data-theme-toggle]').forEach(button => {
      const forest = theme === 'forest';
      button.setAttribute('aria-pressed', String(forest));
      button.setAttribute('aria-label', 'Тема «Лес и крем»');
      button.title = forest ? 'Включить тему «Сумеречный лес»' : 'Включить тему «Лес и крем»';
      button.querySelector('[data-theme-label]').textContent = forest ? 'Лес и крем' : 'Сумеречный лес';
      button.hidden = false;
    });
  }
  apply(theme);
  function init() {
    apply(theme);
    document.querySelectorAll('[data-theme-toggle]').forEach(button => {
      button.addEventListener('click', () => {
        apply(theme === 'forest' ? 'tavern' : 'forest');
        try { localStorage.setItem(key, theme); } catch (_) { /* Still works for this page. */ }
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
  window.addEventListener('storage', event => {
    if (event.key === key || event.key === null) apply(event.newValue);
  });
  window.addEventListener('pageshow', () => {
    try { apply(localStorage.getItem(key)); } catch (_) { /* Keep in-memory choice. */ }
  });
})();
