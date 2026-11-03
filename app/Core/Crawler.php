<?php

namespace App\Core;

/**
 * Verified search-engine crawler detection.
 *
 * Google's own guidance for age-gated sites (Search Central, "Interstitials
 * and dialogs"): "For content behind a mandatory age gate for adult users, we
 * recommend allowing Googlebot to crawl your content without triggering the
 * age gate. You can do this by verifying Googlebot requests and serving the
 * content without age gate."
 *
 * Without that exemption every page a crawler opens wears the full-screen
 * gate (`.age-gate{position:fixed;inset:0;z-index:99999}`), whose <h1> is
 * "Are you over 18?" - so on a video watch page the topmost element at the
 * player's centre is the dialog, the page's only heading is the gate's, and
 * Search Console reports "Video isn't on a watch page" for every URL in the
 * video sitemap.
 *
 * The User-Agent is never trusted on its own: anyone can send "Googlebot".
 * The address has to reverse-resolve into the engine's own domain AND that
 * hostname has to resolve back to the same address (Google's documented
 * method). Verification is cached per address; a failure fails CLOSED, so a
 * box with no DNS, or a proxy in front of the site, keeps the gate exactly as
 * it was.
 */
final class Crawler
{
    /** Confirmed crawlers stay confirmed for a week. */
    private const POSITIVE_TTL = 604800;

    /** An impostor's address is re-checked hourly, not forever. */
    private const NEGATIVE_TTL = 3600;

    /**
     * User-Agent fragments that identify a crawler, mapped to the reverse-DNS
     * suffixes that engine is allowed to own. Google's crawlers all resolve
     * into googlebot.com / google.com, whatever their individual names.
     */
    private const AGENTS = [
        'Googlebot'          => ['googlebot.com', 'google.com'],
        'Google-InspectionTool' => ['googlebot.com', 'google.com'],
        'GoogleOther'        => ['googlebot.com', 'google.com'],
        'AdsBot-Google'      => ['googlebot.com', 'google.com'],
        'Storebot-Google'    => ['googlebot.com', 'google.com'],
        'APIs-Google'        => ['googlebot.com', 'google.com'],
        'Mediapartners-Google' => ['googlebot.com', 'google.com'],
        'Bingbot'            => ['search.msn.com', 'msn.com'],
        'adidxbot'           => ['search.msn.com', 'msn.com'],
        'BingPreview'        => ['search.msn.com', 'msn.com'],
        'Slurp'              => ['crawl.yahoo.net', 'yahoo.com'],
        'DuckDuckBot'        => ['duckduckgo.com'],
        'YandexBot'          => ['yandex.net', 'yandex.ru', 'yandex.com'],
        'Baiduspider'        => ['baidu.com', 'baidu.jp', 'baidubce.com'],
        'Applebot'           => ['applebot.apple.com'],
    ];

    /**
     * True when the request comes from a crawler we have verified, i.e. one
     * that may see the page behind the age gate.
     */
    public static function isVerified(): bool
    {
        $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        if ($agent === '') {
            return false;
        }

        $suffixes = self::suffixesFor($agent);

        if ($suffixes === []) {
            return false; // not a crawler we index with: no DNS lookup at all
        }

        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $key = 'crawler:verified:' . $ip;
        $hit = Cache::get($key);

        if ($hit !== null) {
            return $hit === '1';
        }

        $verified = self::confirm($ip, $suffixes);

        // Cache::set() writes through to Redis when it is available; without
        // Redis it is request-scoped, so a DNS lookup is simply repeated.
        Cache::set($key, $verified ? '1' : '0', $verified ? self::POSITIVE_TTL : self::NEGATIVE_TTL);

        return $verified;
    }

    /**
     * Reverse-DNS then forward-confirm the address against the engine's
     * suffixes. Any doubt returns false.
     */
    private static function confirm(string $ip, array $suffixes): bool
    {
        $host = @gethostbyaddr($ip);

        // gethostbyaddr() hands the address back unchanged when it fails.
        if (!is_string($host) || $host === '' || $host === $ip) {
            return false;
        }

        $host = strtolower(rtrim($host, '.'));

        $owned = false;
        foreach ($suffixes as $suffix) {
            $suffix = strtolower($suffix);
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                $owned = true;
                break;
            }
        }

        if (!$owned) {
            return false;
        }

        // Forward confirmation: the hostname must resolve back to the address
        // that named it. dns_get_record() first - a crawler name with several
        // A records is still legitimate as long as one of them matches.
        $records = @dns_get_record($host, DNS_A);

        foreach ((array) $records as $record) {
            if (($record['ip'] ?? '') === $ip) {
                return true;
            }
        }

        // Some resolvers answer only through gethostbyname().
        return @gethostbyname($host) === $ip;
    }

    /**
     * The suffixes this User-Agent claims, or [] when it is not a crawler we
     * know how to verify.
     */
    private static function suffixesFor(string $agent): array
    {
        foreach (self::AGENTS as $fragment => $suffixes) {
            if (stripos($agent, $fragment) !== false) {
                return $suffixes;
            }
        }

        return [];
    }
}
