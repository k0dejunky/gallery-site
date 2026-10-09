# Gallery Codebase Reference

This file is the authoritative inventory of what already exists in this project.
**Read it before writing any new file, function, or endpoint** — if something
similar already exists, extend/reuse it instead of creating a duplicate.

Last updated: 2026-09-27 (chat attachment/decoration helpers centralised).

---

## 1. Overview

Plain-PHP (no framework) gallery site with:
- member web front end (`views/`),
- admin panel (`views/admin/`, routes under `/admin/*`),
- an operator Android app (`chat-operator-app/`),
- live video streaming (MediaMTX + the `LiveActivity` app),
- an AI chat assistant with a LoRA trainer on a separate PC (`training/`),
- auto-posting to X/Reddit (`AutoPostQueue`),
- PayPal + Braintree subscriptions.

Stack: PHP 8.3 (FPM), MySQL 8 (`gallery_mvc`), Apache (mod_proxy_fcgi), ffmpeg,
MediaMTX 0.23.8 (RTMP :1935, HLS 127.0.0.1:8888, API 127.0.0.1:9997).

Servers:
- dev: 192.168.1.110 (`/var/www/gallery`)
- prod: amethyst2213.com (`/var/www/gallery`)

---

## 2. Directory map

```
app/
  Core/        framework + shared services (Auth, Controller, Database, Cache,
               Request, Router, Csrf, Flash, Mailer, RateLimiter, Validator,
               Housekeeping, ChatAi, ChatModel, ChatSettings, ChatContentSearch,
               AutoPostText, ImageEditor, Braintree/PayPal gateways, helpers.php)
  Models/      database models (all static-method models; see section 4)
  Controllers/ request handlers (one class per area; see section 5)
bin/           CLI workers/scripts (cron jobs, importers, trainers; see section 8)
config/        routes.php (all routes), database.php
database/migrations/  numbered SQL migrations (apply with scripts/migrate.php)
public/        web root (index.php, assets/, uploaded media live in storage/)
scripts/       deploy.sh, migrate.php, live-record.py (MediaMTX recorder),
               publish_gate_smoke.php, restore-drill.sh
storage/       uploads/, logs/, training/, live-recordings/, chat.json (legacy)
views/         PHP templates (layout.php wraps everything; sidebar in layout)
training/      chat LoRA trainer + control server/GUI (Windows PC 192.168.1.250)
chat-operator-app/  the Android operator app (Kotlin)
CODEBASE.md    this file
```

### Routing model

Every route is a row in `config/routes.php`:
`['METHOD', '/path', 'Controller@method']` and optionally a 4th permission
element (`'chat'`, `'admin'`, ...). `Router.php` dispatches. POSTs are CSRF
checked unless the path starts with `/webhooks/` or is in the explicit exempt
list (`/live/start`, `/live/stop`, `/live/chat/send`, `/live/pause`,
`/live/resume`). `LiveController`/`ChatBridgeController` are Bearer-token
authenticated (operator device token or the shared `GALLERY_CHAT_KEY`).

---

## 3. Shared helpers — USE THESE (do not duplicate)

The following are the single source of truth for their responsibility. If you
need the behaviour, call these — never copy the logic into a controller/view.

