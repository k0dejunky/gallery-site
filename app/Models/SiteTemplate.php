<?php

namespace App\Models;

use App\Core\Database;

class SiteTemplate
{
    public const SCOPE_USER  = 'user';
    public const SCOPE_ADMIN = 'admin';

    public static function all(string $scope = self::SCOPE_USER): array
    {
        return Database::run(
            'SELECT * FROM site_templates WHERE scope = ? ORDER BY is_active DESC, updated_at DESC',
            [$scope]
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $row = Database::run('SELECT * FROM site_templates WHERE id = ? LIMIT 1', [$id])->fetch();
        return $row ?: null;
    }

    public static function findBySlug(string $slug, string $scope = self::SCOPE_USER): ?array
    {
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
        $row = Database::run(
            'SELECT * FROM site_templates WHERE name = ? AND scope = ? LIMIT 1',
            [$slug, $scope]
        )->fetch();
        return $row ?: null;
    }

    public static function active(string $scope = self::SCOPE_USER): ?array
    {
        $row = Database::run(
            'SELECT * FROM site_templates WHERE is_active = 1 AND scope = ? LIMIT 1',
            [$scope]
        )->fetch();
        return $row ?: null;
    }

    public static function create(string $name, string $description, string $config, string $scope = self::SCOPE_USER): int
    {
        Database::run(
            'INSERT INTO site_templates (name, description, scope, config_json) VALUES (?, ?, ?, ?)',
            [$name, $description, $scope, $config]
        );
        return (int) Database::connection()->lastInsertId();
    }

    public static function update(int $id, string $name, string $description, string $config): void
    {
        Database::run(
            'UPDATE site_templates SET name = ?, description = ?, config_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$name, $description, $config, $id]
        );
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM site_templates WHERE id = ?', [$id]);
    }

    public static function activate(int $id): void
    {
        $tpl = self::find($id);
        if ($tpl === null) return;
        $scope = $tpl['scope'] ?? self::SCOPE_USER;
        Database::run('UPDATE site_templates SET is_active = 0 WHERE scope = ?', [$scope]);
        Database::run('UPDATE site_templates SET is_active = 1 WHERE id = ?', [$id]);
    }

    public static function deactivateAll(string $scope = self::SCOPE_USER): void
    {
        Database::run('UPDATE site_templates SET is_active = 0 WHERE scope = ?', [$scope]);
    }

    public static function seedDefaults(): void
    {
        $exists = Database::run('SELECT COUNT(*) as c FROM site_templates WHERE scope = ?', [self::SCOPE_USER])->fetch();
        if ((int) ($exists['c'] ?? 0) === 0) {
            Database::run(
                'INSERT INTO site_templates (name, description, scope, config_json, is_active) VALUES (?, ?, ?, ?, 1)',
                ['Default User Theme', 'Base layout for user-facing pages', self::SCOPE_USER, '[]']
            );
        }
        $existsA = Database::run('SELECT COUNT(*) as c FROM site_templates WHERE scope = ?', [self::SCOPE_ADMIN])->fetch();
        if ((int) ($existsA['c'] ?? 0) === 0) {
            Database::run(
                'INSERT INTO site_templates (name, description, scope, config_json, is_active) VALUES (?, ?, ?, ?, 1)',
                ['Default Admin Theme', 'Base layout for admin pages', self::SCOPE_ADMIN, '[]']
            );
        }
    }
}
