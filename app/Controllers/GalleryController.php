<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\ImageEditor;
use App\Models\AuditLog;
use App\Models\AutoPostQueue;
use App\Models\Category;
use App\Models\FavoriteCategory;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Stats;

class GalleryController extends Controller
{
    /**
     * The logged-in user's home page. Builds one section per favourite
     * category (deduplicating galleries that span several favourites) plus a
     * search/type-filtered paginator. Empty favourite sections are dropped
     * so only categories with results appear in the sidebar navigation.
     */
    /**
     * Clean /images and /videos listing URLs: redirect to the filtered
     * gallery listing (?type=), preserving any search/category/sort filters.
     */
    public function indexType(): void
    {
        $type = str_contains((string) $this->request->uri(), '/videos') ? 'videos' : 'images';

        $query = $_GET;
        $query['type'] = $type;

        $this->redirect('/galleries' . ($query ? '?' . http_build_query($query) : ''));
    }

    /**
     * AJAX fragment for the player's in-page gallery browser: a compact grid
     * of video gallery cards. No layout — raw HTML for fetch() to insert.
     * Supports ?q= search and ?offset= paging (30 at a time) so the browser
     * is not limited to the newest galleries.
     */
    public function browseGalleries(): void
    {
        Auth::requireLogin();

        $q      = trim((string) $this->request->query('q', ''));
        $offset = max(0, (int) $this->request->query('offset', 0));
        $limit  = 30;

        // Only video galleries make sense to pick from on the video player.
        $where  = \App\Models\Gallery::publishedVisibleSql('g') . ' AND g.is_secret = 0 AND g.type = \'videos\'';
        $params = [];
        if ($q !== '') {
            $where  .= ' AND (g.title LIKE ? OR g.description LIKE ?)';
            $params = ['%' . $q . '%', '%' . $q . '%'];
        }

        $total = (int) \App\Core\Database::run(
            'SELECT COUNT(*) FROM galleries g WHERE ' . $where,
            $params
        )->fetchColumn();

        $galleries = \App\Core\Database::run(
            'SELECT g.*, (SELECT COUNT(*) FROM gallery_photo gp WHERE gp.gallery_id = g.id) AS photo_count
             FROM galleries g
             WHERE ' . $where . '
             ORDER BY g.created_at DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        )->fetchAll();

        $covers = \App\Models\Gallery::firstPhotos(array_map('intval', array_column($galleries, 'id')));

        header('Content-Type: text/html; charset=utf-8');
        foreach ($galleries as $g) {
            $gallery = $g;
            $cover = $covers[(int) $g['id']] ?? null;
            require __DIR__ . '/../../views/partials/browse_gallery_card.php';
        }
        if ($offset + count($galleries) < $total) {
            echo '<button type="button" class="btn btn-sm btn-outline pb-more" data-offset="' . ($offset + $limit) . '" data-q="' . e($q) . '" style="margin:.5rem auto;display:block;">Load more galleries</button>';
        }
        exit;
    }

    /**
     * AJAX fragment for the player's in-page browser: a gallery's video tiles.
     */
    public function browseGallery(int $id): void
    {
        Auth::requireLogin();

        $gallery = Gallery::findPublic($id, (int) Auth::user()['id']);
        if ($gallery === null) {
            $this->notFound();
            return;
        }
        if (empty($gallery['is_secret'])) {
            Auth::requireGalleryLevel(
                (int) ($gallery['min_level'] ?? 0),
                'This gallery needs a ' . \App\Models\Subscription::levelLabel((int) ($gallery['min_level'] ?? 0)) . ' membership to view.'
            );
        }

        $photos = \App\Core\Database::run(
            'SELECT p.* FROM photos p
             JOIN gallery_photo gp ON gp.photo_id = p.id
             WHERE gp.gallery_id = ? AND p.is_video = 1
             ORDER BY gp.position ASC, p.id ASC
             LIMIT 60',
            [$id]
        )->fetchAll();

        header('Content-Type: text/html; charset=utf-8');
        if ($photos === []) {
            echo '<p class="muted" style="padding:1rem;">No videos in this gallery.</p>';
            exit;
        }
        foreach ($photos as $photo) {
            require __DIR__ . '/../../views/partials/browse_video_tile.php';
        }
        exit;
    }

