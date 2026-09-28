/* First-party aggregate analytics. Never collect URLs, input values or account IDs. */
(() => {
  'use strict';
  const optKey = 'fc-metrics-optout', visitorKey = 'fc-metrics-visitor';
  const privacy = navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.globalPrivacyControl === true;
  let optedOut = false;
  try { optedOut = localStorage.getItem(optKey) === '1'; } catch (_) {}
  const control = document.querySelector('[data-metrics-optout]');
  function updateControl() {
    if (!control) return;
    control.hidden = false;
    control.disabled = privacy;
    control.textContent = privacy ? control.dataset.privacyLabel : optedOut ? control.dataset.includeLabel : control.dataset.excludeLabel;
    control.setAttribute('aria-pressed', String(optedOut || privacy));
  }
  updateControl();
  if (control) control.addEventListener('click', () => {
    try {
      const next = !optedOut;
      localStorage.setItem(optKey, next ? '1' : '0');
      optedOut = next;
      if (next) localStorage.removeItem(visitorKey);
      updateControl();
    } catch (_) {
      const error = document.querySelector('[data-metrics-storage-error]');
      if (error) error.hidden = false;
    }
  });
  window.addEventListener('storage', event => {
    if (event.key === optKey || event.key === null) {
      try { optedOut = localStorage.getItem(optKey) === '1'; } catch (_) {}
      updateControl();
    }
  });
  const page = document.body.dataset.metricsPage;
  if (!page || privacy || optedOut || !window.crypto?.getRandomValues || !window.fetch) return;
  const randomId = () => [...crypto.getRandomValues(new Uint8Array(16))].map(n => n.toString(16).padStart(2, '0')).join('');
  let visitor;
  try {
    const saved = JSON.parse(localStorage.getItem(visitorKey) || 'null');
    if (saved && /^[a-f0-9]{32}$/.test(saved.id) && Number.isFinite(saved.created) && saved.created <= Date.now() && Date.now() - saved.created < 30 * 86400000) visitor = saved.id;
  } catch (_) {}
  if (!visitor) {
    visitor = randomId();
    try { localStorage.setItem(visitorKey, JSON.stringify({ id: visitor, created: Date.now() })); } catch (_) {}
  }
  const lang = document.documentElement.lang === 'en' ? 'en' : 'ru';
  let queue = [], timer;
  function blocked() {
    try { optedOut = localStorage.getItem(optKey) === '1'; } catch (_) {}
    return optedOut;
  }
  function send(events, retry = false) {
    if (blocked()) return;
    try {
      fetch('/dkp/collect.php', {
        method: 'POST', credentials: 'omit', referrerPolicy: 'no-referrer', keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ visitor, events })
      }).then(response => { if (response.status >= 500) throw new Error('Unavailable'); })
        .catch(() => { if (!retry && document.visibilityState === 'visible') setTimeout(() => send(events, true), 3000); });
    } catch (_) { /* Analytics must never interrupt a link or form. */ }
  }
  function flush() {
    clearTimeout(timer); timer = undefined;
    if (blocked()) { queue = []; return; }
    while (queue.length) send(queue.splice(0, 20));
  }
  function record(kind, item, area) {
    if (blocked() || queue.length >= 40) return;
    queue.push({ id: randomId(), kind, item, area, lang });
    if (!timer) timer = setTimeout(flush, 1200);
  }
  function areaOf(el) {
    if (document.body.classList.contains('dkp-app')) return 'dkp';
    for (const [selector, area] of [['header','header'],['.telegram-notice','announcement'],['#home','hero'],['#twitch-stream','player'],['#about','about'],['#schedule','schedule'],['#panel-community','community'],['#panel-support','support'],['#explore','explore'],['footer','footer']]) {
      if (el.closest(selector)) return area;
    }
    return null;
  }
  function actionOf(el) {
    if (el.matches('[data-theme-toggle]')) return 'theme.toggle';
    if (['ru','en'].includes(el.dataset.language)) return 'language.' + el.dataset.language;
    if (['streams','community','support','setup'].includes(el.dataset.interest)) return 'tab.' + el.dataset.interest;
    if (el.matches('.lantern-switch')) return 'lantern.toggle';
    if (el.matches('.about-teams>summary')) return 'about.story';
    if (el.matches('.about-tournaments>summary')) return 'about.tournaments';
    if (el.matches('.full-setup>summary')) return 'setup.expand';
    if (el.matches('label[for="profile-slide-event"]')) return 'dkp.slide.event';
    if (el.matches('label[for="profile-slide-auction"]')) return 'dkp.slide.auction';
    if (!el.matches('a[href]')) return null;
    let url; try { url = new URL(el.getAttribute('href'), location.href); } catch (_) { return null; }
    if (url.protocol === 'ts3server:' && url.hostname === 'sleepingforest.cleanvoice.ru') return 'out.teamspeak';
    const external = { 't.me/fix0rstream':'telegram', 'www.twitch.tv/fix0r':'twitch', 'twitch.tv/fix0r':'twitch', 'discord.gg/shpfugy2jz':'discord', 'boosty.to/fix0r':'boosty' };
    const destination = external[(url.hostname + url.pathname.replace(/\/$/, '')).toLowerCase()];
    if (url.protocol === 'https:' && destination) return 'out.' + destination;
    if (url.origin !== location.origin) return null;
    if (url.pathname === '/dkp/' || url.pathname === '/dkp/index.php') {
      const target = url.searchParams.get('page');
      if (['profile','events','auctions','announcements','manage','login','register','forgot','resend'].includes(target)) return 'dkp.' + target;
      return target ? null : 'nav.dkp';
    }
    const anchors = { '#home':'home', '#twitch-stream':'stream', '#about':'about', '#schedule':'schedule', '#code':'code', '#explore':'explore' };
    if (anchors[url.hash]) return 'nav.' + anchors[url.hash];
    if (['/','/en/','/index.html','/en/index.html'].includes(url.pathname) && !url.hash) return 'nav.home';
    return null;
  }
  function click(event) {
    if (event.type === 'auxclick' ? event.button !== 1 : event.button !== 0) return;
    const el = event.target instanceof Element ? event.target.closest('a,button,summary,label[for^="profile-slide-"]') : null;
    if (!el) return;
    const item = actionOf(el), area = areaOf(el);
    if (item && area) { record('click', item, area); if (el.matches('a[href]')) flush(); }
  }
  document.addEventListener('click', click, true);
  document.addEventListener('auxclick', click, true);
  window.addEventListener('pagehide', flush);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flush(); });
  window.addEventListener('pageshow', event => { if (event.persisted) { record('view', page, 'page'); flush(); } });
  record('view', page, 'page'); flush();
})();
