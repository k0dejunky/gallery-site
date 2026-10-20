<?php

namespace App\Core;

/**
 * The shared admin list component.
 *
 * Every admin listing used to hand-roll its own toolbar, filter form, bulk
 * bar, pager and "no rows" message. They had drifted: two different pagers
 * (one in users.php, one in error_logs.php, an AJAX one in auto_poster.php),
 * three different empty states, and filter forms that dropped the other
 * filters when you changed one of them.
 *
 * This class owns the parts that are the same everywhere:
 *
 *   sortLink()    a sortable column header
 *   pageWindow()  the window of page numbers around the current page
 *   pageUrl()     a page link that keeps the current filters
 *   pager()       the pager markup
 *   filterForm()  a search box plus named selects that keep their values
 *   bulkBar()     the "with selected:" action bar
 *   shell()       the whole card: head, filters, bulk bar, table, pager, empty state
 *
 * The shell is deliberately string-building: a view supplies the cell markup
 * (the only part that genuinely differs per page) and the component supplies
 * everything around it. The cell markup can be passed as a template
 * closure, so a view never has to concatenate HTML by hand.
 */
class AdminTable
{
    /**
     * A window of page numbers around the current page, with ellipses.
     * Returns ints for real pages and '…' for the gaps.
     *
     * @return array<int, int|string>
     */
    public static function pageWindow(int $current, int $pages, int $radius = 2): array
    {
        if ($pages < 1) {
            return [];
        }

        $current = max(1, min($current, $pages));
        $window  = [];

        for ($p = 1; $p <= $pages; $p++) {
            if ($p === 1 || $p === $pages || abs($p - $current) <= $radius) {
                $window[] = $p;
                continue;
            }
            $last = end($window);
            if ($last !== false && $p - (int) $last > 1) {
                $window[] = '…';
            }
        }

        return $window;
    }

    /**
     * A link to another page of the same listing, keeping the current filters.
     */
    public static function pageUrl(string $base, array $params, int $page): string
    {
        $query = array_filter($params, static fn($v) => $v !== null && $v !== '');
        $query['page'] = $page;
        $sep = strpos($base, '?') !== false ? '&' : '?';

        return $base . $sep . http_build_query($query);
    }

    /**
     * A sortable column header. Clicking the column that is already sorted
     * flips the direction; any other column starts ascending.
     */
    public static function sortLink(
        string $column,
        string $label,
        string $sortBy = '',
        string $sortDir = '',
        array $params = [],
        string $base = ''
    ): string {
        $direction = ($sortBy === $column && strtoupper($sortDir) === 'ASC') ? 'DESC' : 'ASC';

        $query = array_filter($params, static fn($v) => $v !== null && $v !== '');
        $query['sort'] = $column;
        $query['dir']  = $direction;

        $href = $base . '?' . http_build_query($query);

        $arrow = '';
        if ($sortBy === $column) {
            $arrow = strtoupper($sortDir) === 'ASC' ? ' ▲' : ' ▼';
        }

        return '<a href="' . e($href) . '">' . e($label) . $arrow . '</a>';
    }

    /**
     * The pager. One implementation for every admin listing.
     *
     * @param array{base?:string,page?:int,pages?:int,params?:array,label?:string} $spec
     */
    public static function pager(array $spec): string
    {
        $base   = (string) ($spec['base'] ?? '');
        $page   = max(1, (int) ($spec['page'] ?? 1));
        $pages  = max(0, (int) ($spec['pages'] ?? 0));
        $params = (array) ($spec['params'] ?? []);
        $label  = (string) ($spec['label'] ?? 'Pagination');

        if ($base === '' || $pages < 2) {
            return '';
        }

        $out = '<nav class="adm-pager" aria-label="' . e($label) . '">';

        if ($page > 1) {
            $out .= '<a class="adm-pager-step" href="' . e(self::pageUrl($base, $params, $page - 1)) . '" rel="prev">&larr; Prev</a>';
        }

        foreach (self::pageWindow($page, $pages) as $p) {
            if ($p === '…') {
                $out .= '<span class="adm-pager-gap" aria-hidden="true">&hellip;</span>';
                continue;
            }
            if ((int) $p === $page) {
                $out .= '<span class="current" aria-current="page" aria-label="Page ' . (int) $p . '">' . (int) $p . '</span>';
                continue;
            }
            $out .= '<a href="' . e(self::pageUrl($base, $params, (int) $p)) . '" aria-label="Page ' . (int) $p . '">' . (int) $p . '</a>';
        }

        if ($page < $pages) {
            $out .= '<a class="adm-pager-step" href="' . e(self::pageUrl($base, $params, $page + 1)) . '" rel="next">Next &rarr;</a>';
        }

        $out .= '<span class="adm-pager-count">Page ' . $page . ' of ' . $pages . '</span>';
        $out .= '</nav>';

        return $out;
    }

