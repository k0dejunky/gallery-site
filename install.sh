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
log "Installing system packages (apache2, php-fpm, gd, mysql, ffmpeg, xsendfile)..."
export DEBIAN_FRONTEND=noninteractive
APT_QUIET=""
[[ $UNATTENDED -eq 1 ]] && APT_QUIET="-q"
apt-get update -y $APT_QUIET
apt-get install -y $APT_QUIET \
    apache2 \
    php \
    php-fpm \
    php-mysql \
    php-gd \
    php-mbstring \
    php-xml \
    php-curl \
    libapache2-mod-xsendfile \
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
# Enable Apache modules: use mpm_event + PHP-FPM (not mod_php/prefork) and
# mod_xsendfile so large media is streamed by Apache instead of PHP.
# ---------------------------------------------------------------------------
log "Enabling Apache modules (rewrite, alias, proxy_fcgi, setenvif, xsendfile, mpm_event)..."

# Detect the installed PHP minor version (used for FPM paths/service name).
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null).$(php -r 'echo PHP_MINOR_VERSION;' 2>/dev/null)"
PHP_VERSION="${PHP_VERSION:-7.4}"

a2enmod rewrite alias 2>/dev/null || true
a2dismod mpm_prefork 2>/dev/null || true
a2dismod php 2>/dev/null || true
a2enmod mpm_event 2>/dev/null || true
a2enmod proxy_fcgi setenvif 2>/dev/null || true
a2enmod xsendfile 2>/dev/null || true
a2enmod deflate headers expires 2>/dev/null || true
a2enconf "php${PHP_VERSION}-fpm" 2>/dev/null || true

# Tune the PHP-FPM pool: dynamic workers plus the 10 GiB upload ceiling.
# (FPM does not honour php_value in .htaccess, so limits live here.)
POOL="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
if [[ -f "$POOL" ]]; then
    cat > "$POOL" <<POOLCONF
[www]
user = www-data
group = www-data
listen = /run/php/php${PHP_VERSION}-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 15
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500

request_terminate_timeout = 300

php_admin_value[upload_max_filesize] = 10G
php_admin_value[post_max_size] = 11G
php_value[memory_limit] = 512M
php_value[max_execution_time] = 300
php_value[max_input_time] = 300
POOLCONF
    ok "PHP-FPM pool configured with 10 GiB upload ceiling."
fi

# Enable OPcache for the web SAPI with timestamp revalidation so the Site
# Editor's runtime template edits are picked up without a manual reset.
OPCACHE_INI="/etc/php/${PHP_VERSION}/mods-available/opcache.ini"
if [[ -f "$OPCACHE_INI" ]] && command -v phpenmod >/dev/null 2>&1; then
    sed -i 's/^opcache.enable=[01]/opcache.enable=1/' "$OPCACHE_INI" 2>/dev/null || true
    sed -i 's/^opcache.validate_timestamps=[01]/opcache.validate_timestamps=1/' "$OPCACHE_INI" 2>/dev/null || true
    sed -i 's/^opcache.revalidate_freq=.*/opcache.revalidate_freq=2/' "$OPCACHE_INI" 2>/dev/null || true
    phpenmod -v ALL opcache 2>/dev/null || true
    ok "OPcache enabled for PHP-FPM."
fi

# Start PHP-FPM now (needed before Apache can proxy to it). The service is
# versioned on Ubuntu/Debian (e.g. php7.4-fpm); try the versioned name first.
FPM_SVC="php${PHP_VERSION}-fpm"
systemctl enable "$FPM_SVC" 2>/dev/null || true
systemctl restart "$FPM_SVC" 2>/dev/null \
    || systemctl restart php-fpm 2>/dev/null \
    || service "$FPM_SVC" restart 2>/dev/null \
    || service php-fpm restart 2>/dev/null || true

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
mkdir -p "$INSTALL_DIR/storage/uploads/pending"
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

    # Offload large media streaming from PHP-FPM to Apache (auth handled in PHP).
    XSendFile On
    XSendFilePath $INSTALL_DIR/storage/uploads
    XSendFilePath $INSTALL_DIR/storage
</Directory>

# Long-lived cache headers for static assets.
<LocationMatch "^/gallery/(assets|favicon)">
    ExpiresActive On
    ExpiresByType image/png "access plus 30 days"
    ExpiresByType image/jpeg "access plus 30 days"
    ExpiresByType image/webp "access plus 30 days"
    ExpiresByType image/gif "access plus 30 days"
    ExpiresByType text/css "access plus 7 days"
    ExpiresByType application/javascript "access plus 7 days"
    Header append Cache-Control "public"
</LocationMatch>
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
# Tune MySQL for the gallery workload
# ---------------------------------------------------------------------------
log "Writing MySQL tuning config (99-gallery-tuning.cnf)..."
mkdir -p /etc/mysql/mysql.conf.d
cat > /etc/mysql/mysql.conf.d/99-gallery-tuning.cnf <<MYCNF
[mysqld]
# Tuned for a small gallery install: fits the database in memory, keeps
# per-connection buffers modest, and enables the slow-query log.
innodb_buffer_pool_size = 256M
innodb_log_file_size = 64M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT
innodb_buffer_pool_instances = 1

tmp_table_size = 32M
max_heap_table_size = 32M

thread_cache_size = 16
max_connections = 100
max_connect_errors = 1000

sort_buffer_size = 1M
join_buffer_size = 1M
read_buffer_size = 512K
read_rnd_buffer_size = 512K
key_buffer_size = 8M

slow_query_log = 1
slow_query_log_file = /var/log/mysql/mysql-slow.log
long_query_time = 2
MYCNF

# Validate the config and apply it.
if command -v mysqld >/dev/null 2>&1; then
    mysqld --validate-config 2>/dev/null \
        && systemctl restart mysql 2>/dev/null || service mysql restart 2>/dev/null || true
    ok "MySQL tuning applied."
else
    warn "mysqld not found; MySQL tuning file written but not applied (apply on next restart)."
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
# The 10 GiB upload ceiling lives in the PHP-FPM pool; .htaccess only holds the
# rewrite rules. Keep it readable by www-data.
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
 Image variants  : web + thumbnail generated in one ffmpeg pass (GD fallback)
 Payments        : admin "Payments" tab; Stripe/PayPal seeded disabled
 Upload limit    : 10 GiB (set in the PHP-FPM pool, not .htaccess)
 Media serving   : streamed by Apache via mod_xsendfile
 PHP runtime     : PHP-FPM + Apache mpm_event (not mod_php)
 Apache config   : /etc/apache2/conf-available/gallery.conf
 MySQL tuning    : /etc/mysql/mysql.conf.d/99-gallery-tuning.cnf
--------------------------------------------------------------------------
EOF

exit 0