| Responsibility | Where |
|---|---|
| Send a JSON response from any controller | `App\Core\Controller::json($data, $status = 200)` (protected; all controllers inherit it — do NOT add `private function json()` to a controller) |
| Raw request body (JSON webhooks/apps) | `App\Core\Controller::rawBody()` (protected, inherited — do NOT re-declare) |
| Require login and return the member row | `App\Core\Auth::requireUser()` (replaces `requireLogin(); $user = Auth::user();`) |
| Membership-admin guard | extend `App\Controllers\MembershipAdminController` (do NOT duplicate the `requirePermission('membership')` constructor) |
| Serve a chat attachment file (expiry, view count, MIME sniff, thumbnail, readfile) | `App\Models\ChatMessage::serveAttachment(array $msg, bool $thumb = false, bool $countView = false)` |
| Sniff a stored file's real MIME type (phone uploads arrive as octet-stream) | `App\Models\ChatMessage::sniffAttachmentType(string $path): string` |
| Add `attachment_url`/`attachment_thumb_url`/expiry metadata to chat message rows | `App\Models\ChatMessage::decorateMessages(array $messages, string $urlBase): array` (`$urlBase` = `/chat/attachment`, `/webhooks/chat/attachment` or `/admin/chat/attachment`) |
| Store an uploaded chat attachment + return `{name,type,path}` | `App\Models\ChatMessage::storeAttachment(array $file)` |
| Expiring-media checks/cleanup | `ChatMessage::isMediaExpired($msg)` / `purgeExpiredMedia()` |
| Live stream key helpers (hash, session lifecycle, isPublishing) | `App\Models\LiveSession` |
| Finalize/import live recordings into galleries | `App\Models\LiveRecording::finalize()` / `importOrphans()` |
| Live MediaMTX HTTP calls (auth webhook, status, close paths) | `LiveController::auth()` + `App\Core\Http::request()` |
| `url()`, `e()`, `tzdate()`, `config()`, `env_value()`, site helpers | `app/Core/helpers.php` (global functions) |

**NOTE (known pitfalls):**
- `GREATEST/LEAST(..., ?)` with a bound PDO parameter compares as **strings**
  (`GREATEST(96,'137')` = 96). Always use `CAST(? AS UNSIGNED)` for numeric
  `GREATEST/LEAST` with a bound parameter.
- Do not add a `private function json()` to a controller — the base
  `Controller::json()` already exists.
- The three chat attachment endpoints must all go through
  `ChatMessage::serveAttachment()` so expiry/view-count/MIME behaviour stays
  consistent.

---

## 4. Models (`app/Models/`) — all static methods

- `AuditLog` — admin audit trail (record/recent/search/diff).
- `AutoPosterConfig` — autopost settings (templates, X/Reddit tokens, schedule).
- `AutoPostQueue` — queued X/Reddit posts (buildText, schedule, recommendations).
- `Category` — gallery categories CRUD.
- `ChatBroadcast` — scheduled daily operator broadcasts.
- `ChatMessage` — the chat core. Key methods: `canChat`, `openFor`, `find`,
  `forUser`, `addMessage`, `storeAttachment`, `serveAttachment`,
  `sniffAttachmentType`, `decorateMessages`, `isMediaExpired`,
  `purgeExpiredMedia`, `messages/messagesLatest/messagesBefore`,
  `latestId/firstId/hasOlder`, `markRead`, `unreadCountForUser`,
  `memberReplyEnabled/setMemberReply`, `setMode`, `insertTrainingPair`,
  `similarContext`, `tokenize`.
- `ChatTraining` — shared training-pair import logic (clean/isJunk/normalizePair/
  insertPairs/savePair).
- `EmailerConfig`/`EmailQueue` — digest emailer.
- `FavoriteCategory` — member favourite categories.
- `Gallery` — gallery CRUD + visibility SQL (`publishedVisibleSql`,
  `userVisibleSql`, `userCanView`, publish scheduling).
- `Http` — tiny `request($url, $opts)` wrapper (used by LiveSession, ChatAi).
- `LiveRecording` — `finalize($streamKey)` (TS→MP4, gallery min_level 3,
  autopost) + `importOrphans()` (housekeeping cron).
- `LiveSession` — live stream session ledger: `hashOf`, `active`, `create`,
  `markLive`, `markEnded`, `validStreamKey`, `closeAllPaths`, `isPublishing`,
  `status`, `playbackToken`, `validPlaybackToken`.
