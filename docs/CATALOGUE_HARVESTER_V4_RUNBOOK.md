# AURENOEVA global product and supplier harvester — V4

## Production scope
One existing Hostinger SQLite database at /var/lib/aurenoeva/catalogue.sqlite3;
one systemd service aurenoeva-catalogue.service and timer.
The old catalogue_worker.py is retired from /opt/aurenoeva/tools after a
consistent SQLite backup and replaced by catalogue_harvester.py. No new VPS,
subscription, CORE, scraper proxy, or competing supplier database.

The website supplier authority remains:
<domain>/.aurenoeva-private/supplier-intelligence/worldwide-vendors.json.
RFQ case files, customer documents and original contact details are unchanged.

## How discovery works
- All 49 website categories across seven sectors seed broad manufacturer,
  distributor and catalogue searches using the same lightweight Bing RSS and
  DuckDuckGo Lite pathways used by the RFQ research flow.
- Existing 19 manufacturer research source groups and their official seeds
  continue as evidence sources, without blocking other websites.
- Every run also authenticates to the existing /catalogue-sync.php endpoint
  to ingest previously discovered RFQ supplier websites and known product pages.
- HTML and structured Product JSON-LD yield model identities with source URLs.
  Search-result leads alone never become confirmed catalogue products.
- Website-published email/phone evidence remains distinguishable from a
  speculative guessed contact, stock claim or distribution authorization.
- Any broken URL, 429, 403, 404, DNS failure, timeout, invalid HTML, or blocked
  search provider only defers that individual URL with bounded backoff.
  No 8-failure quarantine and no global stop.
- Concurrent requests read public HTTPS pages, and SQLite writes are performed
  serially and committed immediately per URL.
- Old products, first_seen dates, prior inventory and historical task tables
  are preserved. An hourly/30-minute run refreshes instead of replacing.
- The usual HTTPS publisher transfers private product-to-vendor relationships
  into the same RFQ supplier master while updating the website search snapshot.
  Existing emails and verified contact states cannot be downgraded.
- The RFQ candidate selector reuses model-specific evidence when preparing
  enquiries; stock, permitted exports, supplier authorization and quotations
  are still separate decisions.

## Additional six catalogue channels — 21 October 2026

The canonical config now contains 30 source groups with 110 initial seed URLs.
The 49 original website categories remain the discovery taxonomy.

- ERSAAL: industrial category, manufacturer-directory and brand-specific
  detail pages. Extract manufacturer and MPN from the actual product-detail
  title; ERSAAL remains the potential supplier, not the manufacturer.
- LabX: laboratory manufacturer directory and supplier/listing leads.
  Directory entries are not assumed to be verified product identities.
- Agilent: broader laboratory product family/product-detail pages.
- Phoenix Contact: both industrial automation/connectors and electrical
  power-equipment product categories.
- Furuno: marine navigation, radar, AIS, sensors and actual model-detail pages.
- Wärtsilä: marine propulsion and generator/engine model-detail pages.

Follow paths for ERSAAL /en/kategorien/, /en/hersteller/ and /en/product/,
plus LabX /manufacturers/; these were previously blocked by the overly narrow
generic /product URL traversal rule. Product, vendor, and seller status remain
separate evidence types. Manufacturer pages and directory totals (e.g. ERSAAL's
advertised product counts) are not internal imported product counts.

Existing robots policies, HTTPS and non-private address checks remain active.
A blocked source is retried without bringing down other source processing.
The source configuration is retrieved from GitHub by the existing V4 installer;
the VPS is not automatically updated merely because the configuration commits.

## Activate one existing engine
In the original Hostinger VPS root shell:

    curl -fsSL https://raw.githubusercontent.com/MayaSamuel2026/aurenoeva-web/main/ops/activate-harvester-v4.sh -o /tmp/aur-v4.sh && bash /tmp/aur-v4.sh

This installs the new worker, retires the old worker source outside the active
tools directory, preserves the original database, starts a run without waiting
for it to finish, and enables every-30-minute runs through the night.

## Verify real—not merely syntax—results
    journalctl -u aurenoeva-catalogue.service -n 100 --no-pager
    systemctl list-timers --all aurenoeva-catalogue.timer --no-pager
    python3 /opt/aurenoeva/tools/catalogue_harvester.py \
      --db /var/lib/aurenoeva/catalogue.sqlite3 \
      --sources /opt/aurenoeva/config/catalogue_harvester.json \
      --export /var/lib/aurenoeva/catalogue-public.json --status

    python3 - <<'PY'
    import sqlite3
    with sqlite3.connect('/var/lib/aurenoeva/catalogue.sqlite3') as db:
        for query in [
           'SELECT COUNT(*) FROM products',
           'SELECT COUNT(DISTINCT website) FROM vendor_leads',
           'SELECT COUNT(*) FROM vendor_product_evidence',
           'SELECT COUNT(*) FROM harvest_queue',
           'SELECT sector,COUNT(*) FROM products GROUP BY sector',
           'SELECT started,pages,products,vendors,failed FROM harvest_runs ORDER BY id DESC LIMIT 10']:
            print('\n',query)
            for row in db.execute(query):print(row)
    PY

