<?php

namespace App\Core;

/**
 * The admin console's navigation, in one place.
 *
 * The sidebar used to be a hand-written list of 33 links; several were the
 * same workflow split across pages (AI Suggestions + AI Descriptions, Logs +
 * Error Logs + User Monitor, Chat + Live Chat + Operator app) and one working
 * page (Funnel) was not in the nav at all. A "hub" is one sidebar entry with
 * a tab strip: the pages keep their own routes, controllers and POST
 * endpoints; only the navigation changes.
 *
 * This map is the single source of truth for:
 *   - the sidebar          (AdminSections::nav())
 *   - the tab strip        (AdminSections::tabs())
 *   - the first tab a hub links to (AdminSections::entry())
 *
 * A tab's `perm` is an AND list (a string or an array). A hub's `perm_any`
 * is the OR of its tabs, so a moderator with `chat` but not `support` sees
 * the Chat hub (Conversations + Devices), not the Live tab.
 */
class AdminSections
{
    /**
     * The hubs, in sidebar order. Each tab: label, href, perm.
     *
     * @return array<string, array{label:string, icon:string, tabs:array<int,array{label:string,href:string,perm:mixed}>}>
     */
    public static function hubs(): array
    {
        return [
            'analytics' => [
                'label' => 'Analytics',
                'icon'  => 'pie',
                'tabs'  => [
                    ['label' => 'Overview',    'href' => '/admin/trends',    'perm' => 'trends'],
                    ['label' => 'Links',       'href' => '/admin/traffic',   'perm' => 'traffic'],
                    ['label' => 'Access logs', 'href' => '/admin/analytics', 'perm' => 'analytics'],
                ],
            ],
            'ai' => [
                'label' => 'AI review',
                'icon'  => 'sparkle',
                'tabs'  => [
                    ['label' => 'Categories',   'href' => '/admin/category-suggestions',    'perm' => 'categories'],
                    ['label' => 'Descriptions', 'href' => '/admin/description-suggestions', 'perm' => 'galleries'],
                ],
            ],
            'design' => [
                'label' => 'Design',
                'icon'  => 'palette',
                'tabs'  => [
                    ['label' => 'Palette', 'href' => '/admin/theme',       'perm' => 'theme'],
                    ['label' => 'Layout',  'href' => '/admin/site-editor', 'perm' => 'site_editor'],
                    ['label' => 'Profile', 'href' => '/admin/profile',     'perm' => 'site_editor'],
                ],
            ],
            'users' => [
                'label' => 'Users',
                'icon'  => 'users',
                'tabs'  => [
                    ['label' => 'Members', 'href' => '/admin/users', 'perm' => 'users'],
                    ['label' => 'Roles',   'href' => '/admin/roles', 'perm' => 'manage_roles'],
                ],
            ],
            'chat' => [
                'label' => 'Chat',
                'icon'  => 'chat',
                'tabs'  => [
                    ['label' => 'Conversations', 'href' => '/admin/chat',            'perm' => 'chat'],
                    ['label' => 'Live',          'href' => '/admin/live-chat',       'perm' => 'support'],
                    ['label' => 'Devices',       'href' => '/admin/operator-tokens', 'perm' => 'chat'],
                ],
            ],
            'membership' => [
                'label' => 'Membership',
                'icon'  => 'card',
                'tabs'  => [
                    ['label' => 'Earnings',      'href' => '/admin/earnings',           'perm' => 'membership'],
                    ['label' => 'Plans',         'href' => '/admin/plans',              'perm' => 'membership'],
                    ['label' => 'Subscriptions', 'href' => '/admin/subscriptions',      'perm' => 'membership'],
                    ['label' => 'Processors',    'href' => '/admin/payment-processors', 'perm' => ['payments', 'membership']],
                ],
            ],
            'logs' => [
                'label' => 'Logs',
                'icon'  => 'doc',
                'tabs'  => [
                    ['label' => 'Audit',      'href' => '/admin/logs',         'perm' => 'logs'],
                    ['label' => 'PHP errors', 'href' => '/admin/error-logs',   'perm' => 'logs'],
                    ['label' => 'Activity',   'href' => '/admin/user-monitor', 'perm' => 'user_monitor'],
                ],
            ],
            'email' => [
                'label' => 'Email',
                'icon'  => 'mail',
                'tabs'  => [
                    ['label' => 'Site mail', 'href' => '/admin/emailer', 'perm' => 'membership'],
                    ['label' => 'Mailboxes', 'href' => '/admin/mail',    'perm' => 'mail_admin'],
                ],
            ],
        ];
    }

