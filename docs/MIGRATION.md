# Gallery site — migration runbook

Hand-over document for moving the production gallery site from the current host
to a new remote server. It captures every piece of production configuration and
data that is **not** baked into `scripts/install.sh` (which only scaffolds a
fresh, minimal install), so the new server can be brought up to feature parity
with the current one.

> Target: Ubuntu 24.04, kernel 6.8, Apache 2.4.58, PHP 8.3 (FPM), MySQL 8.0.

---

## 1. What "clean" looks like on the current server

The production app lives at `/var/www/gallery` and is served at
`http://<host>/gallery`. The current host also carries operational hardening
that the stock installer does **not** set up. Re-create all of the following on
the new server:

### 1.1 OS / stack versions (target)

| Component | Version |
|---|---|
| OS | Ubuntu 24.04.4 LTS |
| Kernel | 6.8 |
| Apache | 2.4.58 |
| PHP | 8.3.6 (FPM, pool `www`) |
| MySQL | 8.0.46 |

The repo's CI/smoke tests and the deploy/restore scripts assume a systemd host.

---

## 2. Data to migrate

Everything under `/var/www/gallery` PLUS the database and media files.

### 2.1 Database

Export the full schema **and** data. Do **not** use `schema.sql` alone — it only
seeds plans + a default admin and would lose all real content:

```bash
mysqldump -u root gallery_mvc > gallery_mvc_full.sql
```

Also dump the `schema_migrations` content is included above; before going live,
confirm migration checksums match (`php scripts/migrate.php --status`).

On the new server (after installing the app code + `.env`):

```bash
mysql -u root gallery_mvc < gallery_mvc_full.sql
```

### 2.2 Media / storage tree

`/var/www/gallery/storage/` holds all user-generated media and generated
thumbnails/derivatives:

```bash
rsync -a /var/www/gallery/storage/ newserver:/var/www/gallery/storage/
```

Preserve ownership (`www-data:www-data`) and perms (uploads `775`).

### 2.3 `.env` (secrets)

The prod `.env` is not in git. Recreate it from the current `/var/www/gallery/.env`
(keys below; keep the DB password, Gmail SMTP app password, cron key, APP_URL).

```
GALLERY_DB_HOST, GALLERY_DB_PORT, GALLERY_DB_NAME, GALLERY_DB_USER, GALLERY_DB_PASSWORD
GALLERY_CRON_KEY, ADMIN_EMAIL, MAIL_FROM, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD
APP_URL
```

### 2.4 Offsite backup (rclone → Google Drive)

`gallery_backup.php` (daily cron) splits an archive and `rclone sync`s it to
Google Drive using a pinned config. Migrate the config and pin the path:

```bash
# From source server:
rsync -a /var/www/.config/rclone/ newserver:/var/www/.config/rclone/
# Then on the new server:
echo 'export RCLONE_CONFIG=/var/www/.config/rclone/rclone.conf' >> /etc/profile.d/gallery.sh
```

The `[gdrive]` remote token auto-refreshes; the Gmail app password is **not**
used for Drive.

---

## 3. Application code

Deploy the repo, then copy any uncommitted/stateful files (storage, .env).
Never copy `node_modules` or the git history.

```bash
git clone https://github.com/k0dejunky/gallery-site.git /tmp/gallery
rsync -a --exclude '.git' --exclude 'node_modules' --exclude 'storage' --exclude '.env' \
  /tmp/gallery/ /var/www/gallery/
```

Then restore `.env` and `storage/` from §2. Run migrations:

```bash
cd /var/www/gallery && php scripts/migrate.php
```

---

## 4. APACHE configuration (not in installer)

The stock installer writes a minimal `/etc/apache2/conf-available/gallery.conf`
(`Alias /gallery` + `<Directory>` only). Production adds these **must-copy**
files:

### 4.1 `/etc/apache2/sites-enabled/gallery-headers.conf`

Security headers + CSP (Braintree/PayPal domains). Copied verbatim:

```apache
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
Header always set Content-Security-Policy "default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' https://js.braintreegateway.com https://www.paypal.com; font-src 'self' data: https://assets.braintreegateway.com; media-src 'self' blob:; connect-src 'self' https://api.braintreegateway.com https://www.paypal.com; frame-src 'self' https://client-analytics.braintreegateway.com https://www.sandbox.paypal.com https://www.paypal.com; frame-ancestors 'self'"
```

### 4.2 `/etc/apache2/sites-available/000-default.conf` additions

The vhost appends to the default site:

- `Alias /gallery /var/www/gallery/public`
- `<Directory /var/www/gallery/public>` with `XSendFile On` and
  `XSendFilePath /var/www/gallery/storage{/uploads}` (media streaming)
- `<LocationMatch "^/gallery/(assets|favicon)">` Expires + Cache-Control
- A self-signed TLS vhost on `:443` (certs in `/etc/apache2/ssl/gallery.{crt,key}`)

### 4.3 `/etc/apache2/conf-available/gallery-php-fpm.conf`

```apache
<FilesMatch \.php$>
    SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
</FilesMatch>
```

### 4.4 Enable required items

