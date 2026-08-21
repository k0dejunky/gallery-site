#!/usr/bin/env bash
#
# gallery-mvc installer
# ---------------------
# Installs a clean copy of the gallery site onto a fresh Ubuntu/Debian host.
#
# Usage:
#   sudo ./install.sh [--source /path/to/site] [--dir /var/www/gallery] \
#                     [--db-name gallery_mvc] [--db-user gallery] \
#                     [--db-pass <auto|password>] [--admin-email admin@example.com] \
#                     [--admin-pass <password>] [--no-frontend-build] [--yes]
#
# Defaults:
#   source      = directory containing this script (must contain app/, config/, public/, etc.)
#   dir         = /var/www/gallery
#   db-name     = gallery_mvc
#   db-user     = gallery
#   db-pass     = auto-generated random password (printed at the end)
#   admin-email = admin@example.com  (seeded by schema.sql)
#   admin-pass  = printed at end; you must change it after first login
#
# Tested on: Ubuntu 20.04 / Debian 11+ (Apache 2.4, PHP 7.4+, MariaDB/MySQL)

set -euo pipefail

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
log()  { printf '\033[1;34m[install]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m[ok]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31m[ERROR]\033[0m %s\n' "$*" >&2; exit 1; }

have() { command -v "$1" >/dev/null 2>&1; }

# ---------------------------------------------------------------------------
# Parse arguments
# ---------------------------------------------------------------------------
SCRIPT_SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_DIR="$SCRIPT_SRC"
INSTALL_DIR="/var/www/gallery"
DB_NAME="gallery_mvc"
DB_USER="gallery"
DB_PASS=""
ADMIN_EMAIL="admin@example.com"
ADMIN_PASS=""
BUILD_FRONTEND=1
ASSUME_YES=0
UNATTENDED=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --source)     SOURCE_DIR="$2"; shift 2 ;;
        --dir)        INSTALL_DIR="$2"; shift 2 ;;
        --db-name)    DB_NAME="$2"; shift 2 ;;
        --db-user)    DB_USER="$2"; shift 2 ;;
        --db-pass)    DB_PASS="$2"; shift 2 ;;
        --admin-email) ADMIN_EMAIL="$2"; shift 2 ;;
        --admin-pass) ADMIN_PASS="$2"; shift 2 ;;
        --no-frontend-build) BUILD_FRONTEND=0; shift ;;
        --yes)        ASSUME_YES=1; shift ;;
        --unattended) UNATTENDED=1; ASSUME_YES=1; shift ;;
        -h|--help)
            echo "Usage: $0 [--source PATH] [--dir PATH] [--db-name NAME] [--db-user USER] [--db-pass PASS] [--admin-email EMAIL] [--admin-pass PASS] [--no-frontend-build] [--yes] [--unattended]"
            echo ""
            echo "  --unattended  Run with no prompts. Sets --yes and never asks for input."
            exit 0 ;;
        *) fail "Unknown argument: $1" ;;
    esac
done

# ---------------------------------------------------------------------------
# Pre-flight checks
# ---------------------------------------------------------------------------
[[ $EUID -eq 0 ]] || fail "Run this script as root (sudo)."

[[ -d "$SOURCE_DIR/app" && -d "$SOURCE_DIR/config" && -d "$SOURCE_DIR/public" && -d "$SOURCE_DIR/views" && -d "$SOURCE_DIR/bin" && -f "$SOURCE_DIR/schema.sql" ]] \
    || fail "Source dir '$SOURCE_DIR' does not look like the gallery site (missing app/, config/, public/, views/, bin/, schema.sql)."

[[ -d "$INSTALL_DIR" && -n "$(ls -A "$INSTALL_DIR" 2>/dev/null)" ]] \
    && fail "Install dir '$INSTALL_DIR' already exists and is not empty."

log "Installing gallery site to: $INSTALL_DIR"
log "  Source code       : $SOURCE_DIR"
log "  Database name/user: $DB_NAME / $DB_USER"
log "  Admin seed email  : $ADMIN_EMAIL"

