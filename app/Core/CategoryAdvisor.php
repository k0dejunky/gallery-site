<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Category;
use App\Models\CategorySuggestion;
use App\Models\Gallery;
use App\Models\Http;
use App\Models\Tag;

/**
 * Suggests categories for a gallery by showing its media to a vision model.
 * Two interchangeable drivers, selected by CATEGORIZER_DRIVER:
 *
 *   ollama - local Ollama /api/generate with base64 images (CATEGORIZER_MODEL,
 *            e.g. qwen2.5vl:3b); the default and the only one with live
 *            credentials today.
 *   api    - any OpenAI-compatible /v1/chat/completions endpoint
 *            (CATEGORIZER_API_URL / _KEY / _MODEL), dormant until a key is
 *            configured.
 *
 * The model only ever picks names from the site's real category list; unknown
 * names are dropped, categories are never auto-created, and nothing is
 * written to gallery_category - proposals land in
 * gallery_category_suggestions for an admin to accept.
 */
class CategoryAdvisor
{
    // The site's category list (~100 names) is evaluated in batches: one
    // vision call per CHUNK_SIZE candidates. A single call with the whole
    // list made the model return exactly one category for almost every
    // gallery; smaller batches make it judge every candidate properly.
    private const CHUNK_SIZE     = 25;
    private const MAX_IMAGES     = 3;
    private const FRAME_WIDTH    = 512;
    private const FRAME_HEIGHT   = 512;
    // CPU vision on a small box can take minutes for the first (cold) call;
    // the worker holds the lock the whole time, so a long wait is fine.
    private const HTTP_TIMEOUT   = 420;

    /**
     * Analyze one gallery. Returns ['ok' => bool, 'engine' => string,
     * 'suggestions' => [['category_id'=>, 'confidence'=>], ...],
     * 'warnings' => string, 'error' => string] - suggestions are resolved
     * against the live category table, unknown names silently dropped.
     *
     * There is NO cap on how many categories fit: every candidate the model
     * matches across every batch is returned (deduped, strongest confidence
     * per category). A batch that fails is skipped as long as at least one
     * batch answered; only a run where every batch failed is an error.
     */
    public static function suggest(int $galleryId): array
    {
        $driver = CategorySuggestion::driver();
        if ($driver === 'off') {
            return ['ok' => false, 'engine' => 'off', 'suggestions' => [], 'warnings' => '', 'error' => 'CATEGORIZER_DRIVER=off'];
        }

        $gallery = Gallery::find($galleryId);
        if ($gallery === null) {
            return ['ok' => false, 'engine' => $driver, 'suggestions' => [], 'warnings' => '', 'error' => 'gallery not found'];
        }

        $names = array_map(static fn (array $c): string => (string) $c['name'], Category::all());
        if ($names === []) {
            return ['ok' => false, 'engine' => $driver, 'suggestions' => [], 'warnings' => '', 'error' => 'no categories defined'];
        }

        $images = self::sampleImages($gallery);
        $chunks = array_chunk($names, self::CHUNK_SIZE);
        $total  = count($chunks);

        $merged   = [];
        $warnings = [];
        $lastErr  = '';
        $answered = 0;

        foreach ($chunks as $i => $batch) {
            $prompt = self::prompt($gallery, $batch, $i, $total, $images !== []);

            try {
                $text = $driver === 'api'
                    ? self::callApi($prompt, $images)
                    : self::callOllama($prompt, $images);
            } catch (\Throwable $e) {
                $lastErr = $e->getMessage();
                $warnings[] = sprintf('batch %d/%d failed: %s', $i + 1, $total, $lastErr);
                continue;
            }

            $answered++;

            foreach (self::resolve($text, $batch) as $row) {
                $cid = $row['category_id'];
                if (!isset($merged[$cid]) || (float) $row['confidence'] > (float) $merged[$cid]['confidence']) {
                    $merged[$cid] = $row;
                }
            }
        }

        if ($answered === 0) {
            return [
                'ok'          => false,
                'engine'      => $driver,
                'suggestions' => [],
                'warnings'    => implode('; ', $warnings),
                'error'       => $lastErr !== '' ? $lastErr : 'every category batch failed',
            ];
        }

        $suggestions = array_values($merged);
        usort(
            $suggestions,
            static fn (array $a, array $b): int => (float) $b['confidence'] <=> (float) $a['confidence']
        );

        return [
            'ok'          => true,
            'engine'      => $driver,
            'suggestions' => $suggestions,
            'warnings'    => implode('; ', $warnings),
            'error'       => '',
        ];
    }

    /* ---------------- prompt + response parsing ---------------- */

