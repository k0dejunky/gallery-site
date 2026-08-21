#!/usr/bin/env bash
#
# gallery-mvc uninstaller
# -----------------------
# Removes a gallery install created by install.sh.
#
# Usage:
#   sudo ./uninstall.sh [--dir /var/www/gallery] [--db-name gallery_mvc] \
#                       [--db-user gallery] [--drop-db] [--purge-packages] \
#                       [--keep-uploads] [--yes]
#
# Defaults:
#   dir         = /var/www/gallery
#   db-name     = gallery_mvc
#   db-user     = gallery
#
# Flags:
#   --drop-db         Also drop the database and the app's MySQL user.
#   --purge-packages  Also remove apache2/php/ffmpeg/mysql packages.
#   --keep-uploads    Leave storage/uploads contents on disk (default: remove).
#   --yes             Do not prompt.
#
# By default this removes ONLY the site files and the Apache alias config.
# Use --drop-db to remove data, and --purge-packages to remove system deps.

set -euo pipefail

log()  { printf '\033[1;34m[uninstall]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m[ok]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31m[ERROR]\033[0m %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Parse arguments
# ---------------------------------------------------------------------------
INSTALL_DIR="/var/www/gallery"
DB_NAME="gallery_mvc"
DB_USER="gallery"
DROP_DB=0
PURGE_PACKAGES=0
KEEP_UPLOADS=0
ASSUME_YES=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dir)            INSTALL_DIR="$2"; shift 2 ;;
        --db-name)        DB_NAME="$2"; shift 2 ;;
        --db-user)        DB_USER="$2"; shift 2 ;;
        --drop-db)        DROP_DB=1; shift ;;
        --purge-packages) PURGE_PACKAGES=1; shift ;;
        --keep-uploads)   KEEP_UPLOADS=1; shift ;;
        --yes)            ASSUME_YES=1; shift ;;
        -h|--help)
            echo "Usage: $0 [--dir PATH] [--db-name NAME] [--db-user USER] [--drop-db] [--purge-packages] [--keep-uploads] [--yes]"
            echo ""
            echo "  --drop-db          Drop the database and app MySQL user."
            echo "  --purge-packages   Remove apache2/php/ffmpeg/mysql system packages."
            echo "  --keep-uploads     Keep storage/uploads contents."
            echo "  --yes              No prompts."
            exit 0 ;;
        *) fail "Unknown argument: $1" ;;
    esac
done

[[ $EUID -eq 0 ]] || fail "Run this script as root (sudo)."

# ---------------------------------------------------------------------------
# Confirm
# ---------------------------------------------------------------------------
log "This will uninstall the gallery from: $INSTALL_DIR"
[[ $DROP_DB -eq 1 ]]        && log "  - drop database '$DB_NAME' and user '$DB_USER'"
[[ $PURGE_PACKAGES -eq 1 ]] && log "  - purge system packages (apache2/php/ffmpeg/mysql)"
[[ $KEEP_UPLOADS -eq 1 ]]   && log "  - keep uploads (--keep-uploads)"
[[ $KEEP_UPLOADS -eq 0 ]]   && log "  - remove site files including uploads"

if [[ $ASSUME_YES -eq 0 ]]; then
    read -r -p "Continue? [y/N] " yn
    [[ "$yn" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 1; }
fi

# ---------------------------------------------------------------------------
# 1. Disable & remove the Apache alias config
# ---------------------------------------------------------------------------
log "Removing Apache /gallery alias config..."
if [[ -f /etc/apache2/conf-available/gallery.conf ]]; then
    a2disconf gallery.conf >/dev/null 2>&1 || true
    rm -f /etc/apache2/conf-available/gallery.conf
    rm -f /etc/apache2/conf-enabled/gallery.conf
    ok "Apache alias config removed."
fi

# ---------------------------------------------------------------------------
# 2. Remove site files
# ---------------------------------------------------------------------------
if [[ -d "$INSTALL_DIR" ]]; then
    if [[ $KEEP_UPLOADS -eq 1 ]]; then
        # Preserve user uploads by moving them aside before removing the dir.
        TMP_UPLOADS="/tmp/gallery-uploads-backup-$(date +%s)"
        if [[ -d "$INSTALL_DIR/storage/uploads" ]]; then
            mv "$INSTALL_DIR/storage/uploads" "$TMP_UPLOADS" 2>/dev/null || true
            ok "Preserved uploads at: $TMP_UPLOADS"
        fi
    fi
    rm -rf "$INSTALL_DIR"
    ok "Removed site directory $INSTALL_DIR."
else
    warn "Site directory $INSTALL_DIR not found; skipping."
fi

# ---------------------------------------------------------------------------
# 3. Drop database / user
# ---------------------------------------------------------------------------
if [[ $DROP_DB -eq 1 ]]; then
    log "Dropping database and user..."
    if have mysql; then
        service mysql start 2>/dev/null || systemctl start mysql 2>/dev/null || true
        mysql -u root <<SQL
DROP DATABASE IF EXISTS \`$DB_NAME\`;
DROP USER IF EXISTS '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
        ok "Dropped database '$DB_NAME' and user '$DB_USER'."
    else
        warn "mysql client not found; skipping database drop."
    fi
fi

# ---------------------------------------------------------------------------
# 4. Purge packages (optional)
# ---------------------------------------------------------------------------
if [[ $PURGE_PACKAGES -eq 1 ]]; then
    log "Purging system packages..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get purge -y \
        libapache2-mod-php \
        php-mysql \
        php-gd \
        php-mbstring \
        php-xml \
        php-curl \
        ffmpeg \
        mysql-server \
        apache2 2>/dev/null || true
    apt-get autoremove -y 2>/dev/null || true
    ok "System packages purged (apache2/php/ffmpeg/mysql)."
fi

# ---------------------------------------------------------------------------
# 5. Restart Apache
# ---------------------------------------------------------------------------
if [[ $PURGE_PACKAGES -eq 0 ]]; then
    log "Restarting Apache..."
    systemctl restart apache2 2>/dev/null || service apache2 restart 2>/dev/null || true
fi

cat <<EOF

--------------------------------------------------------------------------
 Uninstall complete
--------------------------------------------------------------------------
 Site files     : removed from $INSTALL_DIR
 Apache alias   : /gallery removed
 Database       : $([ $DROP_DB -eq 1 ] && echo "dropped" || echo "left in place (use --drop-db)")
 Packages       : $([ $PURGE_PACKAGES -eq 1 ] && echo "purged" || echo "left in place (use --purge-packages)")
--------------------------------------------------------------------------
EOF

exit 0
