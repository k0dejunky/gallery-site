<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Uploads hold originals and generated derivatives side by side, and the
 * prefix is the only thing that says which is which.
 *
 * Two places kept their own list - the System page's orphan report and
 * bin/cleanup_orphan_uploads.php - and both predated the video ladder. The
 * report therefore called every rendition of a live video an orphan (655 of
 * them on 2026-11-12) while the cleanup script would have deleted every
 * rendition AND every blurred teaser. These cases pin the shared resolver.
 */
final class MediaVariantPrefixTest extends TestCase
{
    public function testTheLongestPrefixWins(): void
    {
        $prefixes = media_variant_prefixes();

        $this->assertContains('blur_thumb_', $prefixes);
        $this->assertContains('thumb_', $prefixes);
        $this->assertContains('web_', $prefixes);
        $this->assertContains('poster_', $prefixes);
        $this->assertContains('weblo_', $prefixes);
        $this->assertContains('webhi_', $prefixes);

        $pos = array_flip($prefixes);
        $this->assertLessThan($pos['thumb_'], $pos['blur_thumb_'], 'blur_thumb_ must be tested before thumb_');
        $this->assertLessThan($pos['web_'], $pos['weblo_'], 'weblo_ must be tested before web_');
        $this->assertLessThan($pos['web_'], $pos['webhi_'], 'webhi_ must be tested before web_');
    }

    public function testTheBaseOfEveryDerivativeKind(): void
    {
        $this->assertSame('abc.mp4', media_derivative_base('weblo_abc.mp4'));
        $this->assertSame('abc.mp4', media_derivative_base('webhi_abc.mp4'));
        $this->assertSame('abc.mp4', media_derivative_base('web_abc.mp4'));
        $this->assertSame('abc.mp4', media_derivative_base('poster_abc.mp4'));
        $this->assertSame('abc.jpg', media_derivative_base('thumb_abc.jpg'));
        $this->assertSame('abc.webp', media_derivative_base('blur_thumb_abc.webp'));
    }

    public function testAnOriginalIsReturnedUnchanged(): void
    {
        $this->assertSame('abc.jpg', media_derivative_base('abc.jpg'));
        $this->assertSame('g6abc4dfdd4c276.24547410.mp4', media_derivative_base('g6abc4dfdd4c276.24547410.mp4'));
    }

    public function testAPrefixIsOnlyStrippedOnce(): void
    {
        // web_web_x is a derivative of a file that happens to start with web_.
        $this->assertSame('web_x.mp4', media_derivative_base('web_web_x.mp4'));
    }
}
