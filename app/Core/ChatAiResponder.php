<?php

namespace App\Core;

use App\Models\ChatMessage;

/**
 * Generates one AI reply for a conversation exactly the way the synchronous
 * web-chat path always did: optional gallery content search, few-shot context
 * from the training corpus, then the ChatAi model call, and finally the reply
 * is stored as a ROLE_MODEL message with clickable gallery refs.
 *
 * Extracted so the background worker (chat_ai_async mode) and the synchronous
 * ChatController::send path share one implementation - the queue can never
 * drift from what a direct send would have produced.
 */
final class ChatAiResponder
{
    /**
     * @return array{ok:bool, reply?:string, id?:int, refs?:array<int,array{title:string,url:string}>, error?:string}
     */
    public static function generate(int $conversationId, int $userId, string $message, string $mode, ?int $level = null): array
    {
        $settings = ChatSettings::all();

        $content = [];
        if (!empty($settings['ai_content_search'])) {
            $content = ChatContentSearch::find(
                $message,
                $userId,
                $level ?? Auth::effectiveLevel(),
                max(1, min(12, (int) ($settings['ai_content_search_max'] ?? 6)))
            );
        }

        $aiReply = ChatAi::reply(
            $mode,
            $message,
            // Only the most recent messages feed the model — the full history
            // makes a single generation exceed the HTTP timeout on slow chats.
            ChatMessage::messagesLatest($conversationId, 12),
            ChatMessage::similarContext($message),
            $content
        );

        if (empty($aiReply['ok'])) {
            return ['ok' => false, 'error' => (string) ($aiReply['error'] ?? 'AI unavailable')];
        }

        $replyText = (string) $aiReply['reply'];

        // Only the galleries the model actually named become clickable refs
        // (longest title match against the reply text happens client-side; here
        // a plain substring test is the same one the sync path always used).
        $refs = array_values(array_filter(
            $content,
            static fn (array $g): bool => mb_strpos($replyText, (string) ($g['title'] ?? '')) !== false
        ));
        $refs = array_map(
            static fn (array $g): array => ['title' => (string) $g['title'], 'url' => (string) $g['url']],
            $refs
        );

        ChatMessage::addMessage($conversationId, ChatMessage::ROLE_MODEL, $replyText, null, $refs);

        return [
            'ok'    => true,
            'reply' => $replyText,
            'id'    => ChatMessage::latestId($conversationId),
            'refs'  => $refs,
        ];
    }
}
