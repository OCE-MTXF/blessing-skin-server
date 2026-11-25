<?php

namespace Tests;

use App\Services\SafeMarkdown;
use PHPUnit\Framework\TestCase as UnitTestCase;

final class SafeMarkdownTest extends UnitTestCase
{
    public function testRendersMarkdownWithoutPassingThroughInputHtml(): void
    {
        $html = (new SafeMarkdown)->render('**Update** · [Read more](https://example.test/help) <mark>Important</mark>');

        self::assertStringContainsString('<strong>Update</strong>', $html);
        self::assertStringContainsString('href="https://example.test/help"', $html);
        self::assertStringContainsString('Important', $html);
        self::assertStringNotContainsString('<mark>', $html);
    }

    public function testBoundsLargeDocumentOnAValidUtf8Boundary(): void
    {
        $html = (new SafeMarkdown)->render(str_repeat('皮', 25000).'not in limit');

        self::assertTrue(mb_check_encoding($html, 'UTF-8'));
        self::assertLessThan(65550, strlen($html));
        self::assertStringNotContainsString('not in limit', $html);
    }
}
