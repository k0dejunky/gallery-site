# gallery-mvc

A self-hosted, plain-PHP photo & video gallery site with memberships, categories,
a video editor/exporter, a visual site editor, auto-posting to Reddit/X, an
in-app test suite, admin two-factor authentication, and full email-server
administration.

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
  `PAYPAL_CLIENT_SECRET` are optional. Admin sessions expire after 30 minutes idle by default
  (or 7 days when "keep me signed in" is ticked at login); non-admin sessions use the general
  window. Password recovery/email verification requests are rate limited, and admins can enable
  TOTP two-factor authentication from Settings.

## Admin email server administration

The admin **Email** page manages the Postfix + Dovecot virtual mailboxes that
serve the site's mail domain:

- Lists every mailbox with per-mailbox storage and Postfix/Dovecot/OpenDKIM service status.
- **Create** — email + password (min 8 chars): adds to `/etc/postfix/vmailbox` and the Dovecot
  passwd-file (`/etc/dovecot/users`), generates a `{SHA512-CRYPT}` hash, creates the Maildir
  (`/var/mail/vhosts/{domain}/{user}/`, owned `vmail:mail`), rebuilds the Postfix map and
  reloads Dovecot.
- **Change password** and **Delete** (with confirmation) work the same way.

Privileged operations run through the root-only `bin/mail_admin.php` via a scoped sudoers rule,
so the web process never edits `/etc` directly. On a mail host, install the rule once:

```bash
echo 'www-data ALL=(root) NOPASSWD: /usr/bin/php /var/www/gallery/bin/mail_admin.php *' \
  > /etc/sudoers.d/gallery-mail-admin
chmod 440 /etc/sudoers.d/gallery-mail-admin
visudo -c
```

On hosts without Postfix/Dovecot the page reports "Mail admin unavailable" instead of failing.

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

### Photo edit jobs (bulk rotate)

Long-running image edits (currently bulk rotate) are stored in
`photo_edit_jobs` and processed by the supervised `bin/photo_edit_queue.php`
worker (`bin/photo_edit_worker.php` processes each claimed job). Enqueueing
instead of processing in the HTTP request avoids FPM timeouts when rotating
many large images. Install with:

```bash
sudo cp config/gallery-photo-edit.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now gallery-photo-edit.service
```

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

## Outbound email (SMTP)

The app sends email through the Mailer (SMTP over STARTTLS, or PHP `mail()`
when no SMTP is configured). On the production host the site runs its own
**Postfix + Dovecot + OpenDKIM** stack (the same mailboxes managed from the
admin Email page), so outbound mail is delivered locally. SMTP configuration
lives in `.env`:

- `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` — for a
  relay such as Gmail when not using the local MTA.
- `MAIL_FROM` — the From address used for alerts.
- `ADMIN_EMAIL` — the recipient for throttled admin alerts.

To test delivery, use the **Test SMTP** button on the admin System page (or
the send-test form on the admin Email page), or send an app alert from the
command line. If mail stops working, check `.env`, reload PHP-FPM
(`sudo systemctl reload php8.3-fpm`), and inspect `/var/log/mail.log` on the
mail host.