    /**
     * One batch's prompt: the same gallery context, but only this chunk of
     * candidate categories, asked for exhaustively (every match, not a few).
     */
    private static function prompt(array $gallery, array $categoryNames, int $batchIndex, int $batchTotal, bool $hasImages): string
    {
        $tags = array_map(static fn (array $t): string => (string) $t['name'], Tag::forGallery((int) $gallery['id']));

        $context  = 'Title: ' . $gallery['title'] . "\n";
        if (!empty($gallery['description'])) {
            $context .= 'Description: ' . mb_substr((string) $gallery['description'], 0, 600) . "\n";
        }
        if ($tags !== []) {
            $context .= 'Tags: ' . implode(', ', $tags) . "\n";
        }

        $captions = array_filter(array_column(self::captions((int) $gallery['id']), 'caption'));
        if ($captions !== []) {
            $context .= 'Captions: ' . mb_substr(implode(' | ', array_slice($captions, 0, 3)), 0, 400) . "\n";
        }

        $media = $hasImages
            ? 'The attached image(s) are sampled from this gallery.'
            : 'No preview image could be extracted; judge from the text alone.';

        $list = implode(', ', $categoryNames);
        $batch = $batchTotal > 1
            ? 'This is candidate batch ' . ($batchIndex + 1) . ' of ' . $batchTotal . '.\n'
            : '';

        return "You are categorizing content for an adult gallery site. Judge EACH candidate category below\n"
            . "against this gallery and select EVERY one that fits. Selecting many is expected and correct -\n"
            . "do not stop at one, and do not limit yourself to a few.\n\n"
            . $context . $media . "\n\n"
            . $batch
            . "Candidate categories (choose ONLY from this list): $list\n\n"
            . "Reply with ONLY JSON, no prose, in the form:\n"
            . '{"categories":[{"name":"exact category name","confidence":0.0}]}\n'
            . "containing every candidate that fits (confidence 0.0-1.0 is required for each). "
            . "Return an empty array (\"categories\": []) if none of them fit.\n";
    }

