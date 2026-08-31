<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\ImageEditor;
use App\Models\AuditLog;
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
        $sections   = [];
        $seen       = [];
        $categories = Category::all();

        if ($q === '') {
            $byCategory = Gallery::inCategories(array_column($categories, 'id'), $type, $maxLevel);

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
                Gallery::withoutCategory($type, $maxLevel),
                static fn (array $gallery): bool => !isset($seen[(int) $gallery['id']])
            ));

            if ($uncategorized !== []) {
                $sections[] = ['category' => ['id' => 0, 'name' => 'Uncategorized', 'slug' => ''], 'galleries' => $uncategorized];
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
        if ($catId > 0) {
            $filters['category'] = $catId;
        }
        if ($type !== '') {
            $filters['type'] = $type;
        }
        if ($sort !== '') {
            $filters['sort'] = $sort;
        }

        $paginator = Gallery::paginate($page, 6, $filters);

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

        $filters       = ['q' => $q, 'category' => (int) $category['id'], 'max_level' => Auth::effectiveLevel()];
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

        $gallery = Gallery::find($id);

        if ($gallery === null) {
            $this->notFound();
            return;
        }

        Auth::requireGalleryLevel(
            (int) ($gallery['min_level'] ?? 0),
            'A membership is required to view that gallery.'
        );

        $user = Auth::user();

        if ($user !== null) {
            Gallery::recordView($id, (int) $user['id']);
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
            'returnTo'   => url('/galleries/' . $id),
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

        $gallery = Gallery::find($id);

        if ($gallery === null) {
            $this->notFound();
            return;
        }

        Auth::requireGalleryLevel(
            (int) ($gallery['min_level'] ?? 0),
            'A membership is required to view that gallery.'
        );

        $pageSize = max(1, (int) config('app.gallery_page_size', 48));
        $offset   = max(0, (int) $this->request->query('offset', 0));
        $total    = Gallery::photoCount($id);

        if ($offset >= $total) {
            echo '';
            return;
        }

        $photos  = Gallery::photosSlice($id, $pageSize, $offset);
        $returnTo = url('/galleries/' . $id);

        header('Content-Type: text/html; charset=utf-8');
        foreach ($photos as $k => $photo) {
            $idx     = $offset + $k; // global index across all loaded pages
            require __DIR__ . '/../../views/partials/gallery_grid_item.php';
        }
        exit;
    }

    /**
     * Admin: show the create-gallery form. When arriving via the abandoned
     * uploads resume flow (?resume=1) the current session's staging area is
     * kept so the admin can finish a gallery that was abandoned mid-upload.
     */
    public function create(): void
    {
        Auth::requirePermission('galleries');

        $resume = $this->request->query('resume') === '1';

        // A fresh visit to the create form should not carry over previously
        // staged files that were never saved. Resuming keeps them instead.
        if (!$resume) {
            $dir = config('app.uploads.dir') . '/pending/' . session_id();
            if (is_dir($dir)) {
                $this->clearPendingDir($dir);
            }
            unset($_SESSION['pending_gallery_files']);
        }

        $page = (int) $this->request->query('page', 1);

        $this->viewAdmin('create', [
            'categories'   => Category::all(),
            'galleryType'  => 'images',
            'paginator'    => Gallery::paginate($page, 10),
            'pendingFiles' => $this->pendingListMeta(),
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
        $minLevel    = max(0, min(4, (int) $this->request->input('min_level', '0')));
        $categoryIds = $this->request->post('categories', []);
        $categoryIds = is_array($categoryIds) ? $categoryIds : [];

        if ($title === '') {
            $this->flash('error', 'Title is required.');
            $this->redirect('/admin/galleries/create');
        }

        $galleryId = Gallery::create($title, $description, $type, $minLevel);
        Gallery::setCategories($galleryId, $categoryIds);

        $count = $this->finalizePending($galleryId, $type);

        AuditLog::record((int) Auth::user()['id'], 'create', 'gallery', $galleryId, 'Created gallery "' . $title . '"', null, [
            'title' => $title, 'description' => $description, 'type' => $type,
            'min_level' => $minLevel,
            'categories' => array_map('intval', $categoryIds),
            'photos' => $count,
        ]);

        $this->flash('success', 'Gallery created with ' . $count . ' file(s).');
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
            $this->jsonReply(['ok' => false, 'error' => 'No files selected.']);
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

            $mime = $this->pendingMimeOf($files['tmp_name'][$i]);

            $error = $this->validatePending($files, $i, $config, $type, $mime);
            if ($error !== null) {
                $this->jsonReply(['ok' => false, 'error' => $files['name'][$i] . ': ' . $error]);
                return;
            }

            // Determine the file's real extension from its detected MIME type,
            // so a disguised or oddly-named file is stored with the correct
            // extension (e.g. an image/jpeg named .png becomes .jpg).
            $hash      = sha1_file($files['tmp_name'][$i]);
            $isImage   = strpos($mime, 'image/') === 0;
            $extension = $this->extensionForMime($mime);
            $filename  = uniqid('pending_', true) . '.' . $extension;

            if (!move_uploaded_file($files['tmp_name'][$i], $dir . '/' . $filename)) {
                $this->jsonReply(['ok' => false, 'error' => $files['name'][$i] . ': could not be saved.']);
                return;
            }

            if ($isImage) {
                create_image_variants(
                    $dir . '/' . $filename,
                    $dir . '/web_' . $filename,
                    $dir . '/thumb_' . $filename,
                    $config['web_max_width'],
                    $config['thumb_width'],
                    $config['thumb_height']
                );
            } else {
                create_video_thumbnail(
                    $dir . '/' . $filename,
                    $dir . '/thumb_' . $filename,
                    $config['thumb_width'],
                    $config['thumb_height']
                );
            }

            $list[] = [
                'filename' => $filename,
                'original' => $files['name'][$i],
                'hash'     => $hash,
                'is_image' => $isImage,
            ];
            $added++;
        }

        $_SESSION['pending_gallery_files'] = $list;

        $this->jsonReply([
            'ok' => true,
            'added' => $added,
            'files' => $this->pendingListMeta(),
            'skipped' => $skipped,
        ]);
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
            $this->jsonReply(['ok' => false, 'error' => 'File not found in pending uploads.']);
            return;
        }
        if (!$entry['is_image']) {
            $this->jsonReply(['ok' => false, 'error' => 'Only images can be rotated.']);
            return;
        }

        $direction = in_array($this->request->input('direction', 'right'), ['left', 'right', 'flip'], true)
            ? (string) $this->request->input('direction')
            : 'right';

        $config = config('app.uploads');
        $dir    = $this->pendingDir();
        $path   = $dir . '/' . $filename;

        if (!is_file($path) || !ImageEditor::rotate($path, $direction)) {
            $this->jsonReply(['ok' => false, 'error' => 'Could not rotate image.']);
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

        $this->jsonReply(['ok' => true, 'files' => $this->pendingListMeta()]);
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

        $this->jsonReply(['ok' => true, 'files' => $this->pendingListMeta()]);
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
            ? $this->pendingMimeOf($path)
            : $this->pendingMimeFor($name);

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
    private function pendingListMeta(): array
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
            $isImage   = (bool) $item['is_image'];
            $hash      = $item['hash'];

            if (!is_file($source)) {
                continue;
            }

            $existing = Photo::findByHash($hash);

            if ($existing !== null) {
                Gallery::attachPhoto($galleryId, (int) $existing['id']);
                $added++;
                continue;
            }

            $dest = $config['dir'] . '/' . $filename;

            if (!rename($source, $dest)) {
                continue;
            }

            foreach (['thumb_', 'web_'] as $prefix) {
                $variant = $dir . '/' . $prefix . $filename;
                if (is_file($variant)) {
                    rename($variant, $config['dir'] . '/' . $prefix . $filename);
                }
            }

            $photoId = Photo::create($filename, $hash);
            Gallery::attachPhoto($galleryId, $photoId);
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

        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }

    /**
     * Validate a single staged upload: size, allowed extension, gallery-type
     * match and that it really is an image or a video.
     */
    private function validatePending(array $files, int $index, array $config, string $galleryType, string $mime): ?string
    {
        if ($files['size'][$index] > $config['max_size']) {
            return 'File is too large.';
        }

        if ($mime === '') {
            return 'Could not detect file type.';
        }

        $isImage = strpos($mime, 'image/') === 0;
        $isVideo = strpos($mime, 'video/') === 0;

        // Validate against the detected MIME type, not the filename extension,
        // so an image mislabelled as a video (or vice versa) is rejected for
        // the wrong gallery type.
        if (!$isImage && !$isVideo) {
            return 'File type not allowed. Supported: ' . implode(', ', $config['image_ext']) . ' (images) or ' . implode(', ', $config['video_ext']) . ' (videos).';
        }

        // The MIME-derived extension must also be one the app is configured
        // to accept, so exotic image/video containers are still rejected.
        $extension = $this->extensionForMime($mime);

        if ($isImage && !in_array($extension, $config['image_ext'], true)) {
            return 'Image type not allowed. Supported image types: ' . implode(', ', $config['image_ext']) . '.';
        }
        if ($isVideo && !in_array($extension, $config['video_ext'], true)) {
            return 'Video type not allowed. Supported video types: ' . implode(', ', $config['video_ext']) . '.';
        }

        if ($galleryType === 'videos' && $isImage) {
            return 'Video galleries can only contain video files.';
        }
        if ($galleryType === 'images' && $isVideo) {
            return 'Image galleries can only contain image files.';
        }

        if ($isImage) {
            if (!image_can_decode($files['tmp_name'][$index])) {
                return 'File is not a valid image.';
            }

            return null;
        }

        return null;
    }

    /**
     * Map a detected MIME type to a canonical filename extension. Falls back
     * to the original file extension for an unknown video container, and to
     * 'bin' when nothing else applies.
     */
    private function extensionForMime(string $mime): string
    {
        $map = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'image/webp' => 'webp', 'image/bmp' => 'bmp', 'image/x-ms-bmp' => 'bmp',
            'image/heic' => 'heic', 'image/heif' => 'heic',
            'image/avif' => 'avif', 'image/tiff' => 'tiff', 'image/x-tiff' => 'tiff',
            'image/vnd.microsoft.icon' => 'ico', 'image/x-icon' => 'ico',
            'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogg',
            'video/quicktime' => 'mov', 'video/x-msvideo' => 'avi',
            'video/x-matroska' => 'mkv',
        ];

        return $map[$mime] ?? 'bin';
    }

    /**
     * Emit a JSON reply and stop.
     */
    private function jsonReply(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

    /**
     * Detect a file's MIME type with finfo when available.
     */
    private function pendingMimeOf(string $path): string
    {
        if (class_exists('finfo')) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

            return $mime !== false ? $mime : '';
        }

        return '';
    }

    /**
     * Map a filename extension to a content type for serving staged files.
     */
    private function pendingMimeFor(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp',
            'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm',
            'ogg' => 'video/ogg', 'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
        ];

        return $map[$extension] ?? 'application/octet-stream';
    }

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

        $this->viewAdmin('edit', [
            'gallery'      => $gallery,
            'photos'       => Gallery::photos($id),
            'categories'   => Category::all(),
            'assigned'     => array_map(
                static fn (array $category) => (int) $category['id'],
                Gallery::categories($id)
            ),
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

        $beforeCategories = array_column(Gallery::categories($id), 'id');

        $title       = $this->request->input('title');
        $description = $this->request->input('description');
        $type        = $this->request->input('type', 'images') === 'videos' ? 'videos' : 'images';
        $minLevel    = max(0, min(4, (int) $this->request->input('min_level', '0')));
        $categoryIds = $this->request->post('categories', []);
        $categoryIds = is_array($categoryIds) ? $categoryIds : [];

        if ($title === '') {
            $this->flash('error', 'Title is required.');
            $this->redirect('/admin/galleries/' . $id . '/edit');
        }

        Gallery::update($id, $title, $description, $type, $minLevel);
        Gallery::setCategories($id, $categoryIds);

        $after = [
            'title' => $title, 'description' => $description, 'type' => $type,
            'min_level' => $minLevel,
            'categories' => array_map('intval', $categoryIds),
        ];
        $before = [
            'title' => $gallery['title'] ?? '',
            'description' => $gallery['description'] ?? '',
            'type' => $gallery['type'] ?? 'images',
            'min_level' => (int) ($gallery['min_level'] ?? 0),
            'categories' => $beforeCategories,
        ];

        if ($before !== $after) {
            AuditLog::record((int) Auth::user()['id'], 'update', 'gallery', $id, 'Updated gallery details', $before, $after);
        }

        $this->flash('success', 'Gallery updated.');
        $this->redirect('/admin/galleries/' . $id);
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
        $this->redirect('/admin');
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
            if ($p !== null) {
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