    /**
     * Searching/browsing galleries is allowed without a membership, but
     * opening an individual gallery still requires one (show()).
     * The site editor loads a public, non-personalized preview in an
     * iframe. Normal gallery browsing still requires authentication.
     */
    public function index(): void
    {
        // Searching/browsing galleries is allowed without a membership, but
        // opening an individual gallery still requires one (show()).
        // The site editor loads a public, non-personalized preview in an
        // iframe. Normal gallery browsing still requires authentication.
        $siteEditorPreview = $this->request->query('se', '') === 'user';
        if (!$siteEditorPreview) Auth::requireLogin();

        $page  = (int) $this->request->query('page', 1);
        $q     = trim((string) $this->request->query('q', ''));
        $catId = (int) $this->request->query('category', 0);
        $type  = in_array($this->request->query('type', ''), ['images', 'videos'], true)
            ? (string) $this->request->query('type')
            : '';
        $sort  = in_array($this->request->query('sort', ''), ['newest', 'views', 'title'], true)
            ? (string) $this->request->query('sort')
            : '';

        $user      = Auth::user();
        $isMember  = Auth::hasActiveSubscription();
        $maxLevel  = Auth::effectiveLevel();
        $favorites = $user !== null && $isMember ? FavoriteCategory::forUser((int) $user['id']) : [];

        // Full listing: every gallery grouped under its category — never
        // restricted to favourites and never paginated. A gallery appears
        // once, under its first category (alphabetical order); untagged
        // galleries land in a catch-all "Uncategorized" section. Search
        // still uses the paginated results grid below.
        // The listing is cached by the gallery generation bucket, which is
        // bumped on every gallery/media write, so it never goes stale. The
        // cache key is per-type and per-access-class (not per user) so the
        // common no-secret case is shared across all sessions.
        $sections   = [];
        $seen       = [];
        $categories = Category::all();

        if ($q === '') {
            $listingKey = 'home:' . $type . ':level' . ($maxLevel >= PHP_INT_MAX ? 'all' : (string) $maxLevel)
                . ($user !== null ? ':u' . (int) $user['id'] : '');

            $listingJson = \App\Core\Cache::rememberGen(
                'gallery',
                $listingKey,
                \App\Models\ServerOptimizations::cacheTtl('listing'),
                static function () use ($categories, $type, $maxLevel, $user): string {
                    $sections = [];
                    $seen     = [];
                    $byCategory = Gallery::inCategories(array_column($categories, 'id'), $type, $maxLevel, $user !== null ? (int) $user['id'] : 0);

                    foreach ($categories as $cat) {
                        $galleries = [];

                        foreach (($byCategory[(int) $cat['id']] ?? []) as $gallery) {
                            if (isset($seen[(int) $gallery['id']])) {
                                continue;
                            }

                            $seen[(int) $gallery['id']] = true;
                            $galleries[]                = $gallery;
                        }

                        if ($galleries !== []) {
                            $sections[] = ['category' => $cat, 'galleries' => $galleries];
                        }
                    }

                    $uncategorized = array_values(array_filter(
                        Gallery::withoutCategory($type, $maxLevel, $user !== null ? (int) $user['id'] : 0),
                        static fn (array $gallery): bool => !isset($seen[(int) $gallery['id']])
                    ));

                    if ($uncategorized !== []) {
                        $sections[] = ['category' => ['id' => 0, 'name' => 'Uncategorized', 'slug' => ''], 'galleries' => $uncategorized];
                    }

                    return json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
                }
            );

            $sections = json_decode($listingJson, true) ?: [];

            foreach ($sections as $section) {
                foreach ($section['galleries'] as $g) {
                    $seen[(int) $g['id']] = true;
                }
            }

            if ($sort !== '') {
                foreach ($sections as &$section) {
                    usort($section['galleries'], static function (array $a, array $b) use ($sort): int {
                        if ($sort === 'title') {
                            return strcasecmp((string) $a['title'], (string) $b['title']);
                        }

                        if ($sort === 'views') {
                            return ((int) ($b['unique_views'] ?? 0) <=> (int) ($a['unique_views'] ?? 0))
                                ?: ((int) ($b['views'] ?? 0) <=> (int) ($a['views'] ?? 0));
                        }

                        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
                    });
                }
                unset($section);
            }
        }

        $filters = ['q' => $q, 'max_level' => $maxLevel];
        if ($user !== null) {
            $filters['user_id'] = (int) $user['id'];
        }
        if ($catId > 0) {
            $filters['category'] = $catId;
        }
        if ($type !== '') {
            $filters['type'] = $type;
        }
        if ($sort !== '') {
            $filters['sort'] = $sort;
        }

        // The paginated grid is only used for search results; the full
        // listing above already covers the browse path, so skip the two
        // paginator queries (COUNT + SELECT) unless actually needed.
        $paginator = ($q !== '')
            ? Gallery::paginate($page, 24, $filters)
            : ['items' => [], 'total' => 0, 'page' => $page, 'pages' => 1];

        // A search that found nothing is tracked as a missed search.
        if ($q !== '' && (int) $paginator['total'] === 0 && $user !== null) {
            Stats::recordMissedSearch($q, (int) $user['id']);
        }

        $viewedIds = [];
        $recentlyViewed = [];
        $favoriteGalleryIds = [];
        $favoriteGalleries = [];
        if ($user !== null && $isMember) {
            $allGalleryIds = [];
            foreach ($sections as $section) {
                foreach ($section['galleries'] as $g) {
                    $allGalleryIds[] = (int) $g['id'];
                }
            }
            if ($q !== '') {
                foreach ($paginator['items'] as $g) {
                    $allGalleryIds[] = (int) $g['id'];
                }
            }
            $allGalleryIds = array_unique($allGalleryIds);
            if ($allGalleryIds) {
                $viewedIds = Gallery::viewedByIds((int) $user['id'], $allGalleryIds);
            }
            $recentlyViewed = Gallery::recentlyViewed((int) $user['id'], 8);
            $favoriteGalleryIds = Gallery::favoriteIds((int) $user['id'], $allGalleryIds);
            $favoriteGalleries = Gallery::favoriteGalleries((int) $user['id'], 8);
        }

        $this->view('gallery/index', [
            'paginator'     => $q !== '' ? $paginator : null,
            'categories'    => $categories,
            'favorites'     => $favorites,
            'navCategories' => $favorites,
            'sections'      => $sections,
            'q'             => $q,
            'categoryId'    => $catId,
            'type'          => $type,
            'currentUser'   => $user,
            'hasActive'     => $isMember,
            'sidebarNav'    => true,
            'cardCovers'    => $this->preloadCardData($sections, $q !== '' ? $paginator : null),
            'sort'          => $sort,
            'viewedIds'     => $viewedIds,
            'recentlyViewed' => $recentlyViewed,
            'favoriteGalleryIds' => $favoriteGalleryIds,
            'favoriteGalleries' => $favoriteGalleries,
            'recommended'  => $user !== null ? \App\Models\Gallery::recommended((int) $user['id'], $viewedIds, 6) : [],
        ]);
    }

    /**
     * A single category page: image galleries and video galleries each in
     * their own section, optionally narrowed by a search query scoped to the
     * category. An optional ?type= filter shows only one of the two sections.
     */
    public function category(string $slug): void
    {
        // Category listings (including their scoped search) are browsable
        // without a membership, matching the gallery search page.
        Auth::requireLogin();

        $category = Category::findBySlug($slug);

        if ($category === null) {
            $this->notFound();
            return;
        }

        $user = Auth::user();

        if ($user !== null) {
            Stats::recordCategoryView((int) $category['id'], (int) $user['id']);
        }

        $page = (int) $this->request->query('page', 1);
        $q    = trim((string) $this->request->query('q', ''));
        $type = in_array($this->request->query('type', ''), ['images', 'videos'], true)
            ? (string) $this->request->query('type')
            : '';
        $sort = in_array($this->request->query('sort', ''), ['newest', 'views', 'title'], true)
            ? (string) $this->request->query('sort')
            : '';

        $filters       = ['q' => $q, 'category' => (int) $category['id'], 'max_level' => Auth::effectiveLevel(), 'user_id' => (int) $user['id']];
        if ($sort !== '') {
            $filters['sort'] = $sort;
        }
        $imagePaginator = [];
        $videoPaginator = [];

        if ($type === '' || $type === 'images') {
            $imagePaginator = Gallery::paginate($page, 6, array_merge($filters, ['type' => 'images']));
        }

        if ($type === '' || $type === 'videos') {
            $videoPaginator = Gallery::paginate($page, 6, array_merge($filters, ['type' => 'videos']));
        }

        $this->view('gallery/category', [
            'category'       => $category,
            'imagePaginator' => $imagePaginator,
            'videoPaginator' => $videoPaginator,
            'q'              => $q,
            'type'           => $type,
            'sort'           => $sort,
            'currentUser'    => $user,
            'hasActive'      => Auth::hasActiveSubscription(),
            'cardCovers'     => $this->preloadCardData([], $imagePaginator, $videoPaginator),
            'viewedIds'      => $user !== null ? Gallery::viewedByIds(
                (int) $user['id'],
                array_merge(
                    array_map('intval', array_column($imagePaginator['items'] ?? [], 'id')),
                    array_map('intval', array_column($videoPaginator['items'] ?? [], 'id'))
                )
            ) : [],
            'recentlyViewed' => $user !== null ? Gallery::recentlyViewed((int) $user['id'], 8) : [],
            'favoriteGalleryIds' => ($user !== null && Auth::hasActiveSubscription())
                ? Gallery::favoriteIds((int) $user['id'], array_merge(
                    array_column($imagePaginator['items'] ?? [], 'id'),
                    array_column($videoPaginator['items'] ?? [], 'id')
                )) : [],
            'favoriteGalleries' => ($user !== null && Auth::hasActiveSubscription())
                ? Gallery::favoriteGalleries((int) $user['id'], 8) : [],
            'sidebarNav'     => true,
            'navCategories'  => FavoriteCategory::forUser((int) ($user['id'] ?? 0)),
        ]);
    }

