<?php

namespace App\Models;

use App\Core\Database;

/**
 * The hard-bounce suppression list.
 *
 * Member mail goes out with the site's From address, so a bad recipient used
 * to bounce straight into the admin inbox (147 "Undelivered Mail Returned to
 * Sender" messages by mid-November). The envelope sender is now the dedicated
 * bounce@ mailbox (MAIL_BOUNCE); `bin/bounce_worker.php` reads it, and every
 * address whose mail hard-bounced lands here. From then on:
 *
 *   - EmailQueue marks that recipient's rows "skipped" instead of retrying
 *     three times and leaving them "failed",
 *   - Mailer::send()/sendHtml() refuse the address as a backstop (chat and
 *     support replies do not go through the queue),
 *   - the admin Emailer page lists them with a Release button.
 *
 * A soft bounce (4.x.x) is never recorded: a full mailbox or a greylist is
 * temporary and the member still wants their mail.
 */
class EmailSuppression
{
    /** @var array<string, true>|null normalized address => true, per request */
    private static ?array $cache = null;

    /** Normalize an address the one way every lookup and write does. */
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * The site's own addresses. They are never suppressed: a bounce loop on
     * the alert mailbox must not be able to silence the alerts themselves.
     *
     * @return array<string, true>
     */
    private static function protectedAddresses(): array
    {
        $out = [];

        foreach (['ADMIN_EMAIL', 'MAIL_FROM', 'MAIL_BOUNCE'] as $key) {
            $address = self::normalize((string) env_value($key, ''));
            if ($address !== '') {
                $out[$address] = true;
            }
        }

        return $out;
    }

    /** Is this recipient suppressed? */
    public static function isSuppressed(string $email): bool
    {
        $email = self::normalize($email);

        if ($email === '' || isset(self::protectedAddresses()[$email])) {
            return false;
        }

        return isset(self::allMap()[$email]);
    }

    /**
     * Record a hard bounce. Idempotent: a second bounce of the same address
     * refreshes the reason instead of failing on the unique key.
     */
    public static function suppress(
        string $email,
        string $reason = 'hard bounce',
        string $source = 'dsn',
        string $detail = ''
    ): bool {
        $email = self::normalize($email);

        if ($email === ''
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || isset(self::protectedAddresses()[$email])) {
            return false;
        }

        $reason = mb_substr(trim($reason), 0, 120) ?: 'hard bounce';
        $source = mb_substr(trim($source), 0, 40) ?: 'dsn';
        $detail = mb_substr(trim($detail), 0, 255);

        $exists = Database::run(
            'SELECT id FROM email_suppressions WHERE email = ? LIMIT 1',
            [$email]
        )->fetchColumn();

        if ($exists !== false) {
            Database::run(
                'UPDATE email_suppressions SET reason = ?, source = ?, detail = ? WHERE email = ?',
                [$reason, $source, $detail !== '' ? $detail : null, $email]
            );
        } else {
            Database::run(
                'INSERT INTO email_suppressions (email, reason, source, detail) VALUES (?, ?, ?, ?)',
                [$email, $reason, $source, $detail !== '' ? $detail : null]
            );
        }

        self::$cache = null;

        return true;
    }

    /** Let an address receive mail again (the member fixed their inbox). */
    public static function release(string $email): bool
    {
        $stmt = Database::run(
            'DELETE FROM email_suppressions WHERE email = ?',
            [self::normalize($email)]
        );

        self::$cache = null;

        return $stmt->rowCount() > 0;
    }

    /**
     * Recent suppressions, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        return Database::run(
            'SELECT * FROM email_suppressions ORDER BY id DESC LIMIT ' . (int) $limit
        )->fetchAll();
    }

    /** How many addresses are suppressed. */
    public static function count(): int
    {
        return (int) Database::run('SELECT COUNT(*) FROM email_suppressions')->fetchColumn();
    }

    /**
     * Every suppressed address, normalized, memoised for the request.
     *
     * @return array<string, true>
     */
    private static function allMap(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [];

        try {
            foreach (Database::run('SELECT email FROM email_suppressions')->fetchAll(\PDO::FETCH_COLUMN) as $email) {
                self::$cache[self::normalize((string) $email)] = true;
            }
        } catch (\Throwable $e) {
            // A schema that is behind the code must not stop mail: treat the
            // list as empty for this request.
            self::$cache = [];
        }

        return self::$cache;
    }
}
