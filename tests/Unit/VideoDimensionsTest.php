<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The video ladder's sizing rules.
 *
 * The bug these lock down: the encoder used to probe the CODED dimensions
 * (2400x1080 for a phone clip that is really 1080x2400) and scale a single
 * axis, which produced a 1280x2844 "720p" rendition - four times the
 * intended pixels, in the wrong shape - and put black bars around the
 * video in the player.
 */
final class VideoDimensionsTest extends TestCase
{
    public function testFitKeepsTheAspectInsideTheBox(): void
    {
        // 1920x1080 into the 1280x720 mid box.
        $this->assertSame([1280, 720], video_fit_dimensions(1920, 1080, 1280, 720));
        // 2400x1080 (20:9) into the same box: the width binds, so the height
        // comes out under the cap.
        $this->assertSame([1280, 576], video_fit_dimensions(2400, 1080, 1280, 720));
    }

    public function testFitNeverUpscales(): void
    {
        // A 480p source is left alone - re-encoding it "up" to 720p would
        // only make the file bigger and blurrier.
        $this->assertSame([854, 480], video_fit_dimensions(854, 480, 1280, 720));
        $this->assertSame([320, 240], video_fit_dimensions(320, 240, 1280, 720));
    }

    public function testFitAlwaysReturnsEvenDimensions(): void
    {
        // H.264 rejects odd width/height, so an odd source must still come
        // out encodable.
        [$w, $h] = video_fit_dimensions(641, 481, 1280, 720);
        $this->assertSame(0, $w % 2);
        $this->assertSame(0, $h % 2);
    }

    public function testFitHandlesUnknownSizes(): void
    {
        $this->assertSame([0, 0], video_fit_dimensions(0, 0, 1280, 720));
        $this->assertSame([0, 0], video_fit_dimensions(-10, 20, 1280, 720));
    }

    public function testAVerticalSourceGetsTheTransposedBox(): void
    {
        $rung = ['key' => 'mid', 'max_w' => 1280, 'max_h' => 720];

        // Landscape: the box is used as configured.
        $this->assertSame([1280, 720], video_ladder_box($rung, 1920, 1080));
        // Vertical: the box turns with the picture, so "720p" means 720 wide
        // by 1280 tall - the industry meaning of a vertical 720p clip.
        $this->assertSame([720, 1280], video_ladder_box($rung, 1080, 1920));

        [$w, $h] = video_fit_dimensions(1080, 2400, ...video_ladder_box($rung, 1080, 2400));
        $this->assertSame(576, $w);
        $this->assertSame(1280, $h);
    }

    public function testTheLadderPlanOnlyAsksForRungsThatAreNeeded(): void
    {
        $keys = static function (array $plan): array {
            return array_values(array_map(static fn ($r) => (string) $r['key'], $plan));
        };

        // A 480p source: only the always-on mid rung.
        $this->assertSame(['mid'], $keys(video_ladder_plan(854, 480)));
        // A 1080p source: the 480p one, but not a pointless 1080p re-encode.
        $this->assertSame(['lo', 'mid'], $keys(video_ladder_plan(1920, 1080)));
        // 4K vertical: everything.
        $this->assertSame(['lo', 'mid', 'hi'], $keys(video_ladder_plan(2160, 3840)));
        // Unknown dimensions: trust only the mid rung.
        $this->assertSame(['mid'], $keys(video_ladder_plan(0, 0)));
    }

    public function testAVerticalSourceGetsTheSameRungsAsALandscapeOne(): void
    {
        // A 1080x2400 phone clip exceeds every rung box, so it needs all of
        // them - the old code compared the CODED size and got this wrong for
        // rotated files.
        $keys = array_map(
            static fn ($r) => (string) $r['key'],
            video_ladder_plan(1080, 2400)
        );
        $this->assertSame(['lo', 'mid', 'hi'], $keys);
    }

    /**
     * The end-to-end proof: a phone clip (2400x1080 stored, rotated 90 in
     * the display matrix) must come out of the encoder UPRIGHT, at the right
     * size, with square pixels and no leftover rotation for the browser to
     * apply a second time.
     */
    public function testARotatedSourceEncodesToAnUprightRendition(): void
    {
        $ffmpeg = $this->ffmpegBinary();
        if ($ffmpeg === null) {
            $this->markTestSkipped('ffmpeg is not available');
        }

        $dir = sys_get_temp_dir() . '/videosuite-rot-' . getmypid();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $source = $dir . '/source.mp4';
        $plain  = $dir . '/plain.mp4';
        $dest   = $dir . '/rendition.mp4';

        try {
            // 2400x1080 with a 90 degree display matrix: exactly what a
            // phone records. How the matrix is written differs between
            // ffmpeg versions (the "rotate" tag was dropped in 5.x, and
            // -display_rotation arrived in 6.0), so try the modern way and
            // fall back to the legacy tag.
            $cmd = escapeshellarg($ffmpeg) . ' -nostdin -y -f lavfi -i '
                . escapeshellarg('testsrc=size=2400x1080:rate=10:duration=1')
                . ' -c:v libx264 -preset ultrafast -pix_fmt yuv420p '
                . escapeshellarg($plain) . ' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, 'the fixture could not be built');

            exec(escapeshellarg($ffmpeg) . ' -nostdin -y -display_rotation 90 -i ' . escapeshellarg($plain)
                . ' -c copy ' . escapeshellarg($source) . ' 2>&1', $outA, $rcA);
            if ((int) $rcA !== 0 || (video_display_dimensions($source)['rotation'] ?? 0) !== 90) {
                exec(escapeshellarg($ffmpeg) . ' -nostdin -y -i ' . escapeshellarg($plain)
                    . ' -c copy -metadata:s:v:0 rotate=90 ' . escapeshellarg($source) . ' 2>&1', $out2, $rc2);
            }
            $this->assertSame(90, video_display_dimensions($source)['rotation'] ?? 0, 'the rotated fixture could not be built');

            $dim = video_display_dimensions($source);
            $this->assertIsArray($dim, 'the fixture could not be probed');
            $this->assertSame(2400, $dim['coded_w']);
            $this->assertSame(1080, $dim['coded_h']);
            // The DISPLAY size is what the viewer sees: 1080x2400.
            $this->assertSame(1080, $dim['w']);
            $this->assertSame(2400, $dim['h']);
            $this->assertSame(90, $dim['rotation']);

            $rung = ['key' => 'mid', 'max_w' => 1280, 'max_h' => 720, 'crf' => 30];
            $this->assertTrue(create_video_rendition($source, $dest, $rung, 120));

            $made = video_display_dimensions($dest);
            $this->assertIsArray($made);
            // Upright, inside the 720x1280 box, and NOT the 1280x2844 the
            // old single-axis scaler produced.
            $this->assertSame(576, $made['w']);
            $this->assertSame(1280, $made['h']);
            $this->assertSame(0, $made['rotation'], 'the rotation must be baked into the pixels, not left for the browser');
            $this->assertEqualsWithDelta(1.0, $made['sar'], 0.001, 'the rendition must be square-pixel');
        } finally {
            foreach ([$source, $plain, $dest] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    private function ffmpegBinary(): ?string
    {
        foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        $which = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null'));

        return $which !== '' ? $which : null;
    }
}
