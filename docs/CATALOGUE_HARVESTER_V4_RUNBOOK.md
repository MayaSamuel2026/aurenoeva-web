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
changes update unattended. The updater chooses the newest completed,
successful `AURENOEVA private procurement qualification` workflow from
`main` and downloads only that workflow's immutable commit SHA. Later
unqualified or unrelated commits cannot be installed. The VPS downloads source and offline tests pinned to that
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


## Permanent ChatGPT-to-Hostinger VPS status bridge — 31 October 2026

**Do not ask the owner whether Hostinger VPS access exists again.** The owner
has full Hostinger administration access. A proven, reusable operational bridge
already exists in `MayaSamuel2026/noeva-core`:

- GitHub Actions workflow:
  `.github/workflows/aurenoeva-crawler-vps-status.yml`
- Uses the existing `NOEVA_CORE_VPS_SSH_KEY` GitHub Actions secret and
  the Hostinger VPS `179.198.203.247` (`srv1958260`).
- Runs automatically every hour (minute 17), and can be manually dispatched
  from GitHub Actions; source edits also trigger a qualification readback.
- Reads `systemctl show`, the journal and SQLite
  `/var/lib/aurenoeva/catalogue.sqlite3` in **read-only** mode. No new
  credentials, duplicate crawler, SSH server, database or plugin is required.
- Retrieve the most recent `AURENOEVA VPS crawler operational status`
  GitHub Actions run and its job logs using the connected GitHub tool.
  Parse the lines starting `AURENOEVA_CRAWLER_STATUS_JSON=`,
  `AURENOEVA_QUEUE_PRIORITY_SAMPLE=`,
  `AURENOEVA_RECENT_UPDATER_TEST_ERRORS=` and
  `AURENOEVA_INSTALLED_RELEASE_RECEIPT=`.
- Distinguish **VPS crawl activity**, **new unique products**,
  **published catalogue records** and **homepage searchable records**.
  A fresh website snapshot alone is never crawler-run proof.

**Production findings on 2026-10-31 05:57 UTC**: 141,735 canonical products,
48 completed runs and 2,837 additions in the prior 24 hours, but the three
latest completed runs added **zero products**, with 7,616,601 queued URLs and
16,543 failed requests in 24 hours. The queue showed significant
`www.ifm.com` concentration in an overdue sample. This is a real
discovery-productivity issue, not proof of a stopped systemd service.
The source of excessive failures and any fair-queue repairs must be established
using VPS evidence, not simply by restarting the crawler.

**Updater/publisher regression remediation on 2026-10-31**:
`tools/catalogue_auto_update.py` now stages all test fixtures, makes nested
test directories traversable by the restricted service user, sets the
isolated test `PYTHONPATH`, and preserves an already installed qualified SHA
rather than regressing to an older production-site release when GitHub's API is
unavailable. `tools/catalogue_publish.py` compares the actual
`published_parts` to the eligible published count; the distinct filtered
`searchable_parts` count may be smaller. The existing NOEVA→VPS bridge
installed qualified revision `67950b5aaaa4b021bdff709e49284cc5beda8fe3`
and the real VPS qualification reported `CATALOGUE_UPDATE_TESTS=PASS`
and `AURENOEVA_PUBLISH_READBACK_FIX_INSTALLED=PASS` on
2026-10-31 06:06 UTC.

The website publication must still be verified separately by reading the
**live** `https://aurenoeva.com/catalogue-count.php` and checking that
its `reconciliation` contains current crawl telemetry with
`crawl_status_version=1`, and that its database and published counts match
the new canonical export. Never conflate a green GitHub qualification,
an installed VPS revision and an acknowledged website publication.

## 31 October 2026 07:23 UTC: recovery installation verified, acceptance pending

