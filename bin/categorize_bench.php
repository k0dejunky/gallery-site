<?php

declare(strict_types=1);

/**
 * Categorizer sampling benchmark.
 *
 * Runs the SAME analysis (the same model, the same prompt, the same five
 * category batches) over a set of galleries with different THUMBNAIL
 * samples, and scores every run against the editor's own categories - the
 * ground truth of what the site should be tagged with.
 *
 * It never writes to the database: CategoryAdvisor::suggest() only reads,
 * so the benchmark cannot create proposals, change a gallery or touch the
 * production queue. The rows land in storage/logs/categorizer-bench.csv
 * (and a .jsonl with the full proposal lists).
 *
 * Variants:
 *   head3    - the first three photos: what the categorizer has always done
 *   spread3  - three photos spread over the gallery (first, middle, last)
 *   spread6  - six photos spread over the gallery
 *   describe - one vision call over 12 spread images writes a short
 *              description; the five category batches are judged from that
 *              text (the images are encoded once instead of once a batch)
 *
 * Usage:
 *   php bin/categorize_bench.php [--variants=head3,spread3] [--galleries=1,2]
 *                                [--control=3] [--control-rounds=2]
 *                                [--describe-images=12] [--dry-run]
 *
 * The lock is the categorizer's own lock, so the benchmark and the worker
 * never run at the same time.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\CategoryAdvisor;
use App\Models\Gallery;

$options = [
    'variants'        => 'head3,spread3,spread6,describe',
    'galleries'       => '',
    'control'         => '',
    'control_rounds'  => 2,
    'describe_images' => 12,
    'dry_run'         => false,
    'list'            => false,
    // 'learn' = 0 measures the sampling on its own (the editor's corrections
    // switched off), 1 measures the shipping configuration.
    'learn'           => '1',
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $options['dry_run'] = true;
        continue;
    }
    if ($arg === '--list') {
        $options['list'] = true;
        continue;
    }
    if (preg_match('/^--([a-z_]+)=(.*)$/', $arg, $m)) {
        $key = str_replace('-', '_', $m[1]);
        if (array_key_exists($key, $options)) {
            $options[$key] = $m[2];
        }
    }
}

// The editor's corrections are switched off for a pure sampling measurement.
putenv('CATEGORIZER_LEARN=' . ((string) $options['learn'] === '0' ? '0' : '1'));

$variantMap = [
    'head3'    => ['sample_mode' => 'head',   'sample_max' => 3,  'describe' => false],
    'spread3'  => ['sample_mode' => 'spread', 'sample_max' => 3,  'describe' => false],
    'spread6'  => ['sample_mode' => 'spread', 'sample_max' => 6,  'describe' => false],
    'describe' => ['sample_mode' => 'spread', 'sample_max' => 3,  'describe' => true],
];

$variants = array_values(array_filter(array_map('trim', explode(',', (string) $options['variants']))));
foreach ($variants as $v) {
    if (!isset($variantMap[$v])) {
        fwrite(STDERR, "unknown variant: {$v}\n");
        exit(2);
    }
}

$galleryIds = array_values(array_filter(array_map('intval', explode(',', (string) $options['galleries']))));
$controlIds = array_values(array_filter(array_map('intval', explode(',', (string) $options['control']))));

$logDir  = dirname(__DIR__) . '/storage/logs';
$logFile = $logDir . '/categorize-bench.log';
$csvFile = $logDir . '/categorizer-bench.csv';
$jsonFile = $logDir . '/categorizer-bench.jsonl';

if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

// --list prints the plan (and the ground truth) without calling the model.
if ($options['list']) {
    echo "the variants: " . implode(', ', $variants) . "\n";
    $byId = [];
    foreach (\App\Models\Category::all() as $c) {
        $byId[(int) $c['id']] = (string) $c['name'];
    }
    $plan = static function (int $galleryId, string $variant) use ($byId, $options): void {
        $gallery = Gallery::find($galleryId);
        if ($gallery === null) {
            echo "  gallery {$galleryId}: NOT FOUND\n";
            return;
        }
        $photos = Gallery::photos($galleryId);
        $tags   = array_map(static fn (array $c): int => (int) $c['id'], Gallery::categories($galleryId));
        $names  = [];
        foreach ($tags as $t) {
            $names[] = $byId[$t] ?? ('#' . $t);
        }
        $max = $variant === 'describe' ? (int) $options['describe_images'] : ($variant === 'spread6' ? 6 : 3);
        $mode = $variant === 'head3' ? 'head' : 'spread';
        $pos  = CategoryAdvisor::samplePositions(count($photos), $max, $mode);
        echo sprintf(
            "  gallery %d (%s) variant %s: %d photos, the sample at %s, %d editor tags [%s]\n",
            $galleryId,
            mb_substr((string) $gallery['title'], 0, 30),
            $variant,
            count($photos),
            implode(',', $pos),
            count($tags),
            implode(', ', array_slice($names, 0, 6))
        );
    };
    foreach ($galleryIds as $galleryId) {
        foreach ($variants as $variant) {
            $plan($galleryId, $variant);
        }
    }
    foreach ($controlIds as $galleryId) {
        for ($i = 0; $i < max(1, (int) $options['control_rounds']); $i++) {
            $plan($galleryId, 'head3 (control run ' . ($i + 1) . ')');
        }
    }
    exit(0);
}

// The categorizer's own lock: the benchmark and the worker never overlap.
$lockFile = $logDir . '/categorizer.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false) {
    fwrite(STDERR, "unable to open the lock file {$lockFile}\n");
    exit(1);
}
// Wait for the lock instead of giving up: the worker takes it every minute
// for a short tick, and the benchmark is a long detached run.
$waited = 0;
while (!flock($lock, LOCK_EX | LOCK_NB)) {
    if ($waited >= 900) {
        fwrite(STDERR, "the categorizer lock is still held after 15 minutes - try again later\n");
        exit(1);
    }
    sleep(10);
    $waited += 10;
}

$log = static function (string $line) use ($logFile): void {
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
};

$header = 'at,gallery,photos,variant,ok,proposals,hits,editor_tags,recall,precision,seconds,describe_seconds,error';
if (!is_file($csvFile)) {
    @file_put_contents($csvFile, $header . "\n", FILE_APPEND | LOCK_EX);
}

$log('start: variants=' . implode(',', $variants) . ' galleries=' . implode(',', $galleryIds)
    . ' control=' . implode(',', $controlIds) . ' learn=' . (string) $options['learn']);


$run = static function (int $galleryId, string $variant, array $variantMap, int $describeImages, bool $dryRun) use ($log, $csvFile, $jsonFile, $options): void {
    $gallery = Gallery::find($galleryId);
    if ($gallery === null) {
        $log("gallery {$galleryId}: not found");
        return;
    }

    $photos   = count(Gallery::photos($galleryId));
    $tags     = array_map(static fn (array $c): int => (int) $c['id'], Gallery::categories($galleryId));
    $config   = $variantMap[$variant];
    $suggest  = $config;
    $suggest['describe_images'] = $describeImages;

    $log("gallery {$galleryId} variant {$variant}: {$photos} photos, " . count($tags) . ' editor tags');

    $result = CategoryAdvisor::suggest($galleryId, $suggest);

    $proposals = array_map(static fn (array $s): int => (int) $s['category_id'], $result['suggestions'] ?? []);
    $hits      = count(array_intersect($proposals, $tags));
    $recall    = $tags === [] ? null : round($hits / count($tags), 4);
    $precision = $proposals === [] ? null : round($hits / count($proposals), 4);
    $timings   = $result['timings'] ?? ['total' => 0, 'describe' => null, 'batches' => []];

    $row = [
        date('Y-m-d H:i:s'),
        $galleryId,
        $photos,
        $variant,
        !empty($result['ok']) ? '1' : '0',
        count($proposals),
        $hits,
        count($tags),
        $recall === null ? '' : $recall,
        $precision === null ? '' : $precision,
        $timings['total'] ?? '',
        $timings['describe'] === null ? '' : $timings['describe'],
        mb_substr((string) ($result['error'] ?? ''), 0, 200),
    ];

    if (!$dryRun) {
        @file_put_contents($csvFile, implode(',', array_map(static fn ($v) => is_string($v) ? str_replace([',', '"'], [';', ''], $v) : $v, $row)) . "\n", FILE_APPEND | LOCK_EX);

        $byId = [];
        foreach (\App\Models\Category::all() as $c) {
            $byId[(int) $c['id']] = (string) $c['name'];
        }
        $proposalNames = [];
        foreach ($result['suggestions'] ?? [] as $s) {
            $proposalNames[] = ['name' => $byId[(int) $s['category_id']] ?? ('#' . (int) $s['category_id']),
                                'confidence' => $s['confidence']];
        }
        $tagNames = [];
        foreach ($tags as $t) {
            $tagNames[] = $byId[$t] ?? ('#' . $t);
        }

        @file_put_contents($jsonFile, json_encode([
            'at'            => date('Y-m-d H:i:s'),
            'gallery'       => $galleryId,
            'photos'        => $photos,
            'variant'       => $variant,
            'ok'            => !empty($result['ok']),
            'recall'        => $recall,
            'precision'     => $precision,
            'proposals'     => $proposalNames,
            'editor_tags'   => $tagNames,
            'timings'       => $timings,
            'error'         => $result['error'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    $log(sprintf(
        'gallery %d variant %s: %d proposals, %d hits of %d editor tags (recall %s), %ss',
        $galleryId,
        $variant,
        count($proposals),
        $hits,
        count($tags),
        $recall === null ? 'n/a' : (string) $recall,
        (string) ($timings['total'] ?? '?')
    ));
};

foreach ($galleryIds as $galleryId) {
    foreach ($variants as $variant) {
        $run($galleryId, $variant, $variantMap, (int) $options['describe_images'], (bool) $options['dry_run']);
    }
}

// The control galleries: the same variant twice, so the model's own
// run-to-run variance is known before any difference is called a win.
$rounds = max(1, (int) $options['control_rounds']);
foreach ($controlIds as $galleryId) {
    for ($i = 0; $i < $rounds; $i++) {
        $run($galleryId, 'head3', $variantMap, (int) $options['describe_images'], (bool) $options['dry_run']);
    }
}

$log('done');
exit(0);
