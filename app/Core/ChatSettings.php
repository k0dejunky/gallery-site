<?php

namespace App\Core;

/**
 * Chat site-wide settings, persisted in the chat_settings key/value table
 * (migrated from storage/chat.json). Keys are stored as JSON so scalars and
 * arrays round-trip cleanly.
 *
 *   ai_enabled        bool    master switch for the AI (off = operator-only)
 *   default_ai_mode   string  mode for new conversations (retrieval/finetuned/operator)
 *   daily_message     string  admin-written broadcast shown to non-eligible users
 *   daily_message_at  string  when the broadcast was last written
 *   model             string  base model name
 *   finetuned_model   string  fine-tuned model name
 *   finetuned         array   adapter metadata from the last training upload
 *   trainer_since_id  int     highest chat_training_pairs id the trainer consumed
 *   ai_content_search bool    AI may search site galleries to answer content questions
 *   ai_content_search_max int max galleries the AI can reference per reply
 *
 * On the first read of an existing install whose table is empty, the legacy
 * storage/chat.json is imported once so no settings are lost in the migration.
 */
class ChatSettings
{
    private const TABLE = 'chat_settings';

    private static function defaults(): array
    {
        return [
            'ai_enabled'           => true,
            'default_ai_mode'      => 'retrieval',
            'daily_message'        => '',
            'daily_message_at'     => null,
            'model'                => ChatModel::BASE,
            'finetuned_model'      => ChatModel::FINETUNED,
            'finetuned'            => [],
            'trainer_since_id'     => 0,
            'ai_content_search'    => true,
            'ai_content_search_max' => 6,
        ];
    }

    private static function legacyFile(): string
    {
        return dirname(__DIR__, 2) . '/storage/chat.json';
    }

    public static function all(): array
    {
        $rows = Database::run(
            'SELECT setting_key, setting_value FROM ' . self::TABLE
        )->fetchAll();

        if ($rows === []) {
            // Empty table: an existing install may still hold its state in the
            // legacy file. Import it once, then re-read.
            $legacy = self::readLegacy();
            if ($legacy !== null) {
                self::save($legacy);
                $rows = Database::run(
                    'SELECT setting_key, setting_value FROM ' . self::TABLE
                )->fetchAll();
            }
        }

        $data = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row['setting_value'] ?? ''), true);
            $data[(string) $row['setting_key']] = $decoded === null && trim((string) ($row['setting_value'] ?? '')) !== 'null'
                ? (string) ($row['setting_value'] ?? '')
                : $decoded;
        }

        return array_merge(self::defaults(), array_intersect_key($data, self::defaults()));
    }

    public static function save(array $state): void
    {
        $known = self::defaults();
        foreach ($state as $key => $value) {
            if (!array_key_exists($key, $known)) {
                continue;
            }
            self::put((string) $key, $value);
        }
    }

    /** Upsert a single key (JSON-encoded), leaving the rest untouched. */
    public static function put(string $key, $value): void
    {
        Database::run(
            'INSERT INTO ' . self::TABLE . ' (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, json_encode($value, JSON_UNESCAPED_SLASHES)]
        );
    }

    /** Whether the AI is currently enabled (master switch). */
    public static function aiEnabled(): bool
    {
        return !empty(self::all()['ai_enabled']);
    }

    /**
     * The admin's daily broadcast shown to users who are not chat-eligible,
     * or '' when none has been written.
     */
    public static function dailyMessage(): string
    {
        return (string) (self::all()['daily_message'] ?? '');
    }

    /**
     * The trainer's last-reported watermark: the highest chat_training_pairs id
     * the training PC has consumed (trained). Pairs with a higher id are
     * "waiting to be trained".
     */
    public static function trainerSinceId(): int
    {
        return (int) (self::all()['trainer_since_id'] ?? 0);
    }

    public static function setTrainerSinceId(int $sinceId): void
    {
        self::put('trainer_since_id', max(0, $sinceId));
    }

    /**
     * Read the legacy storage/chat.json into a settings array, or null when
     * the file is absent/unparseable.
     */
    private static function readLegacy(): ?array
    {
        $path = self::legacyFile();
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}