if [[ $ASSUME_YES -eq 0 ]]; then
    read -r -p "Continue? [y/N] " yn
    [[ "$yn" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 1; }
fi

# ---------------------------------------------------------------------------
# Install system packages
# ---------------------------------------------------------------------------
log "Installing system packages (apache2, php, gd, mysql, ffmpeg)..."
export DEBIAN_FRONTEND=noninteractive
APT_QUIET=""
[[ $UNATTENDED -eq 1 ]] && APT_QUIET="-q"
apt-get update -y $APT_QUIET
apt-get install -y $APT_QUIET \
    apache2 \
    php \
    php-mysql \
    php-gd \
    php-mbstring \
    php-xml \
    php-curl \
    mysql-server \
    ffmpeg \
    curl

# PHP modules/features the app relies on.
for mod in pdo pdo_mysql gd fileinfo json mbstring; do
    if php -m 2>/dev/null | grep -q "^${mod}$"; then
        ok "PHP extension present: $mod"
    else
        warn "PHP extension '$mod' not detected (may be compiled-in or need a package like php-$mod)."
    fi
done

# The video export worker is spawned from PHP via exec(); ensure it is allowed.
if command -v phpenmod >/dev/null 2>&1; then
    phpenmod -v ALL mbstring 2>/dev/null || true
fi

# ---------------------------------------------------------------------------
# Enable Apache modules
# ---------------------------------------------------------------------------
log "Enabling Apache modules (rewrite, alias, php)..."
a2enmod rewrite alias 2>/dev/null || true
if have phpenmod && apache2ctl -M 2>/dev/null | grep -q php; then
    : # php module already loaded
else
    warn "PHP apache module not detected; ensure mod_php (libapache2-mod-php) is installed."
fi

# ---------------------------------------------------------------------------
# Copy site code
# ---------------------------------------------------------------------------
log "Copying site code to $INSTALL_DIR..."
mkdir -p "$INSTALL_DIR"
cp -a "$SOURCE_DIR/app"        "$INSTALL_DIR/"
cp -a "$SOURCE_DIR/config"     "$INSTALL_DIR/"
cp -a "$SOURCE_DIR/public"     "$INSTALL_DIR/"
cp -a "$SOURCE_DIR/views"      "$INSTALL_DIR/"
cp -a "$SOURCE_DIR/bin"        "$INSTALL_DIR/"
cp -a "$SOURCE_DIR/schema.sql" "$INSTALL_DIR/"
cp -a "$SOURCE_DIR/schema.sqlite.sql" "$INSTALL_DIR/" 2>/dev/null || true
cp -a "$SOURCE_DIR/.env.example" "$INSTALL_DIR/" 2>/dev/null || true
cp -a "$SOURCE_DIR/.gitignore" "$INSTALL_DIR/" 2>/dev/null || true

# Copy prebuilt video editor dist (skip node_modules / src / .map).
if [[ -d "$SOURCE_DIR/frontend/video-editor/dist" ]]; then
    mkdir -p "$INSTALL_DIR/frontend/video-editor"
    cp -a "$SOURCE_DIR/frontend/video-editor/dist" "$INSTALL_DIR/frontend/video-editor/dist"
    cp -a "$SOURCE_DIR/frontend/video-editor/index.html" "$INSTALL_DIR/frontend/video-editor/" 2>/dev/null || true
    cp -a "$SOURCE_DIR/frontend/video-editor/package.json" "$INSTALL_DIR/frontend/video-editor/" 2>/dev/null || true
    cp -a "$SOURCE_DIR/frontend/video-editor/vite.config.js" "$INSTALL_DIR/frontend/video-editor/" 2>/dev/null || true
    ok "Prebuilt video editor copied."
fi

# Create the storage tree (uploads, themes) - these hold user data and are
# intentionally excluded from version control / source.
log "Creating storage directories..."
mkdir -p "$INSTALL_DIR/storage/uploads/exports"
mkdir -p "$INSTALL_DIR/storage/themes"
# Optionally copy the built-in theme presets if provided in source.
if [[ -d "$SOURCE_DIR/storage/themes" ]]; then
    cp -a "$SOURCE_DIR"/storage/themes/*.json "$INSTALL_DIR/storage/themes/" 2>/dev/null || true
fi

# Remove any accidental user-data files from a source copy (defensive).
rm -rf "$INSTALL_DIR/storage/uploads/*"
rm -f  "$INSTALL_DIR/storage/theme.json" \
       "$INSTALL_DIR/storage/admin-theme.json" \
       "$INSTALL_DIR/storage/site-layout.json" \
       "$INSTALL_DIR/storage/admin-layout.json"

# ---------------------------------------------------------------------------
# Configure Apache
# ---------------------------------------------------------------------------
log "Writing Apache alias for /gallery (global conf, avoids vhost conflicts)..."
cat > /etc/apache2/conf-available/gallery.conf <<EOF
# Serves the gallery app under the /gallery URL path.
Alias /gallery "$INSTALL_DIR/public"

<Directory "$INSTALL_DIR/public">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
EOF
a2enconf gallery.conf >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
# Configure MySQL
# ---------------------------------------------------------------------------
log "Starting MySQL and creating database/user..."
service mysql start 2>/dev/null || systemctl start mysql 2>/dev/null || true

# Auto-generate a DB password if none was supplied.
if [[ -z "$DB_PASS" ]]; then
    DB_PASS="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)"
fi

# Use root via socket auth (default on fresh installs).
mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

# Import schema (includes seed data: plans + default admin user).
log "Importing database schema..."
mysql -u root "$DB_NAME" < "$INSTALL_DIR/schema.sql"

# If a custom admin email/password was requested, apply it now so the seeded
# default (known) password is never left in place. A bcrypt hash is generated
# with PHP to match the app's password_hash() usage.
if [[ -n "$ADMIN_PASS" || "$ADMIN_EMAIL" != "admin@example.com" ]]; then
    ADMIN_PASS="${ADMIN_PASS:-$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 16)}"
    HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT);' "$ADMIN_PASS")"
    mysql -u root "$DB_NAME" <<SQL
UPDATE users
   SET email = '$ADMIN_EMAIL',
       password_hash = '$HASH'
 WHERE role = 'admin'
 LIMIT 1;
SQL
    ok "Seeded admin updated to $ADMIN_EMAIL with a fresh password."
fi

# ---------------------------------------------------------------------------
# Create .env
# ---------------------------------------------------------------------------
log "Writing .env..."
cat > "$INSTALL_DIR/.env" <<EOF
GALLERY_DB_HOST=127.0.0.1
GALLERY_DB_PORT=3306
GALLERY_DB_NAME=$DB_NAME
GALLERY_DB_USER=$DB_USER
GALLERY_DB_PASSWORD=$DB_PASS
EOF

# ---------------------------------------------------------------------------
# Set ownership / permissions
# ---------------------------------------------------------------------------
log "Setting permissions..."
chown -R www-data:www-data "$INSTALL_DIR"
chmod -R 755 "$INSTALL_DIR"
chmod -R 775 "$INSTALL_DIR/storage"
# .htaccess sets the 10 GiB upload ceiling; ensure php_value is honored.
chmod 644 "$INSTALL_DIR/public/.htaccess"

# ---------------------------------------------------------------------------
# (Optional) Rebuild the video editor frontend
# ---------------------------------------------------------------------------
if [[ $BUILD_FRONTEND -eq 1 && -d "$SOURCE_DIR/frontend/video-editor" ]]; then
    if have node && have npm; then
        log "Rebuilding video editor frontend (vite)..."
        ( cd "$SOURCE_DIR/frontend/video-editor" && npm ci --silent && npm run build )
        # Copy the freshly built dist over the installed one.
        cp -a "$SOURCE_DIR/frontend/video-editor/dist/." "$INSTALL_DIR/frontend/video-editor/dist/"
    else
        warn "node/npm not found; keeping the prebuilt video editor dist."
    fi
fi

# ---------------------------------------------------------------------------
# Restart Apache
# ---------------------------------------------------------------------------
log "Restarting Apache..."
systemctl restart apache2 2>/dev/null || service apache2 restart 2>/dev/null || true

# ---------------------------------------------------------------------------
# Verify install
# ---------------------------------------------------------------------------
log "Verifying install..."
if curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1/gallery/login" | grep -q "200"; then
    ok "Site responds at http://<host>/gallery/login (HTTP 200)."
else
    warn "Site did not return HTTP 200; check Apache error log (/var/log/apache2/gallery.error.log)."
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
cat <<EOF

--------------------------------------------------------------------------
 Installation complete
--------------------------------------------------------------------------
 Site URL       : http://<server-ip>/gallery
 Install dir    : $INSTALL_DIR
 Database       : $DB_NAME  (user: $DB_USER)
 DB password    : $DB_PASS
 Admin login    : $ADMIN_EMAIL
 Admin password : ${ADMIN_PASS:-<unchanged; schema default - CHANGE AFTER LOGIN>}
--------------------------------------------------------------------------
 Video exports   : runs via PHP exec() -> /usr/bin/ffmpeg
 Upload limit    : 10 GiB (set in public/.htaccess)
 Apache config   : /etc/apache2/conf-available/gallery.conf
--------------------------------------------------------------------------
EOF

exit 0
