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
