#!/usr/bin/env bash
# AURENOEVA private CRM extension on the existing Hostinger VPS.
# Usage (on the VPS, using an AURENOEVA checkout):
#   CRM_DOCROOT=/real/live/crm AURENOEVA_WEB_USER=existing-php-user bash deployment/install-hostinger.sh
# Does not touch VANTAGE, WeTraQ, CORE, public AURENOEVA pages, or database rows.
set -euo pipefail
APP=/opt/noeva-aurenoeva
SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CRM_DOCROOT="${CRM_DOCROOT:-}"
WEB_USER="${AURENOEVA_WEB_USER:-}"
if [[ -z "$CRM_DOCROOT" || "$CRM_DOCROOT" != /* || -z "$WEB_USER" ]]; then
  echo "BLOCKED: CRM_DOCROOT absolute path and AURENOEVA_WEB_USER are required" >&2
  exit 2
fi
if ! id "$WEB_USER" >/dev/null 2>&1; then
  echo "BLOCKED: PHP service user does not exist" >&2; exit 2
fi
for path in "$CRM_DOCROOT/index.php" "$CRM_DOCROOT/storage/users.json" \
            "$CRM_DOCROOT/wetraq/session-guard.php" \
            "$CRM_DOCROOT/aurenoeva-v1/session.php"; do
  test -f "$path" || { echo "BLOCKED: required CRM file missing" >&2; exit 2; }
done
for path in procurement_intake.py procurement_rfq.py procurement_sanctions.py \
            procurement_case_store.py procurement_hostinger_worker.py; do
  test -f "$SOURCE/tools/$path" || { echo "BLOCKED: source missing $path" >&2; exit 2; }
done
php -l "$SOURCE/deployment/crm/aurenoeva-v1/import.php" >/dev/null
python3 -m compileall -q "$SOURCE/tools/procurement_intake.py" \
 "$SOURCE/tools/procurement_rfq.py" "$SOURCE/tools/procurement_sanctions.py" \
 "$SOURCE/tools/procurement_case_store.py" "$SOURCE/tools/procurement_hostinger_worker.py"
python3 -m unittest discover -s "$SOURCE/tests" -p 'test_procurement_*.py' -q
install -d -m 0755 "$APP" "$APP/app-tools"
install -d -m 0700 "$APP/private" "$APP/private/inbox" "$APP/private/sanctions" \
 "$APP/private/parties" "$APP/backups"
chown -R "$WEB_USER" "$APP/private"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
if [[ -e "$CRM_DOCROOT/aurenoeva-v1/import.php" ]]; then
  cp -p "$CRM_DOCROOT/aurenoeva-v1/import.php" "$APP/backups/import-$stamp.php"
fi
for path in procurement_intake.py procurement_rfq.py procurement_sanctions.py \
            procurement_case_store.py procurement_hostinger_worker.py; do
  if [[ -f "$APP/app-tools/$path" ]]; then cp -p "$APP/app-tools/$path" "$APP/backups/$path.$stamp"; fi
  install -m 0644 "$SOURCE/tools/$path" "$APP/app-tools/$path"
done
install -m 0644 "$SOURCE/deployment/crm/aurenoeva-v1/import.php" \
 "$CRM_DOCROOT/aurenoeva-v1/import.php"
# Existing optional jobs are left in place unless they are the exact same
# independent AURENOEVA cron file. One bounded native Hostinger worker.
cat > /etc/cron.d/noeva-aurenoeva-procurement <<EOF
SHELL=/bin/bash
PATH=/usr/local/bin:/usr/bin:/bin
*/15 * * * * $WEB_USER /usr/bin/flock -n $APP/private/worker.lock /usr/bin/python3 $APP/app-tools/procurement_hostinger_worker.py --inbox $APP/private/inbox --db $APP/private/cases.sqlite3 --feed-dir $APP/private/sanctions --party-dir $APP/private/parties --country UZ >>$APP/private/worker.log 2>&1
15 2 * * * $WEB_USER /usr/bin/flock -n $APP/private/feed.lock /bin/bash -c 'test -r /etc/noeva-aurenoeva/feeds.env && source /etc/noeva-aurenoeva/feeds.env; /usr/bin/python3 $APP/app-tools/procurement_sanctions.py refresh --feed-dir $APP/private/sanctions' >>$APP/private/feeds.log 2>&1
EOF
chmod 0644 /etc/cron.d/noeva-aurenoeva-procurement
# Source verification, not user authentication. The actual route MUST return
# 401/403 without CRM session; public publication is prohibited.
code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 \
 'https://crm.noevasystems.com/aurenoeva-v1/import.php' || true)"
if [[ "$code" != 401 && "$code" != 403 ]]; then
  echo "BLOCKED: unauthenticated import route returned $code, not 401/403" >&2
  if [[ -f "$APP/backups/import-$stamp.php" ]]; then
    cp -p "$APP/backups/import-$stamp.php" "$CRM_DOCROOT/aurenoeva-v1/import.php"
  else
    rm -f "$CRM_DOCROOT/aurenoeva-v1/import.php"
  fi
  rm -f /etc/cron.d/noeva-aurenoeva-procurement
  exit 3
fi
echo "AURENOEVA_IMPORT_CODE_DEPLOYED=PASS"
echo "AURENOEVA_AUTH_UNAUTHENTICATED_DENY=PASS"
echo "AURENOEVA_FEEDS_CONFIGURED=$(test -s /etc/noeva-aurenoeva/feeds.env && echo pending-verification || echo false)"
echo "AURENOEVA_DATA_IMPORT=NO_REAL_CUSTOMER_WRITE_PERFORMED"
