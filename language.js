/* A shared preference for the static homepage and server-rendered DKP pages. */
(() => {
  'use strict';
  const key = 'fc-site-language';
  const root = document.documentElement;
  const valid = value => value === 'ru' || value === 'en';
  const url = new URL(window.location.href);
  const explicit = url.searchParams.get('lang');
  const cookie = document.cookie.split('; ').find(value => value.startsWith('fc_language='))?.split('=')[1];
  let saved;
  try { saved = localStorage.getItem(key); } catch (_) { /* Cookies and links still work. */ }
  // /en/ is a directly shareable English page; /?lang=ru explicitly switches back.
  const language = valid(explicit) ? explicit : root.dataset.homeLanguage === 'en' ? 'en' :
    root.dataset.homeLanguage ? (valid(cookie) ? cookie : valid(saved) ? saved : 'ru') : root.lang;

  function remember(value) {
    try { localStorage.setItem(key, value); } catch (_) { /* Still usable without storage. */ }
    document.cookie = `fc_language=${value}; Max-Age=31536000; Path=/; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
  }
  remember(language);
  if (root.dataset.homeLanguage && root.lang !== language) {
    url.pathname = language === 'en' ? '/en/' : '/';
    url.searchParams.set('lang', language);
    location.replace(url.href);
    return;
  }

  function init() {
    document.querySelectorAll('[data-language]').forEach(link => {
      const target = new URL(link.href, location.href);
      target.hash = location.hash;
      link.href = target.href;
      link.addEventListener('click', () => {
        // The reader may have followed a section anchor since the page loaded.
        const destination = new URL(link.href, location.href);
        destination.hash = location.hash;
        link.href = destination.href;
        remember(link.dataset.language);
      });
    });
    // Keep language even if cookies are blocked. Leave outside destinations alone.
    document.querySelectorAll('a[href]').forEach(link => {
      if (link.hasAttribute('data-language')) return;
      const target = new URL(link.href, location.href);
      if (target.origin !== location.origin) return;
      if (target.pathname === '/dkp/' || target.pathname === '/dkp/index.php') {
        target.searchParams.set('lang', language);
        link.href = target.href;
      } else if (!root.dataset.homeLanguage && target.pathname === '/') {
        target.pathname = language === 'en' ? '/en/' : '/';
        target.searchParams.set('lang', language);
        link.href = target.href;
      }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
