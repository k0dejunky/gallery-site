<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Core\AccessLogParser;
use PHPUnit\Framework\TestCase;

final class AccessLogParserTest extends TestCase
{
    private function sampleLine(): string
    {
        return '52.10.20.30 - - [04/Oct/2026:10:00:00 +0000] "GET /galleries/42 HTTP/1.1" 200 15360 "-" "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"';
    }

    public function testParseLineExtractsRequestFields(): void
    {
        $request = AccessLogParser::parseLine($this->sampleLine());

        $this->assertIsArray($request);
        $this->assertSame('52.10.20.30', $request['ip'] ?? null);
        $this->assertSame('/galleries/42', $request['target'] ?? null);
        $this->assertSame('200', $request['status'] ?? null);
        $this->assertSame('GET', $request['method'] ?? null);
        $this->assertStringContainsString('Chrome/120', $request['agent'] ?? '');
    }

    public function testParseSeparatesHumansBotsAndExcludesPrivate(): void
    {
        $lines = [
            '127.0.0.1 - - [04/Oct/2026:10:00:00 +0000] "GET /cron/housekeeping HTTP/1.1" 200 10 "-" "Gallery-Cron/1.0"',
            '18.7.9.9 - - [04/Oct/2026:03:00:00 +0000] "GET /galleries/42 HTTP/1.1" 200 15360 "-" "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)"',
            $this->sampleLine(),
            '# comment lines are ignored',
            '',
        ];

        $result = AccessLogParser::parse($lines, ['site_host' => 'amethyst2213.com']);
        $day    = $result['days']['2026-10-04'] ?? [];

        $this->assertSame(0, $result['skipped']);
        $this->assertSame(2, $day['hits'] ?? null);        // the private cron line is excluded
        $this->assertSame(1, $day['human_hits'] ?? null);
        $this->assertSame(1, $day['bot_hits'] ?? null);
        $humanVisits = array_filter($result['visits'], fn (array $v): bool => empty($v['is_bot'] ?? false));
        $this->assertCount(1, $humanVisits);               // bots do not open human visits
    }

    public function testParseKeepsPrivateWhenAsked(): void
    {
        $lines = [
            '127.0.0.1 - - [04/Oct/2026:10:00:00 +0000] "GET /galleries/1 HTTP/1.1" 200 10 "-" "Mozilla/5.0 (X11; Linux x86_64)"',
        ];

        $hidden = AccessLogParser::parse($lines, ['site_host' => 'amethyst2213.com']);
        $this->assertSame(0, ($hidden['days']['2026-10-04']['hits'] ?? 0));

        $shown = AccessLogParser::parse($lines, ['site_host' => 'amethyst2213.com', 'include_private' => true]);
        $this->assertSame(1, ($shown['days']['2026-10-04']['hits'] ?? 0));
    }
}