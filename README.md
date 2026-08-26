# gallery-mvc

A self-hosted, plain-PHP photo & video gallery site with memberships, categories,
a video editor/exporter, and a visual site editor.

No Composer, no framework CLI — it's a simple PHP app that ships its own schema
and seed data, so installing on a fresh Ubuntu/Debian server is one command.

## Requirements

- **OS:** Ubuntu 20.04+ / Debian 11+ (Apache 2.4, PHP 7.4+, MariaDB/MySQL)
- **PHP:** PHP-FPM (the installer sets up `mpm_event` + `mod_proxy_fcgi`; mod_php is not used)
- **PHP extensions:** `pdo_mysql`, `gd`, `fileinfo`, `json`, `mbstring`
- **Apache modules:** `rewrite`, `alias`, `proxy_fcgi`, `setenvif`, `xsendfile`, `deflate`, `headers`, `expires` (all enabled by the installer)
- **mod_xsendfile** (`libapache2-mod-xsendfile`) — streams uploaded media from Apache instead of PHP
- **ffmpeg** (video thumbnails + the export worker)
- **Node + npm** (only if you want to rebuild the React video editor; a prebuilt
  `dist/` is included)

## Quick start

```bash
git clone https://github.com/k0dejunky/gallery-site.git /opt/gallery-src
cd /opt/gallery-src
sudo ./install.sh --unattended --source /opt/gallery-src
```

The site is served at `http://<server-ip>/gallery`.

## install.sh

Installs everything onto a fresh host.

```bash
sudo ./install.sh [options]
```