    /**
     * A gallery's photo/video viewer page. Level 0 (free) galleries are open
     * to any logged-in user; higher levels require a matching subscription.
     */
    public function show(int $id): void
    {
        Auth::requireLogin();

        $gallery = Gallery::findPublic($id, (int) Auth::user()['id']);

        if ($gallery === null) {
            $this->notFound();
            return;
        }

        if (empty($gallery['is_secret'])) {
            Auth::requireGalleryLevel(
                (int) ($gallery['min_level'] ?? 0),
                'This gallery needs a ' . \App\Models\Subscription::levelLabel((int) ($gallery['min_level'] ?? 0)) . ' membership to view.'
            );
        }

        $user = Auth::user();

        if ($user !== null) {
            Gallery::recordView($id, (int) $user['id']);

            \App\Models\UserActivity::record(
                (int) $user['id'],
                \App\Models\UserActivity::ACTION_VIEW,
                $id,
                (string) ($gallery['title'] ?? ''),
                $this->request->ip()
            );
        }

        // Paginate the grid so large galleries don't ship every item's markup
        // in the initial response; the remainder loads via "Load more" AJAX.
        $pageSize = max(1, (int) config('app.gallery_page_size', 48));
        $total    = Gallery::photoCount($id);
        $photos   = Gallery::photosSlice($id, $pageSize, 0);

        $this->view('gallery/show', [
            'gallery'    => $gallery,
            'photos'     => $photos,
            'total'      => $total,
            'pageSize'   => $pageSize,
            'categories' => Gallery::categories($id),
            'currentUser' => Auth::user(),
            'photoCount' => $total,
            'returnTo'   => safe_return_to($this->request->query('return_to', '')) ?? url('/galleries/' . $id),
            'collections' => \App\Models\Collection::forUser((int) $user['id']),
        ]);
    }

    /**
     * AJAX: the next page of gallery grid items (HTML fragment) for the
     * gallery viewer's "Load more" UI. Requires the same auth/membership as
     * the gallery itself. Returns plain HTML<figure> items that the front-end
     * appends to #gallery and rebinds.
     */
    public function photosPage(int $id): void
    {
        Auth::requireLogin();

        $gallery = Gallery::findPublic($id, (int) Auth::user()['id']);

        if ($gallery === null) {
            $this->notFound();
            return;
        }

        if (empty($gallery['is_secret'])) {
            Auth::requireGalleryLevel(
                (int) ($gallery['min_level'] ?? 0),
                'This gallery needs a ' . \App\Models\Subscription::levelLabel((int) ($gallery['min_level'] ?? 0)) . ' membership to view.'
            );
        }

        $pageSize = max(1, (int) config('app.gallery_page_size', 48));
        $offset   = max(0, (int) $this->request->query('offset', 0));
        $total    = Gallery::photoCount($id);

        if ($offset >= $total) {
            echo '';
            return;
        }

        $photos  = Gallery::photosSlice($id, $pageSize, $offset);
        $returnTo = safe_return_to($this->request->query('return_to', '')) ?? url('/galleries/' . $id);

        header('Content-Type: text/html; charset=utf-8');
        foreach ($photos as $k => $photo) {
            $idx     = $offset + $k; // global index across all loaded pages
            require __DIR__ . '/../../views/partials/gallery_grid_item.php';
        }
        exit;
    }

    /**
     * Admin: bulk gallery import. A CSV creates gallery shells (title,
     * description, type, min-level, secret, publish schedule, categories);
     * photos are added afterward through the normal upload UI. Supports a
     * dry-run that previews what would be created without writing anything.
     */
    public function galleryImportForm(): void
    {
        Auth::requirePermission('galleries');

        $this->viewAdmin('gallery_import', [
            'categories' => Category::all(),
            'preview'    => [],
            'summary'    => null,
        ]);
    }