- VPS qualified source revision **9e7b08ea2ab62bd7ba940836699ec59e2d78c7b6** was installed. The staged restricted-user tests passed. See [VPS deployment](https://github.com/MayaSamuel2026/noeva-core/actions/runs/41180225475).
- Both supplier-frontier fairness and nontechnical exclusion attestation are installed. The existing 07:00 UTC crawler process was preserved, so that run cannot prove behavior of the new scheduler.
- The qualified updater now fetches only the procurement-private-qualification workflow rather than a potentially oversized all-workflow history. The [VPS audit](https://github.com/MayaSamuel2026/noeva-core/actions/runs/41180292964) verified the live targeted GitHub API feed returned PASS and the latest qualified source revision.
- The website release has passed for this source revision. The latest PHP publication guard permits an exactly reconciled nontechnical reduction even when canonical database identities have grown, and accepts the missing historical withdrawn counter. Ordinary unexpected removals still fail closed.
- **Full production recovery is NOT yet accepted.** At 07:23 UTC, VPS database=141,738 canonical identities but the public website was still serving a snapshot from 05:47:07 UTC: snapshot canonical identities=141,735, published=140,531 and browse-searchable=139,846. The fair-frontier cursor had zero hosts because the new scheduler had not yet started. [Strict VPS-to-website acceptance run](https://github.com/MayaSamuel2026/noeva-core/actions/runs/41180370100) failed correctly with PUBLIC_CATALOGUE_RECONCILIATION_PENDING.
- Acceptance after a newly started crawler run: verify its installed release, new URL cursor usage, processed supplier pages, unique product yield, failure rates, updated SQLite export and a website snapshot with reconciliation.db_products equal to the current canonical database count and reconciliation.eligible_products equal to published_parts. The site crawler field must have current operational telemetry.
- Preserve the existing SQLite catalogue, historical RFQs, supplier relationships and 7.6-million-plus URL backlog throughout recovery; no destructive queue reset or replacement crawler.

## 31 October 2026 07:42 UTC: verified productive crawl and live website

The newly deployed supplier-fair crawler began its first run at 07:30:23 UTC.
By 07:41 UTC its SQLite harvest ledger recorded 792 processed pages,
61 new unique product identities, 251 failed requests, and persistent
source-frontier cursor activity across 36 supplier hosts. Newly discovered
products span medical, test, industrial, and laboratory categories.

Live HTTPS catalogue readback at 07:42 UTC confirmed an atomic site snapshot
updated at 07:37:16 UTC. The publication reconciled 141,774 canonical
identities at export, 139,915 published products, 139,882 searchable
products, and crawler status RECENTLY_COMPLETED. The canonical VPS DB
had already grown to 141,803 during the ongoing run: a 29-product
expected difference between two independently timed measurements.

The permanent hourly NOEVA CORE crawler status workflow now verifies
installed scheduler/publisher, qualified-update feed, live SQLite crawl
activity, source-frontier cursor and per-source yield, the website
snapshot freshness within two hours, and exact internal publication
accounting: exported_products=db_products, eligible_products plus
excluded_products equals exported_products, eligible_products equals
published_parts. Do NOT require equality with simultaneously changing
live SQLite counts. Any stale or inconsistent website snapshot must
fail the hourly check instead of silently reporting success.

Verified run: https://github.com/MayaSamuel2026/noeva-core/actions/runs/41181622197

The 7.6m+ historical URL backlog remains intact. Fair and selective
discovery is now productive, but the backlog and HTTP failure rate
still need ongoing monitoring. No product stock or seller offer
availability is inferred from mere crawler discovery.

### Strict independent recovery acceptance — PASS, 07:42:47 UTC

The NOEVA CORE production acceptance workflow completed successfully:
https://github.com/MayaSamuel2026/noeva-core/actions/runs/41181613240

Verified together in one read-only VPS-to-HTTPS audit:
- Installed qualified SHA 9e7b08ea2ab62bd7ba940836699ec59e2d78c7b6.
- Supplier-fair frontier enabled, with 36 persisted supplier cursors.
- The 07:30:23 UTC crawler run had added 65 canonical product identities
  while still running (828 pages processed, 257 failed).
- Canonical SQLite at acceptance: 141,803 products.
- Fresh public snapshot at 07:37:16 UTC: 141,774 canonical at export;
  139,915 published, 139,882 searchable, 1,859 accounted exclusions
  (including 1,858 nontechnical and 1 generic).
- Public crawler telemetry present, status RECENTLY_COMPLETED.
- Published snapshot internally reconciled, with expected 29-product
  newer live SQLite delta while discovery was actively running.
- The separate hourly status workflow also passed its permanent
  publication freshness and consistency gate:
  https://github.com/MayaSamuel2026/noeva-core/actions/runs/41181622197

Recovery is ACCEPTED for production crawler operation, genuine new-product
discovery, authenticated publication and operational monitoring. Do not
interpret it as completion of the historic 7.6-million-URL backlog or
elimination of the elevated HTTP failure rate.
