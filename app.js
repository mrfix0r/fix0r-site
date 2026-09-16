const tabs = [...document.querySelectorAll('[data-interest]')];
const panels = [...document.querySelectorAll('[role="tabpanel"]')];
function selectInterest(id, focus = false) {
  if (!tabs.some(tab => tab.dataset.interest === id)) return;
  for (const tab of tabs) {
    const selected = tab.dataset.interest === id;
    tab.setAttribute('aria-selected', String(selected));
    tab.classList.toggle('active', selected);
    tab.tabIndex = selected ? 0 : -1;
    if (selected && focus) tab.focus();
  }
  for (const panel of panels) panel.hidden = panel.id !== `panel-${id}`;
}
tabs.forEach((tab, index) => {
  tab.addEventListener('click', () => selectInterest(tab.dataset.interest));
  tab.addEventListener('keydown', event => {
    let next;
    if (['ArrowDown', 'ArrowRight'].includes(event.key)) next = (index + 1) % tabs.length;
    if (['ArrowUp', 'ArrowLeft'].includes(event.key)) next = (index - 1 + tabs.length) % tabs.length;
    if (event.key === 'Home') next = 0;
    if (event.key === 'End') next = tabs.length - 1;
    if (next !== undefined) {
      event.preventDefault();
      selectInterest(tabs[next].dataset.interest, true);
    }
  });
});

const lantern = document.querySelector('.lantern-switch');
lantern.addEventListener('click', () => {
  const on = lantern.getAttribute('aria-pressed') !== 'true';
  lantern.setAttribute('aria-pressed', String(on));
  lantern.setAttribute('aria-label', on ? 'Выключить свет фонаря' : 'Включить свет фонаря');
  document.querySelector('.hero-art').classList.toggle('lantern-on', on);
  document.querySelector('#lantern-label').textContent = on ? 'Фонарь горит' : 'Зажечь фонарь';
});
