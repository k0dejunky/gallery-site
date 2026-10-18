<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\CategorySuggestion;
use App\Models\DescriptionSuggestion;
use App\Models\Gallery;
use App\Models\Tag;

/**
 * Writes a short marketing description for a gallery by showing its media to
 * the same vision backend the category advisor uses (CATEGORIZER_* config:
 * ollama by default, an OpenAI-compatible API when configured). One call per
 * gallery - unlike categories there is nothing to batch.
 *
 * The proposal lands in gallery_description_suggestions for an admin to
 * accept (optionally edited) or dismiss; nothing is written to
 * galleries.description here.
 */
class DescriptionAdvisor
{
    private const MAX_IMAGES = 2;

    /**
     * Analyze one gallery. Returns ['ok' => bool, 'engine' => string,
     * 'description' => string, 'warnings' => string, 'error' => string].
     */
    public static function describe(int $galleryId): array
    {
        $driver = DescriptionSuggestion::driver();
        if ($driver === 'off') {
            return self::failure($driver, 'CATEGORIZER_DRIVER=off');
        }

        $gallery = Gallery::find($galleryId);
        if ($gallery === null) {
            return self::failure($driver, 'gallery not found');
        }

        $images = CategoryAdvisor::sampleImages($gallery, self::MAX_IMAGES);
        $prompt = self::prompt($gallery, $images !== []);

        try {
            $text = $driver === 'api'
                ? CategoryAdvisor::callApi($prompt, $images)
                : CategoryAdvisor::callOllama($prompt, $images);
        } catch (\Throwable $e) {
            return self::failure($driver, $e->getMessage());
        }

        $description = self::parse($text);
        if ($description === '') {
            return self::failure($driver, 'model returned no usable description');
        }

        return [
            'ok'          => true,
            'engine'      => $driver,
            'description' => $description,
            'warnings'    => '',
            'error'       => '',
        ];
    }

    /* ---------------- prompt + response parsing ---------------- */

    private static function prompt(array $gallery, bool $hasImages): string
    {
        $tags = array_map(static fn (array $t): string => (string) $t['name'], Tag::forGallery((int) $gallery['id']));

        $context = 'Title: ' . $gallery['title'] . "\n";
        if (!empty($gallery['description'])) {
            // Regenerate case: keep the true facts, improve the copy.
            $context .= 'Current description (rewrite it; keep true facts): '
                . mb_substr((string) $gallery['description'], 0, 600) . "\n";
        }
        if ($tags !== []) {
            $context .= 'Tags: ' . implode(', ', $tags) . "\n";
        }

        $captions = [];
        foreach (\App\Core\Database::run(
            'SELECT p.caption FROM photos p
             INNER JOIN gallery_photo gp ON gp.photo_id = p.id
             WHERE gp.gallery_id = ? AND p.caption <> \'\' LIMIT 3',
            [(int) $gallery['id']]
        )->fetchAll() as $row) {
            $captions[] = (string) $row['caption'];
        }
        if ($captions !== []) {
            $context .= 'Captions: ' . mb_substr(implode(' | ', $captions), 0, 400) . "\n";
        }

        $media = $hasImages
            ? 'The attached image(s) are sampled from this gallery - describe what is actually visible.'
            : 'No preview image could be extracted; write from the title/tags alone and stay generic.';

        return "You are writing a short marketing description for an adult photo/video gallery page.\n"
            . "It will appear as the page's meta description, so it must read as one natural teaser.\n\n"
            . $context . $media . "\n\n"
            . "Rules:\n"
            . "- 1-2 sentences, roughly 140-220 characters.\n"
            . "- English, present tense, flirty and inviting but tasteful; no explicit anatomical words.\n"
            . "- No hashtags, no emoji, no quotation marks, no site name, no \"click here\".\n"
            . "- Mention the setting, outfit, mood or activity when you can see it; do not invent specifics.\n\n"
            . 'Reply with ONLY JSON, no prose: {"description": "..."}';
    }

    /**
     * Parse the model's reply into a clean description string. Tolerates code
     * fences and stray prose around the JSON; a plain-prose reply is used
     * as-is (cleaned) so a model that ignores the JSON instruction still
     * produces a proposal.
     */
    public static function parse(string $text): string
    {
        $decoded   = json_decode($text, true);
        $foundJson = is_array($decoded);

        if (!$foundJson) {
            // Small models often wrap the JSON in prose or fences.
            $start = strpos($text, '{');
            $end   = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded   = json_decode(substr($text, $start, $end - $start + 1), true);
                $foundJson = is_array($decoded);
            }
        }

        if ($foundJson) {
            // Valid JSON without a usable description key means nothing to
            // propose - never treat machine syntax as prose copy.
            $candidate = isset($decoded['description']) && is_string($decoded['description'])
                ? $decoded['description']
                : '';
        } else {
            // Plain prose fallback: drop fences/backticks and leading labels.
            $candidate = preg_replace('/```[a-z]*|```|`/', '', $text) ?? $text;
            $candidate = preg_replace('/^\s*(description|answer)\s*:\s*/i', '', trim($candidate)) ?? $candidate;
        }

        $candidate = preg_replace('/\s+/u', ' ', trim($candidate)) ?? '';
        $candidate = trim($candidate, " \t\n\r\0\x0B\"'\xE2\x80\x9C\xE2\x80\x9D\xE2\x80\x98\xE2\x80\x99");

        return mb_substr($candidate, 0, DescriptionSuggestion::MAX_LENGTH);
    }

    private static function failure(string $engine, string $error): array
    {
        return [
            'ok'          => false,
            'engine'      => $engine,
            'description' => '',
            'warnings'    => '',
            'error'       => $error,
        ];
    }
}
