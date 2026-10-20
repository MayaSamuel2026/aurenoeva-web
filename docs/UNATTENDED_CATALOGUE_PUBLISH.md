# Aurenoeva unattended catalogue publishing — Hostinger only

**20 October 2026 — SOURCE PREPARED, NOT ACTIVE ON PRODUCTION.**

The VPS has a working hourly crawler, SQLite database, and private identity-only snapshot. Production website search is not yet linked to these results. Never conflate manufacturer product existence with confirmed vendor stock.

## One existing worker; one private delivery

The existing aurenoeva-catalogue.service writes SQLite and a private catalogue-public.json. The companion tools/catalogue_publish.py validates the snapshot, discards generic collection headings (including "Surgical carts"), limits fields to canonical identity, manufacturer, specific model, source URL, description and last-seen date, and syncs by pinned SSH/SFTP to a private destination **outside public_html**. It uses a temporary upload, atomic remote rename, readback SHA-256 and a local last-published digest. If the destination is unconfigured, it does nothing. If the delivery fails, the crawler keeps working and logs the failure; the prior good snapshot remains available where possible.

Only one-time secure pairing is needed. Use the existing Hostinger hPanel/SSH account, ideally through an authenticated ChatGPT Work browser session rather than TinyFish.

## Required one-time infrastructure pairing

1. Check if the VPS and PHP website share the same filesystem. Do not assume shared Hostinger branding means they do.
2. If separate, identify the live PHP document root and create a sibling .aurenoeva-private directory, mode 0700, owned by the website account, never inside public_html.
3. Provision a restricted website SSH/SFTP identity, verify the actual server SSH host key and pin it, and put the private key on the VPS under aurenoeva user-only readable storage. Do not publish credentials in source, tickets, or chat.
4. Install tools/catalogue_publish.py under /opt/aurenoeva/tools/ and the drop-in ops/aurenoeva-catalogue-publish.conf under /etc/systemd/system/aurenoeva-catalogue.service.d/publish.conf.
5. Create /etc/aurenoeva/catalogue-site.env for the service (not in GitHub): AURENOEVA_SFTP_HOST, AURENOEVA_SFTP_USER, AURENOEVA_SFTP_REMOTE_FILE, AURENOEVA_SFTP_KEY, AURENOEVA_SFTP_KNOWN_HOSTS. The latter two refer to private file paths, not inline secrets.
6. Run systemctl daemon-reload and start the existing service once. Validate remote atomic replacement for TWO consecutive writes and verify readback, failure handling and no duplicated service. OpenSSH SFTP should support POSIX atomic rename of an existing destination. If not, implement and test an approved SSH atomic mv instead, never remove the old snapshot before new one is ready.
7. Run the existing .github/workflows/publish.yml release once *after* checking production drift, backups, website hosting topology and actual PHP private-path read access. The workflow stages hotfix/v8.2.6.10/catalogue_local.php and hotfix/v8.2.6.11/catalogue-search.php. Existing customer requirement/email/case routes and revenue-loop event reporting must remain intact.
8. Verify live catalogue-search.php?q=WATO%20EX-35 returns a genuine product ID and source without prices or supplier details, and repeat a controlled enquiry/email test. Confirm the timer remains active and no other crawler is affected.

PHP search looks for the configured AURENOEVA_CATALOGUE_SNAPSHOT absolute private file or automatically checks a .aurenoeva-private directory alongside the PHP DOCUMENT_ROOT. If no owned snapshot exists, the pre-existing legacy search fallback remains intact.

## Fully unattended AFTER secure pairing

- Existing hourly timer invokes the crawler. Per-page commits, bounded wall-clock work and failure retries prevent a stalled source blocking others.
- The same service invokes the companion publisher. Unchanged snapshots are skipped, changed snapshots are transferred atomically and checked, and logged failures are retried next run.
- Website search reads current identity-only records. Unverified supplier stock is NOT part of the public catalogue. No customer names, confidential supplier records, amounts or margins appear.
- Expand allowlisted official manufacturer/supplier sources only with verified evidence. Uzbekistan customer requests remain private demand records, not fictitious sellable inventory.
- RFQ dispatch, sanctions/export-control decisions, legal classifications, quotation and procurement commitments require the corresponding human/authoritative checks and cannot be treated as fully automatic approval.

## Current acceptance status

- [x] Existing timer and initial catalogue writes confirmed by VPS output.
- [x] Companion publisher, selective model filter, PHP adapter and offline tests prepared in source.
- [ ] Secure VPS-to-website route provisioned and two live updates verified.
- [ ] Updated PHP search route deployed with backup and tested in production.
- [ ] True end-to-end website catalogue search and no-regression acceptance passed.

Do not report installation or end-to-end production readiness until the unchecked items are confirmed against actual Hostinger services.
