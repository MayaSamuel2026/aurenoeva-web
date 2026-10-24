/* Background inbox reconciliation for the existing authenticated procurement page.
 * No new login, no outgoing supplier mail, no automatic RFQ modification.
 */
(() => {
  'use strict';
  const go = async () => {
    if (document.visibilityState === 'hidden') return;
    const display = document.getElementById('aur-inbox-live-status');
    const csrf = document.querySelector('input[name="csrf"]')?.value;
    if (!display || !csrf || display.dataset.syncing === '1') return;
    display.dataset.syncing = '1';
    try {
      const body = new URLSearchParams({csrf, sync_supplier_inbox: '1'});
      const response = await fetch('/procurement/', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
        body
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Mailbox sync unavailable');
      display.textContent = 'Mailbox checked · ' + result.processed +
        ' new message(s) · ' + result.quotes_saved + ' quotation(s) captured';
      if (result.processed > 0) {
        // Only refresh when new evidence exists; preserve the user's current tab.
        const hash = location.hash || '#tab-rfq';
        location.replace(location.pathname + location.search + hash);
        location.reload();
      } else if (result.more_pending) {
        setTimeout(go, 12000);
      }
    } catch (error) {
      display.textContent = 'Mailbox sync unavailable: ' + String(error.message || error);
    } finally {
      display.dataset.syncing = '0';
    }
  };
  document.addEventListener('DOMContentLoaded', () => {
    setTimeout(go, 1200);
    setInterval(go, 120000);
  });
})();
