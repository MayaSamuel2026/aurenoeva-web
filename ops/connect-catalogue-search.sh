#!/usr/bin/env bash
# Pair AURENOEVA's existing VPS catalogue to the existing HTTPS website.
# No new daemon, SFTP account or database. Enter the staff-issued key privately.
set -euo pipefail
[[ $(id -u) -eq 0 ]] || { echo "Run as root on the AURENOEVA VPS."; exit 1; }
WORKER=/opt/aurenoeva/tools/catalogue_worker.py
DB=/var/lib/aurenoeva/catalogue.sqlite3
SNAPSHOT=/var/lib/aurenoeva/catalogue-public.json
PUBLISHER=/opt/aurenoeva/tools/catalogue_publish.py
TOKEN_FILE=/var/lib/aurenoeva/catalogue-sync-token
DROP=/etc/systemd/system/aurenoeva-catalogue.service.d/publish.conf
[[ -f "$WORKER" && -f "$DB" ]] || { echo "Existing VPS catalogue not found. Do not create another database."; exit 1; }
id -u aurenoeva >/dev/null || { echo "Existing 'aurenoeva' service user missing."; exit 1; }
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"; unset SYNC_KEY' EXIT
mkdir -p "$WORK/tools" "$WORK/tests"
BASE=https://raw.githubusercontent.com/MayaSamuel2026/aurenoeva-web/135945586dcf2f527da53aadec413c5317b75b22
for file in tools/catalogue_publish.py tests/test_catalogue_publish.py; do
    curl -fsSL --retry 2 --connect-timeout 10 --max-time 45 "$BASE/$file" -o "$WORK/$file"
done
python3 -m py_compile "$WORK/tools/catalogue_publish.py"
(cd "$WORK" && python3 -m unittest discover -s tests -p test_catalogue_publish.py -v)
echo
echo "Generate a catalogue sync key under https://aurenoeva.com/procurement/ first."
read -r -s -p "Paste the 64-character key here (not into chat): " SYNC_KEY
echo
[[ "$SYNC_KEY" =~ ^[a-f0-9]{64}$ ]] || { echo "Invalid key. No configuration changed."; exit 1; }
[[ ! -e "$PUBLISHER" ]] || cp -a "$PUBLISHER" "$PUBLISHER.pre-https-$(date -u +%Y%m%d%H%M%S)"
install -m 644 "$WORK/tools/catalogue_publish.py" "$PUBLISHER"
umask 077
printf '%s\n' "$SYNC_KEY" > "$TOKEN_FILE"
chown aurenoeva:aurenoeva "$TOKEN_FILE"
chmod 600 "$TOKEN_FILE"
unset SYNC_KEY
mkdir -p "$(dirname "$DROP")"
if [[ ! -f "$DROP" ]]; then
    cat > "$DROP" <<'UNIT'
[Service]
ExecStartPost=-/usr/bin/python3 /opt/aurenoeva/tools/catalogue_publish.py
UNIT
fi
systemctl daemon-reload
systemctl enable --now aurenoeva-catalogue.timer
echo
echo "=== CURRENT VPS CATALOGUE ==="
python3 - "$DB" "$SNAPSHOT" <<'PY'
import json,sqlite3,sys
from pathlib import Path
with sqlite3.connect(sys.argv[1]) as db:
    print("SQLite product records:",db.execute("SELECT count(*) FROM products").fetchone()[0])
p=Path(sys.argv[2])
if p.is_file():
    print("Exported records:",len(json.loads(p.read_text()).get("items",[])))
else:
    print("Snapshot not yet present; run the crawler service to export records.")
PY
if [[ -f "$SNAPSHOT" ]]; then
    echo "=== HTTPS PUSH TO WEBSITE ==="
    if ! runuser -u aurenoeva -- /usr/bin/python3 "$PUBLISHER"; then
        echo "WEBSITE PUSH FAILED. Confirm the website release is deployed and the key matches."
        exit 1
    fi
    echo "=== VERIFY LIVE WEBSITE SEARCH AGAINST A REAL CATALOGUE RECORD ==="
    python3 - "$SNAPSHOT" <<'PY'
import json,sys
from pathlib import Path
from urllib.parse import urlencode
from urllib.request import urlopen
item = json.loads(Path(sys.argv[1]).read_text())['items'][0]
model = item['model']
uri = 'https://aurenoeva.com/catalogue-search.php?' + urlencode({'q':model})
with urlopen(uri, timeout=20) as response:
    result = json.loads(response.read(80000))
matches = result.get('items',[])
if result.get('source') != 'AURENOEVA_OWNED_CATALOGUE' or not any(
       p.get('canonical_id') == item.get('canonical_id') for p in matches):
    raise SystemExit('BLOCKED: live website search does not yet show the new snapshot')
print('LIVE_WEBSITE_CATALOGUE_SEARCH=PASS')
print('Matched model:', model)
print('Results:', len(matches))
PY
else
    echo "Token configured; the next successful crawler export will publish automatically."
fi
echo "Catalogue HTTPS synchronization configured on existing service."