    /**
     * Parse the model's reply into resolved category rows. Tolerates code
     * fences and stray prose around the JSON (small models often wrap it).
     * Names are validated against the batch the model was given; there is no
     * limit on how many rows may come back.
     *
     * @return array<int, array{category_id:int, confidence:float}>
     */
    public static function resolve(string $text, array $categoryNames): array
    {
        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            // Find the outermost {...} block and try again.
            $start = strpos($text, '{');
            $end   = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            }
        }

        if (!is_array($decoded) || !isset($decoded['categories']) || !is_array($decoded['categories'])) {
            return [];
        }

        $byLower = [];
        foreach ($categoryNames as $n) {
            $byLower[mb_strtolower(trim((string) $n))] = true;
        }

        $out = [];
        foreach ($decoded['categories'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = mb_strtolower(trim((string) ($item['name'] ?? '')));
            if ($name === '' || !isset($byLower[$name])) {
                continue;
            }

            $confidence = is_numeric($item['confidence'] ?? null) ? (float) $item['confidence'] : null;
            if ($confidence !== null) {
                $confidence = max(0.0, min(1.0, $confidence));
            }

            $out[] = ['name' => trim((string) ($item['name'] ?? '')), 'confidence' => $confidence];
        }

        // Resolve names to ids against the live table (name is UNIQUE).
        $resolved = [];
        foreach ($out as $row) {
            $category = Category::findByName($row['name']);
            if ($category === null) {
                continue;
            }
            $resolved[] = [
                'category_id' => (int) $category['id'],
                'confidence'  => $row['confidence'],
            ];
        }

        // One row per category, strongest confidence first.
        $best = [];
        foreach ($resolved as $r) {
            $cid = $r['category_id'];
            if (!isset($best[$cid]) || (float) $r['confidence'] > (float) $best[$cid]['confidence']) {
                $best[$cid] = $r;
            }
        }

        return array_values($best);
    }

    /* ---------------- drivers ---------------- */

    private static function callOllama(string $prompt, array $images): string
    {
        $model = (string) env_value('CATEGORIZER_MODEL', 'qwen2.5vl:3b');
        $url   = rtrim((string) env_value('OLLAMA_URL', 'http://127.0.0.1:11434'), '/') . '/api/generate';

        $payload = [
            'model'   => $model,
            'prompt'  => $prompt,
            'stream'  => false,
            'images'  => $images,
            'options' => [
                'temperature' => 0.1,
                // Multi-entry JSON: every fitting candidate, not one line.
                'num_predict' => 800,
            ],
        ];

        [$status, , $body] = Http::request($url, [
            'method'  => 'POST',
            'headers' => ['Content-Type: application/json'],
            'json'    => $payload,
            'timeout' => self::HTTP_TIMEOUT,
        ]);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Ollama HTTP $status");
        }

        $data = json_decode($body, true);
        $text = trim((string) ($data['response'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('Ollama returned an empty reply');
        }

        return $text;
    }

    private static function callApi(string $prompt, array $images): string
    {
        $url   = rtrim((string) env_value('CATEGORIZER_API_URL'), '/');
        $key   = (string) env_value('CATEGORIZER_API_KEY');
        $model = (string) env_value('CATEGORIZER_MODEL', 'gpt-4o-mini');

        if ($url === '' || $key === '') {
            throw new \RuntimeException('CATEGORIZER_API_URL / CATEGORIZER_API_KEY not configured');
        }

        // OpenAI-compatible: text part + image_url data URIs.
        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($images as $b64) {
            $content[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => 'data:image/jpeg;base64,' . $b64],
            ];
        }

        $payload = [
            'model'       => $model,
            'messages'    => [[
                'role'    => 'user',
                'content' => $content,
            ]],
            'temperature' => 0.1,
        ];

        [$status, , $body] = Http::request($url, [
            'method'  => 'POST',
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            'json'    => $payload,
            'timeout' => self::HTTP_TIMEOUT,
        ]);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("vision API HTTP $status: " . mb_substr($body, 0, 200));
        }

        $data = json_decode($body, true);
        $text = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('vision API returned an empty reply');
        }

        return $text;
    }

    /* ---------------- media sampling ---------------- */

    /**
     * Up to MAX_IMAGES base64 JPEGs: the cover's thumbnail (or a frame from
     * a video's sample clip) plus thumbs of later files. Missing variants are
     * skipped - a text-only analysis still works.
     */
    private static function sampleImages(array $gallery): array
    {
        $uploads = rtrim((string) config('app.uploads.dir'), '/');
        $photos  = Gallery::photos((int) $gallery['id']);

        $images = [];
        foreach (array_slice($photos, 0, self::MAX_IMAGES) as $photo) {
            $filename = (string) $photo['filename'];

            // Prefer the generated thumb variant (400x300) - small payload.
            $thumb = $uploads . '/thumb_' . $filename;
            if (is_file($thumb)) {
                $data = self::encodeJpeg($thumb);
                if ($data !== null) {
                    $images[] = $data;
                    continue;
                }
            }

            if (!empty($photo['is_video'])) {
                $frame = self::videoFrame($uploads, $filename, (int) ($photo['duration_seconds'] ?? 0));
                if ($frame !== null) {
                    $images[] = $frame;
                }
            }
        }

        return $images;
    }

    /** Grab one mid-video frame into a temp file and base64 it. */
    private static function videoFrame(string $uploads, string $filename, int $duration): ?string
    {
        $source = $uploads . '/' . $filename;
        if (!is_file($source)) {
            return null;
        }

        $second = $duration > 0 ? intdiv($duration, 2) : 1;
        $tmp    = sys_get_temp_dir() . '/categorize_' . bin2hex(random_bytes(8)) . '.jpg';

        $ok = @create_video_frame($source, $tmp, self::FRAME_WIDTH, self::FRAME_HEIGHT, $second);
        $data = $ok && is_file($tmp) ? self::encodeJpeg($tmp) : null;
        @unlink($tmp);

        return $data;
    }

    /** Re-encode any supported image to a bounded JPEG (both drivers get JPEG data URIs). */
    private static function encodeJpeg(string $path): ?string
    {
        if (!is_file($path) || filesize($path) === 0) {
            return null;
        }

        // Under ~1MB, ship as-is when it is already a JPEG.
        if (filesize($path) < 1_000_000 && self::isJpeg($path)) {
            $raw = file_get_contents($path);
            return $raw !== false ? base64_encode($raw) : null;
        }

        if (!function_exists('imagecreatefromstring')) {
            $raw = file_get_contents($path);
            return $raw !== false ? base64_encode($raw) : null;
        }

        $src = @imagecreatefromstring((string) file_get_contents($path));
        if ($src === false) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, 640 / max(1, $w), 640 / max(1, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));

        $out = imagecreatetruecolor($tw, $th);
        imagecopyresampled($out, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($src);

        ob_start();
        imagejpeg($out, null, 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($out);

        return base64_encode($bytes);
    }

    private static function isJpeg(string $path): bool
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $magic = (string) fread($fh, 3);
        fclose($fh);

        return $magic === "\xFF\xD8\xFF";
    }

    private static function captions(int $galleryId): array
    {
        return \App\Core\Database::run(
            'SELECT p.caption
             FROM photos p
             INNER JOIN gallery_photo gp ON gp.photo_id = p.id
             WHERE gp.gallery_id = ? AND p.caption <> \'\'',
            [$galleryId]
        )->fetchAll();
    }
}
