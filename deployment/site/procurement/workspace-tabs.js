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
    // Global verification and crawler diagnostics must not precede the tabs
    // or obscure the active RFQ working area on every screen.
    move(document.getElementById('aur-wp09-verification'), panels.overview);
    move(document.getElementById('aur-automation-health'), panels.settings);
    move(document.getElementById('aur-deepfetcher-inbox'), panels.intelligence);
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
    // Put the actual work first. The six recently researched suppliers were
    // previously nested deep in the Suppliers tab while a 27-message
    // historical reminder table dominated RFQ & replies.
    const nextSourcingRound = document.getElementById('aur-next-sourcing-round');
    const followupRound = document.getElementById('aur-latest-supplier-rfq');
    const latestRound = document.getElementById('aur-nov05-researched-rfq');
    const previousRound = document.getElementById('aur-next-stock-rfq');
    const newRound = nextSourcingRound || followupRound || latestRound || previousRound;
    const approvals = document.getElementById('aur-rfq-approval');
    const reminders = document.getElementById('aur-price-reminders');
    const replies = document.getElementById('supplier-replies');
    const newHeader = document.createElement('div');
    newHeader.className = 'card aur-rfq-priority';
    newHeader.style.cssText = 'padding:20px 24px;margin:0 0 16px;border:1px solid #b9cfc5;background:#f8fbfa';
    const heading = document.createElement('h2');
    heading.textContent = 'New supplier enquiries — review & send';
    heading.style.cssText = 'margin:0 0 8px;font-size:22px';
    const help = document.createElement('p');
    help.textContent = newRound
      ? (nextSourcingRound
          ? 'Latest round: written-price follow-ups and specialist first enquiries. Review the exact RFQ items, then tick up to three recipients. No email is sent automatically.'
          : followupRound
            ? 'Seven new supplier emails and two marketplace contact routes. Save for review, then send up to three at a time.'
          : latestRound
            ? 'Nine product-specific enquiries with source evidence. Save for review, then send up to three at a time.'
            : 'Six product-specific suppliers. Review emails and attachments, then tick up to three recipients.')
      : 'Open an enquiry to prepare and review its supplier emails.';
    help.style.margin = '0';
    newHeader.append(heading, help);
    panels.rfq.appendChild(newHeader);
    if (newRound) {
      if (!nextSourcingRound && !followupRound && !latestRound) newRound.querySelector('h2').textContent = 'Six new suppliers · Prepared for review';
      newRound.style.marginTop = '0';
      move(newRound, panels.rfq);
    }
    if (approvals) {
      approvals.style.padding = '18px 22px';
      approvals.style.borderTop = 'none';
      move(approvals, panels.rfq, true);
    }
    const historical = document.createElement('details');
    historical.className = 'card aur-rfq-history';
    historical.style.cssText = 'padding:14px 22px;margin-top:14px';
    const historicalTitle = document.createElement('summary');
    historicalTitle.textContent = 'Earlier supplier campaigns · View only when needed';
    historicalTitle.style.cssText = 'cursor:pointer;font-weight:700;padding:7px 0';
    historical.appendChild(historicalTitle);
    for (const id of ['aur-stock-supplier-rfqs','aur-nov-short-supplier-rfqs','aur-urgent-supplier-rfqs']) {
      const section = document.getElementById(id);
      if (section) historical.appendChild(section);
    }
    if (nextSourcingRound && followupRound) historical.appendChild(followupRound);
    if (newRound !== latestRound && latestRound) historical.appendChild(latestRound);
    if (newRound !== previousRound && previousRound) historical.appendChild(previousRound);
    if (historical.children.length > 1) panels.rfq.appendChild(historical);
    const extra = (node, label, id) => {
      if (!node) return;
      const box = document.createElement('details');
      box.className = 'card aur-rfq-secondary';
      box.id = id;
      box.style.cssText = 'padding:14px 22px;margin-top:14px';
      const title = document.createElement('summary');
      title.textContent = label;
      title.style.cssText = 'cursor:pointer;font-weight:700;padding:7px 0';
      box.appendChild(title);
      box.appendChild(node);
      panels.rfq.appendChild(box);
    };
    extra(reminders, 'Supplier reminders · Earlier recipients and follow-ups', 'aur-reminders-archive');
    extra(replies, 'Received supplier quotations and mailbox history', 'aur-replies-archive');
    if (oldSupplierCard) {
      if (oldSupplierCard.children.length === 0) oldSupplierCard.remove();
      else move(oldSupplierCard, panels.suppliers);
    }
    move(cardWithTitle('Supplier research'), panels.suppliers);
    if (!suppliers && panels.suppliers.childElementCount === 0)
      panels.suppliers.innerHTML = '<div class="card"><h2>Suppliers</h2><p>Open a saved enquiry to review supplier research.</p></div>';
    if (!newRound && !approvals && !reminders && !replies) {
      const empty = document.createElement('p');
      empty.textContent = 'No supplier RFQ is available for this enquiry yet.';
      panels.rfq.appendChild(empty);
    }

    // Diagnostics, catalogue administration and historical counts stay separate.
    move(document.getElementById('aur-mail-center'), panels.mail);
    move(document.getElementById('aur-brand-brochure'), panels.settings);
    move(document.getElementById('aur-mail-settings')?.closest('.card'), panels.settings);
    move(cardWithTitle('Live product catalogue'), panels.settings);
    move(document.getElementById('supplier-history-counts'), panels.settings);

    // Only read-only shortcuts; they never approve or dispatch emails.
    const actions = document.createElement('div');
    actions.className = 'aur-quick-actions';
    actions.setAttribute('aria-label', 'Common procurement tasks');
    const links = newRound
      ? [[nextSourcingRound ? 'Review next sourcing round' : followupRound ? 'Review 7 supplier emails' : latestRound ? 'Review 9 researched enquiries' : 'Review 6 supplier enquiries', '#' + newRound.id],
         ['Select up to 3 recipients', '#aur-vendor-send-form'],
         ['Review incoming quotations', '#supplier-replies'],
         ['View original equipment', '#original-rfq-equipment']]
      : document.getElementById('aur-send-status')
        ? [['Review supplier RFQ', '#aur-rfq-approval'],
           ['Supplier replies', '#supplier-replies'],
           ['Supplier selection', '#supplier-dashboard']]
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
