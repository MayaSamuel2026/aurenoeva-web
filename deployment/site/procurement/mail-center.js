/* AURENOEVA Mail Center. Reuses the existing authenticated procurement page.
 * Never renders untrusted email HTML; all text is inserted with textContent.
 */
(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('aur-mail-center');
    if (!root) return;
    const folderButtons = [...root.querySelectorAll('[data-mail-folder]')];
    const list = root.querySelector('#aur-mail-list');
    const detail = root.querySelector('#aur-mail-detail');
    const compose = root.querySelector('#aur-mail-compose');
    const editor = root.querySelector('#aur-mail-compose');
    const status = root.querySelector('#aur-mail-feedback');
    const search = root.querySelector('#aur-mail-search');
    const previous = root.querySelector('#aur-mail-prev');
    const next = root.querySelector('#aur-mail-next');
    const heading = root.querySelector('#aur-mail-folder-name');
    const attachmentsInput = root.querySelector('#aur-mail-attachments');
    const attachedNames = root.querySelector('#aur-mail-selected-files');
    const richEditor = root.querySelector('#aur-mail-rich-editor');
    const normalButton = root.querySelector('#aur-mail-normal');
    const boldButton = root.querySelector('#aur-mail-bold');
    const csrf = document.querySelector('input[name="csrf"]')?.value;
    let folder = 'INBOX', page = 1, current = null, messages = [], more = false;

    const label = name => ({
      'INBOX': 'Inbox', 'INBOX.Sent': 'Sent',
      'INBOX.Junk': 'Junk', 'INBOX.Trash': 'Trash'
    })[name] || name;
    const notice = (message, error = false) => {
      status.textContent = message;
      status.setAttribute('role', error ? 'alert' : 'status');
      status.classList.toggle('is-error', error);
    };
    const text = (name, value) => {
      const node = document.createElement(name);
      node.textContent = String(value ?? '');
      return node;
    };
    const api = async (params, data = null) => {
      const url = new URL('/procurement/', location.origin);
      for (const [key, value] of Object.entries(params)) url.searchParams.set(key, value);
      const options = {credentials: 'same-origin', cache: 'no-store'};
      if (data) {
        options.method = 'POST';
        if (data instanceof FormData) {
          data.set('csrf', csrf);
          options.headers = {'Accept': 'application/json'};
          options.body = data;
        } else {
          options.headers = {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'};
          options.body = new URLSearchParams({...data, csrf}).toString();
        }
      }
      const response = await fetch(url, options);
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || result.status || 'mail_request_failed');
      return result;
    };
    const keyForSend = () => {
      const bytes = new Uint8Array(16);
      crypto.getRandomValues(bytes);
      return [...bytes].map(n => n.toString(16).padStart(2, '0')).join('');
    };

    // Browser rich text is never sent unfiltered. Only escaped characters,
    // line breaks and explicitly selected strong emphasis leave the editor.
    const escapeMailText = value => String(value)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    function cleanMailHtml() {
      function visit(node) {
        if (node.nodeType === Node.TEXT_NODE) return escapeMailText(node.nodeValue || '').replace(/\r\n?|\n/g,'<br>');
        if (node.nodeType !== Node.ELEMENT_NODE) return '';
        const tag = node.tagName.toUpperCase();
        if (['SCRIPT','STYLE','SVG','IMG','IFRAME','OBJECT'].includes(tag)) return '';
        if (tag === 'BR') return '<br>';
        const children = Array.from(node.childNodes).map(visit).join('');
        if (['B','STRONG'].includes(tag)
          || (tag === 'SPAN' && (/^(bold|[6-9]00)$/i.test(node.style.fontWeight || ''))))
          return children ? '<strong>' + children + '</strong>' : '';
        if (['DIV','P','LI'].includes(tag)) return children + '<br>';
        return children;
      }
      let html = Array.from(richEditor.childNodes).map(visit).join('');
      // Whitespace outside meaningful content is not part of the RFQ message.
      html = html.replace(/(?:<br>)+$/g,'');
      // Decode entities for the text/plain MIME alternative.
      const decoder = document.createElement('textarea');
      decoder.innerHTML = html.replace(/<br>/g,'\n').replace(/<\/?strong>/g,'');
      return {html, plain: decoder.value.trim()};
    }
    // Pasting from ChatGPT, Word or email must NOT transport external styles,
    // fonts, links, images or arbitrary HTML to suppliers.
    richEditor.addEventListener('paste', event => {
      const pasted = event.clipboardData?.getData('text/plain');
      if (typeof pasted !== 'string') return;
      event.preventDefault();
      richEditor.focus();
      document.execCommand('insertText',false,pasted.replace(/\r\n?/g,'\n'));
    });
    const syncBoldState = () => {
      try {boldButton.setAttribute('aria-pressed',String(document.queryCommandState('bold')));}
      catch (error) {boldButton.setAttribute('aria-pressed','false');}
    };
    for (const button of [normalButton,boldButton])
      button.addEventListener('mousedown',event => event.preventDefault());
    normalButton.addEventListener('click',() => {
      richEditor.focus();document.execCommand('removeFormat',false,null);syncBoldState();
    });
    boldButton.addEventListener('click',() => {
      richEditor.focus();document.execCommand('bold',false,null);syncBoldState();
    });
    richEditor.addEventListener('keyup',syncBoldState);
    richEditor.addEventListener('mouseup',syncBoldState);

    function showList() {
      editor.hidden = true;
      detail.hidden = true;
      list.hidden = false;
    }
    function renderList() {
      list.replaceChildren();
      const query = search.value.trim().toLowerCase();
      const visible = messages.filter(message =>
        [message.from, message.to, message.subject, message.preview, message.reference]
          .some(value => String(value || '').toLowerCase().includes(query)));
      if (!visible.length) {
        const empty = text('p', query ? 'No matching messages on this page.' : 'No messages in this folder.');
        empty.className = 'muted';
        list.appendChild(empty);
      }
      for (const mail of visible) {
        const row = document.createElement('button');
        row.type = 'button';
        row.className = 'aur-mail-row' + (mail.unread ? ' unread' : '');
        const top = document.createElement('div');
        top.className = 'aur-mail-row-top';
        top.appendChild(text('strong', folder === 'INBOX.Sent' ? mail.to : mail.from));
        const timestamp = Date.parse(mail.date);
        top.appendChild(text('time', Number.isFinite(timestamp) ? new Date(timestamp).toLocaleString() : mail.date));
        row.appendChild(top);
        row.appendChild(text('b', mail.subject || '(No subject)'));
        const preview = text('small', mail.preview || '');
        row.appendChild(preview);
        if (mail.status === 'DELIVERY_UNCONFIRMED') {
          const warning = text('span', 'Sending not confirmed');
          warning.className = 'aur-mail-warning'; row.appendChild(warning);
        }
        if (mail.reference) {
          const tag = text('span', mail.reference);
          tag.className = 'aur-mail-reference'; row.appendChild(tag);
        }
        row.addEventListener('click', () => open(mail.uid));
        list.appendChild(row);
      }
      previous.disabled = page <= 1;
      next.disabled = !more;
      heading.textContent = label(folder);
    }
    async function refresh() {
      notice('Loading mail…');
      try {
        const payload = await api({mail_action: 'list', folder, page});
        messages = payload.items || [];
        more = !!payload.more;
        renderList();
        showList();
        notice(payload.warning
          ? 'Mail connection is temporarily unavailable; showing available sent history.'
          : 'Messages synchronized with Hostinger.');
      } catch (error) {
        messages = [];
        renderList();
        notice('Mailbox unavailable. Check the Hostinger mailbox connection in Administration.', true);
      }
    }
    function openCompose(reply = null) {
      current = reply;
      showList();
      list.hidden = true;
      editor.hidden = false;
      compose.reset();
      richEditor.replaceChildren();
      boldButton.setAttribute('aria-pressed','false');
      attachmentsInput.setCustomValidity('');
      root.querySelector('#aur-mail-compose-heading').textContent = reply ? 'Reply' : 'New message';
      if (reply) {
        const from = String(reply.from || '');
        const address = from.match(/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i)?.[0] || '';
        compose.elements.to.value = folder === 'INBOX.Sent' ? reply.to : address;
        compose.elements.subject.value = /^Re:/i.test(reply.subject) ? reply.subject : 'Re: ' + reply.subject;
      }
      compose.elements.send_key.value = keyForSend();
      attachedNames.textContent = '';
      compose.elements.to.focus();
      notice('Write your message. Sending requires your explicit confirmation.');
    }
    async function open(uid) {
      notice('Opening message…');
      try {
        const payload = await api({mail_action: 'read', folder, uid});
        const mail = payload.message;
        current = mail;
        list.hidden = true; editor.hidden = true; detail.hidden = false;
        detail.replaceChildren();
        const bar = document.createElement('div');
        bar.className = 'aur-mail-detail-bar';
        const back = text('button', '← Back to messages');
        back.type = 'button';back.className = 'ghost';
        back.addEventListener('click', showList);
        bar.appendChild(back);
        const reply = text('button', 'Reply');
        reply.type = 'button';
        reply.addEventListener('click', () => openCompose(mail));
        bar.appendChild(reply);
        detail.appendChild(bar);
        detail.appendChild(text('h3', mail.subject));
        const meta = document.createElement('div');
        meta.className = 'aur-mail-meta';
        meta.appendChild(text('p', 'From: ' + mail.from));
        meta.appendChild(text('p', 'To: ' + mail.to));
        meta.appendChild(text('p', 'Date: ' + mail.date));
        if (mail.status) meta.appendChild(text('p', 'Sending status: ' + mail.status));
        if (mail.imap_sent_archive) meta.appendChild(text('p', 'Hostinger Sent folder: ' + (mail.imap_sent_archive === 'ARCHIVED' ? 'Archived' : 'Not confirmed (Mail Center record retained)')));
        detail.appendChild(meta);
        if (mail.reference) {
          const link = document.createElement('a');
          link.href = '/procurement/?case=' + encodeURIComponent(mail.reference) + '#supplier-replies';
          link.textContent = 'Open related RFQ ' + mail.reference + ' →';
          detail.appendChild(link);
        }
        detail.appendChild(text('pre', mail.body || '(No plain-text message content available.)'));
        if (Array.isArray(mail.attachments) && mail.attachments.length) {
          const box = document.createElement('div');
          box.className = 'aur-mail-sent-attachments';
          box.appendChild(text('strong', 'Sent attachments'));
          mail.attachments.forEach((attachment, index) => {
            const link = document.createElement('a');
            const url = new URL('/procurement/', location.origin);
            for (const [key,value] of Object.entries({
              mail_action:'download_attachment',folder,uid,attachment_index:index
            })) url.searchParams.set(key,value);
            link.href = url.toString();
            link.textContent = String(attachment.name || 'Attachment') + ' (' +
              Math.ceil(Number(attachment.size || 0)/1024) + ' KB)';
            box.appendChild(link);
          });
          detail.appendChild(box);
        }
        if (mail.download) {
          const link = document.createElement('a');
          const url = new URL('/procurement/', location.origin);
          for (const [k,v] of Object.entries({mail_action:'download',folder,uid})) url.searchParams.set(k,v);
          link.href = url.toString();
          link.textContent = mail.has_attachments ? 'Download original message and attachments (.eml)' : 'Download original message (.eml)';
          link.className = 'aur-mail-download';
          detail.appendChild(link);
        }
        notice('Message opened.');
      } catch (error) {
        notice('Could not open this message.', true);
      }
    }

    folderButtons.forEach(button => button.addEventListener('click', () => {
      folder = button.dataset.mailFolder; page = 1; search.value = '';
      folderButtons.forEach(item => item.setAttribute('aria-pressed', String(item === button)));
      refresh();
    }));
    root.querySelector('#aur-mail-new').addEventListener('click', () => openCompose());
    // WP09: an authenticated staff member may review a buyer response that
    // summarizes recorded supplier quotes. This only opens a draft.
    document.querySelector('#aur-prepare-buyer-reply')?.addEventListener('click', event => {
      const button = event.currentTarget;
      const to = String(button.dataset.buyerTo || '').trim();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(to)) {
        notice('Buyer email is missing or invalid. No draft opened.', true);
        return;
      }
      document.querySelector('[data-aur-tab="mail"]')?.click();
      openCompose();
      compose.elements.to.value = to;
      compose.elements.subject.value = String(button.dataset.buyerSubject || '').slice(0,250);
      richEditor.textContent = String(button.dataset.buyerBody || '');
      root.scrollIntoView({behavior:'smooth', block:'start'});
      notice('Buyer update is a draft. Check supplier evidence and terms before you explicitly send.');
      compose.elements.to.focus();
    });
    root.querySelector('#aur-mail-reload').addEventListener('click', refresh);
    root.querySelector('#aur-mail-cancel').addEventListener('click', showList);
    previous.addEventListener('click', () => {page = Math.max(1, page-1);refresh();});
    next.addEventListener('click', () => {page++;refresh();});
    search.addEventListener('input', renderList);
    attachmentsInput.addEventListener('change', () => {
      const files = [...attachmentsInput.files];
      const size = files.reduce((total, file) => total + file.size, 0);
      const invalid = files.length > 5 || files.some(file => file.size < 1 || file.size > 8388608)
        || size > 10485760;
      attachedNames.textContent = files.length
        ? files.map(file => file.name + ' (' + Math.ceil(file.size / 1024) + ' KB)').join(' · ')
        : 'No attachments selected.';
      if (invalid) attachedNames.textContent += ' — Too many or too large; choose smaller files.';
      attachmentsInput.setCustomValidity(invalid ? 'Up to 5 files, 8 MB each, 10 MB total.' : '');
      if (invalid) attachmentsInput.reportValidity();
    });

    compose.addEventListener('submit', async event => {
      event.preventDefault();
      const normalized = cleanMailHtml();
      if (!normalized.plain || normalized.plain.length > 50000) {
        notice('Enter a message of up to 50,000 characters before sending.',true);
        richEditor.focus();return;
      }
      compose.elements.body.value = normalized.plain;
      compose.elements.body_html.value = normalized.html;
      const sendButton = compose.querySelector('button[type="submit"]');
      sendButton.disabled = true;
      notice('Submitting to Hostinger SMTP…');
      try {
        const data = new FormData(compose);
        data.set('mail_action','send');
        const result = await api({mail_action:'send'}, data);
        notice(result.status === 'SMTP_ACCEPTED'
          ? 'Hostinger accepted the message. This does not guarantee recipient inbox delivery.'
          : 'Delivery not confirmed. Inspect the Sent history before considering another send.',
          result.status !== 'SMTP_ACCEPTED');
        folder = 'INBOX.Sent';page = 1;
        folderButtons.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.mailFolder === folder)));
        await refresh();
        notice(result.status === 'SMTP_ACCEPTED'
          ? (result.imap_sent_archive === 'ARCHIVED'
            ? 'SMTP accepted · saved in Mail Center and Hostinger Sent.'
            : 'SMTP accepted · saved in Mail Center Sent (Hostinger Sent copy not confirmed).')
          : 'Sending not confirmed. Review your Mail Center Sent history; do not resend automatically.',
          result.status !== 'SMTP_ACCEPTED');
      } catch (error) {
        notice('Sending could not be confirmed. Check Sent before retrying; do not send a duplicate.', true);
      } finally {
        sendButton.disabled = false;
      }
    });

    let loaded = false;
    const boot = () => {
      if (!loaded) {loaded = true;refresh();}
    };
    folderButtons[0]?.setAttribute('aria-pressed','true');
    root.addEventListener('click', boot, {once:true});
    // Load only when mail tab is chosen, rather than fetching private email
    // each time a staff member opens the procurement workflow.
    document.getElementById('aur-tab-mail')?.addEventListener('click', boot);
    if (location.hash === '#tab-mail') boot();
  });
})();