    /**
     * The sidebar groups, in order. A plain item is a one-link entry; a hub
     * item carries `hub` so the layout can resolve the first permitted tab.
     *
     * @return array<int, array{key:string, label:string, items:array<int,array<string,mixed>>}>
     */
    public static function nav(): array
    {
        return [
            [
                'key'   => 'nav-group-overview',
                'label' => 'Overview',
                'items' => [
                    ['key' => 'nav-dashboard', 'label' => 'Dashboard', 'href' => '/admin', 'icon' => 'grid', 'exact' => true],
                    self::hubItem('analytics'),
                    ['key' => 'nav-funnel', 'label' => 'Funnel', 'href' => '/admin/funnel', 'icon' => 'trend', 'perm' => 'dashboard'],
                ],
            ],
            [
                'key'   => 'nav-group-content',
                'label' => 'Content',
                'items' => [
                    ['key' => 'nav-galleries', 'label' => 'Gallery Management', 'href' => '/admin/galleries', 'icon' => 'image', 'perm' => 'galleries'],
                    ['key' => 'nav-categories', 'label' => 'Categories', 'href' => '/admin/categories', 'icon' => 'tag', 'perm' => 'categories'],
                    self::hubItem('ai'),
                    ['key' => 'nav-video-projects', 'label' => 'Video Projects', 'href' => '/admin/video-projects', 'icon' => 'film', 'perm' => 'videos'],
                    ['key' => 'nav-abandoned-uploads', 'label' => 'Uploads & Orphans', 'href' => '/admin/abandoned-uploads', 'icon' => 'upload', 'perm' => 'galleries'],
                    self::hubItem('design'),
                ],
            ],
            [
                'key'   => 'nav-group-community',
                'label' => 'Community & growth',
                'items' => [
                    self::hubItem('users'),
                    ['key' => 'nav-engagement', 'label' => 'Engagement', 'href' => '/admin/engagement', 'icon' => 'heart', 'perm' => 'dashboard'],
                    self::hubItem('chat'),
                    ['key' => 'nav-support', 'label' => 'Support', 'href' => '/admin/support', 'icon' => 'lifebuoy', 'perm' => 'support'],
                    ['key' => 'nav-auto-poster', 'label' => 'Auto Poster', 'href' => '/admin/auto-poster', 'icon' => 'send', 'perm' => 'autoposter'],
                ],
            ],
            [
                'key'   => 'nav-group-money',
                'label' => 'Monetization',
                'items' => [
                    self::hubItem('membership'),
                ],
            ],
            [
                'key'   => 'nav-group-system',
                'label' => 'System',
                'items' => [
                    // Route perm is `dashboard`, SystemController's constructor
                    // asks for `logs`. Both must hold or the link bounces.
                    ['key' => 'nav-system', 'label' => 'System', 'href' => '/admin/system', 'icon' => 'server', 'perm' => ['dashboard', 'logs']],
                    self::hubItem('logs'),
                    self::hubItem('email'),
                    ['key' => 'nav-test-suite', 'label' => 'Test suite', 'href' => '/admin/test-suite', 'icon' => 'check', 'perm' => 'logs'],
                    ['key' => 'nav-help', 'label' => 'Documentation', 'href' => '/admin/help', 'icon' => 'book', 'perm' => 'documentation'],
                ],
            ],
        ];
    }

    /**
     * One sidebar item for a hub. `perm_any` is the OR of the tabs; `match`
     * is every tab href, so the entry lights up on any of them.
     */
    private static function hubItem(string $hubKey): array
    {
        $hub = self::hubs()[$hubKey] ?? null;

        if ($hub === null) {
            return ['key' => 'nav-' . $hubKey, 'label' => ucfirst($hubKey), 'href' => '/admin', 'icon' => 'grid'];
        }

        $perms = [];
        $match = [];
        foreach ($hub['tabs'] as $tab) {
            $match[] = $tab['href'];
            foreach ((array) $tab['perm'] as $permission) {
                $perms[$permission] = true;
            }
        }

        return [
            'key'      => 'nav-' . $hubKey,
            'label'    => $hub['label'],
            'icon'     => $hub['icon'],
            'href'     => $hub['tabs'][0]['href'] ?? '/admin',
            'hub'      => $hubKey,
            'match'    => $match,
            'perm_any' => array_keys($perms),
        ];
    }

    /**
     * The tabs of a hub the current admin may open, in order.
     *
     * @return array<int, array{label:string,href:string,perm:mixed}>
     */
    public static function tabs(string $hub): array
    {
        $tabs = self::hubs()[$hub]['tabs'] ?? [];

        return array_values(array_filter($tabs, static fn (array $tab): bool => self::can($tab['perm'])));
    }

    /** The first permitted tab's href, or null when none is. */
    public static function entry(string $hub): ?string
    {
        $tabs = self::tabs($hub);

        return $tabs[0]['href'] ?? null;
    }

    /** True when the current admin may open a tab (its perm is an AND list). */
    public static function can($perm): bool
    {
        foreach ((array) $perm as $permission) {
            if (!Auth::can($permission)) {
                return false;
            }
        }

        return true;
    }
}
