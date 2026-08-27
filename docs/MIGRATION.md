# Gallery site — migration runbook

Hand-over document for moving the production gallery site from the current host
to a new remote server. It captures every piece of production configuration and
data that is **not** baked into `scripts/install.sh` (which only scaffolds a
fresh, minimal install), so the new server can be brought up to feature parity
with the current one.

> **Migration scope this time:** database **structure**, the `fidjiter@gmail.com`
> super_admin, and the **categories** table data. **Media and the
> `gallery_category` link table are NOT migrated** (no `storage/uploads` copy;
> the links reference gallery IDs that won't exist). See §2.

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

**Scope for this migration:** the database **structure** (full schema), the
**fidjiter admin user**, and the **categories** table data. **Media is NOT
migrated** — the new server starts with an empty `storage/uploads` tree (fresh
uploads only). The `gallery_category` link table is also excluded (its
`gallery_id` references won't exist without gallery content). No gallery/photo
content data and no other user accounts travel over.

### 2.1 Database — schema + selected seed data

Do **not** use the stock `schema.sql` alone for a data migration — it only
seeds plans + a placeholder admin and carries no real data. Instead, restore
the full schema from the production dump and then migrate the specific rows.

**Step 1 — dump the schema (+ schema_migrations metadata) from the current host:**

```bash
mysqldump -u root --no-data gallery_mvc > gallery_schema.sql
mysqldump -u root --no-data gallery_mvc schema_migrations > gallery_schema_migrations.sql 2>/dev/null
```

**Step 2 — on the new server**, after the app code + `.env` are in place:

```bash
mysql -u root gallery_mvc < gallery_schema.sql
# restore migration-metadata so checksums match (must be identical to prod)
mysql -u root gallery_mvc < gallery_schema_migrations.sql
php scripts/migrate.php --status     # expect: no pending migrations
```

**Step 3 — migrate only the fidjiter admin + the categories table.**

The `fidjiter@gmail.com` super_admin and the category list are the only content
rows copied here. The `gallery_category` link table is **deliberately skipped**:
its rows reference `gallery_id`s that will not exist on the new server (no
gallery content/media is migrated), so they would be orphaned or violate the
foreign key.

```bash
# Dump just those rows on the current host:
mysqldump -u root gallery_mvc users --where="id = 2" --no-create-info > seed_admin.sql
mysqldump -u root gallery_mvc categories --no-create-info > seed_categories.sql

# On the new server, load them:
mysql -u root gallery_mvc < seed_admin.sql
mysql -u root gallery_mvc < seed_categories.sql
```

Notes:
- `users` row `id=2` is `fidjiter@gmail.com`, role `super_admin`; its bcrypt
  password hash travels with the row so the existing password keeps working.
- The `categories` table (75 rows: `id`, `name`, `slug`) is fully loaded.
- The `gallery_category` join table is **not** migrated (see above); categories
  can be re-assigned to galleries once content is added later.
- Before going live, confirm migration checksums match
  (`php scripts/migrate.php --status`).

### 2.2 Media / storage tree — NOT migrated

`/var/www/gallery/storage/` holds all user-generated media and generated
derivatives. It is **excluded from this migration**. The new server should
have an empty, writable storage tree:

```bash
# On the new server, create fresh (do NOT copy from the old host):
mkdir -p /var/www/gallery/storage/uploads/exports
chown -R www-data:www-data /var/www/gallery/storage
chmod -R 775 /var/www/gallery/storage
```

Again: **do not** rsync `/var/www/gallery/storage/` from the old server for
this migration.

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
(backup.log reflects a successful run). Media streaming (`/gallery/files/*`)
has no legacy files on the new host (media was not migrated); uploads created
on the new server are served as normal.

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
| DB `gallery_mvc` schema dump (`--no-data` + `schema_migrations`) | table structure |
| DB `users` `id=2` (`fidjiter@gmail.com` super_admin) | admin login (password hash travels) |
| DB `categories` dump | category data (75 rows) |
| `/var/www/.config/rclone/rclone.conf` | Drive offsite backup |
| `/etc/apache2/sites-enabled/gallery-headers.conf` | security headers/CSP |
| edits to `sites-available/000-default.conf` | alias, XSendFile, TLS, assets |
| `/etc/apache2/conf-available/gallery-php-fpm.conf` | PHP-FPM handler |
| `/etc/apache2/ssl/gallery.{crt,key}` | self-signed TLS cert |
| `/etc/php/8.3/fpm/pool.d/www.conf` (edits) + `90-gallery-opcache.ini` | FPM tuning |
| `/etc/cron.d/gallery-{backup,housekeeping,restore-drill}` | cron jobs |
| `/etc/systemd/system/gallery-{video-export,photo-edit}.service` | workers |
| `/etc/apt/apt.conf.d/99gallery-confold` | protect confs on OS upgrades |

**Not migrated in this pass (by design):** `/var/www/gallery/storage/` (media +
derivatives), the `gallery_category` link table, gallery/photo content, and all
user accounts except the fidjiter admin. These stay on the old host.
