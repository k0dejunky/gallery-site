<?php

namespace App\Core;

/**
 * Chat site-wide settings persisted in storage/chat.json.
 *
 *   ai_enabled        bool    master switch for the AI (off = operator-only)
 *   default_ai_mode   string  mode for new conversations (retrieval/finetuned/operator)
 *   daily_message     string  admin-written broadcast shown to non-eligible users
 *   daily_message_at  string  when the broadcast was last written
 *   model             string  base model name
 *   finetuned_model   string  fine-tuned model name
 *   finetuned         array   adapter metadata from the last training upload
 */
class ChatSettings
{
    private static function file(): string
    {
        return dirname(__DIR__, 2) . '/storage/chat.json';
    }

    public static function all(): array
    {
        $data = is_file(self::file()) ? json_decode((string) @file_get_contents(self::file()), true) : null;

        $defaults = [
            'ai_enabled'       => true,
            'default_ai_mode'  => 'retrieval',
            'daily_message'    => '',
            'daily_message_at' => null,
            'model'            => ChatModel::BASE,
            'finetuned_model'  => ChatModel::FINETUNED,
            'finetuned'        => [],
            'trainer_since_id' => 0,
        ];

        if (!is_array($data)) {
            return $defaults;
        }

        return array_merge($defaults, array_intersect_key($data, $defaults));
    }

    public static function save(array $state): void
    {
        $file = self::file();
        @file_put_contents($file, (string) json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
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
        $state = self::all();
        $state['trainer_since_id'] = max(0, $sinceId);
        self::save($state);
    }
}