<?php

namespace App\Models;

use App\Core\Database;

class VideoProject
{
    public static function findForUser(int $photoId, int $userId): ?array
    {
        $row = Database::run(
            'SELECT * FROM video_projects WHERE source_photo_id = ? AND user_id = ? LIMIT 1',
            [$photoId, $userId]
        )->fetch();
        return $row ?: null;
    }

    public static function find(int $id): ?array
    {
        $row = Database::run('SELECT * FROM video_projects WHERE id = ? LIMIT 1', [$id])->fetch();
        return $row ?: null;
    }

    public static function create(int $photoId, int $userId, string $title, array $project): array
    {
        Database::run(
            'INSERT INTO video_projects (source_photo_id, user_id, title, project_json, version)
             VALUES (?, ?, ?, ?, 1)',
            [$photoId, $userId, $title, json_encode($project, JSON_UNESCAPED_SLASHES)]
        );
        return self::find((int) Database::connection()->lastInsertId());
    }

    public static function save(int $id, int $userId, string $title, array $project, int $version): ?array
    {
        $changed = Database::run(
            'UPDATE video_projects SET title = ?, project_json = ?, version = version + 1,
             updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND version = ?',
            [$title, json_encode($project, JSON_UNESCAPED_SLASHES), $id, $userId, $version]
        )->rowCount();
        return $changed ? self::find($id) : null;
    }

    public static function createExport(int $projectId, bool $saveOverOriginal = false, float $exportStart = 0, float $exportEnd = 0): int
    {
        $metadata = json_encode([
            'save_over_original' => $saveOverOriginal,
            'export_start' => $exportStart,
            'export_end' => $exportEnd,
        ]);
        Database::run(
            'INSERT INTO video_export_jobs (project_id, status, progress, metadata_json) VALUES (?, \'queued\', 0, ?)',
            [$projectId, $metadata]
        );
        return (int) Database::connection()->lastInsertId();
    }

    public static function exportJob(int $id, int $userId): ?array
    {
        $row = Database::run(
            'SELECT j.*, p.user_id, p.source_photo_id FROM video_export_jobs j
             JOIN video_projects p ON p.id = j.project_id
             WHERE j.id = ? AND p.user_id = ? LIMIT 1',
            [$id, $userId]
        )->fetch();
        return $row ?: null;
    }

    public static function updateExport(int $id, string $status, int $progress, ?string $output = null, ?string $error = null): void
    {
        Database::run(
            'UPDATE video_export_jobs SET status = ?, progress = ?, output_file = COALESCE(?, output_file),
             error = ?, started_at = CASE WHEN ? = \'running\' AND started_at IS NULL THEN CURRENT_TIMESTAMP ELSE started_at END,
             finished_at = CASE WHEN ? IN (\'completed\', \'failed\') THEN CURRENT_TIMESTAMP ELSE finished_at END
             WHERE id = ?',
            [$status, $progress, $output, $error, $status, $status, $id]
        );
    }
}
