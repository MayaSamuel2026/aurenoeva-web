/* AURENOEVA: one click advances the existing authenticated case in small batches.
   No background service, third-party API key, customer data export or supplier email. */
(() => {
  'use strict';
  const button = document.getElementById('aur-discover-all');
  if (!button) return;
  const form = button.closest('form');
  const progress = document.getElementById('aur-discovery-progress');
  const caseRef = form?.querySelector('input[name="case_ref"]')?.value;
  const csrf = form?.querySelector('input[name="csrf"]')?.value;
  const total = Math.max(0, Number(button.dataset.total) || 0);
  if (!caseRef || !csrf || !total) {
    button.disabled = true;
    return;
  }
  button.addEventListener('click', async () => {
    button.disabled = true;
    const smallBatchButton = form.querySelector('button[type="submit"]');
    if (smallBatchButton) smallBatchButton.disabled = true;
    let processed = 0;
    let leads = 0;
    let providerFailures = 0;
    try {
      for (let n = 0; n < Math.ceil(total / 2) + 1; n += 1) {
        progress.textContent = `Searching worldwide… ${processed} entries checked this run.`;
        const body = new URLSearchParams({
          csrf, case_ref: caseRef, discover_suppliers: '1'
        });
        const response = await fetch('/procurement/', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          body
        });
        if (!response.ok) throw new Error('Research endpoint unavailable (' + response.status + ')');
        const result = await response.json();
        if (result.ok !== true) throw new Error('Research response invalid');
        const count = Number(result.processed || 0);
        if (count <= 0) break;
        processed += count;
        leads += Number(result.new_unverified_links || 0);
        providerFailures += Number(result.provider_failures || 0);
        if (Number(result.provider_failures || 0) >= count) {
          progress.textContent = 'Public search provider unavailable. Results saved; try again after 15 minutes.';
          return;
        }
      }
      progress.textContent = `Discovery updated: ${processed} entries, ${leads} unverified links.`;
      window.location.assign('/procurement/?case=' + encodeURIComponent(caseRef));
    } catch (error) {
      progress.textContent = 'Research interrupted; completed batches remain saved. ' +
        (error?.message || 'Please retry.');
    } finally {
      button.disabled = false;
      if (smallBatchButton) smallBatchButton.disabled = false;
    }
  });
})();
