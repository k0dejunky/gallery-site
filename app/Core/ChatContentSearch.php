<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Cache;
use App\Core\Database;
use App\Models\Gallery;

/**
 * Site-content search for the chat AI. When a member asks for specific
 * content, this finds galleries the member can actually view and returns them
 * as prompt context plus the clickable refs the chat UI auto-links.
 *
 * Matching is two-tier and tolerant of phrasing:
 *  1. Categories the message names (whitespace-normalised, so "blowjob"
 *     matches the "blow job" category) are the strongest signal — every
 *     gallery tagged with them is a candidate.
 *  2. FULLTEXT across gallery titles/descriptions/category names supplements
 *     that with the member's own words.
 *
 * It only fires on genuine content requests — a message that names a known
 * category/topic or uses content-request phrasing. Chit-chat gets no context,
 * so the bot never name-drops galleries casually. Every result is gated by the
 * member's access level and secret-gallery visibility, so nothing the member
 * cannot open is ever surfaced.
 */
final class ChatContentSearch
{
    /**
     * Whether a member message looks like a request for specific site content.
     * Heuristic: it names a known category/topic, or uses content-request
     * phrasing ("do you have", "any videos", "looking for", ...).
     */
    public static function isContentRequest(string $message): bool
    {
        $msg = mb_strtolower(trim($message));
        if (mb_strlen($msg) < 8) {
            return false;
        }

        if (self::matchedCategoryIds($message) !== []) {
            return true;
        }

        return (bool) preg_match(
            '/\b(do you have|have you got|have you|got any|have any|any (videos?|content|pics?|gallery|galleries|stuff)|looking for|is there|show me|send me|video of|content)\b/',
            $msg
        );
    }

    /**
     * Search visible site content for a member's request. Returns up to $max
     * matches, each with the title, a short description, its categories, the
     * minimum membership level and a clickable URL. Empty when the message is
     * not a content request or nothing matches, so normal chat is unchanged.
     *
     * @return array<int, array{title:string, description:string, categories:list<string>, min_level:int, url:string}>
     */
    public static function find(string $message, int $userId, int $level, int $max = 6): array
    {
        $max = max(1, min(12, $max));

        if (!self::isContentRequest($message)) {
            return [];
        }

        $cached = Cache::rememberGen(
            'chat',
            'csearch:' . (int) $level . ':' . md5($message),
            60,
            static function () use ($message, $userId, $level, $max): string {
                $out  = [];
                $seen = [];

                $add = static function (array $g) use (&$out, &$seen): void {
                    $id = (int) $g['id'];
                    if (isset($seen[$id])) {
                        return;
                    }
                    $seen[$id] = true;
                    $cats = array_values(array_filter(array_map(
                        static fn (array $c): string => (string) $c['name'],
                        Gallery::categories($id)
                    )));
                    $out[] = [
                        'title'       => (string) $g['title'],
                        'description' => mb_substr((string) ($g['description'] ?? ''), 0, 220),
                        'categories'  => $cats,
                        'min_level'   => (int) ($g['min_level'] ?? 0),
                        'url'         => url('/galleries/' . $id),
                    ];
                };

                // 1) Strongest: galleries tagged with a category the message names.
                $catIds = self::matchedCategoryIds($message);
                if ($catIds !== []) {
                    $byCategory = Gallery::inCategories($catIds, '', $level, $userId);
                    foreach ($catIds as $catId) {
                        foreach (($byCategory[$catId] ?? []) as $g) {
                            $add($g);
                        }
                    }
                }

                // 2) Supplement: the member's own words across titles/descriptions.
                if (count($out) < $max) {
                    $page = Gallery::paginate(1, $max, [
                        'q'         => $message,
                        'max_level' => $level,
                        'user_id'   => $userId,
                    ]);
                    foreach (($page['items'] ?? []) as $g) {
                        $add($g);
                    }
                }

                return json_encode(array_slice($out, 0, $max), JSON_UNESCAPED_SLASHES);
            }
        );

        $decoded = json_decode($cached, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Category ids whose name appears in the message, whitespace-normalised so
     * compound phrasing ("blowjob", "cumdump") still matches spaced category
     * names ("blow job", "cum dump"). Cached for 5 minutes.
     *
     * @return list<int>
     */
    private static function matchedCategoryIds(string $message): array
    {
        $norm = preg_replace('/\s+/u', '', mb_strtolower($message)) ?? '';
        if ($norm === '') {
            return [];
        }

        $cats = Cache::rememberGen('gallery', 'chat-categories', 300, static function (): string {
            $rows = Database::run('SELECT id, name FROM categories')->fetchAll();
            $out  = [];
            foreach ($rows as $r) {
                $n = preg_replace('/\s+/u', '', mb_strtolower((string) $r['name'])) ?? '';
                if (mb_strlen($n) >= 3) {
                    $out[] = [(int) $r['id'], $n];
                }
            }

            return json_encode($out, JSON_UNESCAPED_SLASHES);
        });

        $ids = [];
        foreach ((array) json_decode($cats, true) as $pair) {
            $name = (string) ($pair[1] ?? '');
            if ($name !== '' && mb_strpos($norm, $name) !== false) {
                $ids[] = (int) ($pair[0] ?? 0);
            }
        }

        return array_values(array_unique($ids));
    }
}