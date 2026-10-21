# AURENOEVA owned catalogue → existing website search

## Implemented architecture (21 October 2026)
One Hostinger VPS runs the existing `aurenoeva-catalogue.service` and stores product identities in SQLite. It exports only a public-safe JSON snapshot. One HTTPS POST sends the snapshot to `https://aurenoeva.com/catalogue-sync.php` with a single secret generated via the existing protected staff admin. The PHP receiver validates and atomically saves `domains/aurenoeva.com/.aurenoeva-private/catalogue-public.json`. Existing `/catalogue-search.php` reads the file; no CORE API, separate CRM, FTP account, or new daemon is required.

**Publishing boundary:** Source and production GitHub branch contain the PHP changes. Hostinger Git auto-deployment and actual public search readback still require operational verification. Backend is never considered connected solely because GitHub contains the files.

## Activate once

1. Open **https://aurenoeva.com/procurement/** after the website release is available. Sign in as the existing administrator. Under **Live product catalogue**, press **Generate / rotate catalogue sync key**. Copy the key; it appears only once.
2. On the **VPS root terminal**, not shared-hosting SSH, run:
```bash
curl -fsSL https://raw.githubusercontent.com/MayaSamuel2026/aurenoeva-web/main/ops/connect-catalogue-search.sh -o /tmp/connect-catalogue-search.sh
bash /tmp/connect-catalogue-search.sh
```
3. Paste the key only into the VPS terminal prompt. The setup checks Python publisher tests, installs it alongside the existing worker, and invokes HTTPS publication without waiting for another crawl. It also enables the original systemd timer's post-crawl publisher. It then checks public search with an actual model from the private snapshot and prints `LIVE_WEBSITE_CATALOGUE_SEARCH=PASS` when verified.

The site receives product identity, manufacturer, model, description, last seen and source only. Supplier details, real inventory, prices, customer enquiries and profit margins remain private. No special routing is needed: unchanged site URLs and the existing procurement case process are preserved.

## Diagnostics
* VPS count: `sqlite3 /var/lib/aurenoeva/catalogue.sqlite3 'SELECT count(*) FROM products;'`
* Worker/publisher journal: `journalctl -u aurenoeva-catalogue.service -n 40 --no-pager`
* Website admin shows the snapshot count; search should return `source: "AURENOEVA_OWNED_CATALOGUE"` plus matching `canonical_id`.
* If pairing fails before saving a snapshot, the previous private snapshot is preserved. If a key rotates, rerun the VPS pairing command. The publisher republishes each hourly run even for unchanged snapshots, so a restored website backup can recover.
* If the sitemap crawler has produced zero qualifying models, the publisher will not replace a good website snapshot with empty results. A working transfer cannot fabricate products.
