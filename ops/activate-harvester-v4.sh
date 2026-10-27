#!/usr/bin/env bash
# Activate AURENOEVA V4 on EXISTING Hostinger VPS; preserve databases and RFQ data.
set -euo pipefail
umask 077
[[ $(id -u) == 0 ]] || { echo "Run as root on the existing Hostinger VPS"; exit 2; }
APP=/opt/aurenoeva
DB=/var/lib/aurenoeva/catalogue.sqlite3
STATE=/var/lib/aurenoeva
SERVICE=aurenoeva-catalogue.service
TIMER=aurenoeva-catalogue.timer
UNIT=/etc/systemd/system/$SERVICE
[[ -s "$DB" && -f "$UNIT" && -d "$APP/tools" ]] || {
 echo "NO_EXISTING_CATALOGUE: refusing to build a competing database"; exit 3;
}
id aurenoeva >/dev/null
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/tools" "$WORK/config" "$WORK/tests" "$WORK/ops"
BASE=https://raw.githubusercontent.com/MayaSamuel2026/aurenoeva-web/main
for file in \
  tools/catalogue_harvester.py tools/catalogue_publish.py tools/catalogue_classification.py tools/catalogue_prices.py \
  tools/catalogue_auto_update.py config/catalogue_harvester.json \
  ops/aurenoeva-catalogue.service ops/aurenoeva-catalogue.timer \
  tests/test_catalogue_harvester.py tests/test_catalogue_publish.py tests/test_catalogue_classification.py \
  tests/test_catalogue_auto_update.py tests/test_catalogue_prices.py tests/test_catalogue_lifecycle.py; do
  curl -fsSL --retry 3 --retry-delay 2 --connect-timeout 10 --max-time 60 \
    "$BASE/$file" -o "$WORK/$file"
done
python3 -m py_compile "$WORK/tools/catalogue_harvester.py" "$WORK/tools/catalogue_publish.py" "$WORK/tools/catalogue_classification.py" "$WORK/tools/catalogue_prices.py" "$WORK/tools/catalogue_auto_update.py"
python3 -m json.tool "$WORK/config/catalogue_harvester.json" >/dev/null
(cd "$WORK" && python3 -m unittest discover -s tests -p 'test_catalogue_*.py' -v)
echo "=== STOP AND RETIRE ORIGINAL CRAWLER ==="
systemctl stop "$TIMER" 2>/dev/null || true
systemctl stop "$SERVICE" 2>/dev/null || true
TS=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP="$DB.backup-before-harvester-v4-$TS"
python3 - "$DB" "$BACKUP" <<'PY'
import sqlite3,sys
source=sqlite3.connect('file:'+sys.argv[1]+'?mode=ro',uri=True)
with sqlite3.connect(sys.argv[2]) as copy:source.backup(copy)
print("EXISTING_PRODUCTS=",source.execute("SELECT COUNT(*) FROM products").fetchone()[0])
print("BACKUP=",sys.argv[2])
source.close()
PY
mkdir -p "$APP/retired-crawlers" "$APP/config"
if [[ -f "$APP/tools/catalogue_worker.py" ]];then
  mv "$APP/tools/catalogue_worker.py" "$APP/retired-crawlers/catalogue_worker.py.$TS"
fi
for file in "$UNIT" "/etc/systemd/system/$TIMER" "$APP/tools/catalogue_publish.py";do
 if [[ -f "$file" ]];then cp -a "$file" "$file.before-harvester-v4-$TS";fi
done
install -m 0644 "$WORK/tools/catalogue_prices.py" "$APP/tools/catalogue_prices.py"
install -m 0644 "$WORK/tools/catalogue_classification.py" "$APP/tools/catalogue_classification.py"
install -m 0644 "$WORK/tools/catalogue_harvester.py" "$APP/tools/catalogue_harvester.py"
install -m 0644 "$WORK/tools/catalogue_publish.py" "$APP/tools/catalogue_publish.py"
install -m 0644 "$WORK/tools/catalogue_auto_update.py" "$APP/tools/catalogue_auto_update.py"
install -m 0644 "$WORK/config/catalogue_harvester.json" "$APP/config/catalogue_harvester.json"
install -m 0644 "$WORK/ops/aurenoeva-catalogue.service" "$UNIT"
install -m 0644 "$WORK/ops/aurenoeva-catalogue.timer" "/etc/systemd/system/$TIMER"
# The already paired private HTTPS token is not rotated or exposed.
if [[ -s "$STATE/catalogue-sync-token" ]];then
  mkdir -p "/etc/systemd/system/$SERVICE.d"
  # Remove the previous '-' prefix that silently classified failed publication
  # as a successful service run. The database is already checkpointed safely.
  printf '[Service]\nExecStartPost=/usr/bin/python3 %s/tools/catalogue_publish.py\n' "$APP" > "/etc/systemd/system/$SERVICE.d/publish.conf"
fi
# Preserve the existing timer; the one installed pre-run updater now handles
# future qualified GitHub source changes without another root shell deployment.
# Retain enough time for the preflight plus bounded harvest and publication.
mkdir -p "/etc/systemd/system/$SERVICE.d"
printf '[Service]\nTimeoutStartSec=3300s\n' \
  > "/etc/systemd/system/$SERVICE.d/zz-harvester-v4.conf"
systemctl daemon-reload
systemctl enable --now "$TIMER"
systemctl start --no-block "$SERVICE"
echo "=== CONFIRM ONE INSTALLED ENGINE ==="
systemctl cat "$SERVICE" | grep -E 'ExecStart=|ExecStartPre=|ExecStartPost=|TimeoutStartSec=|RuntimeMaxSec='
systemctl cat "$SERVICE" | grep -q 'ExecStartPre=-+/usr/bin/python3 /opt/aurenoeva/tools/catalogue_auto_update.py' ||
  { echo "CATALOGUE_AUTO_UPDATER_NOT_INSTALLED"; exit 1; }
echo "CATALOGUE_AUTO_UPDATE_BOOTSTRAP=PASS"
systemctl list-timers --all "$TIMER" --no-pager || true
echo "=== CURRENT DATABASE: ORIGINAL PRODUCTS PRESERVED ==="
python3 - "$DB" "$BACKUP" <<'PY'
import sqlite3,sys
with sqlite3.connect('file:'+sys.argv[1]+'?mode=ro',uri=True) as current:
 with sqlite3.connect('file:'+sys.argv[2]+'?mode=ro',uri=True) as earlier:
  old={row[0] for row in earlier.execute("SELECT product_id FROM products")}
  new={row[0] for row in current.execute("SELECT product_id FROM products")}
  assert not old-new,"HISTORICAL_PRODUCTS_MISSING"
  print("HISTORICAL_PRODUCT_IDENTITIES_PRESERVED=PASS",len(old))
  print("CURRENT_PRODUCTS=",len(new))
PY
echo "HARVESTER_V4_START_QUEUED=PASS"
echo "The single existing systemd timer runs every 30 minutes, including overnight."
echo "Follow product/vendor growth with:"
echo "  journalctl -u aurenoeva-catalogue.service -f"
echo "  runuser -u aurenoeva -- python3 /opt/aurenoeva/tools/catalogue_harvester.py --db /var/lib/aurenoeva/catalogue.sqlite3 --sources /opt/aurenoeva/config/catalogue_harvester.json --export /var/lib/aurenoeva/catalogue-public.json --status"
