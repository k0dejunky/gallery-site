# gallery-reddit — Devvit Web Reddit bridge

This is a **Devvit Web** app (v0.14.2) that lets the gallery's PHP Auto Poster
submit posts into `r/Amethyst2213NSFW`.

Reddit ended self-serve OAuth API access (Responsible Builder Policy, Nov 2025),
so the old `POST /api/submit` client no longer works for a new app. Instead the
server POSTs the pending post to a Devvit **external endpoint**, and Devvit runs
entirely on Reddit's infrastructure to call `reddit.submitPost()`. No Reddit
OAuth credentials are needed on the PHP side at all.

## Architecture

```
AutoPostQueue::post('reddit')
  └─ RedditBridge::publish()            (PHP, gallery server)
       │  POST /external/on/publish + Authorization: bearer devvit_at_...
       ▼
[Devvit Web app on Reddit infra]
  └─ src/server/server.ts  routePublish()
       ├─ if image: media.upload({url: dataUrl, type:'image'}) → mediaUrl
       └─ reddit.submitPost({subredditName, title, nsfw, kind:'image', imageUrls:[mediaUrl]})
       └─ (no image) reddit.submitPost({subredditName, title, nsfw, text: body})
       └─ returns {ok, postId, url}
```

- Synchronous: PHP blocks and records `posted`/`failed` via its normal queue path.
- Reddit allows a **single image per media post**; the bridge sends only the first.
- Images are base64 inside the JSON body (Devvit can't reach the gallery server).
  External endpoint bodies are limited to ~10 MB; keep the thumbnail small.
- The external endpoint requires the **managed token** (`devvit_at_...`) in the
  `Authorization: bearer` header. This is the auth mechanism — no body HMAC.

## PHP side

The following `app/Models/*` files already implement the client:

- `RedditBridge.php` — sends the post, returns `{ok, url, error}` like TwitterClient.
- `AutoPostQueue.php` — `platformAuthorized('reddit')` is true when the bridge
  config is present; the `post()` match arm calls `RedditBridge::publish()`.

The reddit block in the gitignored `storage/autoposter.json` needs three keys:

```json
"reddit": {
  "client_id": "",
  "client_secret": "",
  "username": "",
  "subreddit": "Amethyst2213NSFW",
  "devvit_endpoint": "https://gallery-reddit-<subreddit-id>-external.devvit.net/external/on/publish",
  "bridge_secret": "devvit_at_<token>"
}
```

## One-time setup (you do these — they need Reddit auth/access)

These steps cannot be automated here because the Devvit CLI requires an
interactive Reddit login and External Endpoints are allowlist-gated.

1. **Request External Endpoints access** (required; limited-access feature):
   https://docs.google.com/forms/d/e/1FAIpQLScLU2m-IH9xtt4hqFBNy5AlrswY0pvfvoyTiQREbq_9xDQJkQ/viewform
   Wait for approval before relying on the bridge.

2. **Create the subreddit** `Amethyst2213NSFW` (moderator account that will own the Devvit app).

3. **Install the CLI** (already installed globally as v0.14.2):
   ```bash
   npm i -g devvit          # already at 0.14.2
   devvit login
   ```

4. **Build**
   ```bash
   cd reddit-bridge
   npm install
   npm run build            # -> dist/server/index.js (CommonJS, as Devvit requires)
   ```

5. **Upload** (the `name` is `gallery-reddit`):
   ```bash
   devvit upload
   ```

6. **Install into your subreddit**:
   ```bash
   devvit install <subreddit>  # r/Amethyst2213NSFW
   ```

7. **Create a managed token** in the Developer Portal (Developer Settings →
   **New App Token**). Name it, e.g. `autoposter`. Copy the private secret
   (`devvit_at_...`) — it is shown only once.

8. **Wire up** the gallery server `storage/autoposter.json` reddit block with
   `subreddit`, `devvit_endpoint`, `bridge_secret` (the secret you just copied).
   The endpoint host is:
   ```
   https://gallery-reddit-<subreddit-id>-external.devvit.net/external/on/publish
   ```
   The `<subreddit-id>` is the `t5_...` id of `Amethyst2213NSFW` (visible in the
   app install / portal). The full URL can be confirmed from the app dashboard.

9. Toggle the `reddit` platform on in the Auto Poster admin; the queue will now
   route reddit items through the bridge.

## Notes / risks

- External Endpoints is a **limited-access allowlist** feature — the bridge
  cannot work until your Reddit account is approved.
- Devvit Web server "max request time" is ~30 s; keep one-thumbnail payloads small
  so base64 + upload + submit fits under the limit.
- The app posts as the **app account**, not your user. Make sure the app account
  (auto-created, named after `gallery-reddit`) is an approved submitter / mod of
  the subreddit.
