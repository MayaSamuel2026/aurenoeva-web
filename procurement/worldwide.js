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
    if (label) label.textContent = checks.filter(c => c.checked).length + ' of ' + checks.length + ' contactable suppliers selected';
  };
  document.getElementById('aur-select-all-vendors')?.addEventListener('click', () => {
    checks.forEach((c, i) => { c.checked = i < 300; }); render();
    if (checks.length > 300 && label)
      label.textContent = 'First 300 selected; remaining contacts can be prepared in another draft.';
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

  const sendForm = document.getElementById('aur-vendor-send-form');
  const sendButton = document.getElementById('aur-send-all-rfqs');
  const sendProgress = document.getElementById('aur-send-progress');
  if (sendForm && sendButton && sendProgress) {
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
        const response = await fetch('/procurement/',{
          method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/json',Accept:'application/json'},
          body:JSON.stringify({task:'send',csrf,case_ref:caseRef,
            draft_id:draftId,manual_review:true})
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || result.ok !== true)
          throw new Error('Mail batch response ' + response.status + '. Review the saved draft before resuming.');
        const p = result.progress || {};
        const accepted = Number(p.accepted || 0);
        const unsent = Number(p.remaining || 0);
        const failed = Number(p.failed_or_unconfirmed || 0);
        const uncertain = Number(p.in_flight || 0) + Number(p.other || 0);
        sendProgress.textContent = accepted + ' of ' + (p.total || total) +
          ' accepted by sending server; ' + unsent + ' not attempted; ' +
          failed + ' failed/unconfirmed; ' + uncertain + ' interrupted/uncertain. ' +
          'Consult the saved recipient ledger before any further action.';
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
      sendProgress.textContent = 'Mail dispatch interrupted: ' + (error.message || 'Unknown error') +
        '. Do not click send again. Reload this saved RFQ to check accepted, unattempted and ' +
        'failed/unconfirmed recipients in the private outbox before taking further action.';
      // Keep disabled: the HTTP response can fail after a message was accepted.
      // A reload shows authoritative persisted status and prevents blind repeats.
      sendButton.disabled = true;
    }
    });
  }

  // Recovery is a different explicit action from first-time dispatch.
  // Only the saved failed/unconfirmed recipients are eligible; this code
  // never retries accepted messages, nor a previously attempted recovery.
  const recoveryForm = document.getElementById('aur-rfq-recover-form');
  const recoveryButton = document.getElementById('aur-recover-rfqs');
  const recoveryProgress = document.getElementById('aur-recover-progress');
  if (recoveryForm && recoveryButton && recoveryProgress) {
    recoveryForm.addEventListener('submit', async event => {
      if (!recoveryForm.reportValidity()) return;
      event.preventDefault();
      recoveryButton.disabled = true;
      const csrf = recoveryForm.querySelector('input[name="csrf"]')?.value;
      const caseRef = recoveryForm.querySelector('input[name="case_ref"]')?.value;
      const draftId = recoveryForm.querySelector('input[name="draft_id"]')?.value;
      const total = Number(recoveryForm.dataset.total || 0);
      if (!csrf || !caseRef || !draftId || total < 1) {
        recoveryProgress.textContent = 'Saved RFQ data unavailable. Reload the original draft.';
        return;
      }
      try {
        for (let i = 0; i < Math.ceil(total / 3) + 2; i++) {
          const response = await fetch('/procurement/',{
            method:'POST', credentials:'same-origin',
            headers:{'Content-Type':'application/json',Accept:'application/json'},
            body:JSON.stringify({task:'recover',csrf,case_ref:caseRef,
              draft_id:draftId,manual_review:true,duplicate_risk_ack:true})
          });
          const data = await response.json().catch(() => ({}));
          if (!response.ok || data.ok !== true)
            throw new Error('Recovery stopped (' + response.status + '): ' +
              (data.error || 'check the saved recipient ledger'));
          const p = data.progress || {};
          const accepted = Number(p.accepted || 0);
          const eligible = Number(p.recovery_remaining || 0);
          const failed = Number(p.failed_or_unconfirmed || 0);
          recoveryProgress.textContent = accepted + ' of ' + (p.total || total) +
            ' accepted in the case ledger; ' + eligible + ' follow-ups still eligible; ' +
            failed + ' uncertain. The original accepted suppliers have not been resent.';
          if (data.status !== 'RECOVERY_DISPATCHING') {
            window.location.assign('/procurement/?case=' + encodeURIComponent(caseRef) +
              '&draft=' + encodeURIComponent(draftId) + '#aur-rfq-approval');
            return;
          }
          if (Number(p.processed_this_request || 0) === 0)
            throw new Error('No new messages processed; check the persisted case status');
        }
        throw new Error('Batch limit reached; reload the saved draft before continuing');
      } catch (error) {
        recoveryProgress.textContent = 'Recovery paused: ' + (error.message || 'Unknown error') +
          '. Do not click send again. Reload this original RFQ and inspect the saved ' +
          'recipient statuses before resuming the remaining eligible messages.';
        recoveryButton.disabled = true;
      }
    });
  }
})();
