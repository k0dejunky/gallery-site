<?php

namespace App\Models;

/**
 * Persists the auto-emailer configuration in a JSON file under storage/
 * (gitignored), mirroring the AutoPosterConfig convention.
 *
 * The emailer no longer runs a schedule. It used to mail a periodic digest of
 * the newest uploads to every member on a wall-clock timer; that is gone, and
 * what is left is event-driven only:
 *   - a new chat reply or support notification,
 *   - one email for the day a gallery is posted,
 *   - the live-start broadcast,
 *   - a renewal reminder 7 days before a rebill,
 *   - a win-back to a lapsed member,
 *   - billing dunning (payment failed / past due / expired), which is
 *     account-critical and always sent.
 *
 * So this class is down to the switches and the one remaining limit: how many
 * broadcast emails a single member may receive per day, per kind.
 */
class EmailerConfig
{
    /** Relative path (from the app root) to the JSON config file. */
    private const FILE = '/storage/emailer.json';

    /**
     * Broadcast kinds that share the per-member daily allowance. Chat, support
     * and billing mail are transactional and are never capped.
     */
    public const CAPPED_KINDS = ['gallery', 'live'];

    /**
     * The config file's absolute path.
     */
    private static function file(): string
    {
        return dirname(__DIR__, 2) . self::FILE;
    }

    /**
     * Defaults every config starts from (and individual fields fall back to
     * when the stored file is missing, invalid or missing a key).
     *
     * `enabled` is the master switch for the two broadcast emails (new gallery,
     * live start). Chat/support, renewal reminders, win-backs and dunning are
     * transactional and are not gated by it.
     */
    public static function defaults(): array
    {
        return [
            'enabled'                     => false,
            'lifecycle_renewal_reminders' => true,
            'lifecycle_winbacks'          => true,
            'lifecycle_trial_endings'     => true,
            'lifecycle_inactive_winbacks' => true,
            // Per member, per capped kind, per day. 1 means "one email when
            // today's new gallery is posted" and one live notice a day.
            'notification_daily_cap'      => 1,
        ];
    }

    /**
     * Load the saved config, normalising every field. Unknown keys left over
     * from the retired digest schedule (mode/hour/sample_count/subjects/…) are
     * ignored here and dropped on the next save, so an old file can never
     * resurrect schedule behaviour.
     */
    public static function all(): array
    {
        $data = [];

        $path = self::file();
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $d = self::defaults();

        $d['enabled']                     = !empty($data['enabled']);
        $d['lifecycle_renewal_reminders'] = !array_key_exists('lifecycle_renewal_reminders', $data) ? true : !empty($data['lifecycle_renewal_reminders']);
        $d['lifecycle_winbacks']          = !array_key_exists('lifecycle_winbacks', $data) ? true : !empty($data['lifecycle_winbacks']);
        $d['lifecycle_trial_endings']     = !array_key_exists('lifecycle_trial_endings', $data) ? true : !empty($data['lifecycle_trial_endings']);
        $d['lifecycle_inactive_winbacks'] = !array_key_exists('lifecycle_inactive_winbacks', $data) ? true : !empty($data['lifecycle_inactive_winbacks']);
        $d['notification_daily_cap']      = !array_key_exists('notification_daily_cap', $data)
            ? $d['notification_daily_cap']
            : max(0, min(10, (int) $data['notification_daily_cap']));

        return $d;
    }

    /**
     * Whether the two broadcast emails (new gallery, live start) may go out.
     */
    public static function broadcastsEnabled(): bool
    {
        return !empty(self::all()['enabled']);
    }

    /**
     * Persist an admin-submitted config. Only the surviving keys are written.
     */
    public static function save(array $in): void
    {
        self::write([
            'enabled'                     => !empty($in['enabled']),
            'lifecycle_renewal_reminders' => !empty($in['lifecycle_renewal_reminders']),
            'lifecycle_winbacks'          => !empty($in['lifecycle_winbacks']),
            'lifecycle_trial_endings'     => !empty($in['lifecycle_trial_endings']),
            'lifecycle_inactive_winbacks' => !empty($in['lifecycle_inactive_winbacks']),
            'notification_daily_cap'      => max(0, min(10, (int) ($in['notification_daily_cap'] ?? 1))),
        ]);
    }

    /**
     * Validate a PHP IANA timezone identifier, defaulting to UTC. Kept because
     * Housekeeping and the admin page read the site timezone through here.
     */
    public static function timezone(array $config = []): string
    {
        return SiteConfig::timezone();
    }

    /**
     * Write the config file, creating storage/ if needed.
     */
    private static function write(array $config): void
    {
        $path = self::file();
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents(
            $path,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
