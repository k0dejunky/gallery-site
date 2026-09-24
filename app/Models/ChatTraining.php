<?php

namespace App\Models;

use App\Core\Database;

/**
 * Shared training-corpus logic used by both the CLI importer
 * (bin/chat_training_import.php) and the admin chat import form
 * (AdminChatController::importTraining), so the two entry points behave
 * identically: cleaning, junk filtering, dedupe, and insertion.
 */
class ChatTraining
{
    /** PII/anonymization + whitespace normalization, mirroring the exporter. */
    public static function clean(string $text): string
    {
        $text = trim($text);
        $text = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $text);
        $text = (string) preg_replace('/\b\d{3}[-.)]?\d{3}[-.]?\d{4}\b/', '[phone]', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_substr($text, 0, 2000);
    }

    /** Words/patterns that indicate a test or placeholder message. */
    private const JUNK_WORDS = [
        'test', 'testing', 'tests', 'dbg', 'dbg-test', 'sup', 'hi', 'hello', 'hey',
        'h', 'asdf', 'khgkghkjghkjhg', 'hdjdjfjfj', 'pic', 'tits', 'pussy',
    ];

    /** Heuristic junk filter matching the trainer's: empty, tiny, or placeholder. */
    public static function isJunk(string $text): bool
    {
        $t = trim(strtolower($text));
        if ($t === '') {
            return true;
        }
        // single emoji / symbol-only replies
        if (mb_strlen($t) <= 2 && !ctype_alnum($t)) {
            return true;
        }
        $words = preg_split('/\s+/', $t) ?: [];
        if (count($words) <= 1 && (in_array($t, self::JUNK_WORDS, true) || ctype_digit($t))) {
            return true;
        }
        return false;
    }

    /**
     * Normalize one pair: clean both sides, drop empty/junk rows, and cap the
     * total. Returns ['user_message' => string, 'operator_reply' => string] or
     * null when the pair should be skipped.
     */
    public static function normalizePair(string $userMessage, string $operatorReply): ?array
    {
        $u = self::clean($userMessage);
        $r = self::clean($operatorReply);

        if ($u === '' || $r === '' || self::isJunk($u) || self::isJunk($r)) {
            return null;
        }

        return ['user_message' => $u, 'operator_reply' => $r];
    }

    /**
     * Insert a batch of normalized pairs. Rows that already exist in the table
     * (same cleaned user_message+operator_reply) or in the batch are skipped.
     * Returns ['inserted' => int, 'skipped' => int].
     */
    public static function insertPairs(array $pairs): array
    {
        $inserted = 0;
        $skipped  = 0;
        $seen     = [];

        // Load existing exact-pair fingerprints so re-importing a file is
        // idempotent instead of duplicating the corpus.
        $existing = [];
        foreach (Database::run('SELECT user_message, operator_reply FROM chat_training_pairs')->fetchAll() as $row) {
            $existing[(string) $row['user_message'] . "\0" . (string) $row['operator_reply']] = true;
        }

        foreach ($pairs as $pair) {
            if (!is_array($pair) || !isset($pair['user_message'], $pair['operator_reply'])) {
                $skipped++;
                continue;
            }
            $u = trim((string) $pair['user_message']);
            $r = trim((string) $pair['operator_reply']);
            if ($u === '' || $r === '') {
                $skipped++;
                continue;
            }
            $fingerprint = $u . "\0" . $r;
            if (isset($seen[$fingerprint]) || isset($existing[$fingerprint])) {
                $skipped++;
                continue;
            }
            $seen[$fingerprint] = true;
            $existing[$fingerprint] = true;

            Database::run(
                'INSERT INTO chat_training_pairs (user_message, operator_reply, cleaned) VALUES (?, ?, 1)',
                [$u, $r]
            );
            $inserted++;
        }

        return ['inserted' => $inserted, 'skipped' => $skipped];
    }

    /**
     * Normalize + insert a single operator reply pair as trainer-ready
     * (cleaned = 1). Used when the operator replies live so the model learns
     * from real replies immediately — no separate export step needed.
     * Returns true when the pair was stored, false when it was junk or a
     * duplicate (and so intentionally skipped).
     */
    public static function savePair(string $userMessage, string $operatorReply): bool
    {
        $pair = self::normalizePair($userMessage, $operatorReply);
        if ($pair === null) {
            return false;
        }

        $existing = Database::run(
            'SELECT 1 FROM chat_training_pairs WHERE user_message = ? AND operator_reply = ? LIMIT 1',
            [$pair['user_message'], $pair['operator_reply']]
        )->fetchColumn();

        if ($existing !== false) {
            return false;
        }

        Database::run(
            'INSERT INTO chat_training_pairs (user_message, operator_reply, cleaned) VALUES (?, ?, 1)',
            [$pair['user_message'], $pair['operator_reply']]
        );

        return true;
    }
}