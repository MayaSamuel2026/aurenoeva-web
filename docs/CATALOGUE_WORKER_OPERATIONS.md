# Aurenoeva independent catalogue worker and inventory evidence

Operational state: **source and local tests complete; VPS installation and website snapshot binding require authenticated Hostinger access.** Never claim an unattended run or live catalogue publication on the strength of a GitHub commit.

## Design — deliberately small

- **One application-owned worker**, `tools/catalogue_worker.py`; Python standard library only.
- **One private SQLite file**, WAL and an OS lock; each source URL is its own persisted task. Immediate commit per page; if a source fails or returns 403/429 the others continue, and the failed URL waits with exponential backoff. 8 consecutive errors quarantine a URL for inspection. Sources are allowlisted HTTPS hosts; redirects outside approved hosts are rejected; robots.txt restrictions are respected.
- **One Hostinger systemd timer**, hourly with three minutes' jitter. Each run is bounded to 30 pages or four minutes; host rejects concurrent runs. Next run resumes the queue. Existing jobs and unrelated services are not touched.
- **One inventory-evidence table**, separate from products. Official product pages are proof of product/model existence, **not actual vendor stock**. Unknown inventory remains `UNKNOWN`; seller-provided stock reports become `REVIEW_REQUIRED`, never auto-verified. No customer name, prices, discounts, margins, supplier contacts, quantities, or stock are published in the public snapshot.
- **One simple public-safe snapshot** in private storage for catalogue search. PHP adapter may read it from an absolute path *outside public_html*, configured by `AURENOEVA_CATALOGUE_SNAPSHOT`; fallback to existing CORE search until the new file exists. No changes to the operational, already confirmed requirement-to-case/email handler.

## Immediate installation (existing Hostinger VPS)

After checking actual host access, the running website/server topology, and securing the private path:

```bash
sudo useradd --system --home /var/lib/aurenoeva --shell /usr/sbin/nologin aurenoeva  # only if not already present
sudo install -d -o aurenoeva -g aurenoeva -m 700 /var/lib/aurenoeva
sudo install -d -m 755 /opt/aurenoeva /opt/aurenoeva/tools /opt/aurenoeva/config
sudo install -m 644 tools/catalogue_worker.py /opt/aurenoeva/tools/catalogue_worker.py
sudo install -m 644 config/catalogue_sources.json /opt/aurenoeva/config/catalogue_sources.json
sudo install -m 644 ops/aurenoeva-catalogue.service /etc/systemd/system/
sudo install -m 644 ops/aurenoeva-catalogue.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl start aurenoeva-catalogue.service
sudo systemctl status aurenoeva-catalogue.service --no-pager
sudo systemctl enable --now aurenoeva-catalogue.timer
sudo systemctl list-timers --all | grep aurenoeva-catalogue
```

Before creating a user or enabling this timer, check the existing VPS units/cron to avoid *duplicate* catalogue crawlers; if there is already an Aurenoeva-owned timer, reconcile and reuse it instead.

Verify actual inventory/readback:

```bash
sudo -u aurenoeva python3 /opt/aurenoeva/tools/catalogue_worker.py \
  --db /var/lib/aurenoeva/catalogue.sqlite3 \
  --sources /opt/aurenoeva/config/catalogue_sources.json --status
sudo journalctl -u aurenoeva-catalogue.service -n 80 --no-pager
sudo sqlite3 /var/lib/aurenoeva/catalogue.sqlite3 'select manufacturer,model,last_seen from products limit 10;'
```

Inventory of **reported supplier stock** is accepted only as an explicit private JSON report with schema `aurenoeva-supplier-stock-report/1`, supplier_id, reported_at, and items with manufacturer, model, reported_quantity and optional HTTPS proof_url. Unknown product IDs cannot be inserted by such a report. Reports expire after 48 hours. They are not evidence of owned stock. To import, run the worker once with `--supplier-feed /var/lib/aurenoeva/private-report.json` (private path; never commit supplier reports). Full legal/sanctions and transaction controls remain with the separate RFQ workflow, unaffected by this catalogue.

## Website publication (two potentially separate Hostinger machines)

**Do not assume the Hostinger VPS and the PHP `public_html` host share a filesystem.** If separate, securely copy the already-generated `catalogue-public.json` snapshot to private storage on the PHP host using an existing approved SSH/SFTP deployment account, with upload-then-atomic-rename and readback. Configure `AURENOEVA_CATALOGUE_SNAPSHOT` in the PHP host environment to that private absolute path. Never expose JSON inside a public web directory.

Prepare next website release by copying `hotfix/v8.2.6.10/catalogue_local.php` and the corresponding `catalogue-search.php` into the release site's root. Integrate those two file copies in the existing single `publish.yml` release workflow; do **not** replace the working production files or trigger publication until verified in staging. The new fallback is activated only when the private catalogue file is genuinely available; otherwise the previous search behavior continues.

Run two real smoke tests before declaring success:
1. Source test: run worker and inspect SQLite entries, last-run counters, error handling, source URLs and private JSON snapshot (manufacturer/model, no public prices or stock claims).
2. Website test: `GET /catalogue-search.php?q=WATO%20EX-35` returns real `canonical_id`, manufacturer, model and source reference; no supplier/private fields. Complete test RFQ and check delivery and operations case remain operational.

Priority sources are manufacturer pages for Mindray WATO EX-35, Anthos dental units and Haag-Streit products (sources were confirmed publicly accessible on 20 Oct 2026). No unverified supplier stock is claimed. The Uzbekistan customer sheet (44 equipment options / 39 numbered requirements) is **demand**, not automatically 44 sellable catalogue products; continue to use existing private `procurement_inbox.py` and RFQ compliance workflow.

## Tests

`python3 -m unittest discover -s tests -p 'test_catalogue_worker.py' -v` — eight isolated tests on crash recovery, duplicate suppression, status, private inventory, and export.