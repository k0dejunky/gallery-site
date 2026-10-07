<?php

namespace App\Models;

use App\Core\Database;

/**
 * Gallery taxonomy tags, shown on gallery pages, the admin manage form and the
 * /galleries/tag/{slug} filter page.
 */
class Tag
{
    public static function all(): array
    {
        return Database::run('SELECT * FROM tags ORDER BY name ASC')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $row = Database::run('SELECT * FROM tags WHERE id = ?', [$id])->fetch();
        return $row === false ? null : $row;
    }

    public static function findBySlug(string $slug): ?array
    {
        $row = Database::run('SELECT * FROM tags WHERE slug = ? LIMIT 1', [$slug])->fetch();
        return $row === false ? null : $row;
    }

    public static function create(string $name): int
    {
        $slug = self::slugify($name);

        Database::run(
            'INSERT INTO tags (name, slug, created_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE name = name',
            [$name, $slug]
        );

        $row = Database::run('SELECT id FROM tags WHERE slug = ? LIMIT 1', [$slug])->fetch();
        return (int) ($row['id'] ?? 0);
    }

    public static function findOrCreate(string $name): int
    {
        $slug = self::slugify($name);
        $row  = Database::run('SELECT id FROM tags WHERE slug = ? LIMIT 1', [$slug])->fetch();

        if ($row !== false) {
            return (int) $row['id'];
        }

        return self::create($name);
    }

    public static function forGallery(int $galleryId): array
    {
        return Database::run(
            'SELECT t.* FROM tags t
             JOIN tag_gallery tg ON tg.tag_id = t.id
             WHERE tg.gallery_id = ?
             ORDER BY t.name ASC',
            [$galleryId]
        )->fetchAll();
    }

    /**
     * Replace a gallery's tag set. Unknown names are created; omitted tags are
     * unlinked (never deleted, so a mistyped name is recoverable).
     */
    public static function setForGallery(int $galleryId, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $ids[] = self::findOrCreate($name);
        }

        Database::run('DELETE FROM tag_gallery WHERE gallery_id = ?', [$galleryId]);

        $stmt = Database::connection()->prepare(
            'INSERT INTO tag_gallery (tag_id, gallery_id) VALUES (?, ?)'
        );

        foreach ($ids as $tagId) {
            $stmt->execute([$tagId, $galleryId]);
        }
    }

    public static function galleriesIn(string $slug): array
    {
        return Database::run(
            'SELECT tg.*, g.id AS gallery_id
             FROM tag_gallery tg
             JOIN galleries g ON g.id = tg.gallery_id
             JOIN tags t ON t.id = tg.tag_id
             WHERE t.slug = ?',
            [$slug]
        )->fetchAll();
    }

    public static function slugs(): array
    {
        return array_map(static fn (array $t) => $t['slug'], self::all());
    }

    public static function withCounts(): array
    {
        return Database::run(
            'SELECT t.*, COUNT(tg.gallery_id) AS gallery_count
             FROM tags t
             LEFT JOIN tag_gallery tg ON tg.tag_id = t.id
             GROUP BY t.id
             ORDER BY gallery_count DESC, t.name ASC'
        )->fetchAll();
    }

    public static function slugify(string $str): string
    {
        $str = preg_replace('/[^a-z0-9]+/i', '-', strtolower(trim($str))) ?? '';
        return trim(preg_replace('/-+/', '-', $str), '-') ?: 'tag';
    }
}