| Option | Description |
|--------|-------------|
| `--source PATH` | Directory containing the site code (default: the script's own directory) |
| `--dir PATH` | Install directory (default `/var/www/gallery`) |
| `--db-name NAME` | Database name (default `gallery_mvc`) |
| `--db-user USER` | Database user (default `gallery`) |
| `--db-pass PASS` | DB password (default: auto-generated and printed) |
| `--admin-email EMAIL` | Admin login email (default `admin@example.com`) |
| `--admin-pass PASS` | Admin password (default: auto-generated and printed) |
| `--no-frontend-build` | Keep the prebuilt video editor; don't run Vite |
| `--unattended` | No prompts; sets `--yes` and quiets `apt` |
| `--yes` | Skip the confirmation prompt |

The script:
1. Installs `apache2`, `php-fpm` (+ extensions), `mysql-server`, `ffmpeg`, `libapache2-mod-xsendfile`
2. Enables Apache `mpm_event`, `rewrite`, `alias`, `proxy_fcgi`, `setenvif`, `xsendfile`, `deflate`, `headers`, `expires`
3. Configures the PHP-FPM pool (dynamic workers + the 10 GiB upload ceiling) and enables OPcache
4. Copies the site code and creates an empty `storage/uploads`
5. Writes a global Apache `/gallery` alias (in `conf-available/gallery.conf`) with X-SendFile + static-asset caching
6. Creates the database + user, imports `schema.sql` (plans + admin seed)
7. Applies your admin email/password with a fresh bcrypt hash
8. Writes a MySQL tuning config (`99-gallery-tuning.cnf`) and restarts MySQL
9. Writes `.env`, sets `www-data` ownership/permissions
10. Restarts Apache and verifies the site responds

The URL prefix is hardcoded to `/gallery` in `config/app.php` and in the Apache
alias — keep the two consistent.

## uninstall.sh

Removes an install created by `install.sh`.

```bash
sudo ./uninstall.sh [options]
```

| Option | Description |
|--------|-------------|
| `--drop-db` | Also drop the database and app MySQL user |
| `--purge-packages` | Also remove apache2/php/ffmpeg/mysql packages |
| `--keep-uploads` | Preserve `storage/uploads` contents (moved to `/tmp/gallery-uploads-backup-*`) |
| `--yes` | Skip the confirmation prompt |

By default it removes only the site files and the Apache `/gallery` alias config.

## Configuration

- **URL path:** `config/app.php` → `base_path` (default `/gallery`)
- **Database:** `config/database.php` reads from `.env`
- **Upload limit:** 10 GiB per file — app ceiling in `config/app.php`, web-server ceiling in the PHP-FPM pool (`www.conf`), *not* in `.htaccess` (FPM ignores `php_value`)
- **Media serving:** originals/videos are streamed by Apache via `mod_xsendfile` (auth still enforced in PHP)
- **Uploads:** stored in `storage/uploads/` (gitignored)
- **Themes / layout:** regenerated from defaults in `storage/` if missing
- **MySQL tuning:** `/etc/mysql/mysql.conf.d/99-gallery-tuning.cnf` (buffer pool, slow-query log)
- **Optional hardening:** set `APP_ENV=production` and keep `APP_DEBUG=false`; `APP_URL` and
  `PAYPAL_CLIENT_SECRET` are optional. Authenticated sessions expire after 12 hours idle by
  default, and password recovery/email verification requests are rate limited.

## Server stack & performance

- **PHP-FPM + `mpm_event`** instead of mod_php/`mpm_prefork` — Apache workers stay
  small and PHP processes are pooled, cutting memory dramatically.
- **mod_xsendfile** streams large media through Apache (the controller emits an
  `X-Sendfile` header) so video seeking (`206 Partial Content`) never runs the
  file through a PHP worker.
- **OPcache** enabled with timestamp revalidation (~2 s), so the Site Editor's
  runtime template edits apply without a manual reset.
- **mod_deflate** compresses HTML/CSS/JS; `mod_expires`/`mod_headers` cache static
  assets under `/gallery/assets`.
- **N+1 elimination:** the home page bulk-loads favorite-category galleries in one
  query (`Gallery::inCategories()`), cutting a load from ~78 SQL statements to ~20.

## Development

- Views: `views/`, Controllers: `app/Controllers/`, Models: `app/Models/`
- Routes: `config/routes.php`
- Video editor frontend: `frontend/video-editor/` (Vite + React). Rebuild with
  `npm ci && npm run build` inside that directory; the output goes to `dist/`.

## Background jobs

Video exports are stored in `video_export_jobs` and processed by the supervised
`bin/video_export_queue.php` worker. The worker claims jobs transactionally,
retries failed exports up to three times, and leaves progress in the database
for the existing status endpoint and admin screens.

On systemd hosts, install and enable the service with:

```bash
sudo cp config/gallery-video-export.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now gallery-video-export.service
sudo systemctl status gallery-video-export.service
```

Apply the queue migration before starting the worker with `php scripts/migrate.php`.

## Migrations and deployment

The installer bootstraps the current schema from `schema.sql`. Subsequent
schema changes belong in numbered files under `database/migrations/` and are
applied once with PDO:

```bash
php scripts/migrate.php --status
php scripts/migrate.php
```

To deploy selected files, run from the repository root. The script lints the
local PHP tree, stages and snapshots remote files, lints the installed PHP,
and restores the snapshot if installation or an optional health check fails:

```bash
scripts/deploy.sh app/Models/User.php public/index.php
DEPLOY_HEALTH_URL=http://127.0.0.1/gallery/ scripts/deploy.sh public/index.php
DEPLOY_NO_RELOAD=1 scripts/deploy.sh config/routes.php
```

Use `DEPLOY_HOST`, `DEPLOY_PASS`, `DEPLOY_ROOT`, and `DEPLOY_SNAP_DIR` to
override deployment settings without changing the script.

## Security

- Never commit a real `.env` (it's gitignored).
- Change the seeded admin password after first login.
- The seed password is a known default; pass `--admin-pass` at install time to
  set a fresh one, or reset it from the admin Users page.

## Gmail SMTP app password

The site uses a Gmail App Password for outbound email (support replies,
verification links, password resets). If the password stops working:

1. Go to [Google App Passwords](https://myaccount.google.com/apppasswords)
   while logged into the admin Gmail account.
2. Select app: **Mail**, device: **Other (Custom name)** → enter `Gallery SMTP`.
3. Click **Generate** — Google shows a 16-character password once.
4. Update `.env` on the server:
   ```bash
   sudo nano /var/www/gallery/.env
   ```
   Replace the `MAIL_PASSWORD=` value with the new password.
5. Reload PHP-FPM:
   ```bash
   sudo systemctl reload php8.3-fpm
   ```
6. Test from the admin **System** page — click the **Test SMTP** button.
   Or verify from the command line:
   ```bash
   sudo -u www-data php /var/www/gallery/bin/smtp_test.php
   ```

App passwords don't expire unless you revoke them or change your Google
account password. The admin **System** page shows whether SMTP is configured.
