# Android thin client — Chat Bridge contract

The Android app (OperatorChat) is a **thin client**: no AI logic, no API keys
on the device beyond the operator's Bearer token. It talks to the site's chat
webhooks. The preferred credential is a **per-device operator token**
(`operator_tokens`, SHA-256-hashed server-side, revocable from the admin Chat
page); the legacy shared `GALLERY_CHAT_KEY` is still accepted for migration.

Base URL: `https://amethyst2213.com/gallery`

## Auth

All requests send `Authorization: Bearer <token>`. The app stores URL + token
encrypted (AndroidKeyStore AES/GCM). The inbox, threads, replies, streams,
attachments and app-update endpoints all require this token.

## Endpoints

### Conversations / inbox
- `GET /webhooks/chat/inbox?query=&cursor=&limit=` — cursor-paginated conversation
  list with `has_more`/`next_cursor`.
- `GET /webhooks/chat/users?q=` — member search ("message any user").
- `POST /webhooks/chat/start` — start a conversation with a member (`user_id`).

### Messages
- `GET /webhooks/chat/thread?conversation=<ID>` — most recent 50 messages.
- `GET /webhooks/chat/history?conversation=<ID>&before=<id>&limit=` — older
  messages, oldest-first; returns `has_more`.
- `POST /webhooks/chat/reply` — operator reply (`conversation_id`, `message`,
  `sender_role=operator`), optional multipart `attachment`; sends an
  `Idempotency-Key` header so offline retries never duplicate.

### Live updates
- `GET /webhooks/chat/stream?conversation=<ID>&since=<id>` — SSE long-poll; the
  app loops it for a conversation's live messages.
- `GET /webhooks/chat/events?since=<id>` — SSE long-poll across all
  conversations (drives the notification service).

### Mode / read state
- `POST /webhooks/chat/mode` — set `ai_mode` (retrieval|finetuned|operator).
- `POST /webhooks/chat/reply-toggle` — enable/disable member replies.
- `POST /webhooks/chat/read` — mark read by the operator.

### Attachments
- `GET /webhooks/chat/attachment?message=<id>[&thumb=1]` — download an
  attachment (or its JPEG thumbnail).

### App updates
- `GET /webhooks/chat/apk-info` — `{ latestVersion, versionCode, apkUrl,
  changelog, sha256 }` (sha256 computed live from the published APK).
- `GET /webhooks/chat/apk?version=<X.Y>` — streams the signed APK; the app
  verifies the SHA-256 and the signing certificate before installing.

### Training PC
- `GET /webhooks/chat/training-data?since_id=<N>` — cleaned pairs (JSONL).
- `POST /webhooks/chat/training-upload` — adapter + configs (device token only).
- `POST /webhooks/chat/training-progress` — trainer watermark.
- `GET /webhooks/chat/training-count` — waiting/cleaned pair counts.
- `GET /webhooks/chat/context?conversation=<ID>` — conversation + few-shot
  context for debugging.

## Thin-client behavior (required on the device)

1. Keep a foreground service on an SSE `events` stream; fall back to adaptive
   inbox polling only while the stream is unhealthy.
2. In AI modes (`retrieval`/`finetuned`) the server replies automatically; the
   app only displays messages.
3. In `operator` mode the app surfaces a notification and the operator replies
   via `POST /reply` (`sender_role=operator`), which is harvested into training
   data.
4. Offline replies (text and attachments) are queued and drained by a
   WorkManager worker with the idempotency key.
5. In-app updates verify the downloaded APK's SHA-256 and release-signing
   certificate before invoking the installer.

No model, no chat logic on the device.