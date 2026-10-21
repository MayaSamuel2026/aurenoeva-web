/* AURENOEVA: one click scans saved case incrementally on the existing PHP host.
   Does not send vendor enquiries, edit credentials, or export customer data. */
(() => {
  'use strict';
  const button = document.getElementById('aur-worldwide-scan-all');
  if (!button) return;
  const form = document.getElementById('aur-worldwide-scan-form');
  const progress = document.getElementById('aur-worldwide-progress');
  const caseRef = form?.querySelector('input[name="case_ref"]')?.value;
  const csrf = form?.querySelector('input[name="csrf"]')?.value;
  const total = Math.max(0, Number(button.dataset.total) || 0);
  if (!caseRef || !csrf || total < 1) { button.disabled = true; return; }
  button.addEventListener('click', async () => {
    button.disabled = true;
    const single = form.querySelector('button[type="submit"]');
    if (single) single.disabled = true;
    let processed = 0, leads = 0, failed = 0;
    try {
      for (let iteration = 0; iteration < total + 1; iteration++) {
        progress.textContent = 'Worldwide supplier research… ' + processed + ' of ' + total + ' equipment entries updated.';
        const request = new URLSearchParams({csrf, case_ref: caseRef, scan_worldwide:'1'});
        const res = await fetch('/procurement/',{
          method:'POST',credentials:'same-origin',
          headers:{Accept:'application/json'},body:request
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.ok !== true) throw new Error(
          'Search request failed (' + res.status + '): ' + (data.reason || data.error || 'try again'));
        const done = Number(data.processed||0);
        if (done <= 0) break;
        processed += done;
        leads += Number(data.new_vendor_pages||0);
        failed += Number(data.provider_failures||0);
      }
      progress.textContent = processed === 0
        ? 'No further entries need scanning at the moment. Saved sources remain available.'
        : ('Worldwide research: ' + processed + ' equipment lines checked; ' + leads +
           ' discovered supplier/source leads; ' + failed + ' provider errors. Opening dashboard.');
      window.location.assign('/procurement/?case=' + encodeURIComponent(caseRef));
    } catch (e) {
      progress.textContent = 'Search paused: ' + (e?.message||'Try again') +
       '. Results already scanned are saved. Click again to resume.';
    } finally {
      button.disabled = false;
      if (single) single.disabled = false;
    }
  });
})();

/* Vendor-centric selection and one-click, restart-safe approved mail dispatch. */
(() => {
  'use strict';
  const checks = Array.from(document.querySelectorAll('.aur-vendor-check'));
  const label = document.getElementById('aur-vendor-selection-count');
  const render = () => {
    if (label) label.textContent = checks.filter(c => c.checked).length + ' suppliers selected';
  };
  document.getElementById('aur-select-all-vendors')?.addEventListener('click', () => {
    checks.forEach(c => { c.checked = true; }); render();
  });
  document.getElementById('aur-clear-vendors')?.addEventListener('click', () => {
    checks.forEach(c => { c.checked = false; }); render();
  });
  checks.forEach(c => c.addEventListener('change', render));
  render();

  // Prefer the browser's ordinary same-origin form POST. No JSON gateway,
  // fetch-specific authorization rules, or reliance on AJAX for RFQ creation.
  // Hostinger receives one small string rather than many form-array values.
  const draftForm = document.getElementById('aur-vendor-selection-form');
  const draftButton = document.getElementById('aur-prepare-rfqs');
  const draftProgress = document.getElementById('aur-draft-progress');
  if (draftForm && draftButton && draftProgress) {
    draftForm.addEventListener('submit', event => {
      const selected = [...new Set(checks.filter(c => c.checked).map(c => c.value))];
      if (!selected.length) {
        event.preventDefault();
        draftProgress.textContent = 'Please select at least one supplier with a published email address.';
        return;
      }
      const compact = draftForm.querySelector('#aur-compact-vendor-tokens');
      if (compact && selected.length <= 300) {
        compact.value = selected.join(',');
        checks.forEach(c => c.removeAttribute('name'));
      }
      draftProgress.textContent = 'Preparing one saved RFQ draft for ' +
        selected.length + ' suppliers. No emails are being sent.';
      draftButton.disabled = true;
      // Do NOT preventDefault: PHP responds with either a saved draft redirect
      // or the original dashboard plus an actionable preparation error.
    });
  }

  // Native form submission works with Hostinger's ordinary staff POST route.
  // It avoids previous JSON/AJAX send failures and still uses the signed
  // admin cookie, CSRF token, explicit legal-review checkbox and saved outbox.
  const sendForm = document.getElementById('aur-vendor-send-form');
  const sendButton = document.getElementById('aur-send-all-rfqs');
  const sendProgress = document.getElementById('aur-send-progress');
  if (sendForm && sendButton && sendProgress) {
    sendForm.addEventListener('submit', event => {
      if (!sendForm.reportValidity()) {
        event.preventDefault();
        return;
      }
      sendButton.disabled = true;
      sendProgress.textContent = 'Dispatching approved supplier RFQs. The case keeps a separate result for each recipient; accepted messages will not be resent.';
      // Allow the normal POST and redirect to the same case/draft status.
    });
  }
})();
