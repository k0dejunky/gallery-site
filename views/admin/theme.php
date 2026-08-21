<?php $title = 'Theme'; ?>
<?php
$scope = $scope ?? 'site';
$pinkKeys = ['pink-100', 'pink-200', 'pink-300', 'pink-400'];
$purpleKeys = ['purple-200', 'purple-300', 'purple-400', 'purple-500', 'purple-600', 'purple-700', 'purple-800', 'purple-900'];
$buttonKeys = ['btn-bg', 'btn-color', 'btn-hover-bg', 'btn-danger-bg', 'btn-danger-color', 'btn-danger-hover-bg', 'btn-danger-border', 'filter-bg', 'filter-border', 'filter-color', 'filter-inactive-bg', 'filter-inactive-color', 'filter-inactive-border', 'filter-inactive-hover'];
$sidebarKeys = ['sidebar-bg', 'sidebar-border', 'sidebar-heading', 'sidebar-link-bg', 'sidebar-link-color', 'sidebar-link-border', 'sidebar-link-hover', 'sidebar-active-bg', 'sidebar-active-color', 'sidebar-active-border'];
$cardKeys = ['card-bg', 'card-border', 'card-placeholder-bg', 'card-thumb-bg', 'card-thumb-color', 'card-title-color', 'card-text-color', 'card-cat-link-color'];
$statKeys = ['stat-bg', 'stat-border', 'stat-number-color', 'stat-label-color'];
$tableKeys = ['table-bg', 'table-border', 'table-header-bg', 'table-header-color', 'table-text', 'pagination-bg', 'pagination-color', 'pagination-hover-bg', 'pagination-active-bg', 'pagination-active-color', 'pagination-border'];
$promotionKeys = ['promo-card-bg', 'promo-card-border', 'promo-card-title', 'promo-card-text', 'promo-code-bg', 'promo-code-border', 'promo-code-color'];
$descriptions = [
    'pink-100' => 'Light surfaces and input backgrounds',
    'pink-200' => 'Main page background and success notices',
    'pink-300' => 'Cards, buttons, chips, and table headers',
    'pink-400' => 'Hover states and highlighted borders',

    'purple-200' => 'Soft selected and active surfaces',
    'purple-300' => 'Selected favorites and themed surfaces',
    'purple-400' => 'Active navigation and focus outlines',
    'purple-500' => 'Hover accents and selected text',
    'purple-600' => 'Links and secondary accents',
    'purple-700' => 'Link text and navigation labels',
    'purple-800' => 'Headings and emphasized text',
    'purple-900' => 'Primary body text and dark accents',
    'btn-bg'        => 'Default button background',
    'btn-color'     => 'Default button text color',
    'btn-hover-bg'  => 'Default button hover background',
    'btn-danger-bg'     => 'Danger button background',
    'btn-danger-color'  => 'Danger button text color',
    'btn-danger-hover-bg' => 'Danger button hover background',
    'btn-danger-border' => 'Danger button border',
    'filter-bg'        => 'Active filter background',
    'filter-border'    => 'Active filter border',
    'filter-color'     => 'Active filter text color',
    'filter-inactive-bg'    => 'Inactive filter background',
    'filter-inactive-border'=> 'Inactive filter border',
    'filter-inactive-color' => 'Inactive filter text color',
    'filter-inactive-hover' => 'Inactive filter hover background',
    'sidebar-bg'           => 'Sidebar background',
    'sidebar-border'       => 'Sidebar border',
    'sidebar-heading'      => 'Section heading text',
    'sidebar-link-bg'      => 'Link background',
    'sidebar-link-color'   => 'Link text color',
    'sidebar-link-border'  => 'Link border',
    'sidebar-link-hover'   => 'Link hover background',
    'sidebar-active-bg'    => 'Active link background',
    'sidebar-active-color' => 'Active link text',
    'sidebar-active-border'=> 'Active link border',
    'card-bg'              => 'Card background',
    'card-border'          => 'Card border',
    'card-placeholder-bg'  => 'Thumbnail placeholder bg',
    'card-thumb-bg'        => 'Thumbnail overlay bg',
    'card-thumb-color'     => 'Thumbnail overlay text',
    'card-title-color'     => 'Card title text',
    'card-text-color'      => 'Card description text',
    'card-cat-link-color'  => 'Category toggle link',
    'stat-bg'              => 'Statistic card background',
    'stat-border'          => 'Statistic card border',
    'stat-number-color'    => 'Statistic card number text',
    'stat-label-color'     => 'Statistic card label text',
    'table-bg'             => 'Table background',
    'table-border'         => 'Table cell border',
    'table-header-bg'      => 'Table header background',
    'table-header-color'   => 'Table header text',
    'table-text'           => 'Table cell text',
    'pagination-bg'        => 'Pagination button background',
    'pagination-color'     => 'Pagination button text',
    'pagination-hover-bg'  => 'Pagination button hover background',
    'pagination-active-bg' => 'Active page button background',
    'pagination-active-color' => 'Active page button text',
    'pagination-border'    => 'Pagination button border',
    'promo-card-bg'        => 'Promotion card background',
    'promo-card-border'    => 'Promotion card border',
    'promo-card-title'     => 'Promotion card title',
    'promo-card-text'      => 'Promotion card text',
    'promo-code-bg'        => 'Promotion code background',
    'promo-code-border'    => 'Promotion code border',
    'promo-code-color'     => 'Promotion code text',
];
$scopes = $themeScopes ?? [
    'site'  => ['label' => 'User theme', 'mock' => 'site'],
];

$layoutBySection = [];
foreach ($layoutDefaults as $key => $meta) {
    $layoutBySection[$meta['section']][$key] = $meta;
}

$paletteGrid = [
    '#ffffff', '#f8fafc', '#f1f5f9', '#e2e8f0', '#cbd5e1', '#94a3b8', '#64748b', '#475569',
    '#334155', '#1e293b', '#0f172a', '#000000', '#fef2f2', '#fee2e2', '#fecaca', '#fca5a5',
    '#f87171', '#ef4444', '#dc2626', '#b91c1c', '#991b1b', '#7f1d1d', '#450a0a', '#fff7ed',
    '#ffedd5', '#fed7aa', '#fdba74', '#fb923c', '#f97316', '#ea580c', '#c2410c', '#9a3412',
    '#fffbeb', '#fef3c7', '#fde68a', '#fcd34d', '#fbbf24', '#f59e0b', '#d97706', '#b45309',
    '#fefce8', '#fef9c3', '#fef08a', '#fde047', '#facc15', '#eab308', '#ca8a04', '#a16207',
    '#f0fdf4', '#dcfce7', '#bbf7d0', '#86efac', '#4ade80', '#22c55e', '#16a34a', '#15803d',
    '#ecfdf5', '#d1fae5', '#a7f3d0', '#6ee7b7', '#34d399', '#10b981', '#059669', '#047857',
    '#f0f9ff', '#e0f2fe', '#bae6fd', '#7dd3fc', '#38bdf8', '#0ea5e9', '#0284c7', '#0369a1',
    '#eff6ff', '#dbeafe', '#bfdbfe', '#93c5fd', '#60a5fa', '#3b82f6', '#2563eb', '#1d4ed8',
    '#eef2ff', '#e0e7ff', '#c7d2fe', '#a5b4fc', '#818cf8', '#6366f1', '#4f46e5', '#4338ca',
    '#faf5ff', '#f3e8ff', '#e9d5ff', '#d8b4fe', '#c084fc', '#a855f7', '#9333ea', '#7e22ce',
    '#fdf4ff', '#fae8ff', '#f5d0fe', '#f0abfc', '#e879f9', '#d946ef', '#c026d3', '#a21caf',
    '#fff1f2', '#ffe4e6', '#fecdd3', '#fda4af', '#fb7185', '#f43f5e', '#e11d48', '#be123c',
    '#ffd9e8', '#f9a8d4', '#f472b6', '#ec4899', '#db2777', '#be185d', '#9d174d', '#831843',
];
?>

