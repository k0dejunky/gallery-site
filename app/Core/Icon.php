<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Inline SVG icons for the site chrome.
 *
 * The admin sidebar used to be 31 text-only links, which is slow to scan and
 * wastes the one piece of real estate the operator looks at all day. Icons are
 * inlined (not an icon font, not an external sprite request) so they inherit
 * `currentColor`, cost nothing to load and survive the site editor, which
 * clones the nav into a preview frame.
 *
 * Every glyph is a 24x24 stroked path: `fill="none"`, `stroke="currentColor"`,
 * round caps and joins. Dot-only details (e.g. the palette swatches) rely on a
 * zero-length subpath plus a round linecap, the usual trick.
 */
final class Icon
{
    /** @var array<string, string> icon name => SVG path data */
    private const PATHS = [
        'grid'        => 'M4 4h6v6H4zM14 4h6v6h-6zM14 14h6v6h-6zM4 14h6v6H4z',
        'upload'      => 'M12 16V4M7.5 8.5 12 4l4.5 4.5M4 16v2.5A2.5 2.5 0 0 0 6.5 21h11a2.5 2.5 0 0 0 2.5-2.5V16',
        'trend'       => 'M3 17l5.5-5.5 3.5 3.5L21 6M15 6h6v6',
        'bars'        => 'M5 20v-9M10 20V4M15 20v-7M20 20V9',
        'pie'         => 'M21 12A9 9 0 1 1 12 3v9z',
        'image'       => 'M3 5h18v14H3zM3 16l5-5 4 4 3-3 6 6',
        'tag'         => 'M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8zM7.5 7.5h.01',
        'sparkle'     => 'M12 3l1.7 4.4L18 9l-4.3 1.6L12 15l-1.7-4.4L6 9l4.3-1.6zM18.5 15l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z',
        'film'        => 'M3 5h18v14H3zM7 5v14M17 5v14M3 9.5h4M3 14.5h4M17 9.5h4M17 14.5h4',
        'user'        => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
        'palette'     => 'M12 3a9 9 0 1 0 0 18 2 2 0 0 0 1.6-3.2 2 2 0 0 1 1.6-3.2H18a3.5 3.5 0 0 0 3.5-3.5A8.5 8.5 0 0 0 12 3zM7.5 12.5h.01M10 8.5h.01M14.5 7.5h.01',
        'layout'      => 'M3 5h18v14H3zM3 9.5h18M9.5 9.5V19',
        'heart'       => 'M20.8 5.6a5 5 0 0 0-7.1 0L12 7.3l-1.7-1.7a5 5 0 1 0-7.1 7.1l8.8 8.8 8.8-8.8a5 5 0 0 0 0-7.1z',
        'users'       => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8',
        'chat'        => 'M21 11.5a8.4 8.4 0 0 1-9 8.4 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.2a8.4 8.4 0 0 1-.9-3.8A8.5 8.5 0 0 1 12.5 3 8.5 8.5 0 0 1 21 11.5z',
        'phone'       => 'M8 2h8a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zM10.5 18h3',
        'lifebuoy'    => 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8M4.9 4.9l4.3 4.3M19.1 19.1l-4.3-4.3M19.1 4.9l-4.3 4.3M4.9 19.1l4.3-4.3',
        'send'        => 'M22 2 11 13M22 2l-7 20-4-9-9-4z',
        'dollar'      => 'M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
        'card'        => 'M3 6h18v12H3zM3 10h18M7 15h3',
        'repeat'      => 'M17 2l4 4-4 4M3 12V10a4 4 0 0 1 4-4h14M7 22l-4-4 4-4M21 12v2a4 4 0 0 1-4 4H3',
        'bank'        => 'M3 21h18M5 21V10M9.5 21V10M14.5 21V10M19 21V10M2.5 10 12 4l9.5 6',
        'mail'        => 'M3 5h18v14H3zM3 6.5l9 6.5 9-6.5',
        'inbox'       => 'M3 13h5l1.5 3h5L16 13h5M4.5 5h15v14h-15z',
        'server'      => 'M3.5 4h17v6h-17zM3.5 14h17v6h-17zM7 7h.01M7 17h.01',
        'doc'         => 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8zM14 3v5h5M9 13h6M9 17h4',
        'alert'       => 'M12 3.5 22 20H2zM12 10v4M12 17.5h.01',
        'activity'    => 'M3 12h4l3-8 4 16 3-8h4',
        'check'       => 'M9 11.5l2.5 2.5L21 4.5M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
        'book'        => 'M4 4.5h6a3 3 0 0 1 3 3V20a2.5 2.5 0 0 0-2.5-2.5H4zM20 4.5h-3a3 3 0 0 0-3 3V20a2.5 2.5 0 0 1 2.5-2.5H20z',
        'external'    => 'M14 4h6v6M20 4l-9 9M18 13.5V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h5.5',
        'sliders'     => 'M4 7h8M17 7h3M4 12h3M12 12h8M4 17h11M20 17h0M14.5 7a2 2 0 1 0-4 0 2 2 0 0 0 4 0zM9.5 12a2 2 0 1 0-4 0 2 2 0 0 0 4 0zM17.5 17a2 2 0 1 0-4 0 2 2 0 0 0 4 0z',
        'search'      => 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.3-4.3',
        'logout'      => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
        'menu'        => 'M3 6h18M3 12h18M3 18h18',
        'chevron'     => 'M9 18l6-6-6-6',
        'plus'        => 'M12 5v14M5 12h14',
        'minus'       => 'M5 12h14',
        'broadcast'   => 'M12 12h.01M8.5 8.5a5 5 0 0 0 0 7M15.5 8.5a5 5 0 0 1 0 7M5.6 5.6a9 9 0 0 0 0 12.8M18.4 5.6a9 9 0 0 1 0 12.8',
        'play'        => 'M7 4.5v15l12-7.5z',
        'dot'         => 'M12 12h.01',
    ];

    private function __construct()
    {
    }

    /** True when $name has a glyph — used by the smoke suite to keep navs complete. */
    public static function has(string $name): bool
    {
        return isset(self::PATHS[$name]);
    }

    /** @return string[] every icon name the chrome knows about */
    public static function names(): array
    {
        return array_keys(self::PATHS);
    }

    /**
     * Render one icon. Unknown names degrade to nothing rather than throwing,
     * so a typo can never take an admin page down.
     */
    public static function svg(string $name, string $class = 'nav-ico'): string
    {
        $d = self::PATHS[$name] ?? self::PATHS['dot'];
        $cls = self::attr($class);

        return '<svg class="' . $cls . '" viewBox="0 0 24 24" width="18" height="18" fill="none"'
            . ' stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"'
            . ' aria-hidden="true" focusable="false"><path d="' . self::attr($d) . '"/></svg>';
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}