/* Compact procurement workspace — presentation only.
 * Existing PHP case and mail-ledger writers are unchanged. No network calls.
 */
(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.getElementById('aur-tabs');
    const guide = document.getElementById('aur-workflow');
    if (!tabs || !guide) return;
    const keys = ['overview', 'intelligence', 'enquiry', 'suppliers', 'rfq', 'mail', 'settings'];
    const panels = {};
    for (const key of keys) {
      const panel = document.createElement('section');
      panel.id = 'aur-panel-' + key;
      panel.className = 'aur-panel';
      panel.dataset.aurPanel = key;
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', 'aur-tab-' + key);
      panels[key] = panel;
      tabs.parentNode.insertBefore(panel, guide);
    }
    const move = (element, panel, asCard = false) => {
      if (!element || !panel) return false;
      if (asCard) element.classList.add('card');
      panel.appendChild(element);
      return true;
    };
    const cardWithTitle = title => Array.from(document.querySelectorAll('section.card'))
      .find(card => {
        const heading = card.querySelector('h2');
        return heading && heading.textContent.trim() === title;
      });
    move(guide, panels.overview);
    move(document.getElementById('aur-commercial-intelligence'), panels.intelligence);
    move(document.getElementById('aur-upload'), panels.enquiry);
    move(document.getElementById('saved-cases'), panels.enquiry);

    // Keep the original requested equipment and destination review together.
    const caseCard = document.getElementById('destination-screen')?.closest('.card');
    const researchControls = document.getElementById('supplier-search-controls');
    if (researchControls) {
      researchControls.classList.add('aux-disclosure');
      panels.suppliers.appendChild(researchControls);
    }
    move(caseCard, panels.enquiry);
    move(cardWithTitle('Existing enquiries'), panels.enquiry);

    // Split the old long supplier card into two short working areas.
    const suppliers = document.getElementById('supplier-workspace');
    const oldSupplierCard = suppliers?.closest('.card');
    if (suppliers) {
      suppliers.style.removeProperty('margin-top');
      suppliers.style.removeProperty('border-top');
      suppliers.style.removeProperty('padding-top');
      move(suppliers, panels.suppliers, true);
    }
    const reminders = document.getElementById('aur-price-reminders');
    if (reminders) move(reminders, panels.rfq, true);
    const approvals = document.getElementById('aur-rfq-approval');
    if (approvals) move(approvals, panels.rfq, true);
    const replies = document.getElementById('supplier-replies');
    if (replies) move(replies, panels.rfq, true);
    if (oldSupplierCard) {
      if (oldSupplierCard.children.length === 0) oldSupplierCard.remove();
      else move(oldSupplierCard, panels.suppliers);
    }
    move(cardWithTitle('Supplier research'), panels.suppliers);
    if (!suppliers && panels.suppliers.childElementCount === 0)
      panels.suppliers.innerHTML = '<div class="card"><h2>Suppliers</h2><p>Open a saved enquiry to review supplier research.</p></div>';
    if (!reminders && !approvals && !replies)
      panels.rfq.innerHTML = '<div class="card"><h2>RFQ & replies</h2><p>No supplier RFQ is available for this enquiry yet.</p></div>';

    // Diagnostics, catalogue administration and historical counts stay separate.
    move(document.getElementById('aur-mail-center'), panels.mail);
    move(document.getElementById('aur-mail-settings')?.closest('.card'), panels.settings);
    move(cardWithTitle('Live product catalogue'), panels.settings);
    move(document.getElementById('supplier-history-counts'), panels.settings);

    // Only read-only shortcuts; they never approve or dispatch emails.
    const actions = document.createElement('div');
    actions.className = 'aur-quick-actions';
    actions.setAttribute('aria-label', 'Common procurement tasks');
    const links = document.getElementById('aur-send-status')
      ? [['Prepare supplier reminders', '#aur-price-reminders'], ['Review sent RFQ', '#aur-send-status'],
         ['Supplier replies', '#supplier-replies'], ['Supplier selection', '#supplier-dashboard'],
         ['Original equipment', '#original-rfq-equipment']]
      : [['Upload an enquiry', '#aur-upload'], ['Select suppliers', '#supplier-dashboard']];
    for (const [label, href] of links) {
      if (!document.getElementById(href.slice(1))) continue;
      const a = document.createElement('a');
      a.href = href; a.textContent = label + ' →';
      actions.appendChild(a);
    }
    panels.overview.appendChild(actions);

    const activate = (key, pushHash = false) => {
      if (!panels[key]) key = 'overview';
      for (const name of keys) {
        panels[name].classList.toggle('is-active', name === key);
        const tab = tabs.querySelector('[data-aur-tab="' + name + '"]');
        if (tab) {
          tab.setAttribute('aria-selected', String(name === key));
          tab.tabIndex = name === key ? 0 : -1;
        }
      }
      if (pushHash) {
        history.replaceState(null, '', location.pathname + location.search + '#tab-' + key);
        tabs.scrollIntoView({block: 'start', behavior: 'smooth'});
      }
    };
    const openDeepLink = () => {
      const hash = decodeURIComponent(location.hash.slice(1));
      const tabKey = hash.startsWith('tab-') ? hash.slice(4) : '';
      if (panels[tabKey]) { activate(tabKey); return; }
      const target = hash ? document.getElementById(hash) : null;
      const parentPanel = target?.closest('[data-aur-panel]');
      if (!parentPanel) { activate(tabs.dataset.defaultPanel || 'overview'); return; }
      activate(parentPanel.dataset.aurPanel);
      // Deep links from existing saved RFQs may point inside collapsed details.
      let details = target.closest('details');
      while (details) {
        details.open = true;
        details = details.parentElement?.closest('details') || null;
      }
      requestAnimationFrame(() => target.scrollIntoView({block:'start'}));
    };
    tabs.addEventListener('click', event => {
      const button = event.target.closest('button[data-aur-tab]');
      if (button) activate(button.dataset.aurTab, true);
    });
    tabs.addEventListener('keydown', event => {
      if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
      const current = keys.findIndex(key => tabs.querySelector('[data-aur-tab="' + key + '"]')?.getAttribute('aria-selected') === 'true');
      const next = (current + (event.key === 'ArrowRight' ? 1 : -1) + keys.length) % keys.length;
      event.preventDefault();
      activate(keys[next], true);
      tabs.querySelector('[data-aur-tab="' + keys[next] + '"]')?.focus();
    });
    document.addEventListener('click', event => {
      const link = event.target.closest('a[href^="#"]');
      if (!link) return;
      const target = document.getElementById(decodeURIComponent(link.hash.slice(1)));
      const panel = target?.closest('[data-aur-panel]');
      if (!panel) return;
      activate(panel.dataset.aurPanel);
      let details = target.closest('details');
      while (details) {
        details.open = true;
        details = details.parentElement?.closest('details') || null;
      }
      requestAnimationFrame(() => target.scrollIntoView({block:'start', behavior:'smooth'}));
    });
    window.addEventListener('hashchange', openDeepLink);
    document.documentElement.classList.add('aur-tabs-enabled');
    openDeepLink();
  });
})();
