<?php

namespace App\Core;

/**
 * Client for the self-hosted Ollama model used to answer chat messages.
 *
 * Two modes:
 *  - retrieval: the base model (llama3.2:3b) is prompted with the operator's
 *    most-similar past replies as few-shot context, so the bot mirrors the
 *    operator's tone without any model weights change.
 *  - finetuned: an Ollama model built from a Modelfile that loads a LoRA
 *    adapter fine-tuned on operator replies (FROM base + ADAPTER lora).
 *
 * The model runs locally on the server (no external API, content-safe).
 * Configured via .env: OLLAMA_URL (default http://127.0.0.1:11434) and the
 * model names below. A failure never throws to the caller: it returns an
 * error array so the request can be handled gracefully.
 */
class ChatAi
{
    /** Retrieval-mode model (base, no adapter). */
    public const BASE_MODEL = 'llama3.2:3b';

    /** Fine-tuned model name built from the Modelfile + LoRA adapter. */
    public const FINETUNED_MODEL = 'chat-finetuned';

    private static function baseUrl(): string
    {
        return rtrim((string) env_value('OLLAMA_URL', 'http://127.0.0.1:11434'), '/');
    }

    /**
     * Generate a reply for a member message in the given conversation.
     *
     * @param string $mode      ChatMessage::MODE_RETRIEVAL or MODE_FINETUNED
     * @param array<int,array{id:int,sender_role:string,message:string}> $history
     * @param array<int,array{user_message:string,operator_reply:string}> $fewShot
     * @return array{ok:bool, reply?:string, error?:string}
     */
    public static function reply(string $mode, string $message, array $history = [], array $fewShot = []): array
    {
        $model = $mode === \App\Models\ChatMessage::MODE_FINETUNED ? self::FINETUNED_MODEL : self::BASE_MODEL;

        $system = "You are the chat assistant for an adult content gallery site. "
            . "Be warm, flirty, and human. Stay in character and respond naturally. "
            . "Never break character. Keep replies under 2000 characters.";

        $prompt = $system . "\n\n";

        if ($fewShot !== []) {
            $prompt .= "Here are examples of how the human operator replies, to match their style:\n";
            foreach ($fewShot as $i => $pair) {
                $prompt .= "Example " . ($i + 1) . "\n"
                    . "  member: " . ($pair['user_message'] ?? '') . "\n"
                    . "  operator: " . ($pair['operator_reply'] ?? '') . "\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "Conversation so far:\n";
        foreach ($history as $h) {
            $who = ($h['sender_role'] ?? '') === 'user' ? 'member' : 'assistant';
            $prompt .= $who . ": " . ($h['message'] ?? '') . "\n";
        }

        $prompt .= "member: " . $message . "\nassistant:";

        return self::generate($model, $prompt);
    }

    private static function generate(string $model, string $prompt): array
    {
        $payload = [
            'model'  => $model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => 0.8,
                'num_predict' => 512,
            ],
        ];

        [$status, , $body] = \App\Models\Http::request(self::baseUrl() . '/api/generate', [
            'method'  => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'json'    => $payload,
            'timeout' => 60,
        ]);

        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'error' => 'AI model unavailable (HTTP ' . $status . ').'];
        }

        $data = json_decode($body, true);
        $text = trim((string) ($data['response'] ?? ''));

        if ($text === '') {
            return ['ok' => false, 'error' => 'AI returned an empty reply.'];
        }

        return ['ok' => true, 'reply' => mb_substr($text, 0, \App\Models\ChatMessage::MAX_MESSAGE_LENGTH)];
    }

    /**
     * Lightweight connectivity check (model present?). Used by the admin
     * Chat page to show whether the AI is reachable.
     */
    public static function ping(): array
    {
        [$status, , $body] = \App\Models\Http::request(self::baseUrl() . '/api/tags', [
            'method'  => 'GET',
            'timeout' => 5,
        ]);

        $data = json_decode($body, true);
        $names = [];
        foreach (($data['models'] ?? []) as $m) {
            $names[] = (string) ($m['name'] ?? '');
        }

        return [
            'ok'      => $status >= 200 && $status < 300,
            'models'  => $names,
            'hasBase' => in_array(self::BASE_MODEL, $names, true) || in_array(self::BASE_MODEL . ':latest', $names, true),
            'hasFine' => in_array(self::FINETUNED_MODEL, $names, true) || in_array(self::FINETUNED_MODEL . ':latest', $names, true),
        ];
    }
}