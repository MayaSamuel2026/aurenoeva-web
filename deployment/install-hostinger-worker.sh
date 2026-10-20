#!/usr/bin/env bash
# Install AURENOEVA's private processing and sanctions service on its existing VPS.
# Does NOT create a public route or a second CRM login.
set -euo pipefail
SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP=/opt/noeva-aurenoeva
STATE="$APP/private"
test "$(id -u)" = 0 || { echo "ROOT_REQUIRED"; exit 2; }
for name in procurement_intake.py procurement_rfq.py procurement_sanctions.py \
  procurement_case_store.py procurement_hostinger_worker.py; do
  test -f "$SOURCE/tools/$name" || { echo "SOURCE_MISSING:$name"; exit 2; }
done
python3 -m unittest discover -s "$SOURCE/tests" -p 'test_procurement_*.py' -q
python3 -m compileall -q "$SOURCE/tools"/procurement_*.py
if ! command -v flock >/dev/null || ! command -v cron >/dev/null; then
  echo "HOSTINGER_NATIVE_CRON_OR_FLOCK_MISSING"; exit 3
fi
install -d -m 0755 "$APP" "$APP/app-tools" "$APP/backups"
install -d -m 0700 "$STATE" "$STATE/inbox" "$STATE/sanctions" "$STATE/parties"
chmod 0700 "$STATE" "$STATE/inbox" "$STATE/sanctions" "$STATE/parties"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
for name in procurement_intake.py procurement_rfq.py procurement_sanctions.py \
  procurement_case_store.py procurement_hostinger_worker.py; do
  if test -f "$APP/app-tools/$name"; then
    cp -p "$APP/app-tools/$name" "$APP/backups/$name.$stamp"
  fi
  install -m 0644 "$SOURCE/tools/$name" "$APP/app-tools/$name"
done
# No stored customer evidence is touched. A pre-existing canonical DB is
# retained exactly as it is; changes use SQLite WAL/transactions.
if test -f /etc/cron.d/noeva-aurenoeva-procurement; then
  cp -p /etc/cron.d/noeva-aurenoeva-procurement "$APP/backups/cron.$stamp"
fi
cat > /etc/cron.d/noeva-aurenoeva-procurement <<EOF
SHELL=/bin/bash
PATH=/usr/local/bin:/usr/bin:/bin
*/15 * * * * root /usr/bin/flock -n $STATE/worker.lock /usr/bin/python3 $APP/app-tools/procurement_hostinger_worker.py --inbox $STATE/inbox --db $STATE/cases.sqlite3 --feed-dir $STATE/sanctions --party-dir $STATE/parties --country UZ >>$STATE/worker.log 2>&1
15 2 * * * root /usr/bin/flock -n $STATE/feed.lock /usr/bin/python3 $APP/app-tools/procurement_sanctions.py refresh --feed-dir $STATE/sanctions >>$STATE/feed.log 2>&1
EOF
chmod 0644 /etc/cron.d/noeva-aurenoeva-procurement
# One disposable end-to-end write / exact readback, completely separate
# from the real case DB and inbox. Preserve no test customer data.
TEST="$(mktemp -d "$APP/private/.qualification-XXXXXX")"
cleanup() { rm -rf "$TEST"; }
trap cleanup EXIT
mkdir "$TEST/inbox" "$TEST/parties" "$TEST/feeds"
printf 'No.,Equipment description,Qty.\n1,TEST MRI,1\n,Alternative TEST MRI,\n' > "$TEST/inbox/sample.csv"
python3 "$APP/app-tools/procurement_hostinger_worker.py" \
 --inbox "$TEST/inbox" --db "$TEST/cases.sqlite3" --feed-dir "$TEST/feeds" \
 --party-dir "$TEST/parties" --country UZ > "$TEST/out.json"
python3 - "$TEST/out.json" <<'PY'
import json,sys
v=json.load(open(sys.argv[1]))
assert v['ok'] is True and v['processed']==1,v
item=v['cases'][0]
assert item['rows']==2 and item['readback'] is True and item['legal_state']=='REVIEW_REQUIRED',item
print('AURENOEVA_CANONICAL_WRITE_READBACK=PASS')
PY
if ! systemctl is-active --quiet cron; then
  systemctl start cron
fi
if ! systemctl is-active --quiet cron; then
  echo "AURENOEVA_CRON_NOT_ACTIVE";exit 4
fi
# Verify source-list retrieval now; failure is reported and keeps the legal
# engine on REVIEW_REQUIRED, rather than treating absent data as clearance.
if timeout 115 /usr/bin/python3 "$APP/app-tools/procurement_sanctions.py" refresh \
  --feed-dir "$STATE/sanctions" > "$TEST/feeds-result.json" 2>&1; then
    echo "AURENOEVA_SANCTIONS_REFRESH=PASS"
else
    echo "AURENOEVA_SANCTIONS_REFRESH=BLOCKED_REVIEW_REQUIRED"
fi
echo "AURENOEVA_VPS_WORKER_INSTALLED=PASS"
echo "AURENOEVA_SCHEDULE_ACTIVE=PASS"
echo "AURENOEVA_PUBLIC_UPLOAD_DEPLOYED=NO"
echo "AURENOEVA_LIVE_CUSTOMER_CASE_IMPORTED=NO"