Also check https://aurenoeva.com/procurement/ and test a real known model in
/catalogue-search.php with an ordinary search. The private administration shows
both the published catalogue and worldwide vendor master metrics.

## 22 October 2026: 27,773 products and 24 MB publication repair

At 04:33 Europe/Berlin, the user-provided Hostinger journal confirmed:
- Canonical SQLite database: **27,773** product identities
- JSON export: **27,772** identity records
- Last successful website readback remains at the previous published baseline
  because the publisher reported `DELIVERY_FAILED: missing_or_oversized_snapshot`
- Export vendor evidence was capped at 25,000 product links and 5,000 leads;
  both arbitrary export caps were removed in the V4 source

Root cause: `tools/catalogue_publish.py` refused any combined catalogue +
private vendor-evidence JSON above 24 MB. Additionally, the website receiver
limited its old snapshot to 24 MB and its search reader would ignore any
snapshot larger than 24 MB, as well as all products after the first 80,000.

Repair: the replacement publisher sends product-only records in bounded
batches with accepted-input-count and SHA-256 acknowledgements, followed by
private vendor-evidence batches to the same authenticated receiver. Each
batch is durable and idempotent. The website merges products while retaining
historic records, and the RFQ-owned supplier master merges evidence separately.

The existing canonical database and private RFQ files are not replaced.

### Apply the repair on the EXISTING VPS only after the website release

```bash
curl -fsSL https://raw.githubusercontent.com/MayaSamuel2026/aurenoeva-web/main/ops/repair-large-catalogue-publish.sh -o /tmp/aur-repair-publish.sh && bash /tmp/aur-repair-publish.sh
```

The script first runs unit tests and a token-authenticated preflight call
against the **live** new receiver. If the site has not actually deployed,
it aborts before altering VPS files. Then it backs up each installed worker
file, replaces the old publisher and harvester (no service interruption), and
republishes current JSON product and vendor data. It checks a real website
model after acknowledgement. The original half-hour timer continues.

`PUBLISHED` now means all submitted product and vendor-evidence batches were
acknowledged; publishing receipts are not saved for incomplete jobs.

A PHP JSON snapshot is a short-term search implementation, not ideal
for hundreds of thousands of records. A performance-oriented indexed
search format will be needed as the catalogue grows substantially.
No claim of verified stock/availability derives from these products.

## Status convention
A GitHub source commit, a passed CI run, and even an updated production website
are NOT proof that the Hostinger VPS harvester ran. Only service journal entries,
growing SQLite counters, successful authenticated vendor syncing and the live
website readback establish the running state. Never invent product totals or
claim stock, legally verified supply or completed RFQs from catalogue evidence.

## Automatic qualified VPS updates — 22 October 2026

The same existing `aurenoeva-catalogue.service` now checks for a qualified
GitHub source revision **before each scheduled run** using
`ExecStartPre=-+/usr/bin/python3 /opt/aurenoeva/tools/catalogue_auto_update.py`.
No additional VPS timer, paid service, SSH endpoint, or catalogue database
is introduced.

One **one-time privileged bootstrap** is required to install this updater
into the root-owned `/opt/aurenoeva` code directory and systemd unit.
Run the existing `ops/activate-harvester-v4.sh` installer once after its
GitHub qualification passes; it now installs and asserts the pre-run hook.
Subsequently, ordinary GitHub crawler, publisher and source-configuration
changes update unattended. They must be part of a complete successful
`AURENOEVA private procurement qualification` workflow on the exact
`main` commit. The VPS downloads source and offline tests pinned to that
immutable SHA, reruns tests as the restricted `aurenoeva` user, backs up
previous files and atomically replaces ONLY the three crawler/publisher/
self-updater Python files and the catalogue source configuration.
Code failures roll back and do not prevent the scheduled old-version crawl.
SQLite products, exports, website data, RFQ cases, email and credentials
are never replaced by this updater.

Evidence: `journalctl -u aurenoeva-catalogue.service` shows
`CATALOGUE_AUTO_UPDATE=INSTALLED sha=...`, or
`CATALOGUE_AUTO_UPDATE=UNCHANGED` for a stable release.
The existing timer remains 30 minutes. Changes to systemd unit semantics
still require a privileged release; PHP website deployment remains separate.
