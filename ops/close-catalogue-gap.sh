#!/usr/bin/env bash
# One-time closure of AURENOEVA's partial public catalogue.
# Use ONLY the existing products SQLite and original website token.
set -euo pipefail
umask 077
[[ $(id -u) -eq 0 ]] || { echo "ERROR: Use the existing VPS root terminal"; exit 2; }
APP=/opt/aurenoeva
STATE=/var/lib/aurenoeva
DB="$STATE/catalogue.sqlite3"
SERVICE=aurenoeva-catalogue.service
TIMER=aurenoeva-catalogue.timer
UNIT="/etc/systemd/system/$SERVICE"
[[ -s "$DB" && -s "$STATE/catalogue-sync-token" && -d "$APP/tools" && -f "$UNIT" ]] ||
 { echo "PRESERVED_DATABASE_TOKEN_OR_EXISTING_CRAWLER_NOT_FOUND"; exit 3; }
id aurenoeva >/dev/null || { echo "EXISTING_CRAWLER_USER_NOT_FOUND"; exit 3; }
LOCK="$STATE/catalogue-gap-closure.lock"
exec 9>"$LOCK"
flock -n 9 || { echo "CATALOGUE_GAP_REPAIR_ALREADY_RUNNING"; exit 4; }

echo "=== RECONCILE ONE EXISTING DATABASE; NO RECRAWL ==="
read -r BEFORE_COUNT < <(python3 - "$DB" <<'PY'
import sqlite3,sys
with sqlite3.connect('file:'+sys.argv[1]+'?mode=ro',uri=True) as conn:
 print(conn.execute('SELECT COUNT(*) FROM products').fetchone()[0])
PY
)
[[ "$BEFORE_COUNT" =~ ^[0-9]+$ && "$BEFORE_COUNT" -gt 0 ]] ||
 { echo "CANONICAL_PRODUCTS_NOT_READABLE"; exit 3; }
echo "CANONICAL_SQLITE_PRODUCTS=$BEFORE_COUNT"