- `OperatorToken` — per-device Bearer tokens for the app.
- `Photo` — media file rows (`create`, `attach`, etc.).
- `Subscription` — `isActive`, `pendingFor`, activation.
- `SupportMessage` — support tickets + `unreadCountForUser`/`markReadForUser`.
- `Theme` — site theme presets.
- `User` — user CRUD/roles.
- `WebStats` — reads the nine `web_stats_*` / `web_visits` rollup tables for the
  analytics page: range resolution, headline cards with previous-period change,
  daily/hourly series, request mix, top/entry/exit pages, referrers, agent
  breakdown, statuses, file types, top IPs, visit quality, robots, health and
  CSV rows. Robots are stored separately and hidden unless `$includeBots`; the
  bandwidth and status/file-type counters are all-traffic by design.

---

## 5. Controllers (`app/Controllers/`) and their endpoints

**Member web**
- `AuthController` — login, 2FA, signup, verify, logout, password reset.
- `GalleryController` — gallery browse/show, upload (chunked), edit, publish,
  bulk.
- `CategoryController` — category pages.
- `ChatController` — member chat: `index`, `send`, `poll`, `history`, `stream`
  (SSE), `attachment`. URLs: `/chat`, `/chat/attachment`.
- `FavoriteController`, `SupportController`, `SettingsController`,
  `MembershipController` (Braintree/PayPal subscribe/cancel),
  `PhotoController`, `VideoEditorController` (photo/video editing),
  `ImageController` (serve images), `StorageController::serve` (media),
  `ExportController`, `EmailController`, `HelpController`, `HealthController`.

**Admin**
- `AdminController` — dashboard, gallery mgmt, abandoned uploads.
- `AdminChatController` — operator chat console: `index`, `show`, `history`,
  `mode`, `operatorReply`, daily broadcasts, device tokens, training import,
  `attachment`.
- `AutoPosterController` — X/Reddit autoposter UI + posting actions.
- `SiteEditorController` — theme/site editor.
- `SystemController` — system page: cron schedule, backups, DB ops, cleanup,
  variants, housekeeping "run now", PayPal reconcile.
- `AnalyticsController` — `/admin/analytics` (AWStats-style page from the
  access-log rollups), `/admin/analytics/reparse` (POST, re-reads the log files),
  `/admin/analytics/export` (CSV). Requires the `analytics` permission.
- `EmailerController`, `SupportController` (admin views), `ExportController`.

**Webhooks / APIs (Bearer-authenticated, `/webhooks/*`)**
- `ChatBridgeController` — the Android app's API: `config`, `pending`, `inbox`,
  `thread`, `history`, `stream`, `events`, `reply`, `users`, `start`,
  `replyToggle`, `context`, `trainingData`, `trainingProgress`, `trainingCount`,
  `trainingUpload`, `mode`, `read`, `attachment`, `apkInfo`, `apk`.
- `WebhookController::handle` — PayPal/Braintree payment postbacks.
- `LiveController` — live streaming + live chat:
  `auth` (MediaMTX webhook), `start`, `stop`, `pause`, `resume`, `page`,
  `state`, `status`, `chatSend`, `chatStream`.

**Jobs**
- `CronController::run` — housekeeping + live-recording orphan import
  (invoked by `/etc/cron.d/gallery-housekeeping`).

---

## 6. Core services (`app/Core/`)

- `Controller` — base: `$request`, `view()`, `json()`, sidebar helpers.
- `Auth` — sessions, roles (`ADMIN_ROLES`), `requireLogin`,
  `requireSubscription`, `hasActiveSubscription`, `hasMembershipLevel`.
- `Database` — PDO singleton (`run($sql, $params)`, `connection()`).
- `Request` — input/query/file/header accessors.
- `Router` — dispatch + CSRF.
- `Cache` — generation-keyed cache (`rememberGen`, `bump`).
- `Csrf`, `Flash`, `Validator`, `RateLimiter`, `Mailer`, `Housekeeping`,
  `Totp`, `Charts`, `ImageEditor`, `AutoPostText`, `ChatAi` (Ollama client),
  `ChatModel` (adapter path + rebuild), `ChatSettings` (chat_settings DB table),
  `ChatContentSearch`, `BraintreeGateway`, `PayPalGateway`.
