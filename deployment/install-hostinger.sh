#!/usr/bin/env bash
# Install one AURENOEVA website-native procurement backend on Hostinger.
# AURENOEVA_WEB_DOCROOT=/real/aurenoeva/public_html AURENOEVA_WEB_USER=php-user bash deployment/install-hostinger.sh
set -euo pipefail
APP=/opt/noeva-aurenoeva
SOURCE="$(cd "$(dirname "$0")/.." && pwd)"
DOCROOT="${AURENOEVA_WEB_DOCROOT:-}"
WEBUSER="${AURENOEVA_WEB_USER:-}"
if [[ "$EUID" -ne 0 || "$DOCROOT" != /* || -z "$WEBUSER" ]]; then
  echo "BLOCKED: run as root with absolute AURENOEVA_WEB_DOCROOT and AURENOEVA_WEB_USER" >&2
  exit 2
fi
id "$WEBUSER" >/dev/null 2>&1 || { echo "BLOCKED: invalid website PHP user" >&2; exit 2; }
for file in "$DOCROOT/index.html" "$DOCROOT/requirement-submit.php" \
            "$SOURCE/deployment/site/procurement/index.php"; do
  test -f "$file" || { echo "BLOCKED: missing website/source file: $file" >&2; exit 2; }
done
if [[ "$DOCROOT" == "$APP" || "$DOCROOT" == "$APP/"* ]]; then
  echo "BLOCKED: private data must not be in public document root" >&2; exit 2
fi
for file in procurement_intake.py procurement_rfq.py procurement_sanctions.py \
            procurement_case_store.py procurement_hostinger_worker.py; do
  test -f "$SOURCE/tools/$file" || { echo "BLOCKED: missing tool $file" >&2; exit 2; }
done
php -l "$SOURCE/deployment/site/procurement/index.php" >/dev/null
python3 -m unittest discover -s "$SOURCE/tests" -p 'test_procurement_*.py' -q

install -d -m 0755 "$APP" "$APP/app-tools" "$APP/backups"
install -d -m 0700 "$APP/private" "$APP/private/inbox" "$APP/private/sanctions" "$APP/private/parties"
chown -R "$WEBUSER" "$APP/private"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
if test -f "$DOCROOT/procurement/index.php"; then
  cp -p "$DOCROOT/procurement/index.php" "$APP/backups/procurement-$STAMP.php"
fi
for file in procurement_intake.py procurement_rfq.py procurement_sanctions.py \
            procurement_case_store.py procurement_hostinger_worker.py; do
  if test -f "$APP/app-tools/$file"; then cp -p "$APP/app-tools/$file" "$APP/backups/$file.$STAMP"; fi
  install -m 0644 "$SOURCE/tools/$file" "$APP/app-tools/$file"
done

# Password created once and kept out of public source, logs and web files.
HASHFILE="$APP/private/admin-password.hash"
if [[ ! -s "$HASHFILE" ]]; then
  if [[ ! -t 0 ]]; then
    echo "BLOCKED: use an interactive server console to configure the initial password" >&2
    echo "Source installed; upload stays closed until credentials exist" >&2
    exit 3
  fi
  read -r -s -p "New AURENOEVA procurement admin password: " ADMINPASS
  echo
  if [[ "$(printf '%s' "$ADMINPASS" | wc -c)" -lt 16 ]]; then
    echo "BLOCKED: minimum 16 characters" >&2; exit 3
  fi
  HASH="$(printf '%s' "$ADMINPASS" | php -r '$pw=stream_get_contents(STDIN);echo password_hash($pw,PASSWORD_BCRYPT);')"
  unset ADMINPASS
  printf '%s\n' "$HASH" > "$HASHFILE"
  chown "$WEBUSER" "$HASHFILE"
  chmod 0600 "$HASHFILE"
fi
install -d -m 0755 "$DOCROOT/procurement"
install -m 0644 "$SOURCE/deployment/site/procurement/index.php" "$DOCROOT/procurement/index.php"

cat > /etc/cron.d/noeva-aurenoeva-procurement <<EOF
SHELL=/bin/bash
PATH=/usr/local/bin:/usr/bin:/bin
*/15 * * * * $WEBUSER /usr/bin/flock -n $APP/private/worker.lock /usr/bin/python3 $APP/app-tools/procurement_hostinger_worker.py --inbox $APP/private/inbox --db $APP/private/cases.sqlite3 --feed-dir $APP/private/sanctions --party-dir $APP/private/parties --country UZ >>$APP/private/worker.log 2>&1
15 2 * * * $WEBUSER /usr/bin/flock -n $APP/private/feed.lock /bin/bash -c 'test -r /etc/noeva-aurenoeva/feeds.env && source /etc/noeva-aurenoeva/feeds.env; /usr/bin/python3 $APP/app-tools/procurement_sanctions.py refresh --feed-dir $APP/private/sanctions' >>$APP/private/feeds.log 2>&1
EOF
chmod 0644 /etc/cron.d/noeva-aurenoeva-procurement
echo "AURENOEVA_WEBSITE_PRIVATE_UPLOAD=INSTALLED"
echo "AURENOEVA_ADMIN_ROUTE=https://aurenoeva.com/procurement/"
echo "AURENOEVA_PUBLIC_CATALOGUE=UNCHANGED"
echo "LIVE_AUTH_AND_IMPORT_CHECK=STILL_REQUIRED"
