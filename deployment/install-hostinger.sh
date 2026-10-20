#!/usr/bin/env bash
# One-time password setup on the AURENOEVA website hosting account, no root/VPS/Python.
set -euo pipefail
DOCROOT="${AURENOEVA_WEB_DOCROOT:-$HOME/domains/aurenoeva.com/public_html}"
SETUP="$DOCROOT/procurement/setup-cli.php"
test -f "$SETUP" || { echo "BLOCKED: AURENOEVA_SITE_NOT_DEPLOYED_AT=$DOCROOT" >&2; exit 2; }
command -v php >/dev/null || { echo "BLOCKED: PHP CLI unavailable" >&2; exit 2; }
printf "AURENOEVA procurement password (16+ characters, not echoed): "
IFS= read -r -s AURPASS
printf "\n"
if [[ "$(printf "%s" "$AURPASS" | wc -c)" -lt 16 ]]; then echo "BLOCKED: password too short" >&2; unset AURPASS; exit 2; fi
AUR_SETUP_PASSWORD="$AURPASS" php "$SETUP"
unset AURPASS
echo "Check https://aurenoeva.com/procurement/"
