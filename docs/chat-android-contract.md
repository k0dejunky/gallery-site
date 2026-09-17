# Android thin client — Chat Bridge contract

The Android app is a **thin client**: no AI logic, no API keys on the device.
It talks to the site's chat webhooks using a Bearer token (`GALLERY_CHAT_KEY`).

Base URL: `https://amethyst2213.com/gallery`

## Auth
All requests send: `Authorization: Bearer <GALLERY_CHAT_KEY>`

## Endpoints

### 1. Get conversation config (AI/Live mode + pending count)
`GET /webhooks/chat/config?conversation=<ID>`

```json
{
  "ok": true,
  "conversation": 12,
  "ai_mode": "retrieval",          // "retrieval" | "finetuned"
  "status": "open",                // "open" | "closed"
  "pending": 3,                    // member messages awaiting reply
  "user_id": 42
}
```

### 2. Fetch member messages awaiting a reply
`GET /webhooks/chat/pending?conversation=<ID>`

```json
{
  "ok": true,
  "conversation": 12,
  "messages": [
    { "id": 7, "conversation_id": 12, "sender_role": "user", "message": "hi there", "created_at": "2026-09-16 22:00:00" }
  ]
}
```

### 3. Post a reply (operator live-mode, or model)
`POST /webhooks/chat/reply`  — JSON body:

```json
{ "conversation_id": 12, "message": "hey gorgeous", "sender_role": "operator" }
```

`sender_role`: `"operator"` (human live reply — harvested into training data)
or `"model"` (server/AI produced). Response:

```json
{ "ok": true, "id": 8 }
```

### 4. Conversation context (for the app UI / debugging)
`GET /webhooks/chat/context?conversation=<ID>` → `{ ok, conversation, ai_mode, history[], few_shot[], ai_base }`

## Training PC endpoints
- `GET /webhooks/chat/training-data?since_id=<N>` → newline-delimited JSON of `{id, user_message, operator_reply, created_at}`.
- `POST /webhooks/chat/training-upload` → multipart `adapter` file + `base_model` + `pair_count`; server verifies SHA-256 checksum.

## Thin-client behavior (required on the device)
1. Poll `config?conversation=<ID>` every ~5–10 s.
2. If `ai_mode` is `retrieval`/`finetuned` (AI mode): **do nothing** — the server replies automatically; the app only shows the member's message + the AI response.
3. If the admin has put the conversation in live mode (operator): the app shows a notification; the operator types a reply and sends it via `POST /reply` with `sender_role=operator`.

No model, no token beyond `GALLERY_CHAT_KEY`, no chat logic on the device.