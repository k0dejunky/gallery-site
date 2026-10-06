<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\BloggerClient;
use App\Models\BlueskyClient;
use App\Models\DeSoClient;
use App\Models\DeviantArtClient;
use App\Models\DiscordClient;
use App\Models\E621Client;
use App\Models\HiveClient;
use App\Models\LemmyClient;
use App\Models\LiveJournalClient;
use App\Models\MastodonClient;
use App\Models\MindsClient;
use App\Models\MisskeyClient;
use App\Models\NostrClient;
use App\Models\OdyseeClient;
use App\Models\PeerTubeClient;
use App\Models\RedGifsClient;
use App\Models\RedditClient;
use App\Models\SoFurryClient;
use App\Models\TelegramClient;
use App\Models\TwitterClient;
use App\Models\WeasylClient;
use App\Models\WriteFreelyClient;

/**
 * Registry of every auto-poster channel. This is the single source of truth
 * for a platform's identity, formatting rules (structure, character limits,
 * hashtag style, media handling, blur default) and the shape of its setup
 * form (credential + target fields). Controllers, views, the text engine,
 * the queue and the worker all read from here instead of hard-coding the two
 * original platforms (X / Reddit).
 */
final class Platforms
{
    /**
     * The full channel catalogue. Every entry:
     *
     *   key        canonical key (URL slug + settings key)
     *   label      human name for tabs / messages
     *   dbKey      value stored in auto_poster_queue.platform ('twitter' for x)
     *   client     platform client class (must implement isConfigured(),
     *              isUserAuthorized(), ping(), post(text, media, meta))
     *   structure  'single' (one body) or 'title_body' (title + body)
     *   pattern    default post pattern ({title}/{sep}/{description}/{hashtags})
     *   max_length default character limit (+ clamp range for the editor)
     *   hashtags   whether category hashtags are used; 'hash' = "#tag"
     *   max_tags   default hashtag count
     *   max_media  default attached media count; media_max absolute cap
     *   media      whether the platform accepts file attachments
     *   video      'upload' (attach video file) | 'screenshots' (X-style) | 'none'
     *   blur       default preview blur percent (0 = none)
     *   sensitive  'none' | 'boolean' (content-warning marker)
     *   fields     setup form fields: [name, label, type, placeholder, secret]
     *   oauth      interactive OAuth2 authorize/callback (authorize_url,
     *              token_url, scopes)
     *   instances  whether per-instance accounts are supported (Mastodon family)
     *   enabled    show in the tabs + participate in the queue/refill
     *   content_gate 'none' | 'softcore' | 'furry' (admin-visible note only)
     *   requires   setup checklist text shown on the page
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::CATALOGUE;
    }

    private const CATALOGUE = [
        'x' => [
            'key'         => 'x',
            'label'       => 'X (Twitter)',
            'dbKey'       => 'twitter',
            'client'      => TwitterClient::class,
            'structure'   => 'single',
            'pattern'     => '{title}{sep}{description} {hashtags} come visit my site to see what else I get myself into!! {url}',
            'max_length'  => 280,
            'max_length_min' => 50,
            'max_length_max' => 280,
            'hashtags'    => true,
            'hashtag_style' => 'hash',
            'max_tags'    => 20,
            'max_media'   => 4,
            'media_max'   => 4,
            'media'       => true,
            'video'       => 'screenshots',
            'blur'        => 85,
            'sensitive'   => 'none',
            'fields'      => [
                ['client_id', 'Client ID', 'text', 'X app client ID', true],
                ['client_secret', 'Client Secret', 'password', 'X app client secret', true],
                ['consumer_key', 'API Key (consumer key)', 'text', 'OAuth1 — enables media uploads', true],
                ['consumer_secret', 'API Secret (consumer secret)', 'password', 'OAuth1 secret', true],
                ['oauth_token', 'Access Token', 'text', 'OAuth1 access token', true],
                ['oauth_token_secret', 'Access Token Secret', 'password', 'OAuth1 access token secret', true],
            ],
            'oauth' => [
                'authorize_url' => 'https://x.com/i/oauth2/authorize',
                'token_url'     => 'https://api.x.com/2/oauth2/token',
                'scopes'        => 'tweet.read tweet.write users.read offline.access',
            ],
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'App in the X developer portal (Read & Write). Authorize once to store the refresh token.',
        ],

        // Reddit is kept as an inert entry: the OAuth client still exists but
        // Reddit posts as the authenticated user; credentials + target
        // subreddit come from the channel form, OAuth from the authorize flow.
        'reddit' => [
            'key'          => 'reddit',
            'label'        => 'Reddit',
            'dbKey'        => 'reddit',
            'client'       => RedditClient::class,
            'structure'    => 'title_body',
            'pattern'     => "{title}\n\n{description}\n\n{url}",
            'max_length'   => 40000,
            'max_length_min' => 50,
            'max_length_max' => 40000,
            'hashtags'     => false,
            'hashtag_style' => 'none',
            'max_tags'     => 0,
            'max_media'    => 1,
            'media_max'    => 1,
            'media'        => true,
            'video'        => 'none',
            'blur'         => 0,
            'sensitive'    => 'none',
            'fields'       => [
                ['client_id', 'Client ID', 'text', 'Reddit app client ID (script app)', true],
                ['client_secret', 'Client Secret', 'password', 'Reddit app client secret', true],
                ['username', 'Reddit username', 'text', 'u/yourname', false],
                ['subreddit', 'Target subreddit', 'text', 'Amethyst2213NSFW', false],
            ],
            'oauth'        => null,
            'instances'    => false,
            'enabled'      => true,
            'content_gate' => 'none',
            'requires'     => 'Create a script app at reddit.com/prefs/apps (redirect uri: this site\'s /admin/auto-poster/reddit/callback), save the credentials, set the target subreddit, then click Authorize.',
        ],

        'telegram' => [
            'key'         => 'telegram',
            'label'       => 'Telegram',
            'dbKey'       => 'telegram',
            'client'      => TelegramClient::class,
            'structure'   => 'single',
            'pattern'     => '{title}{sep}{description} {hashtags}

amethyst2213.com',
            'max_length'  => 4096,
            'max_length_min' => 50,
            'max_length_max' => 4096,
            'hashtags'    => true,
            'hashtag_style' => 'hash',
            'max_tags'    => 20,
            'max_media'   => 4,
            'media_max'   => 10,
            'media'       => true,
            'video'       => 'upload',
            'blur'        => 0,
            'sensitive'   => 'none',
            'fields'      => [
                ['bot_token', 'Bot token', 'password', 'From @BotFather', true],
                ['chat_id', 'Channel / group chat ID', 'text', '@MyChannel or -100123456789', false],
            ],
            'oauth'       => null,
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'Create a bot with @BotFather, add it to the channel/group, and paste the token + chat id.',
        ],

        'mastodon' => [
            'key'         => 'mastodon',
            'label'       => 'Mastodon',
            'dbKey'       => 'mastodon',
            'client'      => MastodonClient::class,
            'structure'   => 'single',
            'pattern'     => '{title}{sep}{description} {hashtags}

amethyst2213.com',
            'max_length'  => 500,
            'max_length_min' => 50,
            'max_length_max' => 500,
            'hashtags'    => true,
            'hashtag_style' => 'hash',
            'max_tags'    => 10,
            'max_media'   => 4,
            'media_max'   => 4,
            'media'       => true,
            'video'       => 'upload',
            'blur'        => 0,
            'sensitive'   => 'boolean',
            'fields'      => [
                ['access_token', 'Access token', 'password', 'Settings → Development → New application → Create access token', true],
            ],
            'oauth'       => null,
            'instances'   => true,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'Create a bot account on each instance and generate an access token (Settings → Development).',
        ],

        'pleroma' => [
            'key'         => 'pleroma',
            'label'       => 'Pleroma',
            'dbKey'       => 'pleroma',
            'client'      => MastodonClient::class,
            'structure'   => 'single',
            'pattern'     => '{title}{sep}{description} {hashtags}

amethyst2213.com',
            'max_length'  => 2000,
            'max_length_min' => 50,
            'max_length_max' => 2000,
            'hashtags'    => true,
            'hashtag_style' => 'hash',
            'max_tags'    => 10,
            'max_media'   => 4,
            'media_max'   => 4,
            'media'       => true,
            'video'       => 'upload',
            'blur'        => 0,
            'sensitive'   => 'boolean',
            'fields'      => [
                ['access_token', 'Access token', 'password', 'Pleroma OAuth token', true],
            ],
            'oauth'       => null,
            'instances'   => true,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'Pleroma/Akkoma instance bot account + OAuth token.',
        ],

        'akkoma' => [
            'key'         => 'akkoma',
            'label'       => 'Akkoma',
            'dbKey'       => 'akkoma',
            'client'      => MastodonClient::class,
            'structure'   => 'single',
            'pattern'     => '{title}{sep}{description} {hashtags}

amethyst2213.com',
            'max_length'  => 2000,
            'max_length_min' => 50,
            'max_length_max' => 2000,
            'hashtags'    => true,
            'hashtag_style' => 'hash',
            'max_tags'    => 10,
            'max_media'   => 4,
            'media_max'   => 4,
            'media'       => true,
            'video'       => 'upload',
            'blur'        => 0,
            'sensitive'   => 'boolean',
            'fields'      => [
                ['access_token', 'Access token', 'password', 'Akkoma OAuth token', true],
            ],
            'oauth'       => null,
            'instances'   => true,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'Akkoma instance bot account + OAuth token.',
        ],

        'gotosocial' => [
            'key'         => 'gotosocial',
            'label'       => 'GoToSocial',
            'dbKey'       => 'gotosocial',
            'client'      => MastodonClient::class,
            'structure'   => 'single',
            'pattern'     => '{title}{sep}{description} {hashtags}

amethyst2213.com',
            'max_length'  => 500,
            'max_length_min' => 50,
            'max_length_max' => 500,
            'hashtags'    => true,
            'hashtag_style' => 'hash',
            'max_tags'    => 10,
            'max_media'   => 4,
            'media_max'   => 4,
            'media'       => true,
            'video'       => 'upload',
            'blur'        => 0,
            'sensitive'   => 'boolean',
            'fields'      => [
                ['access_token', 'Access token', 'password', 'GoToSocial token', true],
            ],
            'oauth'       => null,
            'instances'   => true,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'GoToSocial instance bot account + token.',
        ],

        'bluesky' => [
            'key'         => 'bluesky',
            'label'       => 'Bluesky',
            'dbKey'       => 'bluesky',
            'client'      => BlueskyClient::class,
            'structure'   => 'single',
            'pattern'     => '{title}{sep}{description} {hashtags}

amethyst2213.com',
            'max_length'  => 300,
            'max_length_min' => 50,
            'max_length_max' => 300,
            'hashtags'    => true,
            'hashtag_style' => 'hash',
            'max_tags'    => 10,
            'max_media'   => 4,
            'media_max'   => 4,
            'media'       => true,
            'video'       => 'none',
            'blur'        => 0,
            'sensitive'   => 'boolean',
            'fields'      => [
                ['handle', 'Handle', 'text', 'you.bsky.social', false],
                ['app_password', 'App password', 'password', 'Settings → App passwords', true],
            ],
            'oauth'       => null,
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'Bluesky account + an App Password (Settings → App Passwords).',
        ],

        'lemmy' => [
            'key'         => 'lemmy',
            'label'       => 'Lemmy',
            'dbKey'       => 'lemmy',
            'client'      => LemmyClient::class,
            'structure'   => 'title_body',
            'pattern'     => "{title}\n\n{description} {hashtags}\n\namethyst2213.com",
            'max_length'  => 40000,
            'max_length_min' => 50,
            'max_length_max' => 40000,
            'hashtags'    => false,
            'hashtag_style' => 'none',
            'max_tags'    => 0,
            'max_media'   => 1,
            'media_max'   => 1,
            'media'       => true,
            'video'       => 'none',
            'blur'        => 0,
            'sensitive'   => 'boolean',
            'fields'      => [
                ['instance', 'Instance', 'text', 'https://lemmynsfw.com', false],
                ['username', 'Username', 'text', 'bot username', false],
                ['password', 'Password', 'password', 'bot password', true],
                ['community', 'Default community', 'text', 'c/nsfw@instance', false],
            ],
            'oauth'       => null,
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'A bot account on an NSFW Lemmy instance + the target community name.',
        ],

        'discord' => [
            'key'         => 'discord',
            'label'       => 'Discord',
            'dbKey'       => 'discord',
            'client'      => DiscordClient::class,
            'structure'   => 'single',
            'pattern'     => '{title}{sep}{description} {hashtags}

amethyst2213.com',
            'max_length'  => 2000,
            'max_length_min' => 50,
            'max_length_max' => 2000,
            'hashtags'    => true,
            'hashtag_style' => 'hash',
            'max_tags'    => 10,
            'max_media'   => 4,
            'media_max'   => 10,
            'media'       => true,
            'video'       => 'upload',
            'blur'        => 0,
            'sensitive'   => 'none',
            'fields'      => [
                ['webhook_url', 'Webhook URL', 'password', 'Server → Integrations → Webhooks (age-restricted channel)', true],
            ],
            'oauth'       => null,
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'Create a Webhook on an age-restricted (18+) channel of your server.',
        ],

        'minds' => [
            'key'         => 'minds',
            'label'       => 'Minds',
            'dbKey'       => 'minds',
            'client'      => MindsClient::class,
            'structure'   => 'single',
            'pattern'     => '{title}{sep}{description} {hashtags}

amethyst2213.com',
            'max_length'  => 5000,
            'max_length_min' => 50,
            'max_length_max' => 5000,
            'hashtags'    => true,
            'hashtag_style' => 'hash',
            'max_tags'    => 10,
            'max_media'   => 4,
            'media_max'   => 4,
            'media'       => true,
            'video'       => 'upload',
            'blur'        => 0,
            'sensitive'   => 'boolean',
            'fields'      => [
                ['username', 'Username', 'text', 'minds username', false],
                ['password', 'Password', 'password', 'minds password', true],
            ],
            'oauth'       => null,
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'A Minds account (login username + password). Mark posts NSFW.',
        ],

        'misskey' => [
            'key'         => 'misskey',
            'label'       => 'Misskey',
            'dbKey'       => 'misskey',
            'client'      => MisskeyClient::class,
            'structure'   => 'single',
            'pattern'     => '{title}{sep}{description} {hashtags}

amethyst2213.com',
            'max_length'  => 3000,
            'max_length_min' => 50,
            'max_length_max' => 3000,
            'hashtags'    => true,
            'hashtag_style' => 'hash',
            'max_tags'    => 10,
            'max_media'   => 4,
            'media_max'   => 4,
            'media'       => true,
            'video'       => 'upload',
            'blur'        => 0,
            'sensitive'   => 'boolean',
            'fields'      => [
                ['instance', 'Instance', 'text', 'https://misskey.io', false],
                ['api_token', 'API token (i)', 'password', 'Settings → API → generate token', true],
            ],
            'oauth'       => null,
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'A Misskey/Sharkey account + API token (Settings → API).',
        ],

        'nostr' => [
            'key'         => 'nostr',
            'label'       => 'Nostr',
            'dbKey'       => 'nostr',
            'client'      => NostrClient::class,
            'structure'   => 'single',
            'pattern'     => '{title}{sep}{description} {hashtags}

amethyst2213.com',
            'max_length'  => 5000,
            'max_length_min' => 50,
            'max_length_max' => 5000,
            'hashtags'    => true,
            'hashtag_style' => 'hash',
            'max_tags'    => 10,
            'max_media'   => 4,
            'media_max'   => 4,
            'media'       => false,
            'video'       => 'none',
            'blur'        => 0,
            'sensitive'   => 'none',
            'fields'      => [
                ['nsec', 'Private key (nsec/nhex)', 'password', 'nostr secret key', true],
                ['relay', 'Relay URL', 'text', 'wss://relay.damus.io', false],
            ],
            'oauth'       => null,
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'A nostr secret key + relay. Requires the secp256k1 PHP extension for signing.',
        ],

        'hive' => [
            'key'         => 'hive',
            'label'       => 'Hive / PeakD',
            'dbKey'       => 'hive',
            'client'      => HiveClient::class,
            'structure'   => 'single',
            'pattern'     => "{title}\n\n{description}\n\namethyst2213.com",
            'max_length'  => 10000,
            'max_length_min' => 50,
            'max_length_max' => 10000,
            'hashtags'    => true,
            'hashtag_style' => 'hash',
            'max_tags'    => 5,
            'max_media'   => 0,
            'media_max'   => 0,
            'media'       => false,
            'video'       => 'none',
            'blur'        => 0,
            'sensitive'   => 'boolean',
            'fields'      => [
                ['account', 'Account', 'text', 'hive username', false],
                ['posting_key', 'Posting key', 'password', 'hive posting private key', true],
            ],
            'oauth'       => null,
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'A Hive account + its Posting key. Requires secp256k1 PHP extension.',
        ],

        'deso' => [
            'key'         => 'deso',
            'label'       => 'DeSo',
            'dbKey'       => 'deso',
            'client'      => DeSoClient::class,
            'structure'   => 'single',
            'pattern'     => '{title}{sep}{description} {hashtags}

amethyst2213.com',
            'max_length'  => 5000,
            'max_length_min' => 50,
            'max_length_max' => 5000,
            'hashtags'    => true,
            'hashtag_style' => 'hash',
            'max_tags'    => 10,
            'max_media'   => 1,
            'media_max'   => 1,
            'media'       => true,
            'video'       => 'none',
            'blur'        => 0,
            'sensitive'   => 'none',
            'fields'      => [
                ['public_key', 'Public key (BC1...)', 'text', 'your DeSo public key', false],
                ['seed_hex', 'Seed hex', 'password', 'private seed hex for signing', true],
            ],
            'oauth'       => null,
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'A DeSo wallet (public key + seed). Requires secp256k1 PHP extension.',
        ],

        'peertube' => [
            'key'         => 'peertube',
            'label'       => 'PeerTube',
            'dbKey'       => 'peertube',
            'client'      => PeerTubeClient::class,
            'structure'   => 'title_body',
            'pattern'     => "{title}\n\n{description}\n\namethyst2213.com",
            'max_length'  => 1000,
            'max_length_min' => 10,
            'max_length_max' => 2000,
            'hashtags'    => false,
            'hashtag_style' => 'none',
            'max_tags'    => 0,
            'max_media'   => 1,
            'media_max'   => 1,
            'media'       => true,
            'video'       => 'upload',
            'blur'        => 0,
            'sensitive'   => 'boolean',
            'fields'      => [
                ['instance', 'Instance', 'text', 'https://peer.tube', false],
                ['access_token', 'Access token', 'password', 'Instance → account → API token', true],
            ],
            'oauth'       => null,
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'An account on an adult-allowing PeerTube instance + an access token.',
        ],

        'odysee' => [
            'key'         => 'odysee',
            'label'       => 'Odysee / LBRY',
            'dbKey'       => 'odysee',
            'client'      => OdyseeClient::class,
            'structure'   => 'title_body',
            'pattern'     => "{title}\n\n{description}\n\namethyst2213.com",
            'max_length'  => 5000,
            'max_length_min' => 50,
            'max_length_max' => 5000,
            'hashtags'    => false,
            'hashtag_style' => 'none',
            'max_tags'    => 0,
            'max_media'   => 1,
            'media_max'   => 1,
            'media'       => true,
            'video'       => 'upload',
            'blur'        => 0,
            'sensitive'   => 'boolean',
            'fields'      => [
                ['lbrynet_url', 'LBRY SDK URL', 'text', 'http://127.0.0.1:5279', false],
                ['account', 'Account id', 'text', 'LBRY wallet account id', false],
            ],
            'oauth'       => null,
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'A running LBRY SDK daemon (lbrynet) with a funded wallet.',
        ],

        'redgifs' => [
            'key'         => 'redgifs',
            'label'       => 'RedGIFs',
            'dbKey'       => 'redgifs',
            'client'      => RedGifsClient::class,
            'structure'   => 'single',
            'pattern'     => '{title}{sep}{description}',
            'max_length'  => 300,
            'max_length_min' => 10,
            'max_length_max' => 300,
            'hashtags'    => false,
            'hashtag_style' => 'none',
            'max_tags'    => 0,
            'max_media'   => 1,
            'media_max'   => 1,
            'media'       => true,
            'video'       => 'upload',
            'blur'        => 0,
            'sensitive'   => 'none',
            'fields'      => [
                ['username', 'Username', 'text', 'redgifs username', false],
                ['api_token', 'API token', 'password', 'RedGIFs API token (issued to registered creators)', true],
            ],
            'oauth'       => null,
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'A RedGIFs account (login) for the session token.',
        ],

        'blogger' => [
            'key'         => 'blogger',
            'label'       => 'Blogger (Google)',
            'dbKey'       => 'blogger',
            'client'      => BloggerClient::class,
            'structure'   => 'title_body',
            'pattern'     => "{title}\n\n<p>{description}</p><p><a href=\"amethyst2213.com\">amethyst2213.com</a></p>",
            'max_length'  => 100000,
            'max_length_min' => 10,
            'max_length_max' => 100000,
            'hashtags'    => false,
            'hashtag_style' => 'none',
            'max_tags'    => 0,
            'max_media'   => 1,
            'media_max'   => 1,
            'media'       => false,
            'video'       => 'none',
            'blur'        => 0,
            'sensitive'   => 'none',
            'fields'      => [
                ['blog_id', 'Blog ID', 'text', 'from the Blogger dashboard URL', false],
                ['client_id', 'Client ID', 'text', 'Google OAuth2 client ID', true],
                ['client_secret', 'Client Secret', 'password', 'Google OAuth2 client secret', true],
            ],
            'oauth' => [
                'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url'     => 'https://oauth2.googleapis.com/token',
                'scopes'        => 'https://www.googleapis.com/auth/blogger',
            ],
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'Google Cloud OAuth2 app (Blogger API) + a Blogger blog flagged adult. Authorize once.',
        ],

        'deviantart' => [
            'key'         => 'deviantart',
            'label'       => 'DeviantArt',
            'dbKey'       => 'deviantart',
            'client'      => DeviantArtClient::class,
            'structure'   => 'title_body',
            'pattern'     => "{title}\n\n{description}\n\namethyst2213.com",
            'max_length'  => 10000,
            'max_length_min' => 10,
            'max_length_max' => 10000,
            'hashtags'    => false,
            'hashtag_style' => 'none',
            'max_tags'    => 0,
            'max_media'   => 1,
            'media_max'   => 1,
            'media'       => true,
            'video'       => 'none',
            'blur'        => 0,
            'sensitive'   => 'boolean',
            'fields'      => [
                ['client_id', 'Client ID', 'text', 'DeviantArt application client id', true],
                ['client_secret', 'Client Secret', 'password', 'DeviantArt application secret', true],
            ],
            'oauth' => [
                'authorize_url' => 'https://www.deviantart.com/oauth2/authorize',
                'token_url'     => 'https://www.deviantart.com/oauth2/token',
                'scopes'        => 'basic publish',
            ],
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'softcore',
            'requires'    => 'DeviantArt application (client id/secret). Explicit sexual material is prohibited — artistic nudity only. Authorize once.',
        ],

        'e621' => [
            'key'         => 'e621',
            'label'       => 'e621',
            'dbKey'       => 'e621',
            'client'      => E621Client::class,
            'structure'   => 'single',
            'pattern'     => '{title}{sep}{description}',
            'max_length'  => 1000,
            'max_length_min' => 10,
            'max_length_max' => 1000,
            'hashtags'    => false,
            'hashtag_style' => 'none',
            'max_tags'    => 0,
            'max_media'   => 1,
            'media_max'   => 1,
            'media'       => true,
            'video'       => 'none',
            'blur'        => 0,
            'sensitive'   => 'none',
            'fields'      => [
                ['username', 'Username', 'text', 'e621 username', false],
                ['api_key', 'API key', 'password', 'from the e621 account settings', true],
            ],
            'oauth'       => null,
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'furry',
            'requires'    => 'e621 account + API key. Furry content only; at least 10 tags per upload.',
        ],

        'weasyl' => [
            'key'         => 'weasyl',
            'label'       => 'Weasyl',
            'dbKey'       => 'weasyl',
            'client'      => WeasylClient::class,
            'structure'   => 'title_body',
            'pattern'     => "{title}\n\n{description}\n\namethyst2213.com",
            'max_length'  => 10000,
            'max_length_min' => 10,
            'max_length_max' => 10000,
            'hashtags'    => false,
            'hashtag_style' => 'none',
            'max_tags'    => 0,
            'max_media'   => 1,
            'media_max'   => 1,
            'media'       => true,
            'video'       => 'none',
            'blur'        => 0,
            'sensitive'   => 'boolean',
            'fields'      => [
                ['username', 'Username', 'text', 'weasyl username', false],
                ['api_key', 'API key', 'password', 'weasyl API key', true],
            ],
            'oauth'       => null,
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'furry',
            'requires'    => 'Weasyl account + API key. Explicit rating allowed.',
        ],

        'sofurry' => [
            'key'         => 'sofurry',
            'label'       => 'SoFurry',
            'dbKey'       => 'sofurry',
            'client'      => SoFurryClient::class,
            'structure'   => 'title_body',
            'pattern'     => "{title}\n\n{description}\n\namethyst2213.com",
            'max_length'  => 10000,
            'max_length_min' => 10,
            'max_length_max' => 10000,
            'hashtags'    => false,
            'hashtag_style' => 'none',
            'max_tags'    => 0,
            'max_media'   => 1,
            'media_max'   => 1,
            'media'       => true,
            'video'       => 'none',
            'blur'        => 0,
            'sensitive'   => 'boolean',
            'fields'      => [
                ['access_token', 'Access token', 'password', 'SoFurry OAuth bearer token', true],
            ],
            'oauth'       => null,
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'furry',
            'requires'    => 'SoFurry account + OAuth token. Original work only (no AI).',
        ],

        'writefreely' => [
            'key'         => 'writefreely',
            'label'       => 'WriteFreely',
            'dbKey'       => 'writefreely',
            'client'      => WriteFreelyClient::class,
            'structure'   => 'title_body',
            'pattern'     => "{title}\n\n{description}\n\namethyst2213.com",
            'max_length'  => 100000,
            'max_length_min' => 10,
            'max_length_max' => 100000,
            'hashtags'    => false,
            'hashtag_style' => 'none',
            'max_tags'    => 0,
            'max_media'   => 0,
            'media_max'   => 0,
            'media'       => false,
            'video'       => 'none',
            'blur'        => 0,
            'sensitive'   => 'none',
            'fields'      => [
                ['instance', 'Instance', 'text', 'https://write.as', false],
                ['access_token', 'Access token', 'password', 'WriteFreely token', true],
                ['collection', 'Collection (blog) alias', 'text', 'your-blog', false],
            ],
            'oauth'       => null,
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'A WriteFreely/Write.as account + access token on an instance that permits your content.',
        ],

        'livejournal' => [
            'key'         => 'livejournal',
            'label'       => 'LiveJournal',
            'dbKey'       => 'livejournal',
            'client'      => LiveJournalClient::class,
            'structure'   => 'title_body',
            'pattern'     => "{title}\n\n{description}\n\namethyst2213.com",
            'max_length'  => 100000,
            'max_length_min' => 10,
            'max_length_max' => 100000,
            'hashtags'    => false,
            'hashtag_style' => 'none',
            'max_tags'    => 0,
            'max_media'   => 0,
            'media_max'   => 0,
            'media'       => false,
            'video'       => 'none',
            'blur'        => 0,
            'sensitive'   => 'boolean',
            'fields'      => [
                ['username', 'Username', 'text', 'livejournal username', false],
                ['password', 'Password', 'password', 'livejournal password', true],
            ],
            'oauth'       => null,
            'instances'   => false,
            'enabled'     => true,
            'content_gate' => 'none',
            'requires'    => 'LiveJournal account. NOTE: their ToS restricts unsolicited advertising — use cautiously.',
        ],
    ];

    /** All registered channel keys. */
    public static function keys(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /** Only the enabled channels (shown in tabs + used by the queue/refill). */
    public static function enabled(): array
    {
        return array_values(array_filter(self::CATALOGUE, static fn (array $p): bool => (bool) $p['enabled']));
    }

    /** Enabled channel keys. */
    public static function enabledKeys(): array
    {
        return array_column(self::enabled(), 'key');
    }

    /**
     * Resolve any platform identifier (canonical key or a stored dbKey) to a
     * canonical key. Legacy 'twitter' maps to 'x'; 'reddit' resolves but stays
     * disabled. Returns '' when the platform is unknown.
     */
    public static function canonicalize(string $key): string
    {
        $key = strtolower(trim($key));
        if ($key === 'twitter') {
            $key = 'x';
        }
        foreach (self::CATALOGUE as $canonical => $p) {
            if ($canonical === $key || ($p['dbKey'] ?? '') === $key) {
                return $canonical;
            }
        }
        return '';
    }

    /** The stored queue platform value for a canonical key. */
    public static function dbKey(string $key): string
    {
        $key = self::canonicalize($key);
        return (string) (self::CATALOGUE[$key]['dbKey'] ?? $key);
    }

    /** Resolve a stored dbKey back to a canonical key. */
    public static function canonicalFromDbKey(string $dbKey): string
    {
        return self::canonicalize($dbKey);
    }

    /** One channel entry (or null when unknown). */
    public static function get(string $key): ?array
    {
        $key = self::canonicalize($key);
        return self::CATALOGUE[$key] ?? null;
    }

    public static function label(string $key): string
    {
        $key = self::canonicalize($key);
        return (string) (self::CATALOGUE[$key]['label'] ?? ucfirst($key ?: 'unknown'));
    }

    public static function clientClass(string $key): ?string
    {
        $key = self::canonicalize($key);
        return isset(self::CATALOGUE[$key]['client']) ? (string) self::CATALOGUE[$key]['client'] : null;
    }

    public static function isEnabled(string $key): bool
    {
        $key = self::canonicalize($key);
        return isset(self::CATALOGUE[$key]) && (bool) self::CATALOGUE[$key]['enabled'];
    }

    public static function structure(string $key): string
    {
        $key = self::canonicalize($key);
        return (string) (self::CATALOGUE[$key]['structure'] ?? 'single');
    }

    public static function isTitleBody(string $key): bool
    {
        return self::structure($key) === 'title_body';
    }

    public static function maxLength(string $key): int
    {
        $key = self::canonicalize($key);
        return (int) (self::CATALOGUE[$key]['max_length'] ?? 280);
    }

    public static function maxLengthClamp(string $key): array
    {
        $key = self::canonicalize($key);
        $p   = self::CATALOGUE[$key] ?? [];
        return [(int) ($p['max_length_min'] ?? 50), (int) ($p['max_length_max'] ?? $p['max_length'] ?? 280)];
    }

    /** The default (registry) template settings a channel uses before any admin edits. */
    public static function templateDefaults(string $key): array
    {
        $key = self::canonicalize($key);
        $p   = self::CATALOGUE[$key] ?? [];

        return [
            'pattern'        => (string) ($p['pattern'] ?? '{title}{sep}{description}'),
            'max_tags'       => (int) ($p['max_tags'] ?? 0),
            'max_length'     => (int) ($p['max_length'] ?? 280),
            'schedule_minutes' => 60,
            'recent_days'    => 14,
            'max_media'      => (int) ($p['max_media'] ?? 0),
            'media_max'      => (int) ($p['media_max'] ?? $p['max_media'] ?? 0),
            'blur_percent'   => (int) ($p['blur'] ?? 0),
            'screenshots'    => 3,
            'banned_words'   => [],
            'hashtag_style'  => (string) ($p['hashtag_style'] ?? 'hash'),
            'structure'      => (string) ($p['structure'] ?? 'single'),
            'media'          => (bool) ($p['media'] ?? false),
            'video'          => (string) ($p['video'] ?? 'none'),
            'sensitive'      => (string) ($p['sensitive'] ?? 'none'),
        ];
    }

    /**
     * The default set of channels that participate in the queue/refill when the
     * admin has not made an explicit selection.
     */
    public static function defaultEnabledKeys(): array
    {
        return self::enabledKeys();
    }

    /** Form fields declared by a channel. */
    public static function fields(string $key): array
    {
        $key = self::canonicalize($key);
        return self::CATALOGUE[$key]['fields'] ?? [];
    }

    /** The compose/target fields a post can override per-post (subset of fields marked as targets). */
    public static function targetFieldNames(string $key): array
    {
        $targets = [];
        foreach (self::fields($key) as $field) {
            if (in_array($field[0] ?? '', ['chat_id', 'community', 'collection', 'relay'], true)) {
                $targets[] = $field[0];
            }
        }
        return $targets;
    }
}