    /**
     * The filter form: a search box and any number of named selects.
     *
     * The selects keep their current value, and the form carries the other
     * filters as hidden fields so changing one filter does not clear the
     * others (the old hand-rolled forms did exactly that).
     *
     * @param array{
     *   action?:string, method?:string, search?:array{name?:string,value?:string,placeholder?:string},
     *   filters?:array<int,array{name:string,label:string,options:array,value?:string}>,
     *   hidden?:array, actions?:string, autosubmit?:bool
     * } $spec
     */
    public static function filterForm(array $spec): string
    {
        $action  = (string) ($spec['action'] ?? '');
        $method  = strtoupper((string) ($spec['method'] ?? 'GET'));
        $search  = (array) ($spec['search'] ?? []);
        $filters = (array) ($spec['filters'] ?? []);
        $hidden  = (array) ($spec['hidden'] ?? []);
        $actions = (string) ($spec['actions'] ?? '');

        if ($action === '' || ($search === [] && $filters === [] && $actions === '')) {
            return '';
        }

        $out = '<form class="adm-list-filters" method="' . e($method) . '" action="' . e($action) . '">';

        foreach ($hidden as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $out .= '<input type="hidden" name="' . e((string) $name) . '" value="' . e((string) $value) . '">';
        }

        if ($search !== []) {
            $out .= '<div class="adm-field adm-field-search">';
            $out .= '<label for="adm-search"><span>Search</span></label>';
            $out .= '<input type="search" id="adm-search" name="' . e((string) ($search['name'] ?? 'q')) . '" value="'
                . e((string) ($search['value'] ?? '')) . '" placeholder="'
                . e((string) ($search['placeholder'] ?? 'Search…')) . '">';
            $out .= '</div>';
        }

        foreach ($filters as $filter) {
            $name = (string) ($filter['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out .= '<div class="adm-field">';
            $out .= '<label for="adm-filter-' . e($name) . '"><span>' . e((string) ($filter['label'] ?? $name)) . '</span></label>';
            $out .= '<select id="adm-filter-' . e($name) . '" name="' . e($name) . '"'
                . (!empty($filter['autosubmit']) ? ' data-autosubmit' : '') . '>';
            $current = (string) ($filter['value'] ?? '');
            foreach ((array) ($filter['options'] ?? []) as $value => $label) {
                $out .= '<option value="' . e((string) $value) . '"' . ((string) $value === $current ? ' selected' : '') . '>'
                    . e((string) $label) . '</option>';
            }
            $out .= '</select></div>';
        }

        if ($actions !== '') {
            $out .= '<div class="adm-field-actions">' . $actions . '</div>';
        }

        $out .= '</form>';

        return $out;
    }

    /**
     * The bulk-action bar: the controls that act on the selected rows.
     *
     * The bar is only half the story - the row checkboxes live in the table,
     * so the FORM has to wrap both. shell() does that when the bulk spec
     * asks for it ('wrap' => true); on its own this renders the controls
     * only, for a form the view opens itself.
     *
     * @param array{action?:string,label?:string,controls?:string,confirm?:string,method?:string} $spec
     */
    public static function bulkBar(array $spec): string
    {
        $label   = (string) ($spec['label'] ?? 'With selected:');
        $controls = (string) ($spec['controls'] ?? '');

        if ($controls === '') {
            return '';
        }

        $out = '<div class="adm-bulk">';
        $out .= '<strong>' . e($label) . '</strong>';
        $out .= '<div class="adm-bulk-controls">' . $controls . '</div>';
        $out .= '</div>';

        return $out;
    }

    /**
     * The opening tag of a bulk-action form. The closing tag is a plain
     * </form>, so a view can use it around markup the component does not
     * generate.
     *
     * @param array{action?:string,confirm?:string,method?:string,id?:string} $spec
     */
    public static function bulkFormOpen(array $spec): string
    {
        $action  = (string) ($spec['action'] ?? '');
        $confirm = (string) ($spec['confirm'] ?? '');
        $method  = strtoupper((string) ($spec['method'] ?? 'POST'));
        $id      = (string) ($spec['id'] ?? '');

        if ($action === '') {
            return '';
        }

        return '<form' . ($id !== '' ? ' id="' . e($id) . '"' : '')
            . ' class="adm-bulk-form" method="' . e($method) . '" action="' . e($action) . '"'
            . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
            . csrf_field();
    }

    /**
     * The empty state.
     */
    public static function emptyState(string $message, string $action = ''): string
    {
        $out = '<div class="adm-empty"><p>' . e($message) . '</p>';
        if ($action !== '') {
            $out .= $action;
        }
        $out .= '</div>';

        return $out;
    }

    /**
     * The whole card.
     *
     * @param array{
     *   title?:string, description?:string, count?:int, total?:int, countNote?:string,
     *   filter?:array, bulk?:array, columns?:array, rows?:array, body?:callable|string,
     *   empty?:string, emptyAction?:string, pager?:array, tableClass?:string, head?:string, foot?:string
     * } $spec
     */
    public static function shell(array $spec): string
    {
        $title       = (string) ($spec['title'] ?? '');
        $description = (string) ($spec['description'] ?? '');
        $columns     = (array) ($spec['columns'] ?? []);
        $rows        = (array) ($spec['rows'] ?? []);
        $empty       = (string) ($spec['empty'] ?? 'Nothing to show.');
        $emptyAction = (string) ($spec['emptyAction'] ?? '');
        $tableClass  = trim('adm-list-table ' . (string) ($spec['tableClass'] ?? ''));

        $body = $spec['body'] ?? null;
        if (is_callable($body)) {
            ob_start();
            $body();
            $body = (string) ob_get_clean();
        }
        $body = (string) $body;

        $out = '<section class="adm-list">';

        if ($title !== '' || $description !== '' || isset($spec['count'])) {
            $out .= '<div class="adm-list-head">';
            if ($title !== '') {
                $out .= '<h2 class="adm-list-title">' . e($title) . '</h2>';
            }
            if (isset($spec['count'])) {
                $out .= '<p class="adm-list-count">' . self::countLine(
                    (int) $spec['count'],
                    isset($spec['total']) ? (int) $spec['total'] : (int) $spec['count'],
                    (string) ($spec['countNote'] ?? '')
                ) . '</p>';
            }
            if ($description !== '') {
                $out .= '<p class="adm-list-note">' . e($description) . '</p>';
            }
            if (!empty($spec['head'])) {
                $out .= '<div class="adm-list-head-aside">' . $spec['head'] . '</div>';
            }
            $out .= '</div>';
        }

        if (!empty($spec['filter'])) {
            $out .= self::filterForm((array) $spec['filter']);
        }

        // A bulk form wraps the bar AND the table: the row checkboxes live
        // inside the table, so a form that only wrapped the bar would post
        // no ids at all.
        $bulk      = (array) ($spec['bulk'] ?? []);
        $bulkOpen  = '';
        $bulkClose = '';
        if ($bulk !== []) {
            if (!empty($bulk['wrap'])) {
                $bulkOpen  = self::bulkFormOpen($bulk);
                $bulkClose = '</form>';
            }
            $out .= $bulkOpen . self::bulkBar($bulk);
        }

        $hasRows = $body !== '' || $rows !== [];

        if (!$hasRows) {
            $out .= self::emptyState($empty, $emptyAction);
        } else {
            $out .= '<div class="adm-list-scroll"><table class="' . e($tableClass) . '">';

            if ($columns !== []) {
                $out .= '<thead><tr>';
                foreach ($columns as $column) {
                    $label = (string) ($column['label'] ?? '');
                    $class = trim('adm-list-th ' . (string) ($column['class'] ?? ''));
                    $out .= '<th' . ($class !== '' ? ' class="' . e($class) . '"' : '') . '>' . $label . '</th>';
                }
                $out .= '</tr></thead>';
            }

            $out .= '<tbody>';
            if ($body !== '') {
                $out .= $body;
            } else {
                foreach ($rows as $row) {
                    $out .= '<tr>';
                    foreach ((array) $row as $cell) {
                        $out .= '<td>' . $cell . '</td>';
                    }
                    $out .= '</tr>';
                }
            }
            $out .= '</tbody></table></div>';
        }

        $out .= $bulkClose;

        if (!empty($spec['foot'])) {
            $out .= '<div class="adm-list-foot">' . $spec['foot'] . '</div>';
        }

        if (!empty($spec['pager'])) {
            $pager = self::pager((array) $spec['pager']);
            if ($pager !== '') {
                $out .= '<div class="adm-list-foot">' . $pager . '</div>';
            }
        }

        $out .= '</section>';

        return $out;
    }

    /**
     * "Showing 20 of 130 users (5 suspended)".
     */
    public static function countLine(int $shown, int $total, string $note = ''): string
    {
        $out = 'Showing <strong>' . number_format($shown) . '</strong> of <strong>' . number_format($total) . '</strong>';
        if ($note !== '') {
            $out .= ' (' . e($note) . ')';
        }

        return $out;
    }
}
