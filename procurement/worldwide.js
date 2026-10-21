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
        if (!res.ok) throw new Error('Search request failed (' + res.status + ')');
        const data = await res.json();
        if (data.ok !== true) throw new Error('Invalid search result');
        const done = Number(data.processed||0);
        if (done <= 0) break;
        processed += done;
        leads += Number(data.new_vendor_pages||0);
        failed += Number(data.provider_failures||0);
      }
      progress.textContent = 'Worldwide research finished: ' + processed + ' entries checked, ' + leads +
        ' supplier/source leads, ' + failed + ' search provider errors. Opening updated catalogue.';
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
