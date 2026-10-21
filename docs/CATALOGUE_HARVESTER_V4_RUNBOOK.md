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

## Status convention
A GitHub source commit, a passed CI run, and even an updated production website
are NOT proof that the Hostinger VPS harvester ran. Only service journal entries,
growing SQLite counters, successful authenticated vendor syncing and the live
website readback establish the running state. Never invent product totals or
claim stock, legally verified supply or completed RFQs from catalogue evidence.