<style>
    .theme-editor { display: flex; flex-direction: column; gap: 1.25rem; }
    .theme-editor > [data-saved-themes] { margin-top: -1.25rem; }
    .theme-preview-label { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--purple-700); margin-bottom: 0.4rem; }
    #theme-preview { border-radius: var(--border-radius-lg); box-shadow: var(--shadow); font-size: var(--font-size-base); line-height: var(--line-height); }
    [data-mock] { background: var(--pink-200); border: var(--input-border-width, 1px) solid var(--pink-300); border-radius: var(--border-radius-lg); padding: var(--spacing-md); }
    #theme-preview a { color: var(--purple-600); text-decoration: none; transition: color 0.15s; }
    #theme-preview a:hover { color: var(--purple-400); }
    #theme-preview h4 { font-size: var(--font-size-lg); }
    #theme-preview p { font-size: var(--font-size-sm); }
    #theme-preview input[type="text"] { padding: var(--input-padding); border: var(--input-border-width) solid var(--pink-300); border-radius: var(--input-radius); background: var(--pink-100); color: var(--purple-900); font-size: var(--font-size-sm); }
    #theme-preview .pv-site-header img { max-width: 200px; display: block; margin: 0 auto var(--spacing-sm); border-radius: var(--border-radius-lg); box-shadow: var(--shadow); }
    #theme-preview .pv-nav { display: flex; gap: var(--spacing-md); align-items: center; flex-wrap: wrap; border-bottom: var(--input-border-width, 1px) solid var(--pink-300); padding-bottom: var(--spacing-sm); margin-bottom: var(--spacing-md); }
    #theme-preview .pv-nav .pv-brand { font-weight: bold; font-size: var(--font-size-lg); color: var(--purple-900); }
    #theme-preview .pv-nav a { text-decoration: none; color: var(--purple-700); font-size: var(--font-size-sm); }
    #theme-preview .pv-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--spacing-sm); margin-top: var(--spacing-md); }
    #theme-preview .pv-card { border: var(--input-border-width, 1px) solid var(--card-border); border-radius: var(--card-radius); overflow: hidden; background: var(--card-bg); box-shadow: var(--shadow); }
    #theme-preview .pv-thumb { height: 72px; display: grid; place-items: center; background: var(--card-thumb-bg); color: var(--card-thumb-color); font-size: var(--font-size-xs); }
    #theme-preview .pv-card-body { padding: var(--card-padding); }
    #theme-preview .pv-card-body h4 { margin: 0 0 var(--spacing-xs); font-size: var(--font-size-sm); color: var(--card-title-color); }
    #theme-preview .pv-card-body p { margin: 0; font-size: var(--font-size-xs); color: var(--card-text-color); }
    #theme-preview .pv-actions { display: flex; gap: var(--spacing-sm); margin-top: var(--spacing-md); }
    #theme-preview .pv-actions .btn { display: inline-block; padding: var(--btn-padding); background: var(--btn-bg); color: var(--btn-color); text-decoration: none; border-radius: var(--btn-radius); border: none; font-size: var(--btn-font-size); }
    #theme-preview .pv-actions .btn:hover { background: var(--btn-hover-bg); }
    #theme-preview .pv-actions .btn-outline { background: var(--btn-danger-bg); color: var(--btn-danger-color); border: var(--input-border-width, 1px) solid var(--btn-danger-border); }
    #theme-preview .pv-actions .btn-outline:hover { background: var(--btn-danger-hover-bg); }
    #theme-preview .pv-actions .btn-danger { background: var(--btn-danger-bg); color: var(--btn-danger-color); border: var(--input-border-width, 1px) solid var(--btn-danger-border); }
    #theme-preview .pv-actions .btn-danger:hover { background: var(--btn-danger-hover-bg); }
    #theme-preview .pv-admin { display: grid; grid-template-columns: 120px 1fr; gap: var(--spacing-md); }
    #theme-preview .pv-admin-heading { margin: 0 0 0.5rem; color: var(--purple-800); }
    #theme-preview .pv-flash { margin-bottom: var(--spacing-sm); padding: var(--card-padding); background: var(--pink-200); border-left: 4px solid var(--purple-500); border-radius: var(--border-radius-sm); font-size: var(--font-size-xs); color: var(--purple-900); transition: border-color 0.15s; }
    #theme-preview .pv-nav-col { background: var(--sidebar-bg); border: var(--input-border-width, 1px) solid var(--sidebar-border); border-radius: var(--border-radius); padding: var(--spacing-sm); display: flex; flex-direction: column; gap: var(--spacing-xs); }
    #theme-preview .pv-nav-col b { color: var(--sidebar-heading); font-size: var(--font-size-xs); margin-bottom: var(--spacing-xs); }
    #theme-preview .pv-nav-col span { padding: var(--spacing-xs) var(--spacing-sm); border-radius: var(--border-radius); background: var(--sidebar-link-bg); border: var(--input-border-width, 1px) solid var(--sidebar-link-border); color: var(--sidebar-link-color); font-size: var(--font-size-xs); cursor: pointer; transition: background 0.15s, border-color 0.15s, color 0.15s; }
    #theme-preview .pv-nav-col span:hover { background: var(--sidebar-link-hover); }
    #theme-preview .pv-nav-col span.on { background: var(--sidebar-active-bg); border-color: var(--sidebar-active-border); color: var(--sidebar-active-color); }
    #theme-preview .pv-stat-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--spacing-sm); margin-bottom: var(--spacing-md); }
    #theme-preview .pv-stat { background: var(--stat-bg); border: var(--input-border-width, 1px) solid var(--stat-border); border-radius: var(--card-radius); padding: var(--spacing-sm); cursor: default; transition: border-color 0.15s, box-shadow 0.15s; }
    #theme-preview .pv-stat:hover { border-color: var(--purple-400); box-shadow: 0 2px 8px rgba(147,51,234,0.15); }
    #theme-preview .pv-stat b { display: block; color: var(--stat-number-color); font-size: var(--font-size-lg); }
    #theme-preview .pv-stat small { color: var(--stat-label-color); font-size: var(--font-size-xs); }
    #theme-preview .pv-admin table { margin-bottom: var(--spacing-sm); border-radius: var(--table-radius); background: var(--table-bg); }
    #theme-preview .pv-admin table td { font-size: var(--font-size-xs); padding: var(--spacing-xs) var(--spacing-sm); color: var(--table-text); border: var(--input-border-width) solid var(--table-border); }
    #theme-preview .pv-admin table tr:not(:first-child):hover td { background: var(--pink-200); }
    #theme-preview .pv-admin table td a { color: var(--purple-600); transition: color 0.15s; }
    #theme-preview .pv-admin table td a:hover { color: var(--purple-400); }
    #theme-preview .pv-admin table th { font-size: var(--font-size-xs); padding: var(--spacing-xs) var(--spacing-sm); background: var(--table-header-bg); color: var(--table-header-color); border: var(--input-border-width) solid var(--table-border); }
    #theme-preview .pv-admin .chip { display: inline-flex; padding: var(--chip-padding); border-radius: var(--chip-radius); background: var(--card-bg); border: var(--input-border-width, 1px) solid var(--card-border); color: var(--card-text-color); font-size: var(--font-size-xs); cursor: pointer; transition: background 0.15s, border-color 0.15s; }
    #theme-preview .pv-admin .chip:hover { background: var(--pink-300); border-color: var(--pink-400); }
    #theme-preview .pv-admin .chip.active { background: var(--filter-bg); border-color: var(--filter-border); color: var(--filter-color); }
    #theme-preview .pv-promo-heading { margin: var(--spacing-md) 0 var(--spacing-xs); color: var(--promo-card-title); font-size: var(--font-size-sm); }
    #theme-preview .pv-promo-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--spacing-sm); }
    #theme-preview .pv-promo-card { padding: var(--spacing-sm); background: var(--promo-card-bg); border: var(--input-border-width) solid var(--promo-card-border); border-radius: var(--card-radius); color: var(--promo-card-text); }
    #theme-preview .pv-promo-card strong { display: block; margin-bottom: var(--spacing-xs); color: var(--promo-card-title); font-size: var(--font-size-xs); }
    #theme-preview .pv-promo-code { display: block; margin-bottom: var(--spacing-xs); padding: var(--spacing-xs); background: var(--promo-code-bg); border: var(--input-border-width) dashed var(--promo-code-border); border-radius: var(--border-radius-sm); color: var(--promo-code-color); font-family: monospace; font-size: var(--font-size-xs); font-weight: bold; text-align: center; }
    #theme-preview .pv-promo-card small { color: var(--promo-card-text); font-size: var(--font-size-xs); }
    #theme-preview .pv-site-layout { display: flex; gap: var(--spacing-md); align-items: flex-start; margin-top: var(--spacing-sm); }
    #theme-preview .pv-sidebar { flex: 0 0 120px; display: flex; flex-direction: column; gap: var(--spacing-xs); }
    #theme-preview .pv-sidebar-box { display: flex; flex-direction: column; gap: var(--spacing-xs); padding: var(--card-padding); background: var(--sidebar-bg); border: var(--input-border-width, 1px) solid var(--sidebar-border); border-radius: var(--border-radius-lg); }
    #theme-preview .pv-sidebar-box h5 { margin: 0; font-size: var(--font-size-xs); text-transform: uppercase; letter-spacing: 0.05em; color: var(--sidebar-heading); text-align: center; }
    #theme-preview .pv-sidebar-link { display: block; padding: var(--spacing-xs) var(--card-padding); border-radius: var(--border-radius); color: var(--sidebar-link-color); text-decoration: none; background: var(--sidebar-link-bg); border: var(--input-border-width, 1px) solid var(--sidebar-link-border); font-size: var(--font-size-xs); text-align: center; }
    #theme-preview .pv-sidebar-link.active { background: var(--sidebar-active-bg); color: var(--sidebar-active-color); border-color: var(--sidebar-active-border); font-weight: bold; }
    #theme-preview .pv-sidebar-link:hover { background: var(--sidebar-link-hover); }
    #theme-preview .pv-site-main { flex: 1; min-width: 0; }
    .theme-palette[open] > summary h2::after { content: "\25BC"; }
    .theme-palette > summary h2::after { content: "\25B6"; }
    .theme-palette { display: flex; flex-direction: column; }
    .theme-palette > summary { list-style: none; cursor: pointer; padding: var(--spacing-sm) var(--spacing-md); background: var(--pink-100); border: var(--input-border-width) solid var(--pink-300); border-radius: var(--border-radius); display: flex; align-items: center; user-select: none; margin: 0; }
    .theme-palette > summary::-webkit-details-marker { display: none; }
    .theme-palette > summary h2 { margin: 0; flex: 1; font-size: var(--font-size-lg); }
    .theme-palette[open] > summary { border-radius: var(--border-radius) var(--border-radius) 0 0; border-bottom: none; }
    .theme-palette[open] > .theme-grid { padding: var(--spacing-md); border: var(--input-border-width) solid var(--pink-300); border-top: none; border-radius: 0 0 var(--border-radius) var(--border-radius); background: var(--pink-100); margin: 0; }
    .theme-palette[open] > .presets-section { padding: var(--spacing-md); border: var(--input-border-width) solid var(--pink-300); border-top: none; border-radius: 0 0 var(--border-radius) var(--border-radius); background: var(--pink-100); margin: 0; }
    .layout-section { display: flex; flex-direction: column; }
    .layout-section > summary { list-style: none; cursor: pointer; padding: var(--spacing-sm) var(--spacing-md); background: var(--pink-100); border: var(--input-border-width) solid var(--pink-300); border-radius: var(--border-radius); display: flex; align-items: center; user-select: none; margin: 0; }
    .layout-section > summary::-webkit-details-marker { display: none; }
    .layout-section > summary::after { content: "\25B6"; }
    .layout-section[open] > summary::after { content: "\25BC"; }
    .layout-section > summary h3 { margin: 0; flex: 1; font-size: var(--font-size-md); }
    .layout-section[open] > summary { border-radius: var(--border-radius) var(--border-radius) 0 0; border-bottom: none; }
    .layout-section[open] > .layout-groups-inner { padding: var(--spacing-md); border: var(--input-border-width) solid var(--pink-300); border-top: none; border-radius: 0 0 var(--border-radius) var(--border-radius); background: var(--pink-100); margin: 0; }
    .layout-groups-inner { display: grid; grid-template-columns: repeat(5, 1fr); gap: var(--spacing-sm); padding: var(--spacing-md); }
    .theme-palettes { display: grid; grid-template-columns: 1fr; gap: 0; }
    .theme-palette h2 { margin: 0 0 0.5rem; font-size: 0.95rem; color: var(--purple-800); }
    .theme-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.5rem; }
    .theme-swatch { display: flex; flex-direction: column; gap: 0.3rem; padding: 0.5rem; border: 1px solid var(--pink-300); border-radius: 6px; background: var(--pink-100); position: relative; }
    .theme-swatch:hover { border-color: var(--pink-400); }
    .theme-name { font-size: 0.75rem; font-weight: bold; color: var(--purple-800); }
    .theme-description { font-size: 0.7rem; color: var(--purple-700); }
    .theme-swatch code { font-size: 0.7rem; color: var(--purple-700); background: var(--pink-200); padding: 0.15rem 0.35rem; border-radius: 4px; word-break: break-all; }

    /* ── Color wheel picker ── */
    .cpick { position: relative; }
    .cpick-trigger { display: flex; align-items: center; gap: 0.4rem; width: 100%; padding: 4px; border: 1px solid var(--pink-400); border-radius: 4px; background: var(--pink-100); cursor: pointer; box-sizing: border-box; }
    .cpick-swatch { width: 24px; height: 24px; border-radius: 3px; border: 1px solid var(--pink-400); flex-shrink: 0; }
    .cpick-hex { font-size: 0.7rem; color: var(--purple-700); font-family: monospace; flex: 1; text-align: left; }
    .cpick-arrow { font-size: 0.55rem; color: var(--purple-700); }
    .cpick-dropdown { display: none; position: fixed; z-index: 1000; width: 230px; max-height: calc(100vh - 1rem); overflow-y: auto; background: var(--pink-100); border: 1px solid var(--pink-400); border-radius: 6px; padding: 0.5rem; box-shadow: 0 8px 24px rgba(0,0,0,0.22); }
    .cpick-open .cpick-dropdown { display: block; }
    .cpick-dragbar { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin: -0.5rem -0.5rem 0.5rem; padding: 0.35rem 0.5rem; border-bottom: 1px solid var(--pink-300); color: var(--purple-700); font-size: 0.65rem; font-weight: bold; cursor: move; user-select: none; touch-action: none; }
    .cpick-dragbar::after { content: "Drag to move"; font-size: 0.55rem; font-weight: normal; opacity: 0.75; }
    .cpick-wheel-wrap { position: relative; width: 200px; height: 200px; margin: 0 auto 0.5rem; border-radius: 50%; overflow: hidden; cursor: crosshair; border: 2px solid var(--pink-400); touch-action: none; }
    .cpick-wheel-wrap canvas { display: block; width: 100%; height: 100%; }
    .cpick-wheel-ind { position: absolute; width: 14px; height: 14px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,0.4), inset 0 0 0 1px rgba(0,0,0,0.2); pointer-events: none; transform: translate(-50%, -50%); z-index: 2; }
    .cpick-brightness-wrap { position: relative; width: 200px; height: 16px; margin: 0 auto 0.5rem; border-radius: 8px; overflow: hidden; cursor: pointer; border: 1px solid var(--pink-400); touch-action: none; }
    .cpick-brightness-wrap canvas { display: block; width: 100%; height: 100%; }
    .cpick-brightness-ind { position: absolute; top: -1px; width: 6px; height: 18px; border-radius: 3px; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,0.4); pointer-events: none; transform: translateX(-50%); z-index: 2; }
    .cpick-contrast-wrap { position: relative; width: 200px; height: 16px; margin: 0 auto 0.5rem; border-radius: 8px; overflow: hidden; cursor: pointer; border: 1px solid var(--pink-400); touch-action: none; }
    .cpick-contrast-wrap canvas { display: block; width: 100%; height: 100%; }
    .cpick-contrast-ind { position: absolute; top: -1px; width: 6px; height: 18px; border-radius: 3px; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,0.4); pointer-events: none; transform: translateX(-50%); z-index: 2; }
    .cpick-preview-row { display: flex; gap: 0.4rem; align-items: center; margin-bottom: 0.4rem; }
    .cpick-preview-swatch { width: 32px; height: 24px; border-radius: 4px; border: 1px solid var(--pink-400); flex-shrink: 0; }
    .cpick-hsb-label { font-size: 0.6rem; color: var(--purple-700); font-family: monospace; }
    .cpick-custom { display: flex; gap: 0.3rem; align-items: center; }
    .cpick-custom input { flex: 1; padding: 0.25rem 0.4rem; border: 1px solid var(--pink-300); border-radius: 3px; background: var(--pink-200); color: var(--purple-900); font-size: 0.7rem; font-family: monospace; box-sizing: border-box; }
    .cpick-custom input:focus { outline: 2px solid var(--purple-400); border-color: var(--purple-400); }
    .cpick-custom .cpick-go { padding: 0.25rem 0.5rem; border: 1px solid var(--pink-400); border-radius: 3px; background: var(--pink-300); color: var(--purple-900); font-size: 0.65rem; cursor: pointer; }
    .cpick-custom .cpick-cancel { padding: 0.25rem 0.5rem; border: 1px solid var(--pink-400); border-radius: 3px; background: transparent; color: var(--purple-800); font-size: 0.65rem; cursor: pointer; }
    .cpick-palette-toggle { display: block; width: 100%; margin-top: 0.4rem; padding: 0.2rem; border: 1px solid var(--pink-300); border-radius: 3px; background: var(--pink-200); color: var(--purple-700); font-size: 0.6rem; cursor: pointer; text-align: center; }
    .cpick-palette-toggle:hover { background: var(--pink-300); }
    .cpick-palette { display: grid; grid-template-columns: repeat(8, 1fr); gap: 2px; margin-top: 0.4rem; }
    .cpick-opt { width: 100%; aspect-ratio: 1; border-radius: 2px; border: 2px solid transparent; cursor: pointer; padding: 0; }
    .cpick-opt:hover { border-color: var(--purple-400); transform: scale(1.15); }
    .cpick-opt.selected { border-color: var(--purple-900); box-shadow: 0 0 0 1px var(--purple-400); }

    .theme-actions { display: flex; gap: 0.6rem; margin: 1rem 0 0.5rem; justify-content: center; }
    .theme-section-divider { border-top: 2px solid var(--pink-400); margin: 1.5rem 0 0.75rem; padding-top: 0.75rem; }
    .theme-section-title { font-size: 1rem; color: var(--purple-800); margin: 0 0 0.5rem; font-weight: bold; }
    .theme-section-desc { font-size: 0.8rem; color: var(--purple-700); margin: 0 0 0.75rem; }
    .layout-groups { display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.5rem; }
    .layout-group { background: var(--pink-100); border: 1px solid var(--pink-300); border-radius: 6px; padding: 0.4rem; }
    .layout-group h3 { margin: 0 0 0.3rem; font-size: 0.7rem; color: var(--purple-800); border-bottom: 1px solid var(--pink-300); padding-bottom: 0.2rem; text-align: center; }
    .layout-row { display: flex; flex-direction: column; gap: 0.1rem; padding: 0.15rem 0; }
    .layout-row label { font-size: 0.65rem; color: var(--purple-700); }
    .layout-row input[type="text"] { width: 100%; padding: 0.2rem 0.3rem; border: 1px solid var(--pink-300); border-radius: 3px; background: var(--pink-200); color: var(--purple-900); font-size: 0.65rem; font-family: monospace; box-sizing: border-box; }
    .layout-row input[type="text"]:focus { outline: 2px solid var(--purple-400); border-color: var(--purple-400); }
    .layout-row select { width: 100%; padding: 0.2rem 0.3rem; border: 1px solid var(--pink-300); border-radius: 3px; background: var(--pink-200); color: var(--purple-900); font-size: 0.65rem; box-sizing: border-box; }
    @media (max-width: 950px) { .layout-groups-inner { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 750px) { .layout-groups-inner { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 480px) { .layout-groups-inner { grid-template-columns: 1fr; } }

    .presets-section { }
    .presets-save { display: flex; gap: var(--spacing-sm); align-items: end; margin-bottom: var(--spacing-sm); }
    .presets-save label { font-size: var(--font-size-sm); color: var(--purple-700); flex: 1; }
    .presets-save input[type="text"] { width: 100%; padding: var(--input-padding); border: var(--input-border-width) solid var(--pink-300); border-radius: var(--input-radius); background: var(--pink-100); color: var(--purple-900); font-size: var(--font-size-sm); box-sizing: border-box; }
    .presets-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: var(--spacing-sm); }
    .preset-card { background: var(--pink-100); border: var(--input-border-width) solid var(--pink-300); border-radius: var(--border-radius); padding: var(--spacing-sm); }
    .preset-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--spacing-xs); }
    .preset-card-name { font-weight: bold; font-size: var(--font-size-sm); color: var(--purple-800); }
    .preset-card-scope { font-size: var(--font-size-xs); color: var(--purple-700); background: var(--pink-200); padding: 0.1rem 0.4rem; border-radius: var(--border-radius-sm); }
    .preset-card-date { font-size: var(--font-size-xs); color: var(--purple-700); margin-bottom: var(--spacing-xs); }
    .preset-card-swatches { display: flex; gap: 3px; margin-bottom: var(--spacing-xs); flex-wrap: wrap; }
    .preset-card-swatches span { width: 16px; height: 16px; border-radius: 3px; border: 1px solid var(--pink-400); display: inline-block; }
    .preset-card-actions { display: flex; gap: var(--spacing-xs); }
    .preset-card-actions .btn { padding: var(--spacing-xs) var(--spacing-sm); font-size: var(--font-size-xs); }
    .preset-empty { font-size: var(--font-size-sm); color: var(--purple-700); font-style: italic; padding: var(--spacing-sm) 0; }
    .save-toast { position: fixed; bottom: 1.5rem; right: 1.5rem; background: var(--purple-500); color: #fff; padding: var(--spacing-sm) var(--spacing-md); border-radius: var(--border-radius); font-size: var(--font-size-sm); box-shadow: 0 4px 12px rgba(0,0,0,0.25); z-index: 9999; opacity: 0; transform: translateY(10px); transition: opacity 0.25s, transform 0.25s; pointer-events: none; }
    .save-toast.visible { opacity: 1; transform: translateY(0); }
    .save-overlay { position: fixed; inset: 0; background: rgba(59,7,100,0.55); z-index: 10000; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.2s; }
    .save-overlay.visible { opacity: 1; pointer-events: auto; }
    .save-overlay-box { background: var(--pink-100); border: 1px solid var(--pink-400); border-radius: var(--border-radius-lg); padding: var(--spacing-lg) var(--spacing-xl); min-width: 320px; text-align: center; box-shadow: 0 8px 32px rgba(59,7,100,0.3); }
    .save-overlay-box p { margin: 0 0 var(--spacing-sm); color: var(--purple-800); font-size: var(--font-size-sm); }
    .save-progress-track { width: 100%; height: 8px; background: var(--pink-300); border-radius: 4px; overflow: hidden; }
    .save-progress-bar { height: 100%; width: 0%; background: linear-gradient(90deg, var(--purple-500), var(--pink-400)); border-radius: 4px; transition: width 0.15s ease; }
    .save-progress-pct { margin-top: var(--spacing-xs); font-size: var(--font-size-xs); color: var(--purple-700); }
</style>


<form method="post" action="<?= url('/admin/theme') ?>" enctype="multipart/form-data" id="theme-form">
    <?= csrf_field() ?>
    <input type="hidden" name="scope" id="theme-scope" value="site">

    <div class="theme-editor">
        <div class="theme-preview-label">Live preview</div>
        <div id="theme-preview">
            <div data-mock="site">
                <div class="pv-site-header">
                    <img src="<?= e(\App\Models\Theme::titleImageUrl($scope)) ?>" alt="">
                </div>
                <nav class="pv-nav">
                    <span class="pv-brand">Galleries</span>
                    <a href="#">Home</a>
                    <a href="#">Categories</a>
                    <span class="chip"><a href="#">All</a></span>
                    <span class="chip"><a href="#">Images</a></span>
                    <span class="chip"><a href="#">Videos</a></span>
                    <span class="chip filter-inactive"><a href="#">Inactive</a></span>
                </nav>
                <div class="pv-site-layout">
                    <div class="pv-sidebar">
                        <div class="pv-sidebar-box">
                            <h5>Menu</h5>
                            <a class="pv-sidebar-link active" href="#">Galleries</a>
                            <a class="pv-sidebar-link" href="#">Membership</a>
                            <a class="pv-sidebar-link" href="#">Settings</a>
                        </div>
                        <div class="pv-sidebar-box">
                            <h5>Favorites</h5>
                            <a class="pv-sidebar-link" href="#">Nature</a>
                            <a class="pv-sidebar-link" href="#">Travel</a>
                            <a class="pv-sidebar-link" href="#">Portraits</a>
                        </div>
                    </div>
                    <div class="pv-site-main">
                        <input type="text" placeholder="Search galleries..." style="max-width: 320px;">
                        <div style="margin: var(--spacing-sm) 0; padding: var(--card-padding); background: var(--pink-200); border-left: 4px solid var(--purple-500); border-radius: var(--border-radius-sm); font-size: var(--font-size-sm); color: var(--purple-900);">Success! Gallery created.</div>
                        <div class="pv-grid">
                            <div class="pv-card"><div class="pv-thumb">Thumb</div><div class="pv-card-body"><h4>Gallery title</h4><p>12 photos &middot; 340 views</p><a href="#" style="font-size: var(--font-size-xs);">View details &rarr;</a></div></div>
                            <div class="pv-card"><div class="pv-thumb">Thumb</div><div class="pv-card-body"><h4>Gallery title</h4><p>8 photos &middot; 190 views</p><a href="#" style="font-size: var(--font-size-xs);">View details &rarr;</a></div></div>
                            <div class="pv-card"><div class="pv-thumb">Thumb</div><div class="pv-card-body"><h4>Gallery title</h4><p>21 photos &middot; 512 views</p><a href="#" style="font-size: var(--font-size-xs);">View details &rarr;</a></div></div>
                        </div>
                        <div class="pv-actions"><a class="btn" href="#">View Gallery</a><a class="btn btn-danger" href="#">Delete</a></div>
                        <div style="margin-top: var(--spacing-sm); font-size: var(--font-size-xs);"><a href="#">Show more galleries</a> &middot; <a href="#">View all categories</a></div>
                    </div>
                </div>
            </div>

            <div data-mock="admin" hidden>
                <div class="pv-admin">
                    <div class="pv-nav-col">
                        <b>Admin</b>
                        <span class="on">Dashboard</span>
                        <span>Galleries</span>
                        <span>Categories</span>
                        <span>Users</span>
                        <span>Theme</span>
                        <span>Logs</span>
                    </div>
                    <div>
                        <h4 class="pv-admin-heading">Dashboard</h4>
                        <div class="pv-stat-row">
                            <div class="pv-stat"><b>1,204</b><small>Total views</small></div>
                            <div class="pv-stat"><b>86</b><small>Photos</small></div>
                            <div class="pv-stat"><b>12</b><small>Galleries</small></div>
                        </div>
                        <div class="pv-flash">Theme updated successfully.</div>
                        <table>
                            <thead><tr><th>Gallery</th><th>Photos</th><th>Views</th></tr></thead>
                            <tbody>
                                <tr><td><a href="#">Travel photos</a></td><td>12</td><td>340</td></tr>
                                <tr><td><a href="#">Evening walk</a></td><td>8</td><td>190</td></tr>
                            </tbody>
                        </table>
                        <h5 class="pv-promo-heading">Promotion codes</h5>
                        <div class="pv-promo-row">
                            <div class="pv-promo-card"><strong>Summer upgrade offer</strong><span class="pv-promo-code">SALE-A1B2C3</span><small>20% off · Applies to Gold</small></div>
                            <div class="pv-promo-card"><strong>Welcome discount</strong><span class="pv-promo-code">SALE-D4E5F6</span><small>$5 off · Applies to Silver</small></div>
                        </div>
                        <div style="margin-top: var(--spacing-sm); font-size: var(--font-size-xs);"><a href="#">View all galleries</a> &middot; <a href="#">Manage categories</a></div>
                        <div style="margin-top: var(--spacing-xs);"><span class="chip active" style="font-size:var(--font-size-xs)">Active filter</span> <span class="chip filter-inactive" style="font-size:var(--font-size-xs)">Inactive filter</span></div>
                        <div class="pv-actions"><a class="btn" href="#">Create Gallery</a><a class="btn btn-outline" href="#">Manage Users</a></div>
                    </div>
                    </div>
                </div>
            </div>
        </div>

        <?php foreach ($scopes as $scope => $info): ?>
            <?php $palette = $scope === 'admin' ? $adminTheme : $siteTheme; ?>
            <?php $layout = $scope === 'admin' ? $adminLayout : $siteLayout; ?>
            <div data-scope-panel="<?= e($scope) ?>" <?= $scope === 'site' ? '' : 'hidden' ?>>
                <div class="theme-palettes">

                    <details class="layout-section">
                        <summary><h3>Layout &amp; Typography</h3></summary>
                        <div class="layout-groups-inner">
                            <?php foreach ($layoutSections as $secKey => $secLabel): ?>
                                <?php if (!empty($layoutBySection[$secKey])): ?>
                                    <div class="layout-group">
                                        <h3><?= e($secLabel) ?></h3>
                                        <?php foreach ($layoutBySection[$secKey] as $key => $meta): ?>
                                            <div class="layout-row">
                                                <label for="layout-<?= e($scope) ?>-<?= e($key) ?>"><?= e($meta['label']) ?></label>
                                                <input type="text" name="<?= e($scope) ?>-<?= e($key) ?>" id="layout-<?= e($scope) ?>-<?= e($key) ?>" value="<?= e($layout[$key] ?? $meta['value']) ?>">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </details>

                    <?php
                    $paletteSections = [
                        'Pink palette'   => $pinkKeys,
                        'Purple palette' => $purpleKeys,
                        'Button colors'  => $buttonKeys,
                        'Sidebar colors' => $sidebarKeys,
                        'Gallery card colors' => $cardKeys,
                        'Statistic cards' => $statKeys,
                        'Table colors' => $tableKeys,
                        'Promotion code cards' => $promotionKeys,
                    ];
                    $firstOpen = false;
                    ?>
                    <?php foreach ($paletteSections as $secName => $secKeys): ?>
                        <details class="theme-palette">
                            <summary><h2><?= e($secName) ?></h2></summary>
                            <div class="theme-grid"<?= in_array($secName, ['Button colors', 'Sidebar colors', 'Gallery card colors']) ? ' style="grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));"' : '' ?>>
                                <?php foreach ($secKeys as $key): ?>
                                    <div class="theme-swatch">
                                         <span class="theme-name"><?= e($key) ?></span>
                                         <span class="theme-description"><?= e($descriptions[$key]) ?></span>
                                         <div class="cpick">
                                             <input type="hidden" name="<?= e($scope) ?>-<?= e($key) ?>" value="<?= e($palette[$key] ?? '') ?>" data-cpick-hidden>
                                             <div class="cpick-trigger" data-cpick>
<span class="cpick-swatch" style="background:<?= e($palette[$key] ?? '') ?>"></span>
                                                  <span class="cpick-hex"><?= e($palette[$key] ?? '') ?></span>
                                                 <span class="cpick-arrow">&#9660;</span>
                                             </div>
                                            <div class="cpick-dropdown">
                                                <div class="cpick-dragbar" data-cpick-drag>Color picker</div>
                                                <div class="cpick-wheel-wrap" data-cpick-wheel>
                                                    <canvas width="200" height="200"></canvas>
                                                    <div class="cpick-wheel-ind" data-cpick-wheel-ind></div>
                                                </div>
                                                <div class="cpick-brightness-wrap" data-cpick-bright>
                                                    <canvas width="200" height="16"></canvas>
                                                    <div class="cpick-brightness-ind" data-cpick-bright-ind></div>
                                                </div>
                                                <div class="cpick-contrast-wrap" data-cpick-contrast>
                                                    <canvas width="200" height="16"></canvas>
                                                    <div class="cpick-contrast-ind" data-cpick-contrast-ind></div>
                                                </div>
                                                <div class="cpick-preview-row">
                                                    <div class="cpick-preview-swatch" data-cpick-preview></div>
                                                    <span class="cpick-hsb-label" data-cpick-hsb></span>
                                                </div>
                                                <div class="cpick-custom">
                                                     <input type="text" data-cpick-hex value="<?= e($palette[$key] ?? '') ?>" maxlength="7" placeholder="#hex">
                                                     <button type="button" class="cpick-go" data-cpick-go>Set</button>
                                                     <button type="button" class="cpick-cancel" data-cpick-cancel>Cancel</button>
                                                 </div>
                                                <button type="button" class="cpick-palette-toggle" data-cpick-toggle>Quick palette</button>
                                                <div class="cpick-palette" data-cpick-palette style="display:none">
                                                    <?php foreach ($paletteGrid as $c): ?>
                                                        <button type="button" class="cpick-opt<?= ($palette[$key] ?? '') === $c ? ' selected' : '' ?>" data-hex="<?= e($c) ?>" style="background:<?= e($c) ?>" title="<?= e($c) ?>"></button>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php $firstOpen = false; ?>
                    <?php endforeach; ?>

                    <details class="theme-palette">
                        <summary><h2>Title image</h2></summary>
                        <div style="padding: var(--spacing-md);">
                            <p style="margin: 0 0 var(--spacing-sm); font-size: var(--font-size-sm); color: var(--purple-700);">Replace the header title image shown on all site and admin pages.</p>
                            <?php
                            $currentTitleImage = \App\Models\Theme::titleImage($scope);
                            $isDefault = $currentTitleImage === '/assets/images/AmethystTitleImage.png';
                            ?>
                            <div style="display: flex; align-items: center; gap: var(--spacing-md); margin-bottom: var(--spacing-sm);">
                                <img src="<?= e(\App\Models\Theme::titleImageUrl($scope)) ?>" alt="Current title image" style="max-height: 60px; background: var(--pink-200); padding: var(--spacing-xs) var(--spacing-sm); border-radius: var(--border-radius);">
                                <div>
                                    <div style="font-size: var(--font-size-xs); color: var(--purple-700);"><?= $isDefault ? 'Default image' : e($currentTitleImage) ?></div>
                                    <?php if (!$isDefault): ?>
                                        <label style="display: inline-flex; align-items: center; gap: var(--spacing-xs); margin-top: var(--spacing-xs); font-size: var(--font-size-xs); color: var(--purple-700); cursor: pointer;">
                                            <input type="checkbox" name="reset_title_image" value="1"> Reset to default
                                        </label>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <label style="display: block; font-size: var(--font-size-sm); color: var(--purple-700);">Upload new image
                                <input type="file" name="title_image" accept="image/*" style="display: block; margin-top: var(--spacing-xs);">
                            </label>
                        </div>
                    </details>

                </div>
            </div>
        <?php endforeach; ?>

        <details class="theme-palette" data-saved-themes>
            <summary><h2>Saved Themes</h2></summary>
            <div class="presets-section">
                <div class="presets-actions" style="display: flex; gap: var(--spacing-sm); flex-wrap: wrap; margin-bottom: var(--spacing-md);">
                    <button type="button" class="btn btn-outline" id="theme-reset-colors">Reset colors</button>
                    <button type="button" class="btn btn-outline" id="theme-reset-layout">Reset layout</button>
                    <button type="button" class="btn" id="theme-save-btn">Save Theme</button>
                </div>
                <div id="preset-save-form" data-action="<?= url('/admin/theme/presets/save') ?>" style="margin-bottom: var(--spacing-sm);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="scope" value="site">
                    <div class="presets-save">
                        <label>Theme name <input type="text" name="preset_name" placeholder="Optional — leave empty to update loaded theme"></label>
                        <button type="button" class="btn" data-preset-save>Save as Preset</button>
                    </div>
                </div>
                <?php
                $presetColors = [];
                foreach ($presets as $p) {
                    $full = \App\Models\Theme::loadPreset($p['slug']);
                    if ($full) {
                        $presetColors[$p['slug']] = ['colors' => $full['colors'] ?? [], 'layout' => $full['layout'] ?? [], 'title_image' => $full['title_image'] ?? '', 'name' => $p['name'] ?? '', 'scope' => $p['scope'] ?? 'site'];
                    }
                }
                ?>
                <script>var presetData = <?= json_encode($presetColors) ?>;</script>
                <?php if (empty($presets)): ?>
                    <div class="preset-empty">No saved themes yet. Save a preset above or use Save Theme to save directly.</div>
                <?php else: ?>
                    <div class="presets-grid">
                        <?php foreach ($presets as $p): ?>
                            <?php $full = \App\Models\Theme::loadPreset($p['slug']); $swatches = $full ? array_slice($full['colors'] ?? [], 0, 10) : []; ?>
                            <div class="preset-card" data-preset-scope="<?= e($p['scope'] ?? 'site') ?>">
                                <div class="preset-card-header"><span class="preset-card-name"><?= e($p['name']) ?></span><span class="preset-card-scope"><?= ($p['scope'] ?? 'site') === 'admin' ? 'Admin theme' : 'User theme' ?></span></div>
                                <div class="preset-card-date"><?= e($p['created_at']) ?></div>
                                <?php if (!empty($swatches)): ?><div class="preset-card-swatches"><?php foreach ($swatches as $hex): ?><span style="background: <?= e($hex) ?>;" title="<?= e($hex) ?>"></span><?php endforeach; ?></div><?php endif; ?>
                                <div class="preset-card-actions">
                                    <button type="button" class="btn btn-outline" onclick="loadPreset('<?= e($p['slug']) ?>')">Load</button>
                                    <button type="button" class="btn preset-action" data-preset-action="apply" data-preset-slug="<?= e($p['slug']) ?>">Apply</button>
                                    <button type="button" class="btn btn-danger btn-sm preset-action" data-preset-action="delete" data-preset-slug="<?= e($p['slug']) ?>">Delete</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </details>

        </div>
</form>

<div class="save-toast" id="save-toast">Theme saved</div>
<div class="save-overlay" id="save-overlay">
    <div class="save-overlay-box">
        <p id="save-overlay-msg">Saving theme...</p>
        <div class="save-progress-track"><div class="save-progress-bar" id="save-progress-bar"></div></div>
        <div class="save-progress-pct" id="save-progress-pct">0%</div>
    </div>
</div>


<script>
(function () {
    var preview    = document.getElementById('theme-preview');
    var scopeInput = document.getElementById('theme-scope');
    var colorDefs  = <?= json_encode($defaults) ?>;
    var layoutDefs = <?= json_encode(array_map(function($m) { return $m['value']; }, $layoutDefaults)) ?>;

    /* ── Scope panel switching ── */

    var loadedPresetSlug = null;

    function showScope(scope) {
        scopeInput.value = scope;
        var panels = document.querySelectorAll('[data-scope-panel]');
        for (var i = 0; i < panels.length; i++) {
            var p = panels[i];
            p.hidden = p.getAttribute('data-scope-panel') !== scope;
        }
        var siteMock = preview.querySelector('[data-mock="site"]');
        var adminMock = preview.querySelector('[data-mock="admin"]');
        if (siteMock) siteMock.hidden = scope !== 'site';
        if (adminMock) adminMock.hidden = scope !== 'admin';
        var si = document.getElementById('preset-save-form');
        if (si) {
            var sc = si.querySelector('input[name="scope"]');
            if (sc) sc.value = scope;
        }
        loadedPresetSlug = null;
        document.querySelectorAll('.theme-tab').forEach(function(t) {
            var on = t.getAttribute('data-scope') === scope;
            t.classList.toggle('active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('.preset-card').forEach(function(c) {
            c.classList.toggle('active', c.getAttribute('data-preset-scope') === scope);
        });
    }

    showScope('site');

    window.switchThemeScope = showScope;

    /* ── HSB/RGB/Hex conversion ── */

    function hsbToRgb(h, s, v) {
        h = ((h % 360) + 360) % 360;
        s = Math.max(0, Math.min(1, s));
        v = Math.max(0, Math.min(1, v));
        var c = v * s, x = c * (1 - Math.abs(((h / 60) % 2) - 1)), m = v - c;
        var r, g, b;
        if (h < 60)       { r = c; g = x; b = 0; }
        else if (h < 120) { r = x; g = c; b = 0; }
        else if (h < 180) { r = 0; g = c; b = x; }
        else if (h < 240) { r = 0; g = x; b = c; }
        else if (h < 300) { r = x; g = 0; b = c; }
        else              { r = c; g = 0; b = x; }
        return [Math.round((r + m) * 255), Math.round((g + m) * 255), Math.round((b + m) * 255)];
    }

    function rgbToHsb(r, g, b) {
        r /= 255; g /= 255; b /= 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b);
        var d = max - min, h = 0, s = max === 0 ? 0 : d / max, v = max;
        if (d !== 0) {
            if (max === r) h = ((g - b) / d + (g < b ? 6 : 0)) * 60;
            else if (max === g) h = ((b - r) / d + 2) * 60;
            else h = ((r - g) / d + 4) * 60;
        }
        return [Math.round(h), Math.round(s * 100), Math.round(v * 100)];
    }

    function rgbToHex(r, g, b) {
        return '#' + [r, g, b].map(function (c) { return c.toString(16).padStart(2, '0'); }).join('');
    }

    function hexToRgb(hex) {
        hex = hex.replace(/^#/, '');
        if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
        if (!/^[0-9a-fA-F]{6}$/.test(hex)) return null;
        return [parseInt(hex.slice(0,2),16), parseInt(hex.slice(2,4),16), parseInt(hex.slice(4,6),16)];
    }

    /* ── Color wheel rendering ── */

    function drawWheel(canvas) {
        var ctx = canvas.getContext('2d');
        var w = canvas.width, h = canvas.height, cx = w / 2, cy = h / 2, r = Math.min(cx, cy) - 1;
        var img = ctx.createImageData(w, h);
        var data = img.data;
        for (var y = 0; y < h; y++) {
            for (var x = 0; x < w; x++) {
                var dx = x - cx, dy = y - cy;
                var dist = Math.sqrt(dx * dx + dy * dy);
                var idx = (y * w + x) * 4;
                if (dist <= r) {
                    var angle = (Math.atan2(dy, dx) * 180 / Math.PI + 360) % 360;
                    var sat = dist / r;
                    var rgb = hsbToRgb(angle, sat, 1);
                    data[idx] = rgb[0]; data[idx+1] = rgb[1]; data[idx+2] = rgb[2]; data[idx+3] = 255;
                } else {
                    data[idx] = 0; data[idx+1] = 0; data[idx+2] = 0; data[idx+3] = 0;
                }
            }
        }
        ctx.putImageData(img, 0, 0);
    }

    function drawBrightness(canvas, h, s) {
        var ctx = canvas.getContext('2d');
        var w = canvas.width, bh = canvas.height;
        var grad = ctx.createLinearGradient(0, 0, w, 0);
        grad.addColorStop(0, 'rgb(0,0,0)');
        var rgb = hsbToRgb(h, s, 1);
        grad.addColorStop(1, 'rgb(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ')');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, w, bh);
    }

    function contrastRgb(rgb, contrast) {
        var factor = contrast / 50;
        return rgb.map(function (value) {
            return Math.max(0, Math.min(255, Math.round((value - 128) * factor + 128)));
        });
    }

    function drawContrast(canvas, rgb) {
        var ctx = canvas.getContext('2d');
        var grad = ctx.createLinearGradient(0, 0, canvas.width, 0);
        grad.addColorStop(0, 'rgb(128,128,128)');
        grad.addColorStop(0.5, 'rgb(' + rgb.join(',') + ')');
        var high = contrastRgb(rgb, 100);
        grad.addColorStop(1, 'rgb(' + high.join(',') + ')');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, canvas.width, canvas.height);
    }

    /* ── Update indicators and preview from HSB state ── */

    function updateWheelInd(cpick, h, s) {
        var wrap = cpick.querySelector('[data-cpick-wheel]');
        var ind = cpick.querySelector('[data-cpick-wheel-ind]');
        var r = wrap.offsetWidth / 2;
        var rad = h * Math.PI / 180;
        var dist = s / 100 * r;
        ind.style.left = (r + Math.cos(rad) * dist) + 'px';
        ind.style.top = (r + Math.sin(rad) * dist) + 'px';
    }

    function updateBrightInd(cpick, v) {
        var wrap = cpick.querySelector('[data-cpick-bright]');
        var ind = cpick.querySelector('[data-cpick-bright-ind]');
        ind.style.left = (v / 100 * wrap.offsetWidth) + 'px';
    }

    function updateContrastInd(cpick, contrast) {
        var wrap = cpick.querySelector('[data-cpick-contrast]');
        var ind = cpick.querySelector('[data-cpick-contrast-ind]');
        ind.style.left = (contrast / 100 * wrap.offsetWidth) + 'px';
    }

    function applyHsbToCpick(cpick, h, s, v, contrast) {
        var rgb = contrastRgb(hsbToRgb(h, s / 100, v / 100), contrast);
        var hex = rgbToHex(rgb[0], rgb[1], rgb[2]);
        cpick._hue = h;
        cpick._saturation = s;
        cpick._contrast = contrast;
        setCpickColor(cpick, hex, true);
        updateWheelInd(cpick, h, s);
        updateBrightInd(cpick, v);
        var brightCanvas = cpick.querySelector('[data-cpick-bright] canvas');
        if (brightCanvas) drawBrightness(brightCanvas, h, s / 100);
        var contrastCanvas = cpick.querySelector('[data-cpick-contrast] canvas');
        if (contrastCanvas) drawContrast(contrastCanvas, hsbToRgb(h, s / 100, v / 100));
        updateContrastInd(cpick, contrast);
        var prevSwatch = cpick.querySelector('[data-cpick-preview]');
        if (prevSwatch) prevSwatch.style.background = hex;
        var hsbLabel = cpick.querySelector('[data-cpick-hsb]');
        if (hsbLabel) hsbLabel.textContent = 'H:' + h + ' S:' + s + '% B:' + v + '%';
    }

    function getHsbFromCpick(cpick) {
        var hidden = cpick.querySelector('input[type="hidden"]');
        var rgb = hexToRgb(hidden.value || '#000000');
        if (!rgb) return [0, 100, 100];
        var hsb = rgbToHsb(rgb[0], rgb[1], rgb[2]);
        // Grayscale colors have no hue in RGB, so retain the last hue chosen
        // on the wheel instead of jumping back to red when saturation is 0.
        if (hsb[1] === 0 && typeof cpick._hue === 'number') {
            hsb[0] = cpick._hue;
            if (typeof cpick._saturation === 'number') hsb[1] = cpick._saturation;
        }
        return hsb;
    }
    function keyOf(el) { return el.name.replace(/^[^-]+-/, ''); }

    function setVar(el, target) {
        var t = target || preview.querySelector('[data-mock="' + scopeInput.value + '"]');
        if (t) t.style.setProperty('--' + keyOf(el), el.value);
    }

    function applyScopeToMock(scope) {
        var mock = preview.querySelector('[data-mock="' + scope + '"]');
        if (!mock) return;
        var prefix = scope + '-';
        document.querySelectorAll('[data-scope-panel="' + scope + '"] input[type="hidden"], [data-scope-panel="' + scope + '"] input[type="text"], [data-scope-panel="' + scope + '"] select').forEach(function (el) {
            if (el.name && el.name.indexOf(prefix) === 0) {
                mock.style.setProperty('--' + keyOf(el), el.value);
            }
        });
    }

    applyScopeToMock('site');
    applyScopeToMock('admin');

    /* ── Save/restore details state ── */

    function getOpenDetails() {
        var open = [];
        document.querySelectorAll('[data-scope-panel]:not([hidden]) details[open] > summary').forEach(function (s) {
            open.push(s.textContent.trim());
        });
        return open;
    }

    function restoreDetails(openList) {
        document.querySelectorAll('[data-scope-panel]:not([hidden]) details > summary').forEach(function (s) {
            s.parentElement.open = openList.indexOf(s.textContent.trim()) !== -1;
        });
    }

    function showToast(msg) {
        var t = document.getElementById('save-toast');
        if (!t) return;
        t.textContent = msg || 'Theme saved';
        t.classList.add('visible');
        clearTimeout(t._timer);
        t._timer = setTimeout(function () { t.classList.remove('visible'); }, 2200);
    }

    /* ── AJAX form save (with progress bar + reload) ── */

    var mainFormEl = document.getElementById('theme-form');
    var saveBtn    = document.getElementById('theme-save-btn');
    var overlay    = document.getElementById('save-overlay');
    var bar        = document.getElementById('save-progress-bar');
    var pctLabel   = document.getElementById('save-progress-pct');
    var overlayMsg = document.getElementById('save-overlay-msg');

    function showOverlay(msg, pct) {
        if (overlayMsg) overlayMsg.textContent = msg || 'Saving...';
        if (bar) bar.style.width = (pct || 0) + '%';
        if (pctLabel) pctLabel.textContent = Math.round(pct || 0) + '%';
        if (overlay) overlay.classList.add('visible');
    }
    function hideOverlay() { if (overlay) overlay.classList.remove('visible'); }
    function setProgress(pct) {
        if (bar) bar.style.width = pct + '%';
        if (pctLabel) pctLabel.textContent = Math.round(pct) + '%';
    }

    if (mainFormEl && saveBtn) {
        saveBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var scope = scopeInput.value;

            showOverlay('Saving theme...', 0);

            var fd = new FormData(mainFormEl);
            fd.set('scope', scope);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', mainFormEl.action, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            if (xhr.upload) {
                xhr.upload.onprogress = function (ev) {
                    if (ev.lengthComputable) setProgress(Math.round(ev.loaded / ev.total * 90));
                };
            }
            xhr.onload = function () {
                setProgress(100);
                if (overlayMsg) overlayMsg.textContent = 'Applying changes...';
                setTimeout(function () { window.location.reload(); }, 300);
            };
            xhr.onerror = function () {
                hideOverlay();
                showToast('Save failed');
            };
            xhr.send(fd);
        });
    }

    /* ── Live layout input updates ── */

    document.querySelectorAll('.layout-row input[type="text"], .layout-row select').forEach(function (el) {
        el.addEventListener('input', function () { setVar(el); });
        el.addEventListener('change', function () { setVar(el); });
    });

    /* ── Custom color picker ── */

    function setCpickColor(cpick, hex, preserveExtreme) {
        var hidden  = cpick.querySelector('input[type="hidden"]');
        var swatch  = cpick.querySelector('.cpick-swatch');
        var hexDisp = cpick.querySelector('.cpick-hex');
        var hexIn   = cpick.querySelector('[data-cpick-hex]');

        if (!hex.match(/^#[0-9a-fA-F]{6}$/)) return;
        hex = hex.toLowerCase();

        hidden.value = hex;
        setVar(hidden);
        swatch.style.background = hex;
        hexDisp.textContent = hex;
        if (hexIn) hexIn.value = hex;

        cpick.querySelectorAll('.cpick-opt').forEach(function (o) {
            o.classList.toggle('selected', o.dataset.hex === hex);
        });

        var prevSwatch = cpick.querySelector('[data-cpick-preview]');
        if (prevSwatch) prevSwatch.style.background = hex;

        var rgb = hexToRgb(hex);
        if (rgb) {
            var hsb = rgbToHsb(rgb[0], rgb[1], rgb[2]);
            if (hsb[1] > 0 || !preserveExtreme) {
                cpick._hue = hsb[0];
                cpick._saturation = hsb[1];
            }
            else if (typeof cpick._hue === 'number') hsb[0] = cpick._hue;
            if (typeof cpick._saturation === 'number' && hsb[1] === 0) hsb[1] = cpick._saturation;
            var hsbLabel = cpick.querySelector('[data-cpick-hsb]');
            if (hsbLabel) hsbLabel.textContent = 'H:' + hsb[0] + ' S:' + hsb[1] + '% B:' + hsb[2] + '%';
        }

        setVar(hidden);
    }

    document.querySelectorAll('.cpick').forEach(function (cpick) {
        var trigger   = cpick.querySelector('[data-cpick]');
        var dropdown  = cpick.querySelector('.cpick-dropdown');
        var hexInput  = cpick.querySelector('[data-cpick-hex]');
        var goBtn     = cpick.querySelector('[data-cpick-go]');
        var wheelWrap = cpick.querySelector('[data-cpick-wheel]');
        var brightWrap = cpick.querySelector('[data-cpick-bright]');
        var contrastWrap = cpick.querySelector('[data-cpick-contrast]');
        var dragbar = cpick.querySelector('[data-cpick-drag]');
        var paletteDiv = cpick.querySelector('[data-cpick-palette]');
        var toggleBtn = cpick.querySelector('[data-cpick-toggle]');
        var wheelInited = false;
        var dragging = null; // 'wheel' or 'bright'
        var moving = null;

        function positionPopup() {
            var triggerRect = trigger.getBoundingClientRect();
            var popupRect = dropdown.getBoundingClientRect();
            var gap = 6;
            var left = triggerRect.left;
            var top = triggerRect.bottom + gap;

            if (left + popupRect.width > window.innerWidth - 8) {
                left = window.innerWidth - popupRect.width - 8;
            }
            if (left < 8) left = 8;
            if (top + popupRect.height > window.innerHeight - 8 && triggerRect.top > popupRect.height + gap) {
                top = triggerRect.top - popupRect.height - gap;
            }
            if (top < 8) top = 8;

            dropdown.style.left = left + 'px';
            dropdown.style.top = top + 'px';
        }

        function initWheel() {
            if (wheelInited) return;
            wheelInited = true;
            var wheelCanvas = wheelWrap.querySelector('canvas');
            var brightCanvas = brightWrap.querySelector('canvas');
            var contrastCanvas = contrastWrap.querySelector('canvas');
            drawWheel(wheelCanvas);
            var hsb = getHsbFromCpick(cpick);
            if (typeof cpick._hue !== 'number') cpick._hue = hsb[0];
            if (typeof cpick._saturation !== 'number') cpick._saturation = hsb[1];
            cpick._contrast = 50;
            drawBrightness(brightCanvas, hsb[0], hsb[1] / 100);
            drawContrast(contrastCanvas, hsbToRgb(hsb[0], hsb[1] / 100, hsb[2] / 100));
            updateWheelInd(cpick, hsb[0], hsb[1]);
            updateBrightInd(cpick, hsb[2]);
            updateContrastInd(cpick, cpick._contrast);
        }

        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            var wasOpen = cpick.classList.contains('cpick-open');
            document.querySelectorAll('.cpick-open').forEach(function (c) { c.classList.remove('cpick-open'); });
            if (!wasOpen) {
                var hInput = cpick.querySelector('input[type="hidden"]');
                cpick._originalColor = hInput ? hInput.value : null;
                cpick.classList.add('cpick-open');
                initWheel();
                positionPopup();
                var hsb = getHsbFromCpick(cpick);
                var brightCanvas = brightWrap.querySelector('canvas');
                drawBrightness(brightCanvas, hsb[0], hsb[1] / 100);
                var contrastCanvas = contrastWrap.querySelector('canvas');
                drawContrast(contrastCanvas, hsbToRgb(hsb[0], hsb[1] / 100, hsb[2] / 100));
            }
        });

        dragbar.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var rect = dropdown.getBoundingClientRect();
            moving = { pointerId: e.pointerId, x: e.clientX, y: e.clientY, left: rect.left, top: rect.top };
            dragbar.setPointerCapture(e.pointerId);
        });

        dragbar.addEventListener('pointermove', function (e) {
            if (!moving || moving.pointerId !== e.pointerId) return;
            var left = moving.left + e.clientX - moving.x;
            var top = moving.top + e.clientY - moving.y;
            var maxLeft = window.innerWidth - dropdown.offsetWidth - 8;
            var maxTop = window.innerHeight - dropdown.offsetHeight - 8;
            dropdown.style.left = Math.max(8, Math.min(left, maxLeft)) + 'px';
            dropdown.style.top = Math.max(8, Math.min(top, maxTop)) + 'px';
        });

        dragbar.addEventListener('pointerup', function (e) {
            if (moving && moving.pointerId === e.pointerId) moving = null;
        });
        dragbar.addEventListener('pointercancel', function () { moving = null; });
        dragbar.addEventListener('click', function (e) { e.stopPropagation(); });

        function handleWheelMouse(e) {
            var rect = wheelWrap.getBoundingClientRect();
            var cx = rect.width / 2, cy = rect.height / 2;
            var x = e.clientX - rect.left, y = e.clientY - rect.top;
            var dx = x - cx, dy = y - cy;
            var r = Math.min(cx, cy) - 1;
            var dist = Math.sqrt(dx * dx + dy * dy);
            if (dist > r) { dist = r; }
            var angle = (Math.atan2(dy, dx) * 180 / Math.PI + 360) % 360;
            var sat = Math.round(dist / r * 100);
            var hsb = getHsbFromCpick(cpick);
            applyHsbToCpick(cpick, Math.round(angle), sat, hsb[2], cpick._contrast || 50);
        }

        function handleBrightMouse(e) {
            var rect = brightWrap.getBoundingClientRect();
            var x = e.clientX - rect.left;
            var v = Math.round(Math.max(0, Math.min(1, x / rect.width)) * 100);
            var hsb = getHsbFromCpick(cpick);
            applyHsbToCpick(cpick, hsb[0], hsb[1], v, cpick._contrast || 50);
        }

        function handleContrastMouse(e) {
            var rect = contrastWrap.getBoundingClientRect();
            var x = e.clientX - rect.left;
            var contrast = Math.round(Math.max(0, Math.min(1, x / rect.width)) * 100);
            var hsb = getHsbFromCpick(cpick);
            applyHsbToCpick(cpick, hsb[0], hsb[1], hsb[2], contrast);
        }

        function beginPointer(kind, element, e, handler) {
            e.preventDefault(); e.stopPropagation();
            dragging = kind;
            element.setPointerCapture(e.pointerId);
            handler(e);
        }

        wheelWrap.addEventListener('pointerdown', function (e) { beginPointer('wheel', wheelWrap, e, handleWheelMouse); });
        brightWrap.addEventListener('pointerdown', function (e) { beginPointer('bright', brightWrap, e, handleBrightMouse); });
        contrastWrap.addEventListener('pointerdown', function (e) { beginPointer('contrast', contrastWrap, e, handleContrastMouse); });

        wheelWrap.addEventListener('pointermove', function (e) { if (dragging === 'wheel') handleWheelMouse(e); });
        brightWrap.addEventListener('pointermove', function (e) { if (dragging === 'bright') handleBrightMouse(e); });
        contrastWrap.addEventListener('pointermove', function (e) { if (dragging === 'contrast') handleContrastMouse(e); });
        document.addEventListener('pointerup', function () { dragging = null; });
        document.addEventListener('pointercancel', function () { dragging = null; });

        cpick.querySelectorAll('.cpick-opt').forEach(function (opt) {
            opt.addEventListener('click', function (e) {
                e.stopPropagation();
                setCpickColor(cpick, opt.dataset.hex);
                var hsb = getHsbFromCpick(cpick);
                initWheel();
                updateWheelInd(cpick, hsb[0], hsb[1]);
                updateBrightInd(cpick, hsb[2]);
                var brightCanvas = brightWrap.querySelector('canvas');
                drawBrightness(brightCanvas, hsb[0], hsb[1] / 100);
                var contrastCanvas = contrastWrap.querySelector('canvas');
                drawContrast(contrastCanvas, hsbToRgb(hsb[0], hsb[1] / 100, hsb[2] / 100));
                updateContrastInd(cpick, cpick._contrast || 50);
            });
        });

        function applyHex() {
            var v = hexInput.value.trim();
            if (v.charAt(0) !== '#') v = '#' + v;
            setCpickColor(cpick, v);
            var hsb = getHsbFromCpick(cpick);
            if (wheelInited) {
                updateWheelInd(cpick, hsb[0], hsb[1]);
                updateBrightInd(cpick, hsb[2]);
                var brightCanvas = brightWrap.querySelector('canvas');
                drawBrightness(brightCanvas, hsb[0], hsb[1] / 100);
            }
        }

        hexInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); applyHex(); }
        });
        hexInput.addEventListener('input', function () { applyHex(); });
        goBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            applyHex();
            cpick.classList.remove('cpick-open');
            document.querySelectorAll('.cpick-open').forEach(function (c) { c.classList.remove('cpick-open'); });
        });
        var cancelBtn = cpick.querySelector('[data-cpick-cancel]');
        cancelBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (cpick._originalColor) {
                setCpickColor(cpick, cpick._originalColor);
                var hsb = getHsbFromCpick(cpick);
                if (wheelInited) {
                    updateWheelInd(cpick, hsb[0], hsb[1]);
                    updateBrightInd(cpick, hsb[2]);
                    var brightCanvas = brightWrap.querySelector('canvas');
                    drawBrightness(brightCanvas, hsb[0], hsb[1] / 100);
                }
            }
            cpick.classList.remove('cpick-open');
        });

        if (toggleBtn && paletteDiv) {
            toggleBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = paletteDiv.style.display !== 'none';
                paletteDiv.style.display = open ? 'none' : 'grid';
                toggleBtn.textContent = open ? 'Quick palette' : 'Hide palette';
            });
        }
    });

    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('.cpick')) return;
        document.querySelectorAll('.cpick-open').forEach(function (c) { c.classList.remove('cpick-open'); });
    });

    /* ── Reset buttons ── */

    document.getElementById('theme-reset-colors').addEventListener('click', function () {
        loadedPresetSlug = null;
        var scope = scopeInput.value;
        document.querySelectorAll('[data-scope-panel="' + scope + '"] .cpick').forEach(function (cpick) {
            var hidden = cpick.querySelector('input[type="hidden"]');
            var key = keyOf(hidden);
            if (colorDefs[key]) setCpickColor(cpick, colorDefs[key]);
        });
    });

    document.getElementById('theme-reset-layout').addEventListener('click', function () {
        loadedPresetSlug = null;
        var scope = scopeInput.value;
        document.querySelectorAll('[data-scope-panel="' + scope + '"] input[type="text"], [data-scope-panel="' + scope + '"] select').forEach(function (el) {
            el.value = layoutDefs[keyOf(el)] || '';
            setVar(el);
        });
    });

    /* ── Preset load ── */

    window.loadPreset = function (slug) {
        var data = presetData[slug];
        if (!data) return;

        var scope = data.scope || scopeInput.value;
        if (scopeInput.value !== scope) showScope(scope);
        var prefix = scope + '-';

        if (data.colors) {
            Object.keys(colorDefs).forEach(function (key) {
                var cpick = document.querySelector('[data-scope-panel="' + scope + '"] .cpick input[name="' + prefix + key + '"]');
                if (cpick) setCpickColor(cpick.closest('.cpick'), data.colors[key] || colorDefs[key]);
            });
        }

        if (data.layout) {
            Object.keys(layoutDefs).forEach(function (key) {
                var el = document.querySelector('[data-scope-panel="' + scope + '"] [name="' + prefix + key + '"]');
                if (el) { el.value = data.layout[key] || layoutDefs[key]; setVar(el); }
            });
        }

        loadedPresetSlug = slug;
        var nameInput = presetForm ? presetForm.querySelector('[name="preset_name"]') : null;
        if (nameInput) nameInput.value = data.name || slug;
    };

    /* ── Preset save (captures live state via JS) ── */

    var presetForm = document.getElementById('preset-save-form');
    if (presetForm) {
        function collectState() {
            var scope = scopeInput.value;
            var prefix = scope + '-';
            var colors = {};
            var layout = {};

            document.querySelectorAll('[data-scope-panel="' + scope + '"] .cpick input[type="hidden"]').forEach(function (el) {
                colors[keyOf(el)] = el.value;
            });

            document.querySelectorAll('[data-scope-panel="' + scope + '"] input[type="text"], [data-scope-panel="' + scope + '"] select').forEach(function (el) {
                if (el.name && el.name.indexOf(prefix) === 0) layout[keyOf(el)] = el.value;
            });

            return { scope: scope, colors: colors, layout: layout };
        }

        function savePreset() {
            var state = collectState();
            var nameInput = presetForm.querySelector('[name="preset_name"]');
            var name = nameInput ? nameInput.value.trim() : '';

            var fd = new FormData();
            presetForm.querySelectorAll('[name]').forEach(function (el) {
                fd.append(el.name, el.value);
            });
            fd.set('scope', state.scope);
            fd.append('colors', JSON.stringify(state.colors));
            fd.append('layout', JSON.stringify(state.layout));

            if (name) {
                fd.set('preset_name', name);
                fetch(presetForm.dataset.action, { method: 'POST', body: fd })
                    .then(function () { window.location.reload(); });
            } else if (loadedPresetSlug) {
                fd.set('preset_name', loadedPresetSlug);
                fetch(presetForm.dataset.action, { method: 'POST', body: fd })
                    .then(function () { window.location.reload(); });
            } else {
                var mainFd = new FormData(mainFormEl);
                mainFd.set('scope', state.scope);
                Object.keys(state.colors).forEach(function (k) { mainFd.set(state.scope + '-' + k, state.colors[k]); });
                Object.keys(state.layout).forEach(function (k) { mainFd.set(state.scope + '-' + k, state.layout[k]); });
                var xhr = new XMLHttpRequest();
                xhr.open('POST', mainFormEl.action, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onload = function () {
                    applyScopeToMock('site');
                    applyScopeToMock('admin');
                    showToast('Theme saved');
                };
                xhr.send(mainFd);
            }
        }

        var saveButton = presetForm.querySelector('[data-preset-save]');
        if (saveButton) saveButton.addEventListener('click', savePreset);

        document.querySelectorAll('[data-preset-action]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (button.dataset.presetAction === 'delete' && !window.confirm('Delete this theme?')) return;
                var card = button.closest('.preset-card');
                var presetScope = card ? card.getAttribute('data-preset-scope') : scopeInput.value;
                var form = document.createElement('form');
                form.method = 'post';
                form.action = button.dataset.presetAction === 'apply'
                    ? '<?= url('/admin/theme/presets/apply') ?>'
                    : '<?= url('/admin/theme/presets/delete') ?>';
                [['_token', document.querySelector('#theme-form input[name="_token"]').value],
                 ['preset_slug', button.dataset.presetSlug],
                 ['scope', presetScope]].forEach(function (pair) {
                    var input = document.createElement('input');
                    input.type = 'hidden'; input.name = pair[0]; input.value = pair[1];
                    form.appendChild(input);
                });
                document.body.appendChild(form);
                form.submit();
            });
        });
    }
})();
</script>
