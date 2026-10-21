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

  const sendForm = document.getElementById('aur-vendor-send-form');
  const sendButton = document.getElementById('aur-send-all-rfqs');
  const sendProgress = document.getElementById('aur-send-progress');
  if (!sendForm || !sendButton || !sendProgress) return;
  sendForm.addEventListener('submit', async event => {
    if (!sendForm.reportValidity()) return;
    event.preventDefault();
    sendButton.disabled = true;
    const csrf = sendForm.querySelector('input[name="csrf"]')?.value;
    const caseRef = sendForm.querySelector('input[name="case_ref"]')?.value;
    const draftId = sendForm.querySelector('input[name="draft_id"]')?.value;
    const total = Number(sendForm.dataset.total || 0);
    if (!csrf || !caseRef || !draftId || total <= 0) {
      sendProgress.textContent = 'RFQ information unavailable; reload the saved draft.';
      sendButton.disabled = false; return;
    }
    try {
      for (let i = 0; i < Math.ceil(total / 3) + 2; i++) {
        const data = new URLSearchParams({
          csrf,case_ref:caseRef,draft_id:draftId,manual_review:'1',send_vendor_rfqs:'1'
        });
        const response = await fetch('/procurement/',{
          method:'POST',credentials:'same-origin',
          headers:{Accept:'application/json'},body:data
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || result.ok !== true)
          throw new Error('Mail batch response ' + response.status + '. Review the saved draft before resuming.');
        const p = result.progress || {};
        sendProgress.textContent = (p.accepted || 0) + ' of ' + (p.total || total) +
          ' vendor emails accepted by mail transport; ' + (p.remaining || 0) + ' pending.';
        if (result.status !== 'DISPATCHING') {
          window.location.assign('/procurement/?case=' + encodeURIComponent(caseRef) +
            '&draft=' + encodeURIComponent(draftId) + '#supplier-history');
          return;
        }
        if (Number(p.processed_this_request || 0) === 0)
          throw new Error('No further send progress; review saved outbox.');
      }
      throw new Error('Maximum batch count reached; reopen the draft to continue.');
    } catch (error) {
      sendProgress.textContent = 'Mail dispatch paused: ' + (error.message || 'Unknown error') +
        ' — sent messages stay recorded and will not be resent. Reopen the saved draft.';
      sendButton.disabled = false;
    }
  });
})();
