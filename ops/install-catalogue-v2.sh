#!/usr/bin/env bash
# Upgrade the EXISTING AURENOEVA catalogue worker in-place; preserve SQLite.
set -euo pipefail
if [[ "$(id -u)" -ne 0 ]]; then echo "Run as root (sudo bash)."; exit 1; fi
ROOT=/opt/aurenoeva
DB=/var/lib/aurenoeva/catalogue.sqlite3
UNIT=/etc/systemd/system/aurenoeva-catalogue.service
[[ -f "$ROOT/tools/catalogue_worker.py" && -f "$ROOT/config/catalogue_sources.json" && -f "$UNIT" ]] ||
  { echo "Existing crawler installation not found; aborting without changes."; exit 1; }
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/tools" "$WORK/tests" "$WORK/config" "$WORK/ops"
BASE=https://raw.githubusercontent.com/MayaSamuel2026/aurenoeva-web/ee5c0c46818b67221a19e94a0222e1b0eb91425d
for file in tools/catalogue_worker.py tests/test_catalogue_worker.py config/catalogue_sources.json ops/aurenoeva-catalogue.service; do
  curl -fsSL --retry 2 --connect-timeout 10 --max-time 45 "$BASE/$file" -o "$WORK/$file"
done
python3 -m py_compile "$WORK/tools/catalogue_worker.py"
python3 -m json.tool "$WORK/config/catalogue_sources.json" >/dev/null
(cd "$WORK" && python3 -m unittest discover -s tests -p test_catalogue_worker.py -v)
# Keep original service/database and a rollback copy of each modified file.
cp -a "$ROOT/tools/catalogue_worker.py" "$ROOT/tools/catalogue_worker.py.pre-v2"
cp -a "$ROOT/config/catalogue_sources.json" "$ROOT/config/catalogue_sources.json.pre-v2"
cp -a "$UNIT" "$UNIT.pre-v2"
install -m 644 "$WORK/tools/catalogue_worker.py" "$ROOT/tools/catalogue_worker.py"
install -m 644 "$WORK/config/catalogue_sources.json" "$ROOT/config/catalogue_sources.json"
install -m 644 "$WORK/ops/aurenoeva-catalogue.service" "$UNIT"
systemctl daemon-reload
systemctl enable --now aurenoeva-catalogue.timer
systemctl start aurenoeva-catalogue.service
echo "=== LAST RUN ==="
journalctl -u aurenoeva-catalogue.service -n 15 --no-pager
echo "=== DATABASE ==="
python3 - "$DB" <<'PY'
import sqlite3, sys
db = sqlite3.connect(sys.argv[1])
print("Products:", db.execute("SELECT count(*) FROM products").fetchone()[0])
print("Queued source URLs:", db.execute("SELECT count(*) FROM tasks").fetchone()[0])
for row in db.execute("SELECT manufacturer,model FROM products ORDER BY manufacturer,model LIMIT 30"):
    print(" | ".join(row))
PY
echo "Catalogue V2 installed. Publishing to the website requires separate private snapshot connection."
