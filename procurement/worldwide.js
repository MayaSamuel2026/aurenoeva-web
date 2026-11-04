/* AURENOEVA: one click scans saved case incrementally on the existing PHP host.
   Does not send vendor enquiries, edit credentials, or export customer data. */
(() => {
  'use strict';
  // The overview reads the same rendered, deduplicated supplier selection:
  // no second query, new backend, or browser-side supplier count estimate.
  const supplierSummary = document.getElementById('supplier-workspace');
  const contactSummary = document.getElementById('aur-summary-contactable');
  if (supplierSummary && contactSummary) {
    contactSummary.textContent = supplierSummary.dataset.contactable || '0';
  }
  // Jump links must reveal their own native collapsible panels.
  const openAnchorPanel = () => {
    const id = decodeURIComponent(window.location.hash.slice(1));
    if (!id) return;
    const target = document.getElementById(id);
    if (!target) return;
    if (target.tagName === 'DETAILS') target.open = true;
    const parent = target.closest('details');
    if (parent) parent.open = true;
  };
  window.addEventListener('hashchange', openAnchorPanel);
  openAnchorPanel();
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

  // Authoritative delivery proof: retrieve the persisted RFQ file after
  // sending, never present a browser progress counter as an audited result.
  const ledgerPanel = document.getElementById('aur-rfq-approval');
  const ledgerNotice = document.getElementById('aur-ledger-verification');
  const savedCase = ledgerPanel?.dataset.caseRef;
  const savedDraft = ledgerPanel?.dataset.draftId;
  async function readSavedLedger(caseRef, draftId) {
    const qs = new URLSearchParams({rfq_status:'1',case_ref:caseRef,draft:draftId});
    const response=await fetch('/procurement/?'+qs.toString(),{
      method:'GET',credentials:'same-origin',cache:'no-store',
      headers:{Accept:'application/json'}
    });
    const data=await response.json().catch(()=>null);
    if(!response.ok || data?.ok!==true ||
       data.case_reference!==caseRef || data.draft_id!==draftId)
      throw new Error('Cannot verify saved sending evidence ('+response.status+')');
    return data;
  }
  function renderSavedLedger(data) {
    const p=data.progress||{};
    const confirmed=Number(p.smtp_250_confirmed||0);
    const legacy=Number(p.accepted_without_smtp_250_proof||0);
    const unconfirmed=Number(p.total||0)-Number(p.accepted||0);
    document.getElementById('aur-smtp-confirmed')?.replaceChildren(
      document.createTextNode(String(confirmed)));
    document.getElementById('aur-legacy-accepted')?.replaceChildren(
      document.createTextNode(String(legacy)));
    document.getElementById('aur-awaiting')?.replaceChildren(
      document.createTextNode(String(Math.max(0,unconfirmed))));
    if(ledgerNotice) ledgerNotice.textContent =
      'Verified against the saved RFQ: '+confirmed+' authenticated SMTP 250 acceptances; '+
      legacy+' historical acceptances without stored SMTP 250 proof; '+
      Math.max(0,unconfirmed)+' unresolved. SMTP acceptance is evidence of sending, not inbox delivery.';
    return {confirmed,legacy,unconfirmed};
  }
  // Read-only recipient reconciliation. No confirmation comes from browser
  // counters or a POST response alone; the saved private RFQ is authoritative.
  function recipientState(value) {
    switch (value) {
      case 'ACCEPTED_BY_MAIL_TRANSPORT':return 'Accepted by sending server';
      case 'TRANSPORT_FAILED_OR_UNCONFIRMED':return 'Failed or not confirmed — review';
      case 'IN_FLIGHT':return 'Processing or uncertain — review';
      case 'DRAFT':return 'Not sent';
      default:return 'Status needs review';
    }
  }
  function syncSupplierRecipientRows(data) {
    if(!Array.isArray(data.recipients))return;
    for(const item of data.recipients){
      const input=document.querySelector('.aur-short-batch-select[value="'+Number(item.index)+'"]');
      if(!input)continue;
      const row=input.closest('tr');
      const state=String(item.status||'UNKNOWN');
      if(state!=='DRAFT'){input.checked=false;input.disabled=true;}
      if(row){
        row.dataset.deliveryStatus=state;
        const cell=row.lastElementChild;
        if(cell)cell.textContent=recipientState(state);
      }
    }
    const count=document.getElementById('aur-short-batch-selection');
    if(count)count.textContent=
      document.querySelectorAll('.aur-short-batch-select:checked:not(:disabled)').length+
      ' of 3 selected. Only newly selected, unsent suppliers can be contacted.';
  }
  function displayBatchReceipt(data, indices) {
    const receipt=document.getElementById('aur-send-receipt');
    if(!receipt||!Array.isArray(data.recipients)||!Array.isArray(indices)||!indices.length)return null;
    const byIndex=new Map(data.recipients.map(item=>[Number(item.index),item]));
    const valid=indices.every(index=>byIndex.has(Number(index)));
    if(!valid)return null;
    const selected=indices.map(index=>byIndex.get(Number(index)));
    const accepted=selected.filter(row=>row.status==='ACCEPTED_BY_MAIL_TRANSPORT').length;
    const unsent=selected.filter(row=>row.status==='DRAFT').length;
    const unconfirmed=selected.length-accepted-unsent;
    const title=document.createElement('strong');
    title.textContent='Latest batch · '+accepted+' of '+selected.length+
      ' accepted by the sending server';
    const description=document.createElement('p');
    description.textContent=unconfirmed
      ? unconfirmed+' recipient(s) need reconciliation. Do not resend those recipients until you check their saved status.'
      : unsent
        ? unsent+' selected recipient(s) were not attempted. Check the saved status before further action.'
        : 'All selected recipients were processed. SMTP acceptance does not prove delivery to the recipient inbox.';
    const list=document.createElement('ul');
    list.style.cssText='margin:8px 0;padding-left:20px';
    for(const row of selected) {
      const li=document.createElement('li');
      li.textContent=String(row.supplier||'Supplier')+' — '+recipientState(row.status);
      list.appendChild(li);
    }
    receipt.replaceChildren(title,description,list);
    receipt.hidden=false;
    receipt.dataset.batchStatus=unconfirmed||unsent?'attention':'recorded';
    return {accepted,unconfirmed,unsent,processed:selected.length};
  }
  if(savedCase && savedDraft) {
    readSavedLedger(savedCase,savedDraft).then(data=>{
      const c=renderSavedLedger(data);
      // Replace any stale server-rendered page only once with the current
      // recipient table. No writing, no SMTP call and no new RFQ on refresh.
      const originalAccepted=Number(ledgerPanel.dataset.accepted||0);
      const originalConfirmed=Number(ledgerPanel.dataset.confirmed||0);
      const originalUnconfirmed=Number(ledgerPanel.dataset.open||0);
      syncSupplierRecipientRows(data);
      if(new URLSearchParams(location.search).has('send_review'))
        displayBatchReceipt(data,data.last_batch?.indices);
      if(c.confirmed!==originalConfirmed ||
         c.confirmed+c.legacy!==originalAccepted ||
         c.unconfirmed!==originalUnconfirmed) {
        // Stay on this page and retain the send confirmation; a redirect
        // previously looked like a dead button after SMTP had succeeded.
        if(ledgerNotice)ledgerNotice.textContent += ' Recipient statuses updated from saved records.';
      }
    }).catch(err=>{
      if(ledgerNotice) ledgerNotice.textContent =
        'Saved mail ledger could not be verified. Do not assume any unverified messages were sent. '+err.message;
    });
  }

  const sendForm = document.getElementById('aur-vendor-send-form');
  const sendButton = document.getElementById('aur-send-all-rfqs');
  const sendProgress = document.getElementById('aur-send-progress');
  const shortChecks = [...document.querySelectorAll('.aur-short-batch-select')];
  const shortCount = document.getElementById('aur-short-batch-selection');
  if (shortChecks.length && shortCount) {
    const updateShortSelection=() => {
      const checked=shortChecks.filter(c=>c.checked);
      if (checked.length>3) {
        checked[checked.length-1].checked=false;
      }
      const count=shortChecks.filter(c=>c.checked).length;
      shortCount.textContent=count+' of 3 selected. Only checked recipients will be sent.';
    };
    shortChecks.forEach(c=>c.addEventListener('change',updateShortSelection));
    updateShortSelection();
  }

  if (sendForm && sendButton && sendProgress) {
    sendForm.addEventListener('submit', async event => {
    // Native required-field validation previously stopped the submit event
    // before this listener, leaving the button looking completely inert.
    // Handle validation here and show a visible reason beside the button.
    event.preventDefault();
    if(sendForm.dataset.sending==='1')return; // no second SMTP POST in flight
    const explainSend = (message, isError=false) => {
      sendProgress.textContent = message;
      sendProgress.style.background=isError?'#fff2ef':
        sendForm.dataset.sending==='1'?'#fff7e9':'#edf7f0';
      sendProgress.style.borderColor=isError?'#e9b6aa':
        sendForm.dataset.sending==='1'?'#e6cf9f':'#a5ceb4';
      sendProgress.style.color=isError?'#77362e':'#163e34';
      sendProgress.setAttribute('role', isError ? 'alert' : 'status');
      sendProgress.setAttribute('aria-live', isError ? 'assertive' : 'polite');
      sendProgress.scrollIntoView({block:'nearest'});
    };
    const reviewBox=sendForm.querySelector('input[name="manual_review"]');
    if (!reviewBox?.checked) {
      explainSend('Before sending, tick the review and authorization checkbox immediately above this button.',true);
      reviewBox?.focus({preventScroll:true});
      return;
    }
    const chosen=sendForm.dataset.selectedOnly === '1'
      ? [...sendForm.querySelectorAll('.aur-short-batch-select:checked')].map(c=>Number(c.value))
      : [];
    if (sendForm.dataset.selectedOnly === '1' &&
        (chosen.length<1 || chosen.length>3 || new Set(chosen).size!==chosen.length)) {
      explainSend('Please select one, two or three unsent supplier emails in the table above.',true);
      return;
    }
    if (!sendForm.checkValidity()) {
      explainSend('Please complete the required form fields before sending.',true);
      return;
    }
    const originalSendLabel=sendButton.textContent;
    sendButton.disabled = true;
    sendButton.textContent='Sending… Please do not click again';
    sendForm.dataset.sending='1';
    sendForm.setAttribute('aria-busy','true');
    explainSend('Sending selected supplier enquiries. Your results will appear here as each batch is recorded. Do not click again.');
    const csrf = sendForm.querySelector('input[name="csrf"]')?.value;
    const caseRef = sendForm.querySelector('input[name="case_ref"]')?.value;
    const draftId = sendForm.querySelector('input[name="draft_id"]')?.value;
    const total = Number(sendForm.dataset.total || 0);
    if (!csrf || !caseRef || !draftId || total <= 0) {
      explainSend('RFQ information unavailable; reload the saved draft.',true);
      sendButton.disabled=false;
      sendButton.textContent=originalSendLabel;
      sendForm.dataset.sending='0';
      sendForm.removeAttribute('aria-busy');
      return;
    }
    // Short supplier round is one user-selected batch only. Never auto-loop
    // through the unselected recipients as the original bulk send does.
    if (sendForm.dataset.selectedOnly === '1') {
      const selected = chosen;
      if (selected.length < 1 || selected.length > 3 ||
          new Set(selected).size !== selected.length) {
        explainSend('Please tick 1 to 3 unsent supplier emails.',true);
        sendButton.disabled=false;
        sendButton.textContent=originalSendLabel;
        sendForm.dataset.sending='0';
        sendForm.removeAttribute('aria-busy');
        return;
      }
      try {
        const response = await fetch('/procurement/',{
          method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/json',Accept:'application/json'},
          body:JSON.stringify({task:'send',csrf,case_ref:caseRef,
            draft_id:draftId,manual_review:true,selected_indices:selected})
        });
        const result = await response.json().catch(()=>({}));
        if(!response.ok||result.ok!==true) {
          const reasons={
            rfq_smtp_test_required:'The internal mailbox test did not pass. Open Mail settings and check SMTP.',
            rfq_smtp_not_configured:'The sending mailbox is not configured. Open Mail settings.',
            refresh_german_supplier_wording_first:'Update the unsent German wording before sending.',
            select_one_to_three_suppliers:'Select one, two or three unsent recipients.',
            selected_supplier_already_attempted:'A selected supplier was already attempted. Check the saved status.',
            destination_requires_sanctions_review:'A destination or trade-compliance review is still required.',
            supplier_legal_review_acknowledgement_required:'Tick the review authorization checkbox.',
            rfq_duplicate_equipment_review_required:'Review duplicate original equipment entries before sending.',
            rfq_smtp_invalid_configuration:'Correct the AURENOEVA SMTP settings.'
          };
          throw new Error(reasons[result.error]||'Send response '+response.status+
            ': '+(result.error||'check the saved RFQ status'));
        }
        if(Number(result.progress?.processed_this_request)!==selected.length)
          throw new Error('The send receipt did not match the number selected.');
        // Verify with a separate authenticated GET to the immutable outbox.
        explainSend('Mail server response received. Checking each supplier in the saved RFQ…');
        const verified=await readSavedLedger(caseRef,draftId);
        if(Number(verified.progress?.accepted)!==Number(result.progress?.accepted) ||
           Number(verified.progress?.remaining)!==Number(result.progress?.remaining))
          throw new Error('Saved RFQ results differ from the send response.');
        const receipt=displayBatchReceipt(verified,selected);
        if(!receipt)throw new Error('Per-recipient results could not be verified.');
        renderSavedLedger(verified);
        syncSupplierRecipientRows(verified);
        const remaining=Number(verified.progress?.remaining||0);
        const needsReview=receipt.unconfirmed>0||receipt.unsent>0;
        explainSend('Batch recorded: '+receipt.accepted+' of '+receipt.processed+
          ' accepted by the sending server; '+receipt.unconfirmed+
          ' failed or unconfirmed; '+remaining+' other recipient(s) remain unsent. '+
          (needsReview?'Review the individual results below before further action.':
           'You can select the next unsent suppliers below.'));
        // Keep confirmation visible on the same page. A previous automatic
        // redirect hid this result and made a successful send look inert.
        const url=new URL(window.location.href);
        url.searchParams.set('send_review','1');
        url.hash='aur-send-progress';
        window.history.replaceState(null,'',url.toString());
        const reviewBox=sendForm.querySelector('input[name="manual_review"]');
        if(reviewBox)reviewBox.checked=false;
        sendButton.disabled=needsReview||remaining===0;
        sendButton.textContent=needsReview?'Check saved status before continuing':
          remaining?'Send next selected suppliers · Max 3':'All selected enquiries processed';
      }catch(error){
        explainSend('The send outcome needs verification: '+
          (error.message||'Unknown response')+'. Checking the saved RFQ; do not click Send again.',true);
        try{
          const verified=await readSavedLedger(caseRef,draftId);
          renderSavedLedger(verified);
          syncSupplierRecipientRows(verified);
          const recorded=displayBatchReceipt(verified,selected);
          if(recorded&&recorded.accepted)
            explainSend(recorded.accepted+' selected recipient(s) show SMTP acceptance in the saved ledger. '+
              'Review each supplier below. Do not resend any attempted recipient.',true);
        }catch(readError){
          explainSend('Unable to confirm the send outcome. Do not click Send again; '+
            'reload this RFQ and inspect the saved individual supplier statuses.',true);
        }
        sendButton.disabled=true;
        sendButton.textContent='Review saved RFQ status before further sending';
      }finally{
        sendForm.dataset.sending='0';
        sendForm.removeAttribute('aria-busy');
      }
      return;
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
          const verified=await readSavedLedger(caseRef,draftId);
          if(Number(verified.progress?.accepted)!==accepted ||
             Number(verified.progress?.remaining)!==unsent)
            throw new Error('Saved RFQ ledger does not match SMTP response');
          renderSavedLedger(verified);
          displayBatchReceipt(verified,verified.last_batch?.indices);
          explainSend(accepted+' of '+(p.total||total)+' supplier enquiries accepted by the sending server; '+
            failed+' failed/unconfirmed; '+unsent+' not yet sent. Review the recipient ledger below.');
          const url=new URL(window.location.href);
          url.searchParams.set('send_review','1');
          url.hash='aur-send-progress';
          window.history.replaceState(null,'',url.toString());
          sendButton.textContent='Batch completed · See saved status';
          sendForm.dataset.sending='0';
          sendForm.removeAttribute('aria-busy');
          return;
        }
        if (Number(p.processed_this_request || 0) === 0)
          throw new Error('No further send progress; review saved outbox.');
      }
      throw new Error('Maximum batch count reached; reopen the draft to continue.');
    } catch (error) {
      explainSend('Mail dispatch interrupted: ' + (error.message || 'Unknown error') +
        '. Do not click send again. Reload this saved RFQ to check accepted, unattempted and ' +
        'failed/unconfirmed recipients in the private outbox before taking further action.',true);
      // Keep disabled: the HTTP response can fail after a message was accepted.
      // A reload shows authoritative persisted status and prevents blind repeats.
      sendButton.disabled = true;
      sendButton.textContent='Review saved RFQ status before further sending';
      sendForm.dataset.sending='0';
      sendForm.removeAttribute('aria-busy');
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
            // Read the file again AFTER the POST. This independent readback
            // must agree before telling the user sending has completed.
            const verified=await readSavedLedger(caseRef,draftId);
            if(Number(verified.progress?.accepted)!==accepted ||
               Number(verified.progress?.recovery_remaining)!==eligible)
              throw new Error('Mail responses and persisted RFQ ledger disagree');
            renderSavedLedger(verified);
            recoveryProgress.textContent = 'Saved RFQ independently verified: ' +
              Number(verified.progress?.smtp_250_confirmed||0) +
              ' SMTP 250 acceptances and ' +
              Number(verified.progress?.accepted_without_smtp_250_proof||0) +
              ' earlier accepted records without saved 250 proof. Refreshing recipient ledger.';
            window.location.assign('/procurement/?case=' + encodeURIComponent(caseRef) +
              '&draft=' + encodeURIComponent(draftId) + '&ledger_refreshed=1#aur-rfq-approval');
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
