/* AURENOEVA homepage counter; counts are supplied by the published search snapshot. */
(function () {
  'use strict';
  function init() {
    const actions = document.querySelector('.hero .hero-actions');
    if (!actions || document.getElementById('aur-catalogue-counter')) return;
    const style = document.createElement('style');
    style.textContent = '.aur-catalogue-counter{display:flex;align-items:center;gap:15px;flex-wrap:wrap;margin:19px 0 4px;padding:16px 19px;border:1px solid #C9DDD4;border-radius:16px;background:linear-gradient(110deg,#fff,#EEF5F1);box-shadow:0 9px 28px rgba(14,58,51,.045)}.aur-catalogue-counter__number{font-size:clamp(32px,3.25vw,48px);font-weight:780;letter-spacing:-.055em;line-height:1;color:#0E3A33;font-variant-numeric:tabular-nums}.aur-catalogue-counter__content{display:grid;gap:2px;min-width:170px}.aur-catalogue-counter__label{font-size:14px;line-height:1.3;font-weight:750;color:#0E3A33}.aur-catalogue-counter__note{font-size:11.5px;color:#506B60;line-height:1.35}.aur-catalogue-counter__link{margin-left:auto;color:#276758;font-size:12px;font-weight:760;white-space:nowrap;text-decoration:underline;text-underline-offset:3px}@media(max-width:600px){.aur-catalogue-counter{gap:10px;padding:13px}.aur-catalogue-counter__link{margin-left:0;width:100%}}';
    document.head.appendChild(style);
    const banner = document.createElement('div');
    banner.id = 'aur-catalogue-counter';
    banner.className = 'aur-catalogue-counter';
    banner.setAttribute('aria-label', 'Searchable product catalogue');
    banner.innerHTML = '<strong class="aur-catalogue-counter__number" id="aur-catalogue-total"></strong><span class="aur-catalogue-counter__content"><span class="aur-catalogue-counter__label" id="aur-catalogue-label"></span><span class="aur-catalogue-counter__note" id="aur-catalogue-note"></span></span><a class="aur-catalogue-counter__link" href="#procurement" id="aur-catalogue-link"></a>';
    banner.hidden = true;
    actions.insertAdjacentElement('afterend', banner);
    let total = null;
    const number = banner.querySelector('#aur-catalogue-total');
    const label = banner.querySelector('#aur-catalogue-label');
    const note = banner.querySelector('#aur-catalogue-note');
    const link = banner.querySelector('#aur-catalogue-link');
    function lang() {
      return document.querySelector('.hero ~ * .lang button.active')?.dataset.lang ||
        document.querySelector('.lang button.active')?.dataset.lang ||
        document.documentElement.lang || 'en';
    }
    function render(locale) {
      if (total === null) return;
      const de = String(locale || lang()).toLowerCase().startsWith('de');
      number.textContent = new Intl.NumberFormat(de ? 'de-DE' : 'en-GB').format(total);
      label.textContent = de ? 'Durchsuchbare Teile im Katalog' : 'Parts searchable in our catalogue';
      note.textContent = de ? 'Lieferbarkeit wird auf Anfrage geprüft.' : 'Supplier stock is checked on enquiry.';
      link.textContent = de ? 'Jetzt suchen →' : 'Search parts →';
      banner.hidden = false;
    }
    document.querySelectorAll('.lang button[data-lang]').forEach(button =>
      button.addEventListener('click', () => render(button.dataset.lang)));
    document.addEventListener('aur:lang', event => render(event.detail?.lang));
    fetch('catalogue-count.php', {credentials:'same-origin',cache:'no-store'})
      .then(response => response.ok ? response.json() : null)
      .then(data => {
        if (!data || data.ok !== true || !Number.isSafeInteger(data.searchable_parts) ||
            data.searchable_parts < 1) return;
        total = data.searchable_parts;
        render(lang());
      })
      .catch(() => { /* Do not advertise an unverifiable number. */ });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
