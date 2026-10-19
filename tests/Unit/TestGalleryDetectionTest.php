<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Which galleries must never be announced to members.
 *
 * Working on the site means creating throwaway galleries, and every one of
 * those that went live mailed all the opted-in members the title of a set
 * that was never meant to be seen.
 */
final class TestGalleryDetectionTest extends TestCase
{
    public function testTheExplicitFlagAlwaysWins(): void
    {
        $this->assertTrue(is_test_gallery(['title' => 'Beach day', 'is_test' => 1]));
        $this->assertTrue(is_test_gallery(['title' => 'Beach day', 'is_test' => '1']));
        $this->assertFalse(is_test_gallery(['title' => 'Beach day', 'is_test' => 0]));
    }

    public function testATestLookingTitleIsRecognised(): void
    {
        $this->assertTrue(is_test_gallery(['title' => 'PROBE Gallery']));
        $this->assertTrue(is_test_gallery(['title' => 'probe-rotated-fixture']));
        $this->assertTrue(is_test_gallery(['title' => 'Test Gallery']));
        $this->assertTrue(is_test_gallery(['title' => 'smoketest set']));
        $this->assertTrue(is_test_gallery(['title' => 'tmp upload']));
        $this->assertTrue(is_test_gallery(['title' => 'scratch gallery']));
    }

    public function testTheMatchIsCaseInsensitive(): void
    {
        $this->assertTrue(is_test_gallery(['title' => 'probe gallery']));
        $this->assertTrue(is_test_gallery(['title' => 'TEST GALLERY']));
    }

    public function testARegularTitleIsNotMistakenForATestOne(): void
    {
        $this->assertFalse(is_test_gallery(['title' => 'Beach day']));
        $this->assertFalse(is_test_gallery(['title' => 'Getting fucked at the lake']));
        // The pattern must not swallow a word that merely starts with the same
        // letters.
        $this->assertFalse(is_test_gallery(['title' => 'Testament']));
        $this->assertFalse(is_test_gallery(['title' => 'Probes']));
        $this->assertFalse(is_test_gallery(['title' => '']));
    }

    public function testAMissingTitleIsNotATestGallery(): void
    {
        $this->assertFalse(is_test_gallery([]));
        $this->assertFalse(is_test_gallery(['is_test' => 0]));
    }
}