# The existing publish workflow writes this receipt ONLY after running the
# catalogue and PHP qualification. Fetch it from GitHub's static CDN instead
# of the anonymous Actions API, which may be rate-limited on this VPS.
RELEASE_SHA=$(python3 - <<'PY'
import re,urllib.request
url="https://raw.githubusercontent.com/MayaSamuel2026/aurenoeva-web/production/VERSION.txt"
req=urllib.request.Request(url,headers={"User-Agent":"AurenoevaCatalogueQualifiedRelease/1.0","Cache-Control":"no-cache"})
with urllib.request.urlopen(req,timeout=25) as resp:
 data=resp.read(512).decode("utf-8","strict")
match=re.fullmatch(r"Aurenoeva V[^\n]+ production ([a-f0-9]{40})\s*",data)
if not match:raise SystemExit("NO_QUALIFIED_PRODUCTION_RELEASE_RECEIPT")
print(match.group(1))
PY
)
[[ "$RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo "INVALID_QUALIFIED_REVISION"; exit 3; }
echo "QUALIFIED_RELEASE_SHA=$RELEASE_SHA"

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
chmod 0755 "$WORK"
for file in \
 tools/catalogue_harvester.py \
 tools/catalogue_publish.py \
 tools/catalogue_auto_update.py \
 config/catalogue_harvester.json \
 tests/test_catalogue_harvester.py \
 tests/test_catalogue_publish.py \
 tests/test_catalogue_auto_update.py \
 ops/aurenoeva-catalogue.service \
 ops/aurenoeva-catalogue.timer;do
  mkdir -p "$WORK/$(dirname "$file")"
  chmod 0755 "$WORK/$(dirname "$file")"
  curl --fail --location --silent --show-error --retry 3 --max-time 65 \
   "https://raw.githubusercontent.com/MayaSamuel2026/aurenoeva-web/$RELEASE_SHA/$file" \
   -o "$WORK/$file"
  chmod 0644 "$WORK/$file"
done

python3 -m py_compile "$WORK/tools/catalogue_harvester.py" \
  "$WORK/tools/catalogue_publish.py" "$WORK/tools/catalogue_auto_update.py"
python3 -m json.tool "$WORK/config/catalogue_harvester.json" >/dev/null
echo "=== QUALIFY PATCH BEFORE INSTALLATION ==="
(cd "$WORK" && runuser -u aurenoeva -- env PYTHONDONTWRITEBYTECODE=1 \
 python3 -B -m unittest discover -s tests -p 'test_catalogue_*.py' -q)
echo "CRAWLER_AND_PUBLICATION_TESTS=PASS"

STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP="$STATE/catalogue-gap-backup-$STAMP"
mkdir -m 0700 "$BACKUP"
# Use SQLite's online backup API so the original WAL database is never copied
# inconsistently while the existing crawler is collecting records.
python3 - "$DB" "$BACKUP/catalogue.sqlite3" <<'PY'
import sqlite3,sys
with sqlite3.connect('file:'+sys.argv[1]+'?mode=ro',uri=True) as src:
 with sqlite3.connect(sys.argv[2]) as dest:src.backup(dest)
PY
for file in tools/catalogue_harvester.py tools/catalogue_publish.py \
 tools/catalogue_auto_update.py config/catalogue_harvester.json;do
 if [[ -f "$APP/$file" ]];then
   mkdir -p "$BACKUP/$(dirname "$file")"
   cp -a "$APP/$file" "$BACKUP/$file"
 fi
done
cp -a "$UNIT" "$BACKUP/aurenoeva-catalogue.service"
if [[ -f "/etc/systemd/system/$TIMER" ]];then
 cp -a "/etc/systemd/system/$TIMER" "$BACKUP/aurenoeva-catalogue.timer"
fi
echo "SAFE_SQLITE_AND_CODE_BACKUP=$BACKUP"

# Update only files used by the one existing service; no competing process.
mkdir -p "$APP/config"
for file in tools/catalogue_harvester.py tools/catalogue_publish.py \
 tools/catalogue_auto_update.py config/catalogue_harvester.json;do
 install -m 0644 "$WORK/$file" "$APP/$file"
done
install -m 0644 "$WORK/ops/aurenoeva-catalogue.service" "$UNIT"
install -m 0644 "$WORK/ops/aurenoeva-catalogue.timer" "/etc/systemd/system/$TIMER"
mkdir -p "/etc/systemd/system/$SERVICE.d"
# Clear any legacy suppressed or duplicate post-start hooks.
printf '[Service]\nExecStartPost=\nExecStartPost=/usr/bin/python3 /opt/aurenoeva/tools/catalogue_publish.py\n' \
 > "/etc/systemd/system/$SERVICE.d/zz-catalogue-full-publish.conf"
# Do not create a new timer or restart/interrupt the already-running crawler.
systemctl daemon-reload
systemctl enable --now "$TIMER"

echo "=== PUBLISH ALL ELIGIBLE IDENTITIES NOW; NO RECRAWL ==="
LOG="$BACKUP/publication.log"
PUBLISHED=0
# Retry a short-lived website outage or existing concurrent publication.
for attempt in 1 2 3; do
 if runuser -u aurenoeva -- /usr/bin/python3 \
 "$APP/tools/catalogue_publish.py" >"$LOG" 2>&1;then
   PUBLISHED=1
   break
 fi
 echo "PUBLICATION_ATTEMPT_${attempt}=FAILED"
 tail -n 8 "$LOG"
 if [[ "$attempt" -lt 3 ]];then sleep 12; fi
done
if [[ "$PUBLISHED" != 1 ]];then
 tail -n 45 "$LOG"
 echo "CATALOGUE_FULL_PUBLICATION=FAILED; preserved database and previous website snapshot"
 exit 5
fi
tail -n 20 "$LOG"

echo "=== CONFIRM DATABASE AND LIVE PUBLIC SEARCH COUNT ==="
python3 - "$DB" "$BEFORE_COUNT" <<'PY'
import json,sqlite3,sys,urllib.request
with sqlite3.connect('file:'+sys.argv[1]+'?mode=ro',uri=True) as conn:
 current=conn.execute('SELECT COUNT(*) FROM products').fetchone()[0]
assert current>=int(sys.argv[2]),"ORIGINAL_PRODUCT_IDENTITIES_LOST"
req=urllib.request.Request(
 'https://aurenoeva.com/catalogue-count.php?final-verify='+str(current),
 headers={'Cache-Control':'no-cache'})
with urllib.request.urlopen(req,timeout=25) as result:
 data=json.load(result)
assert data.get('ok') is True and data.get('searchable_parts',0)>0
stats=data.get('reconciliation')
assert isinstance(stats,dict),"LIVE_SITE_RECONCILIATION_NOT_AVAILABLE"
assert stats['eligible_products']==data['searchable_parts']
assert stats['db_products']<=current, "PUBLISHED_DATABASE_COUNT_IN_FUTURE"
print("VPS_DATABASE_PRODUCTS=",current)
print("WEBSITE_SEARCHABLE_PRODUCTS=",data['searchable_parts'])
print("ELIGIBILITY_EXCLUSIONS=",json.dumps({
 key:val for key,val in stats.items() if key.startswith('excluded')},sort_keys=True))
print("CATALOGUE_RECONCILIATION_AND_PUBLICATION=PASS")
PY

systemctl is-enabled "$TIMER" >/dev/null
echo "CONTINUOUS_30_MINUTE_CRAWLER_TIMER=ENABLED"
echo "SYSTEMD_CRAWLER_PUBLISH_HOOK:"
systemctl cat "$SERVICE" | grep -E 'ExecStart=|ExecStartPre=|ExecStartPost='
echo "ONE_EXISTING_DATABASE_AND_WEBSITE_PUBLICATION=PASS"