    public function galleryImport(): void
    {
        Auth::requirePermission('galleries');
        $dryRun = $this->request->post('dry_run') === '1';
        $file   = $this->request->file('csv');

        if ($file === null || empty($file['tmp_name']) || !is_file($file['tmp_name'])) {
            $this->flash('error', 'Upload a CSV file.');
            $this->redirect('/admin/galleries/import');
        }

        $content = (string) file_get_contents($file['tmp_name']);
        $rows    = self::parseGalleryCsv($content);
        if ($rows === []) {
            $this->flash('error', 'No usable rows found. Expected a header: title,description,type,min_level,is_secret,published_at,categories.');
            $this->redirect('/admin/galleries/import');
        }

        // Category name -> id lookup (existing categories only).
        $cats = Category::all();
        $catIdsByName = [];
        foreach ($cats as $c) {
            $catIdsByName[strtolower((string) $c['name'])] = (int) $c['id'];
        }

        $created = 0;
        $errors  = [];
        $preview = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2; // 1-indexed + header
            $title  = trim((string) ($row['title'] ?? ''));

            if ($title === '') {
                $errors[] = "Row {$rowNum}: missing title.";
                continue;
            }

            $type     = strtolower(trim((string) ($row['type'] ?? ''))) === 'videos' ? 'videos' : 'images';
            $minLevel = max(0, min(3, (int) ($row['min_level'] ?? 0)));
            $isSecret = !empty($row['is_secret']) && (int) $row['is_secret'] === 1;
            $publishedAt = Gallery::normalizePublishAt($row['published_at'] ?? null);

            $categoryIds = [];
            foreach (array_filter(array_map('trim', explode('|', (string) ($row['categories'] ?? '')))) as $name) {
                if (isset($catIdsByName[strtolower($name)])) {
                    $categoryIds[] = $catIdsByName[strtolower($name)];
                }
            }

            if ($dryRun) {
                $preview[] = [
                    'row'       => $rowNum,
                    'title'     => $title,
                    'type'      => $type,
                    'min_level' => $minLevel,
                    'secret'    => $isSecret ? 'yes' : 'no',
                    'published' => $publishedAt ?: '(immediate)',
                    'categories' => count($categoryIds),
                    'ok'        => true,
                ];
                continue;
            }

            try {
                $galleryId = Gallery::create($title, (string) ($row['description'] ?? ''), $type, $minLevel, $publishedAt, $isSecret);
                Gallery::setCategories($galleryId, $categoryIds);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$rowNum}: " . $e->getMessage();
            }
        }

        if ($dryRun) {
            $this->viewAdmin('gallery_import', [
                'categories' => Category::all(),
                'preview'    => $preview,
                'summary'    => ['dry_run' => true, 'would_create' => count($preview), 'errors' => count($errors)],
                'errors'     => $errors,
            ]);
            return;
        }

        $this->flash(
            $errors === [] ? 'success' : ($created > 0 ? 'warning' : 'error'),
            "Imported {$created} gallery" . ($created === 1 ? '' : 'ies') . ($errors !== [] ? ' with ' . count($errors) . ' row error(s).' : '.')
        );
        $this->redirect('/admin/galleries/import');
    }

    /**
     * Parse a gallery CSV into rows. Accepts optional header; columns in
     * order: title, description, type, min_level, is_secret, published_at,
     * categories (pipe-separated).
     */
    private static function parseGalleryCsv(string $content): array
    {
        $lines = preg_split('/\r?\n/', trim($content)) ?: [];
        if ($lines === []) {
            return [];
        }

        $rows   = [];
        $header = false;
        foreach ($lines as $line) {
            $cols = str_getcsv($line);
            if ($cols === []) {
                continue;
            }
            // Skip the header line if the first cell matches a known column.
            if (!$header && isset($cols[0]) && strtolower(trim((string) $cols[0])) === 'title') {
                $header = true;
                continue;
            }
            $rows[] = [
                'title'       => (string) ($cols[0] ?? ''),
                'description' => (string) ($cols[1] ?? ''),
                'type'        => (string) ($cols[2] ?? 'images'),
                'min_level'   => (int) ($cols[3] ?? 0),
                'is_secret'   => (string) ($cols[4] ?? '0'),
                'published_at'=> (string) ($cols[5] ?? ''),
                'categories'  => (string) ($cols[6] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Admin: show the create-gallery form. Staged uploads already in this
     * session's pending area are shown (and can be removed tile-by-tile), so a
     * plain GET never destroys files an admin is mid-way through staging —
     * including files staged on the manage page for another gallery. The
     * abandoned-uploads page covers leftover staging from sessions that ended
     * without saving.
     */
    public function create(): void
    {
        Auth::requirePermission('galleries');

        $this->viewAdmin('create', [
            'categories'   => Category::all(),
            'galleryType'  => 'images',
            'pendingFiles' => $this->pendingListMeta(),
            'queuedGalleries' => Gallery::queuedForPublishing(Auth::isSuperAdmin()),
            'accessUsers' => Auth::isSuperAdmin() ? \App\Models\User::allForGalleryAccess() : [],
        ]);
    }

    /**
     * Admin: persist a new gallery (with its image/video type) and its
     * category assignments.
     */
    public function store(): void
    {
        Auth::requirePermission('galleries');

        $title       = $this->request->input('title');
        $description = $this->request->input('description');
        $type        = $this->request->input('type', 'images') === 'videos' ? 'videos' : 'images';
        $minLevel    = max(0, min(3, (int) $this->request->input('min_level', '0')));
        $isSecret    = Auth::isSuperAdmin() && $this->request->post('is_secret') === '1';
        $allowedUsers = $this->request->post('allowed_users', []);
        $allowedUsers = is_array($allowedUsers) ? $allowedUsers : [];
        $categoryIds = $this->request->post('categories', []);
        $categoryIds = is_array($categoryIds) ? $categoryIds : [];

        $errors = \App\Core\Validator::validate([
            'title'       => $title,
            'min_level'   => $minLevel,
            'categories'  => $categoryIds,
        ], [
            'title'      => 'required|max:255',
            'min_level'  => 'numeric|min:0|max:3',
            'categories' => 'numeric',
        ]);

        if ($errors !== []) {
            $this->flash('error', implode(' ', $errors));
            $this->redirect('/admin/galleries/create');
        }

        // "Add to gallery queue" schedules a future publication; "Save
        // Gallery" publishes immediately. A queue schedule must be a valid
        // future moment, otherwise the gallery is rejected.
        $queue     = (string) $this->request->post('submit_action', 'save') === 'queue';
        $publishedAt = null;

        if ($queue) {
            $publishAtRaw = (string) $this->request->post('publish_at', '');
            if (!Gallery::validPublishSchedule($publishAtRaw)) {
                $this->flash('error', 'A valid future publish date and time is required to add the gallery to the queue.');
                $this->redirect('/admin/galleries/create');
            }
            $publishedAt = Gallery::normalizePublishAt($publishAtRaw);
        }

        $galleryId = Gallery::create($title, $description, $type, $minLevel, $publishedAt, $isSecret);
        Gallery::setCategories($galleryId, $categoryIds);
        if ($isSecret) {
            Gallery::setAllowedUsers($galleryId, $allowedUsers);
        }

        $count = $this->finalizePending($galleryId, $type);

        AuditLog::record((int) Auth::user()['id'], 'create', 'gallery', $galleryId, 'Created gallery "' . $title . '"', null, [
            'title' => $title, 'description' => $description, 'type' => $type,
            'min_level' => $minLevel,
            'categories' => array_map('intval', $categoryIds),
            'photos' => $count,
            'published_at' => $publishedAt,
            'is_secret' => $isSecret,
            'allowed_users' => array_map('intval', $allowedUsers),
        ]);

        // A queued gallery schedules its recommended X + Reddit posts for the
        // publish moment so they go out the moment the gallery goes live.
        // enqueue() normalizes the site-tz picker value to UTC itself.
        if ($queue && $publishedAt !== null) {
            $siteTzValue = str_replace('T', ' ', (string) $this->request->post('publish_at', ''));
            AutoPostQueue::enqueueGalleryPosts($galleryId, $siteTzValue);
            $this->flash('success', 'Gallery created with ' . $count . ' file(s) and added to the gallery queue.');
        } else {
            $this->flash('success', 'Gallery created with ' . $count . ' file(s).');
        }
        $this->redirect('/admin/galleries/' . $galleryId);
    }

    /**
     * Admin: move the current session's staged uploads into an existing
     * gallery. The manage page stages drag-and-dropped files exactly like the
     * create page (same pending endpoints), then commits them here.
     */
    public function commitPending(int $galleryId): void
    {
        Auth::requirePermission('galleries');

        $gallery = Gallery::find($galleryId);

        if ($gallery === null) {
            $this->notFound();
            return;
        }

        if (!empty($gallery['is_secret']) && !Auth::isSuperAdmin()) {
            $this->notFound();
            return;
        }

        $count = $this->finalizePending($galleryId, (string) ($gallery['type'] ?? 'images'));

        AuditLog::record(
            (int) Auth::user()['id'],
            'update',
            'gallery',
            $galleryId,
            'Added ' . $count . ' staged file(s) to gallery "' . $gallery['title'] . '"',
            null,
            ['photos' => $count, 'title' => $gallery['title']]
        );

        $this->flash('success', $count . ' file(s) added to the gallery.');
        $this->redirect('/admin/galleries/' . $galleryId);
    }

    /**
     * Admin: accept an AJAX multi-file upload into this session's pending
     * area so an admin can stage files before naming and saving the gallery.
     * Generates thumbnails/variants immediately and returns the updated list
     * as JSON for the tiled preview.
     */
    public function pendingUpload(): void
    {
        Auth::requirePermission('galleries');

        $files = $this->request->file('photos');
        if ($files === null) {
            $this->json(['ok' => false, 'error' => 'No files selected.']);
            return;
        }

        $type   = $this->request->input('type', 'images') === 'videos' ? 'videos' : 'images';
        $config = config('app.uploads');
        $dir    = $this->pendingDir();

        $list   = $_SESSION['pending_gallery_files'] ?? [];
        $count  = count($files['name']);
        $added  = 0;
        $skipped = [];

        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                // Never silently swallow a failed file — surface it so the
                // admin knows something did not stage.
                $skipped[] = (string) $files['name'][$i];
                continue;
            }

            $meta = \App\Core\MediaUploader::inspect($files, $i, $config, $type);
            if ($meta === null) {
                $this->json(['ok' => false, 'error' => $files['name'][$i] . ': ' . \App\Core\MediaUploader::error()]);
                return;
            }

            // The file's real extension comes from its detected MIME type, so
            // a disguised or oddly-named file is stored with the correct
            // extension (e.g. an image/jpeg named .png becomes .jpg).
            $filename = uniqid('pending_', true) . '.' . $meta['extension'];
            $dest     = $dir . '/' . $filename;

            if (!move_uploaded_file($files['tmp_name'][$i], $dest)) {
                $this->json(['ok' => false, 'error' => $files['name'][$i] . ': could not be saved.']);
                return;
            }

            if (!\App\Core\MediaUploader::generateVariants($dest, $meta['is_image'], $config)) {
                @unlink($dest);
                $this->json(['ok' => false, 'error' => $files['name'][$i] . ': could not generate a preview (file may be corrupt or unsupported).']);
                return;
            }

            // Move moov to the front + build a web-optimized rendition so the
            // browser can start/seek quickly without high bandwidth.
            if (!$meta['is_image']) {
                set_time_limit(0);
                faststart_video_if_needed($dest);
                create_video_web_rendition($dest, $dir . '/web_' . $filename);
            }

            $list[] = [
                'filename' => $filename,
                'original' => $files['name'][$i],
                'hash'     => $meta['hash'],
                'is_image' => $meta['is_image'],
            ];
            $added++;
        }

        $_SESSION['pending_gallery_files'] = $list;

        $this->json([
            'ok' => true,
            'added' => $added,
            'files' => $this->pendingListMeta(),
            'skipped' => $skipped,
        ]);
    }

    /**
     * Admin: accept one resumable-upload chunk (a single file slice) and store
     * it as part-<index> under this session's .chunks/<upload_uid>/ directory.
     * Chunks are written independently so a failed request can simply be
     * retried (overwriting the same part) — this is what makes the upload
     * resumable. The full file is only validated/reassembled in chunkComplete().
     */
    public function chunkUpload(): void
    {
        Auth::requirePermission('galleries');

        $uid   = $this->sanitizeUid((string) $this->request->input('upload_uid'));
        $index = max(0, (int) $this->request->input('chunk_index', '-1'));
        $total = max(1, (int) $this->request->input('total_chunks', '0'));
        $chunk = $this->request->file('chunk');

        if ($uid === '' || $total > 200000 || $index >= $total) {
            $this->json(['ok' => false, 'error' => 'Invalid chunk parameters.']);
            return;
        }

        // Normalise the uploaded chunk: a single "chunk" field arrives in the
        // flat shape (name/tmp_name/error as scalars), while a "chunk[]" field
        // arrives nested. Accept both.
        $tmp   = is_array($chunk['tmp_name'] ?? null) ? ($chunk['tmp_name'][0] ?? null) : ($chunk['tmp_name'] ?? null);
        $err   = is_array($chunk['error'] ?? null) ? ($chunk['error'][0] ?? UPLOAD_ERR_NO_FILE) : ($chunk['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($chunk === null || $tmp === null || $tmp === '' || $err !== UPLOAD_ERR_OK) {
            $this->json(['ok' => false, 'error' => 'Missing chunk data.']);
            return;
        }

        $config = config('app.uploads');

        // Per-chunk ceiling: a resumable upload is sliced into configured-size
        // parts; a single request exceeding the chunk size (anything up to the
        // PHP upload ceiling) is rejected instead of staged, so a scripted
        // session cannot stream arbitrarily large chunks in one request.
        $chunkSize = (int) ($config['chunk_size'] ?? 0);
        $chunkBytes = is_file($tmp) ? (int) filesize($tmp) : 0;
        if ($chunkSize > 0 && $chunkBytes > $chunkSize) {
            $this->json(['ok' => false, 'error' => 'Chunk exceeds the configured chunk size.']);
            return;
        }

        // Per-session staging ceiling: bound how much a single admin session
        // may hold in the pending area (staged files + in-progress chunks) so
        // an abandoned or scripted upload cannot fill the disk before the
        // final assemble-time validation ever runs.
        $stagedLimit = (int) ($config['max_size'] ?? 0) * 3;
        if ($stagedLimit > 0 && $this->pendingStagedBytes() + $chunkBytes > $stagedLimit) {
            $this->json(['ok' => false, 'error' => 'Session upload staging limit reached.']);
            return;
        }

        $parts = $this->chunksDir($uid);
        if (!is_dir($parts) && !@mkdir($parts, 0775, true)) {
            $this->json(['ok' => false, 'error' => 'Could not allocate upload space.']);
            return;
        }

        $partFile = $parts . '/part-' . str_pad((string) $index, 6, '0', STR_PAD_LEFT);

        if (!move_uploaded_file($tmp, $partFile)) {
            $this->json(['ok' => false, 'error' => 'Could not save chunk.']);
            return;
        }

        $this->json(['ok' => true, 'index' => $index]);
    }

    /**
     * Admin: reassemble all uploaded chunks into a single file, then validate,
     * move and variant-generate it exactly like a direct upload. The final
     * result is identical to pendingUpload()'s, so the tiled preview and
     * finalizePending() work unchanged.
     */
    public function chunkComplete(): void
    {
        Auth::requirePermission('galleries');

        $uid          = $this->sanitizeUid((string) $this->request->input('upload_uid'));
        $originalName = (string) $this->request->input('original_name', 'upload');
        $type         = $this->request->input('type', 'images') === 'videos' ? 'videos' : 'images';
        $totalChunks  = (int) $this->request->input('total_chunks', '1');
        $config       = config('app.uploads');
        $dir          = $this->pendingDir();

        if ($uid === '' || $totalChunks < 1 || $totalChunks > 200000) {
            $this->json(['ok' => false, 'error' => 'Invalid upload parameters.']);
            return;
        }

        $assembled = $this->reassembleChunks($uid, $totalChunks);
        if ($assembled === null) {
            $this->json(['ok' => false, 'error' => 'Upload is incomplete. Some chunks are missing; please retry.']);
            return;
        }

        $files = [
            'name'     => [$originalName],
            'tmp_name' => [$assembled],
            'size'     => [(int) filesize($assembled)],
            'error'    => [UPLOAD_ERR_OK],
        ];

        $meta = \App\Core\MediaUploader::inspect($files, 0, $config, $type);

        if ($meta === null) {
            @unlink($assembled);
            $this->removeChunks($uid);
            $this->json(['ok' => false, 'error' => $originalName . ': ' . \App\Core\MediaUploader::error()]);
            return;
        }

        $filename = uniqid('pending_', true) . '.' . $meta['extension'];

        if (!@rename($assembled, $dir . '/' . $filename)) {
            $this->removeChunks($uid);
            $this->json(['ok' => false, 'error' => $originalName . ': could not be saved.']);
            return;
        }

        if (!\App\Core\MediaUploader::generateVariants($dir . '/' . $filename, $meta['is_image'], $config)) {
            @unlink($dir . '/' . $filename);
            $this->removeChunks($uid);
            $this->json(['ok' => false, 'error' => $originalName . ': could not generate a preview (file may be corrupt or unsupported).']);
            return;
        }

        if (!$meta['is_image']) {
            set_time_limit(0);
            faststart_video_if_needed($dir . '/' . $filename);
            create_video_web_rendition($dir . '/' . $filename, $dir . '/web_' . $filename);
        }

        $this->removeChunks($uid);

        $list = $_SESSION['pending_gallery_files'] ?? [];
        $list[] = [
            'filename' => $filename,
            'original' => $originalName,
            'hash'     => $meta['hash'],
            'is_image' => $meta['is_image'],
        ];
        $_SESSION['pending_gallery_files'] = $list;

        $this->json([
            'ok' => true,
            'added' => 1,
            'files' => $this->pendingListMeta(),
        ]);
    }

    /**
     * Admin: discard any partially-uploaded chunks for an upload identifier,
     * used by the client when an upload is cancelled or abandoned.
     */
    public function chunkAbort(): void
    {
        Auth::requirePermission('galleries');

        $uid = $this->sanitizeUid((string) $this->request->input('upload_uid'));

        if ($uid !== '') {
            $this->removeChunks($uid);
        }

        $this->json(['ok' => true]);
    }

    /**
     * Absolute path to this session's chunk staging directory for the given
     * (already sanitised) upload identifier, creating the parent when needed.
     */
    private function chunksDir(string $uid): string
    {
        return config('app.uploads.dir') . '/pending/' . session_id() . '/.chunks/' . $uid;
    }

    /**
     * Total bytes currently held in this session's pending area: staged files
     * plus in-progress chunk parts. Used to bound how much a single session
     * can stage (disk-fill guard).
     */
    private function pendingStagedBytes(): int
    {
        $root = config('app.uploads.dir') . '/pending/' . session_id();

        if (!is_dir($root)) {
            return 0;
        }

        $total = 0;

        foreach (glob($root . '/*') ?: [] as $path) {
            if (is_file($path)) {
                $total += (int) filesize($path);
            }
        }

        $chunksRoot = $root . '/.chunks';
        if (is_dir($chunksRoot)) {
            foreach (glob($chunksRoot . '/*/*') ?: [] as $part) {
                if (is_file($part)) {
                    $total += (int) filesize($part);
                }
            }
        }

        return $total;
    }

    /**
     * Restrict an upload identifier to filesystem-safe characters.
     */
    private function sanitizeUid(string $uid): string
    {
        $uid = trim($uid);
        if (strlen($uid) > 128) {
            $uid = substr($uid, 0, 128);
        }

        return preg_replace('/[^A-Za-z0-9_\-]/', '_', $uid);
    }

    /**
     * Reassemble all numbered parts for an upload identifier into a single
     * file in the pending directory, returning that path or null when any part
     * is missing (so an interrupted transfer is detected before validation).
     */
    private function reassembleChunks(string $uid, int $totalChunks): ?string
    {
        $parts = $this->chunksDir($uid);
        $out   = $this->pendingDir() . '/.assembled-' . $uid;

        $fh = @fopen($out, 'wb');
        if ($fh === false) {
            return null;
        }

        for ($i = 0; $i < $totalChunks; $i++) {
            $part = $parts . '/part-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            if (!is_file($part)) {
                fclose($fh);
                @unlink($out);
                return null;
            }
            $in = @fopen($part, 'rb');
            if ($in === false) {
                fclose($fh);
                @unlink($out);
                return null;
            }
            stream_copy_to_stream($in, $fh);
            fclose($in);
        }

        fclose($fh);

        return $out;
    }

    /**
     * Recursively remove all chunks for an upload identifier.
     */
    private function removeChunks(string $uid): void
    {
        $parts = $this->chunksDir($uid);

        if (!is_dir($parts)) {
            return;
        }

        foreach (glob($parts . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($parts);
    }

    /**
     * Admin: rotate a staged (pending) image in place and regenerate its
     * thumbnail/web variant. Returns the refreshed pending list as JSON.
     */
    public function pendingRotate(string $file): void
    {
        Auth::requirePermission('galleries');

        $filename = basename($file);
        $entry    = $this->pendingEntry($filename);

        if ($entry === null) {
            $this->json(['ok' => false, 'error' => 'File not found in pending uploads.']);
            return;
        }
        if (!$entry['is_image']) {
            $this->json(['ok' => false, 'error' => 'Only images can be rotated.']);
            return;
        }

        $direction = in_array($this->request->input('direction', 'right'), ['left', 'right', 'flip'], true)
            ? (string) $this->request->input('direction')
            : 'right';

        $config = config('app.uploads');
        $dir    = $this->pendingDir();
        $path   = $dir . '/' . $filename;

        if (!is_file($path) || !ImageEditor::rotate($path, $direction)) {
            $this->json(['ok' => false, 'error' => 'Could not rotate image.']);
            return;
        }

        create_image_variants(
            $path,
            $dir . '/web_' . $filename,
            $dir . '/thumb_' . $filename,
            $config['web_max_width'],
            $config['thumb_width'],
            $config['thumb_height']
        );

        $this->json(['ok' => true, 'files' => $this->pendingListMeta()]);
    }

    /**
     * Admin: remove a staged (pending) file and its variants from this
     * session's pending area. Returns the refreshed pending list as JSON.
     */
    public function pendingDelete(string $file): void
    {
        Auth::requirePermission('galleries');

        $filename = basename($file);
        $list     = $_SESSION['pending_gallery_files'] ?? [];
        $dir      = $this->pendingDir();

        $newList = array_values(array_filter(
            $list,
            static fn (array $item): bool => $item['filename'] !== $filename
        ));

        foreach (['', 'thumb_', 'web_'] as $prefix) {
            $candidate = $dir . '/' . $prefix . $filename;
            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }

        $_SESSION['pending_gallery_files'] = $newList;

        $this->json(['ok' => true, 'files' => $this->pendingListMeta()]);
    }

    /**
     * Admin: serve a staged (pending) file or its generated thumbnail/web
     * variant for the tiled preview, scoped to the current session.
     */
    public function pendingFile(string $file): void
    {
        Auth::requirePermission('galleries');

        $filename = basename($file);
        if ($filename === '' || $filename !== $file || $this->pendingEntry($filename) === null) {
            $this->notFound();
            return;
        }

        $size = (string) $this->request->query('size', '');
        $name = $filename;
        if ($size === 'thumb') {
            $name = 'thumb_' . $filename;
        } elseif ($size === 'web') {
            $name = 'web_' . $filename;
        }

        $path = $this->pendingDir() . '/' . $name;

        if (!is_file($path)) {
            $this->notFound();
            return;
        }

        $mime = in_array($size, ['thumb', 'web'], true)
            ? sniff_mime($path)
            : mime_for_extension($name);

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=3600');
        readfile($path);
        exit;
    }

    /**
     * Absolute path to this session's pending upload directory, creating it
     * when needed.
     */
    private function pendingDir(): string
    {
        $dir = config('app.uploads.dir') . '/pending/' . session_id();

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * Look up a staged file entry by its stored filename.
     */
    private function pendingEntry(string $filename): ?array
    {
        $list = $_SESSION['pending_gallery_files'] ?? [];

        foreach ($list as $item) {
            if ($item['filename'] === $filename) {
                return $item;
            }
        }

        return null;
    }

    /**
     * The staged files as URL/thumbnail-ready rows for the tiled preview.
     */
    public function pendingListMeta(): array
    {
        $config = config('app.uploads');
        $list   = $_SESSION['pending_gallery_files'] ?? [];
        $rows   = [];

        foreach ($list as $item) {
            $filename = $item['filename'];

            $rows[] = [
                'filename' => $filename,
                'original' => $item['original'],
                'is_image' => (bool) $item['is_image'],
                'size'     => is_file($config['dir'] . '/pending/' . session_id() . '/' . $filename)
                    ? (int) filesize($config['dir'] . '/pending/' . session_id() . '/' . $filename)
                    : 0,
                'thumb_url' => url('/admin/galleries/pending/' . rawurlencode($filename) . '?size=thumb'),
                'web_url'   => url('/admin/galleries/pending/' . rawurlencode($filename) . '?size=web'),
                'file_url'  => url('/admin/galleries/pending/' . rawurlencode($filename)),
            ];
        }

        return $rows;
    }

    /**
     * Move every staged file into the new gallery, creating Photo records,
     * generating the missing variants and attaching them. Returns the number
     * of photos added.
     */
    private function finalizePending(int $galleryId, string $galleryType): int
    {
        $list   = $_SESSION['pending_gallery_files'] ?? [];
        $config = config('app.uploads');
        $dir    = $this->pendingDir();

        $added = 0;

        foreach ($list as $item) {
            $filename  = $item['filename'];
            $source    = $dir . '/' . $filename;
            $hash      = $item['hash'];

            if (!is_file($source)) {
                continue;
            }

            // Staged files carry a "pending_" tracking prefix. Strip it so the
            // committed photo (and its thumb_/web_ variants) is stored with a
            // clean filename; keep the prefix's uniqueness by reusing the rest
            // of the generated name.
            $finalName = preg_replace('/^pending_/', '', $filename);

            $dest = $config['dir'] . '/' . $finalName;

            if (!rename($source, $dest)) {
                continue;
            }

            foreach (['thumb_', 'web_'] as $prefix) {
                $variant = $dir . '/' . $prefix . $filename;
                if (is_file($variant)) {
                    rename($variant, $config['dir'] . '/' . $prefix . $finalName);
                }
            }

            \App\Core\MediaUploader::commit($galleryId, $finalName, $hash);
            $added++;
        }

        $this->clearPendingDir($dir);
        unset($_SESSION['pending_gallery_files']);

        return $added;
    }

    /**
     * Remove leftover staged files and the pending directory.
     */
    private function clearPendingDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_merge(glob($dir . '/*') ?: [], glob($dir . '/.*') ?: []) as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        // Drop any leftover chunk-staging directories (may hold partially
        // uploaded chunks from an abandoned/resumable upload). Each uid is a
        // subdirectory of .chunks; remove those then the .chunks dir itself.
        $chunksRoot = $dir . '/.chunks';
        if (is_dir($chunksRoot)) {
            foreach (glob($chunksRoot . '/*') ?: [] as $sub) {
                if (is_dir($sub)) {
                    $this->removeChunks((string) basename($sub));
                } else {
                    @unlink($sub);
                }
            }
            @rmdir($chunksRoot);
        }

        @rmdir($dir);
    }

    /**
     * Validate a single staged upload: size, allowed extension, gallery-type
     * match and that it really is an image or a video.
     */
    /**
     * Admin: show the edit-gallery form, including the files it currently
     * contains so admins can see the gallery contents while editing.
     */
    public function edit(int $id): void
    {
        Auth::requirePermission('galleries');

        $gallery = Gallery::find($id);

        if ($gallery === null) {
            $this->notFound();
            return;
        }

        if (!empty($gallery['is_secret']) && !Auth::isSuperAdmin()) {
            $this->notFound();
            return;
        }

        $this->viewAdmin('edit', [
            'gallery'      => $gallery,
            // Capped so a huge gallery never makes the edit form render one
            // tile per file; the manage page still lists everything.
            'photos'       => Gallery::photosSlice($id, 200, 0),
            'photoCount'   => Gallery::photoCount($id),
            'categories'   => Category::all(),
            'assigned'     => array_map(
                static fn (array $category) => (int) $category['id'],
                Gallery::categories($id)
            ),
            'queuedGalleries' => Gallery::queuedForPublishing(Auth::isSuperAdmin()),
            'allowedUsers' => !empty($gallery['is_secret']) ? Gallery::allowedUsers($id) : [],
            'accessUsers' => Auth::isSuperAdmin() ? \App\Models\User::allForGalleryAccess() : [],
        ]);
    }

    /**
     * Admin: save changes to an existing gallery (including its type).
     */
    public function update(int $id): void
    {
        Auth::requirePermission('galleries');
        $gallery = Gallery::find($id);

        if ($gallery === null) {
            $this->notFound();
            return;
        }

        if (!empty($gallery['is_secret']) && !Auth::isSuperAdmin()) {
            $this->notFound();
            return;
        }

        $beforeCategories = array_column(Gallery::categories($id), 'id');

        $title       = $this->request->input('title');
        $description = $this->request->input('description');
        $type        = $this->request->input('type', 'images') === 'videos' ? 'videos' : 'images';
        $minLevel    = max(0, min(3, (int) $this->request->input('min_level', '0')));
        $isSecret    = Auth::isSuperAdmin() && $this->request->post('is_secret') === '1';
        $allowedUsers = $this->request->post('allowed_users', []);
        $allowedUsers = is_array($allowedUsers) ? $allowedUsers : [];
        $categoryIds = $this->request->post('categories', []);
        $categoryIds = is_array($categoryIds) ? $categoryIds : [];

        // Publication schedule handling: a non-empty publish_at picker value sets
// or updates the schedule (validated future-only). An explicit "publish
// now" / "clear schedule" action with an empty picker clears it. Otherwise
// the gallery's existing schedule is kept untouched.
$publishAction = (string) $this->request->post('publish_action', '');
$publishAtRaw  = trim((string) $this->request->post('publish_at', ''));
$publishedAt   = null;

if ($publishAtRaw !== '') {
    if (!Gallery::validPublishSchedule($publishAtRaw)) {
        $this->flash('error', 'The publish schedule must be a valid time in the future.');
        $this->redirect('/admin/galleries/' . $id . '/edit');
    }
    $publishedAt = Gallery::normalizePublishAt($publishAtRaw);
} elseif ($publishAction === 'now' || $publishAction === 'clear') {
    $publishedAt = null;
} else {
    // No schedule control on this form (e.g. the manage page): keep the
    // gallery's existing schedule untouched.
    $publishedAt = $gallery['published_at'] ?? null;
}

        if ($title === '') {
            $this->flash('error', 'Title is required.');
            $this->redirect('/admin/galleries/' . $id . '/edit');
        }

        Gallery::update($id, $title, $description, $type, $minLevel, $publishedAt, $isSecret);
        Gallery::setCategories($id, $categoryIds);
        Gallery::setAllowedUsers($id, $isSecret ? $allowedUsers : []);

        // Resync pending X/Reddit auto-post rows to the new publish moment so
        // the posts go out when the gallery goes live (or immediately when the
        // schedule was cleared / published now).
        AutoPostQueue::rescheduleGalleryPosts($id, $publishedAt);

        $after = [
            'title' => $title, 'description' => $description, 'type' => $type,
            'min_level' => $minLevel,
            'categories' => array_map('intval', $categoryIds),
            'published_at' => $publishedAt,
            'is_secret' => $isSecret,
            'allowed_users' => array_map('intval', $allowedUsers),
        ];
        $before = [
            'title' => $gallery['title'] ?? '',
            'description' => $gallery['description'] ?? '',
            'type' => $gallery['type'] ?? 'images',
            'min_level' => (int) ($gallery['min_level'] ?? 0),
            'categories' => $beforeCategories,
            'published_at' => $gallery['published_at'] ?? null,
            'is_secret' => !empty($gallery['is_secret']),
            'allowed_users' => array_map('intval', array_column(Gallery::allowedUsers($id), 'id')),
        ];

        if ($before !== $after) {
            AuditLog::record((int) Auth::user()['id'], 'update', 'gallery', $id, 'Updated gallery details', $before, $after);
        }

        $this->flash('success', 'Gallery updated.');
        $this->redirect('/admin/galleries/' . $id);
    }

    /**
     * Admin: force a queued gallery to publish immediately, clearing its
     * schedule and making its pending X/Reddit auto-post rows due now.
     */
    public function publishNow(int $id): void
    {
        Auth::requirePermission('galleries');
        $gallery = Gallery::find($id);

        if ($gallery === null) {
            $this->notFound();
            return;
        }

        $this->guardSecretAdmin($gallery);

        Gallery::publishNow($id);
        AutoPostQueue::advanceGalleryPosts($id);

        AuditLog::record(
            (int) Auth::user()['id'],
            'update',
            'gallery',
            $id,
            'Published queued gallery "' . ($gallery['title'] ?? '') . '" now'
        );

        $this->flash('success', 'Gallery published now.');
        $this->redirect('/admin/galleries');
    }

    /**
     * Admin: soft-delete a gallery. It disappears from the site but keeps its
     * files and data so it can be restored from the admin logs.
     */
    public function destroy(int $id): void
    {
        Auth::requirePermission('galleries');
        $gallery = Gallery::findIncludingDeleted($id);

        if ($gallery === null) {
            $this->notFound();
            return;
        }

        $this->guardSecretAdmin($gallery);

        if ($gallery['deleted_at'] === null) {
            $beforeCategories = array_column(Gallery::categories($id), 'id');

            AuditLog::record((int) Auth::user()['id'], 'delete', 'gallery', $id, 'Deleted gallery "' . $gallery['title'] . '"', [
                'title' => $gallery['title'] ?? '',
                'description' => $gallery['description'] ?? '',
                'type' => $gallery['type'] ?? 'images',
                'categories' => $beforeCategories,
            ]);
        }

        Gallery::softDelete($id);

        $this->flash('success', 'Gallery deleted. You can restore it from the admin logs.');
        $this->redirect('/admin/galleries');
    }

    /**
     * Queue a gallery for a recommended auto-post without visiting the Auto
     * Poster page. Handed to AutoPostQueue::enqueue once per platform, it does
     * what it does for any recommendation: resolve the gallery's media set,
     * build the platform-appropriate draft text from title/description +
     * category hashtags, schedule the default publish time and insert the
     * queue row. Galleries that already have a queue row for a platform are
     * skipped there, mirroring recommendations().
     */
    public function recommendPost(int $id): void
    {
        Auth::requirePermission('galleries');
        $gallery = Gallery::find($id);

        if ($gallery === null) {
            $this->notFound();
            return;
        }

        $this->guardSecretAdmin($gallery);

        $queued    = [];
        $duplicate = [];
        foreach (['twitter' => 'X', 'reddit' => 'Reddit'] as $platform => $label) {
            $alreadyQueued = Database::run(
                'SELECT q.id FROM auto_poster_queue q
                 WHERE q.gallery_id = ? AND q.platform = ?
                   AND q.status IN (?, ?, ?, ?, ?)
                 LIMIT 1',
                [$id, $platform, 'queued', 'posted', 'failed', 'skipped', 'dismissed']
            )->fetch();

            if ($alreadyQueued) {
                $duplicate[] = $label;
                continue;
            }

            $queueId = AutoPostQueue::enqueue($id, null, null, $platform);

            if ($queueId > 0) {
                $queued[] = $label;
                AuditLog::record(
                    (int) Auth::user()['id'],
                    'create',
                    'auto_post_queue',
                    $queueId,
                    'Queued gallery #' . $id . ' for a recommended post on ' . $label
                );
            }
        }

        if ($queued !== []) {
            $message = 'Gallery queued for recommended posts on ' . implode(' and ', $queued) . '.';
            if ($duplicate !== []) {
                $message .= ' Already in the ' . implode(' and ', $duplicate) . ' auto-post queue — nothing duplicated.';
            }
            $this->flash('success', $message);
        } elseif ($duplicate !== []) {
            $this->flash('error', 'Gallery is already in the auto-post queue on ' . implode(' and ', $duplicate) . '.');
        } else {
            $this->flash('error', 'Gallery could not be queued for a recommended post.');
        }
        $this->redirect('/admin/galleries');
    }

    /**
     * Bulk actions from the dashboard list: multi-select soft-delete or
     * re-assign every checked gallery to a single category.
     */
    public function bulk(): void
    {
        Auth::requirePermission('galleries');

        $ids = array_values(array_filter(array_map('intval', (array) ($this->request->post('ids') ?? []))));
        $action = (string) $this->request->post('action', '');

        if ($ids === [] || !in_array($action, ['delete', 'category'], true)) {
            $this->flash('error', 'Nothing to do — select galleries and an action first.');
            $this->redirect('/admin');
        }

        $categoryId = (int) $this->request->post('category_id', 0);
        if ($action === 'category' && $categoryId <= 0) {
            $this->flash('error', 'Pick a category to assign.');
            $this->redirect('/admin');
        }

        $adminId = (int) Auth::user()['id'];
        $done = 0;

        foreach ($ids as $id) {
            $gallery = Gallery::findIncludingDeleted($id);

            if ($gallery === null) {
                continue;
            }

            if (!empty($gallery['is_secret']) && !Auth::isSuperAdmin()) {
                continue;
            }

            if ($action === 'delete') {
                if ($gallery['deleted_at'] === null) {
                    AuditLog::record($adminId, 'delete', 'gallery', $id,
                        'Bulk-deleted gallery "' . $gallery['title'] . '"',
                        ['title' => $gallery['title'] ?? '', 'categories' => array_column(Gallery::categories($id), 'id')]);
                    Gallery::softDelete($id);
                }
                $done++;
            } else {
                Gallery::setCategories($id, [$categoryId]);
                AuditLog::record($adminId, 'update', 'gallery', $id,
                    'Bulk-recategorized gallery "' . $gallery['title'] . '"',
                    ['categories' => array_column(Gallery::categories($id), 'id')],
                    ['categories' => [$categoryId]]);
                $done++;
            }
        }

        $this->flash('success', ucfirst($action === 'delete' ? 'Deleted' : 'Recategorized') . " {$done} gallery(ies).");
        $this->redirect('/admin');
    }

    private function guardSecretAdmin(?array $gallery): void
    {
        if ($gallery !== null && !empty($gallery['is_secret']) && !Auth::isSuperAdmin()) {
            $this->notFound();
            exit;
        }
    }

    /**
     * Bulk-load cover photos and categories for all galleries that will be
     * rendered as cards, eliminating N+1 queries.
     */
    private function preloadCardData(array $sections, ?array $paginator, ?array $secondPaginator = null): array
    {
        $ids = [];

        foreach ($sections as $section) {
            foreach ($section['galleries'] as $g) {
                $ids[] = (int) $g['id'];
            }
        }

        foreach ([$paginator, $secondPaginator] as $p) {
            if ($p !== null && !empty($p['items'])) {
                foreach ($p['items'] as $g) {
                    $ids[] = (int) $g['id'];
                }
            }
        }

        $ids = array_unique($ids);

        return [
            'covers'     => Gallery::firstPhotos($ids),
            'categories' => Gallery::categoriesBulk($ids),
        ];
    }
}
