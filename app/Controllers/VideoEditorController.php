<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\VideoProject;

class VideoEditorController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('videos');
    }

    public function edit(int $id): void
    {
        $photo = Photo::find($id);
        if ($photo === null || !is_video($photo['filename'])) {
            $this->notFound();
            return;
        }

        $userId = (int) Auth::user()['id'];
        $project = VideoProject::findForUser($id, $userId);
        if ($project === null) {
            $project = VideoProject::create($id, $userId, pathinfo($photo['filename'], PATHINFO_FILENAME), [
                'version' => 1,
                'tracks' => [
                    ['type' => 'video', 'clips' => [['asset_id' => $id, 'start' => 0, 'end' => 0, 'transition' => 'none']]],
                    ['type' => 'audio', 'clips' => [['asset_id' => $id, 'volume' => 1, 'fade_in' => 0, 'fade_out' => 0]]],
                    ['type' => 'captions', 'items' => []],
                ],
                'text_overlays' => [],
            ]);
        }

        $project['project'] = json_decode((string) $project['project_json'], true) ?: [];
        $this->viewStandalone('admin/video_editor', ['photo' => $photo, 'project' => $project]);
    }

    public function dashboard(): void
    {
        $rows = Database::run(
            'SELECT p.*, u.email AS user_email, ph.filename AS source_filename,
                    (SELECT COUNT(*) FROM video_export_jobs e WHERE e.project_id = p.id AND e.status <> \'failed\') AS export_count
             FROM video_projects p
             LEFT JOIN users u ON u.id = p.user_id
             LEFT JOIN photos ph ON ph.id = p.source_photo_id
             ORDER BY p.updated_at DESC LIMIT 200'
        )->fetchAll();

        $exports = Database::run(
            'SELECT e.*, p.title AS project_title, ph.filename AS source_filename, u.email AS user_email
             FROM video_export_jobs e
             JOIN video_projects p ON p.id = e.project_id
             LEFT JOIN photos ph ON ph.id = p.source_photo_id
             LEFT JOIN users u ON u.id = p.user_id
             WHERE e.status <> \'failed\'
             ORDER BY e.id DESC LIMIT 100'
        )->fetchAll();
        $uploadsDir = config('app.uploads')['dir'];
        foreach ($exports as &$ex) {
            $ex['file_exists'] = 0;
            $ex['file_size'] = null;
            $ex['file_url'] = null;
            if (!empty($ex['output_file'])) {
                $base = basename($ex['output_file']);
                $candidates = [$uploadsDir . '/exports/' . $base, $uploadsDir . '/' . $base];
                foreach ($candidates as $c) {
                    if (is_file($c)) {
                        $ex['file_exists'] = 1;
                        $ex['file_size'] = filesize($c);
                        break;
                    }
                }
            }
        }
        unset($ex);

        $this->viewAdmin('video_projects', ['projects' => $rows, 'exports' => $exports]);
    }

    /**
     * Admin: list every uploaded video with its gallery, view count and any
     * linked video projects. Reached from the "Video list" button on the
     * Video Projects page.
     */
    public function videoList(): void
    {
        $search = trim($this->request->input('q') ?? '');
        $where  = 'p.is_video = 1';
        $params = [];

        if ($search !== '') {
            $like = '%' . $search . '%';
            $where .= ' AND (p.filename LIKE ? OR EXISTS (
                SELECT 1 FROM gallery_photo gp2
                INNER JOIN galleries g2 ON g2.id = gp2.gallery_id
                WHERE gp2.photo_id = p.id AND g2.title LIKE ?
            ))';
            $params[] = $like;
            $params[] = $like;
        }

        $rows = Database::run(
            'SELECT p.id, p.filename, p.views, p.created_at,
                    (SELECT g.title FROM gallery_photo gp
                     INNER JOIN galleries g ON g.id = gp.gallery_id
                     WHERE gp.photo_id = p.id ORDER BY gp.gallery_id LIMIT 1) AS gallery_title,
                    (SELECT COUNT(*) FROM video_projects vp WHERE vp.source_photo_id = p.id) AS project_count
             FROM photos p
             WHERE ' . $where . '
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT ' . (int) 200,
            $params
        )->fetchAll();

        $this->viewAdmin('videos', [
            'videos' => $rows,
            'search' => $search,
        ]);
    }

    public function save(int $id): void
    {
        $project = VideoProject::find($id);
        if ($project === null) { $this->json(['error' => 'Project not found.'], 404); return; }

        $payload = $this->request->post('project', '');
        $data = is_string($payload) ? json_decode($payload, true) : null;
        if (!is_array($data)) { $this->json(['error' => 'Invalid project data.'], 422); return; }

        $data = $this->sanitizeProject($data, (int) $project['source_photo_id']);
        $title = trim((string) $this->request->post('title', $project['title']));
        $version = (int) $this->request->post('version', 0);
        $saved = VideoProject::save((int) $project['id'], (int) Auth::user()['id'], $title ?: 'Untitled video project', $data, $version);
        if ($saved === null) { $this->json(['error' => 'Project changed elsewhere. Reload and try again.'], 409); return; }
        $this->json(['ok' => true, 'version' => (int) $saved['version']]);
    }

    public function export(int $id): void
    {
        $project = VideoProject::find($id);
        if ($project === null || (int) $project['user_id'] !== (int) Auth::user()['id']) {
            $this->json(['error' => 'Project not found.'], 404); return;
        }
        $saveOverOriginal = !empty($this->request->post('save_over_original'));
        $exportStart = max(0, (float) ($this->request->post('export_start', 0)));
        $exportEnd = max(0, (float) ($this->request->post('export_end', 0)));
        $jobId = VideoProject::createExport($id, $saveOverOriginal, $exportStart, $exportEnd);
        $worker = dirname(__DIR__, 2) . '/bin/video_export_worker.php';
        $phpCli = is_executable('/usr/bin/php') ? '/usr/bin/php' : PHP_BINARY;
        $command = 'nohup ' . escapeshellarg($phpCli) . ' ' . escapeshellarg($worker) . ' ' . (int) $jobId . ' >/dev/null 2>&1 &';
        exec($command);
        $this->json(['ok' => true, 'job_id' => $jobId]);
    }

    public function status(int $id): void
    {
        $job = VideoProject::exportJob($id, (int) Auth::user()['id']);
        if ($job === null) { $this->json(['error' => 'Export not found.'], 404); return; }
        $this->json(['status' => $job['status'], 'progress' => (int) $job['progress'], 'error' => $job['error'], 'output' => $job['output_file']]);
    }

    public function download(int $id): void
    {
        $job = VideoProject::exportJob($id, (int) Auth::user()['id']);
        if ($job === null || $job['status'] !== 'completed' || empty($job['output_file'])) { $this->notFound(); return; }
        $uploadsDir = config('app.uploads')['dir'];
        $base = basename($job['output_file']);
        $path = null;
        foreach ([$uploadsDir . '/exports/' . $base, $uploadsDir . '/' . $base] as $candidate) {
            if (is_file($candidate)) { $path = $candidate; break; }
        }
        if ($path === null) { $this->notFound(); return; }
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public function stream(int $id): void
    {
        $job = VideoProject::exportJob($id, (int) Auth::user()['id']);
        if ($job === null || $job['status'] !== 'completed' || empty($job['output_file'])) { $this->notFound(); return; }
        $uploadsDir = config('app.uploads')['dir'];
        $base = basename($job['output_file']);
        $path = null;
        foreach ([$uploadsDir . '/exports/' . $base, $uploadsDir . '/' . $base] as $candidate) {
            if (is_file($candidate)) { $path = $candidate; break; }
        }
        if ($path === null) { $this->notFound(); return; }

        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        while (ob_get_level() > 0) ob_end_clean();
        $size = (int) filesize($path);
        header('Content-Type: video/mp4');
        header('Content-Disposition: inline; filename="' . $base . '"');
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=3600');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            header('Content-Length: ' . $size);
            exit;
        }

        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
        if (preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
            $start = $m[1] !== '' ? (int) $m[1] : null;
            $end = $m[2] !== '' ? (int) $m[2] : null;
            if ($start === null) {
                $start = max(0, $size - $end);
                $end = $size - 1;
            } elseif ($end === null || $end >= $size) {
                $end = $size - 1;
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $length = $end - $start + 1;
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            header('Content-Length: ' . $length);
            $handle = fopen($path, 'rb');
            if ($handle === false) exit;
            fseek($handle, $start);
            $remaining = $length;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, min(8192, $remaining));
                if ($chunk === false || $chunk === '') break;
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($handle);
            exit;
        }

        header('Content-Length: ' . $size);
        readfile($path);
        exit;
    }

    public function deleteExport(int $id): void
    {
        $job = VideoProject::exportJob($id, (int) Auth::user()['id']);
        if ($job === null) { $this->notFound(); return; }

        if (!in_array($job['status'], ['completed', 'failed'], true)) {
            $this->redirect('/admin/video-projects');
            return;
        }

        if (!empty($job['output_file'])) {
            $uploadsDir = config('app.uploads')['dir'];
            $base = basename($job['output_file']);
            foreach ([$uploadsDir . '/exports/' . $base, $uploadsDir . '/' . $base] as $candidate) {
                if (is_file($candidate)) @unlink($candidate);
            }
        }
        Database::run('DELETE FROM video_export_jobs WHERE id = ?', [$id]);
        $this->redirect('/admin/video-projects');
    }

    public function createGalleryFromExport(int $id): void
    {
        $job = VideoProject::exportJob($id, (int) Auth::user()['id']);
        if ($job === null || $job['status'] !== 'completed' || empty($job['output_file'])) {
            $this->flash('error', 'Export not found or not completed.');
            $this->redirect('/admin/video-projects');
            return;
        }

        $uploadsDir = config('app.uploads')['dir'];
        $base = basename($job['output_file']);
        $filePath = null;
        foreach ([$uploadsDir . '/exports/' . $base, $uploadsDir . '/' . $base] as $candidate) {
            if (is_file($candidate)) { $filePath = $candidate; break; }
        }
        if ($filePath === null) {
            $this->flash('error', 'Exported file not found on disk.');
            $this->redirect('/admin/video-projects');
            return;
        }

        if ($this->request->method() === 'POST') {
            $title       = trim((string) $this->request->input('title', ''));
            $description = trim((string) $this->request->input('description', ''));
            $categoryIds = $this->request->post('categories', []);
            $categoryIds = is_array($categoryIds) ? $categoryIds : [];
            $minLevel    = max(0, min(4, (int) $this->request->input('min_level', 0)));

            if ($title === '') {
                $this->flash('error', 'Title is required.');
                $this->redirect('/admin/video-exports/' . $id . '/create-gallery');
                return;
            }

            $galleryId = Gallery::create($title, $description, 'videos', $minLevel);
            Gallery::setCategories($galleryId, $categoryIds);

            $hash    = hash_file('sha1', $filePath);
            $photo   = Photo::findByHash($hash);
            $photoId = $photo !== null ? (int) $photo['id'] : Photo::create($base, $hash);

            // The gallery card renders the exported file's thumbnail from
            // uploads/thumb_<outputFile>. Generate it now if it is missing
            // (the export worker may not have created one).
            $config = config('app.uploads');
            $thumb  = $config['dir'] . '/thumb_' . $base;
            if (!is_file($thumb)) {
                create_video_thumbnail(
                    $filePath,
                    $thumb,
                    $config['thumb_width'],
                    $config['thumb_height']
                );
            }

            Gallery::attachPhoto($galleryId, $photoId);

            AuditLog::record((int) Auth::user()['id'], 'create', 'gallery', $galleryId, 'Created video gallery "' . $title . '"', null, [
                'title' => $title, 'description' => $description, 'type' => 'videos',
                'categories' => array_map('intval', $categoryIds),
            ]);

            $this->flash('success', 'Video gallery created.');
            $this->redirect('/admin/galleries/' . $galleryId);
            return;
        }

        $project = VideoProject::find((int) $job['project_id']);
        $this->viewAdmin('video_export_create_gallery', [
            'export'    => $job,
            'project'   => $project,
            'categories' => Category::all(),
            'prefill'   => [
                'title'       => trim((string) ($project['title'] ?? 'Untitled')),
                'description' => '',
                'min_level'   => 0,
            ],
        ]);
    }

    private function sanitizeProject(array $project, int $sourceId): array
    {
        $project['speed'] = max(0.25, min(3, (float) ($project['speed'] ?? 1)));
        $filters = is_array($project['filters'] ?? null) ? $project['filters'] : [];
        $project['filters'] = [
            'brightness' => max(-0.5, min(0.5, (float) ($filters['brightness'] ?? 0))),
            'contrast'   => max(0.5, min(2, (float) ($filters['contrast'] ?? 1))),
            'saturation' => max(0, min(2, (float) ($filters['saturation'] ?? 1))),
            'grayscale'  => !empty($filters['grayscale']),
            'sepia'      => !empty($filters['sepia']),
            'blur'       => max(0, min(8, (float) ($filters['blur'] ?? 0))),
            'hue'        => max(-180, min(180, (float) ($filters['hue'] ?? 0))),
        ];
        $crop = is_array($project['crop'] ?? null) ? $project['crop'] : [];
        $project['crop'] = [
            'zoom'    => max(1, min(3, (float) ($crop['zoom'] ?? 1))),
            'panX'    => max(0, min(1, (float) ($crop['panX'] ?? 0.5))),
            'panY'    => max(0, min(1, (float) ($crop['panY'] ?? 0.5))),
            'mirrorH' => !empty($crop['mirrorH']),
            'mirrorV' => !empty($crop['mirrorV']),
        ];
        $project['tracks'] = is_array($project['tracks'] ?? null) ? $project['tracks'] : [];
        foreach ($project['tracks'] as &$track) {
            $track['type'] = in_array($track['type'] ?? '', ['video', 'audio', 'captions'], true) ? $track['type'] : 'video';
            if (isset($track['clips']) && is_array($track['clips'])) {
                foreach ($track['clips'] as &$clip) {
                    $clip['asset_id'] = $sourceId;
                    foreach (['start', 'end', 'volume', 'fade_in', 'fade_out'] as $key) {
                        if (isset($clip[$key])) $clip[$key] = max(0, min(86400, (float) $clip[$key]));
                    }
                    $transition = $clip['transition'] ?? 'none';
                    $clip['transition'] = in_array($transition, ['none', 'fade'], true) ? $transition : 'none';
                    $clip['muted'] = !empty($clip['muted']);
                }
                unset($clip);
            }
        }
        unset($track);
        $project['text_overlays'] = is_array($project['text_overlays'] ?? null) ? array_slice($project['text_overlays'], 0, 30) : [];
        foreach ($project['text_overlays'] as &$item) {
            $item['text'] = substr(trim((string) ($item['text'] ?? '')), 0, 300);
            $item['start'] = max(0, min(86400, (float) ($item['start'] ?? 0)));
            $item['end'] = max($item['start'], min(86400, (float) ($item['end'] ?? 5)));
            $item['x'] = max(0, min(1, (float) ($item['x'] ?? .5)));
            $item['y'] = max(0, min(1, (float) ($item['y'] ?? .85)));
            $item['font_size'] = max(10, min(180, (int) ($item['font_size'] ?? 32)));
            $color = (string) ($item['color'] ?? '#ffffff');
            $item['color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : '#ffffff';
            $item['opacity'] = max(0, min(1, (float) ($item['opacity'] ?? 1)));
            $item['padding'] = max(0, min(40, (int) ($item['padding'] ?? 8)));
            $item['shadow'] = !empty($item['shadow']);
        }
        unset($item);
        $project['markers'] = is_array($project['markers'] ?? null) ? array_slice($project['markers'], 0, 50) : [];
        foreach ($project['markers'] as &$m) {
            $m['time'] = max(0, min(86400, (float) ($m['time'] ?? 0)));
            $m['label'] = substr(trim((string) ($m['label'] ?? '')), 0, 100);
        }
        unset($m);
        $project['blur_regions'] = is_array($project['blur_regions'] ?? null) ? array_slice($project['blur_regions'], 0, 20) : [];
        foreach ($project['blur_regions'] as &$br) {
            $br['id'] = (int) ($br['id'] ?? 0);
            $br['x'] = max(0, min(1, (float) ($br['x'] ?? 0)));
            $br['y'] = max(0, min(1, (float) ($br['y'] ?? 0)));
            $br['w'] = max(0.01, min(1, (float) ($br['w'] ?? 0.1)));
            $br['h'] = max(0.01, min(1, (float) ($br['h'] ?? 0.1)));
            $br['strength'] = max(2, min(30, (int) ($br['strength'] ?? 10)));
            $br['start'] = max(0, min(86400, (float) ($br['start'] ?? 0)));
            $br['end'] = max($br['start'], min(86400, (float) ($br['end'] ?? 5)));
            $points = is_array($br['points'] ?? null) ? array_slice($br['points'], 0, 200) : null;
            if (is_array($points) && count($points) >= 2) {
                $clean = [];
                foreach ($points as $pt) {
                    if (!is_array($pt)) continue;
                    $clean[] = [max(0, min(1, (float) ($pt[0] ?? 0))), max(0, min(1, (float) ($pt[1] ?? 0)))];
                }
                $br['points'] = $clean;
                $br['r'] = max(0.005, min(0.5, (float) ($br['r'] ?? 0.05)));
            } else {
                unset($br['points'], $br['r']);
            }
        }
        unset($br);
        return $project;
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }
}