- `AccessLogParser` — pure, DB-free Apache combined-log parser. `parse()` returns
  every accumulator at once (tests/small windows); `streamByDay()` walks a
  chronological stream and hands one day at a time to a callback (backfills, so
  memory stays flat). Options: `base_path`, `site_host`, `include_private`,
  `timezone`. Private/loopback addresses are dropped by default (own cron/health
  traffic); robots are counted alongside humans in `bot_hits`/`bot_page_views`.
- `AccessLogAggregator` — turns the parser output into the nine `web_stats_*` /
  `web_visits` tables: discovers rotated logs (glob, mtime-ordered oldest first,
  gzip-aware), deletes+reinserts one day per transaction, then recomputes the
  session rollups in SQL so a visit that crosses midnight lands on its start day.
  `bin/aggregate_access_log.php` is the CLI (flock'd); the admin page and the
  hourly `gallery-web-analytics` cron entry both call the same class.
- `helpers.php` — global `url()`, `e()`, `tzdate()`, `config()`, `env_value()`,
  `site_timezone()`, thumbnail helpers, etc.

---

## 7. Views (`views/`)

- `layout.php` — global chrome + left sidebar; the sidebar renders the Live
  link (subscribers/admins), Chat link with unread badge, favourite categories.
- `chat/index.php` — member chat (SSE live updates, lazy history, thumbnails).
- `live.php` — live player (hls.js, mpegts HLS), live group chat, mute +
  fullscreen controls, pause overlay.
- `admin/chat.php`, `admin/chat_show.php` — operator chat list + conversation.
- `gallery/*`, `auth/*`, `settings.php`, `support/*`, `membership/*`,
  `favorites`, `admin/*` (dashboard, system, autoposter, site editor),
  `emails/*`, `errors/*`, `about.php`, `privacy.php`, `terms.php`.

---

## 8. CLI workers (`bin/`)

- `apply_cron.php` — writes `/etc/cron.d/gallery-*` entries from settings
  (including `gallery-web-analytics`, hourly, `--days=2`).
- `aggregate_access_log.php` — fold Apache access logs into the analytics
  tables. `--days=N`, `--from/--to`, `--files=`, `--include-private`,
  `--dry-run`, `--quiet`. Needs www-data to read /var/log/apache2 (group `adm`).
- `live_recording_import.php` — orphan live-recording importer (housekeeping).
- `daily_chat_worker.php`, `chat_training_import.php`,
  `chat_training_export.php`, `autopost_worker.php`, `email_worker.php`,
  `gallery_backup.php`, `photo_edit_worker.php`, `video_export_worker.php`,
  `paypal_reconcile.php`, `mail_admin.php`, `strip_pending_prefix.php`,
  `test_runner.php`, `apply_server_optimizations.php`.

---

## 9. Live streaming (existing system)

- MediaMTX config: `/etc/mediamtx/mediamtx.yml` (RTMP :1935, HLS :8888 mpegts,
  API :9997, `externalAuthenticationURL` → `/gallery/webhooks/live/auth`).
- Recorder: `scripts/live-record.py` started by MediaMTX `runOnReady`; writes
  `<streamkey>.ts` into `storage/live-recordings/` (reads HLS segments as the
  internal `rec` reader).
- `LiveSession` model + `LiveController` manage sessions; recordings are
  finalized into `min_level 3` video galleries by `LiveRecording`.
- The member live page is `views/live.php` (player + chat + pause overlay).
- Do NOT re-create: `LiveController`, `LiveSession`, `LiveRecording`,
  `scripts/live-record.py`, the `/live/*` routes, or the pause/record/awake
  logic inside `LiveActivity.kt`.

---

## 10. Android operator app (`chat-operator-app/`)

Kotlin app; the only client for `ChatBridgeController` webhooks.

- `App.kt` — Application: Coil ImageLoader with Bearer interceptor.
- `MainActivity.kt`, `MainViewModel.kt` — login/session + update flow.
- `ChatActivity.kt` — the operator chat (send text/images/video, expiry dialog,
  message adapter, live group chat in `LiveActivity`).
- `LiveActivity.kt` — live broadcast: RootEncoder `RtmpStream`, preview
  (TextureView), flip camera, orientation-aware encoder, mute/pause, wake lock,
  local MP4 recording.
- `ChatBridge.kt` — OkHttp client for `/webhooks/chat/*`.
- `SendQueue.kt` / `SendReplyWorker.kt` — offline send queue.
- `ChatPollService.kt`, `ChatActionReceiver.kt`, `MarkReadWorker.kt` —
  notifications/mark-read.
- `Updater.kt` — APK download + verify (signer/checksum) + install.
- `SecurePrefs.kt`, `Passcode.kt`, `Favorites.kt`, `MediaViewerActivity.kt`,
  `SettingsActivity.kt`, `StatusBarToolbar.kt`.

Version bumps are **mandatory** before every release build (see AGENTS.md):
edit `versionName`/`versionCode` in `app/build.gradle.kts`, publish the signed
APK as `public/assets/apk/OperatorChat-v{version}.apk`, and update
`public/assets/apk/operator-chat-version.json`.

Repo hygiene: only the latest and the immediately-previous APK are kept in git
(older builds were pruned to shrink the repo). When publishing a new build,
`git rm` the oldest tracked APK so exactly two binaries + the manifest remain.


---

## 11. Chat / AI / trainer

- Operator chat + AI: `ChatController` (member), `ChatBridgeController` (app),
  `AdminChatController` (operator), `ChatAi`/`ChatModel` (Ollama),
  `ChatSettings` (chat_settings table), `ChatContentSearch`.
- Training corpus: `chat_training_pairs` table; `ChatTraining` model +
  `bin/chat_training_import.php` + admin import form.
- Always-on trainer: `training/chat_trainer.py` (PC 192.168.1.250),
  `training/trainer_control.py` (control server :8790), `trainer_gui.py`.
- Watermark reporting: `ChatBridgeController::trainingProgress`,
  `trainingCount`, `trainingUpload` (adapter upload → Ollama rebuild).

---

## 12. Deploying

`scripts/deploy.sh` copies listed files to a server and reloads php-fpm.
Env: `DEPLOY_HOST`, `DEPLOY_PASS`, `DEPLOY_ROOT`, `DEPLOY_HEALTH_URL`,
`DEPLOY_MIGRATE=1` runs `scripts/migrate.php`. Deployment order:
dev → smoke → prod → verify → commit/push.

---

## 13. Things that already exist (don't recreate)

- JSON responses: `Controller::json()` (base class).
- Chat attachment serving/thumbnail/MIME sniffing/decoration:
  `ChatMessage::serveAttachment`, `sniffAttachmentType`, `decorateMessages`,
  `storeAttachment`.
- Live recording → gallery import: `LiveRecording` + `scripts/live-record.py` +
  `bin/live_recording_import.php`.
- Live pause/resume + viewer "be right back" overlay: `LiveController::pause/
  resume`, `live_sessions.paused_at`, `views/live.php` overlay.
- App live controls (mute mic, pause, flip, orientation, local recording,
  keep-awake): all in `LiveActivity.kt` / `activity_live.xml`.
- Unread chat badge: `ChatMessage::unreadCountForUser` + `markRead` (sidebar).
- Expiring media: `chat_messages.expires_at/max_views/view_count` +
  `ChatMessage::isMediaExpired`/`purgeExpiredMedia`.