```bash
a2enmod rewrite alias headers expires xsendfile ssl
# xsendfile module package: libapache2-mod-xsendfile
a2ensite gallery-headers.conf
a2enconf gallery-php-fpm.conf
systemctl reload apache2
```

> Add `Dpkg::Options "--force-confold"` so OS upgrades never clobber these
> hand-edited confs: `/etc/apt/apt.conf.d/99gallery-confold`.

---

## 5. PHP-FPM configuration

Pool `/etc/php/8.3/fpm/pool.d/www.conf` (gallery-specific values):

```
pm.max_children = 15
pm.max_spare_servers = 6
pm.max_requests = 500
request_terminate_timeout = 300
php_admin_value[upload_max_filesize] = 0
php_admin_value[max_file_uploads] = 200
php_admin_value[upload_tmp_dir] = /var/tmp
php_value[memory_limit] = 512M
```

Conf `/etc/php/8.3/fpm/conf.d/90-gallery-opcache.ini`:

```
opcache.memory_consumption=192
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
```

Plus in `php.ini`: `expose_php = Off`.

---

## 6. CRONTAB entries

Recreate all three files in `/etc/cron.d/` (owner `root:root`, mode `644`):

`gallery-backup` (daily 03:00):
```
0 3 * * * www-data /usr/bin/php /var/www/gallery/bin/gallery_backup.php >> /var/www/gallery/storage/logs/backup.log 2>&1
```

`gallery-housekeeping` (every 15 min — the key is `GALLERY_CRON_KEY`):
```
*/15 * * * * www-data curl -fsS "http://127.0.0.1/gallery/cron/housekeeping?key=<KEY>" > /dev/null 2>&1
```

`gallery-restore-drill` (weekly Sunday 04:00):
```
0 4 * * 0 root /usr/local/bin/restore-drill >> /var/www/gallery/storage/logs/drill.log 2>&1
```
(a restore-drill script proves Drive backups are restorable.)

---

## 7. SYSTEMD background workers

Install both units from the repo `config/` and enable them. Both need the
corresponding migration applied first (`100` is a placeholder; run
`php scripts/migrate.php`).

```bash
sudo cp config/gallery-video-export.service /etc/systemd/system/
sudo cp config/gallery-photo-edit.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now gallery-video-export.service gallery-photo-edit.service
```

Workers:
- `bin/video_export_queue.php` → `bin/video_export_worker.php` (video exports)
- `bin/photo_edit_queue.php` → `bin/photo_edit_worker.php` (bulk rotate)

---

## 8. Pillar scripts (may move to repo)

Host-level helpers on the current box (keep copies on the new host):

- `/home/webdev/install.sh`, `/home/webdev/uninstall.sh` — install/teardown
- `/usr/local/bin/restore-drill` — weekly backup restore drill
- `/usr/local/sbin/gallery-post-os-upgrade` — post-OS-upgrade fixup

---

## 9. Verification checklist (post-migration)

```bash
php tests/smoke.php                  # 220 static checks, all pass
curl -s http://<host>/gallery/login | grep -q '200'   # site up
curl -s http://127.0.0.1/gallery/health              # JSON {ok:true,...}
php scripts/migrate.php --status     # no pending migrations
curl -s http://127.0.0.1/gallery/account              # admin login works
systemctl is-active gallery-video-export gallery-photo-edit   # both active
# Restore drill: prove a Drive backup restores (weekly cron does this)
```

Also confirm: storage uploads writable by `www-data`, rclone sync works
(backup.log reflects a successful run), and the `/gallery/files/*` media
streaming path returns `206` for ranges.

---

## 10. DNS / email / payments (external, unchanged)

- **Email (SPF/DKIM/DMARC):** pending — needs DNS access. `MAIL_*` uses Gmail
  SMTP with an app password.
- **PayPal:** webhook auto-activation requires a REST App Secret + Webhook ID;
  currently records pending subscriptions for manual admin approval.
- **Braintree:** uses a zero-dependency custom client; may need prod creds.

---

## Summary of non-repo files that must be copied

| Path (source host) | Purpose |
|---|---|
| `/var/www/gallery/.env` | secrets (DB, SMTP, cron key) |
| `/var/www/gallery/storage/` | media + generated derivatives |
| DB `gallery_mvc` (mysql dump) | all content |
| `/var/www/.config/rclone/rclone.conf` | Drive offsite backup |
| `/etc/apache2/sites-enabled/gallery-headers.conf` | security headers/CSP |
| edits to `sites-available/000-default.conf` | alias, XSendFile, TLS, assets |
| `/etc/apache2/conf-available/gallery-php-fpm.conf` | PHP-FPM handler |
| `/etc/apache2/ssl/gallery.{crt,key}` | self-signed TLS cert |
| `/etc/php/8.3/fpm/pool.d/www.conf` (edits) + `90-gallery-opcache.ini` | FPM tuning |
| `/etc/cron.d/gallery-{backup,housekeeping,restore-drill}` | cron jobs |
| `/etc/systemd/system/gallery-{video-export,photo-edit}.service` | workers |
| `/etc/apt/apt.conf.d/99gallery-confold` | protect confs on OS upgrades |
