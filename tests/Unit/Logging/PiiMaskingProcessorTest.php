<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Core\Config\Config;
use App\Core\Http\RequestContext;
use App\Shared\Logging\LoggerFactory;
use App\Shared\Logging\PiiMaskingProcessor;
use PHPUnit\Framework\TestCase;

final class PiiMaskingProcessorTest extends TestCase
{
    public function testMasksEmailsPhonesAndTokens(): void
    {
        $masked = PiiMaskingProcessor::maskString(
            'Booking for jane.wanjiru@example.co.ke, phone +254712345678 / 0712 345 678, token '
            . str_repeat('aB3_', 10)
        );

        self::assertStringNotContainsString('jane.wanjiru@example.co.ke', $masked);
        self::assertStringNotContainsString('712345678', $masked);
        self::assertStringNotContainsString('0712 345 678', $masked);
        self::assertStringNotContainsString(str_repeat('aB3_', 10), $masked);
        self::assertStringContainsString('j***@***', $masked);
        self::assertStringContainsString('***678', $masked);
        self::assertStringContainsString('[redacted]', $masked);
    }

    public function testLeavesDatesTimesAmountsAndUuidsAlone(): void
    {
        $text = 'At 2026-11-21 10:00:00 booked KSh 38,500 ref 0193a1b2-7c3d-7e4f-8a9b-0c1d2e3f4a5b status 200';

        self::assertSame($text, PiiMaskingProcessor::maskString($text));
    }

    public function testRedactsSensitiveContextKeysRecursively(): void
    {
        $masked = PiiMaskingProcessor::maskArray([
            'password' => 'hunter2hunter2',
            'nested' => ['Authorization' => 'Bearer abc', 'email' => 'a@b.co'],
            'count' => 3,
        ]);

        self::assertSame('[redacted]', $masked['password']);
        self::assertSame('[redacted]', $masked['nested']['Authorization']);
        self::assertSame('a***@***', $masked['nested']['email']);
        self::assertSame(3, $masked['count']);
    }

    public function testExceptionMessagesAreMasked(): void
    {
        $masked = PiiMaskingProcessor::maskArray([
            'exception' => new \RuntimeException("Duplicate entry 'mary@example.com' for key 'uq_users_email'"),
        ]);

        self::assertIsArray($masked['exception']);
        self::assertStringNotContainsString('mary@example.com', $masked['exception']['message']);
    }

    public function testLoggerWritesMaskedLinesWithRequestId(): void
    {
        $dir = sys_get_temp_dir() . '/albabook-log-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $context = new RequestContext();
        $context->requestId = '0193a1b2-7c3d-7e4f-8a9b-0c1d2e3f4a5b';

        $logger = LoggerFactory::create(new Config(['logging' => [
            'channel' => 'file', 'format' => 'json', 'level' => 'debug', 'path' => 'app.log', 'max_files' => 1,
        ]]), $context, $dir);
        $logger->warning('Reminder failed for 0722 000 111', ['email' => 'wanjiru@example.com', 'password' => 'x']);

        $files = glob($dir . '/app*.log') ?: [];
        self::assertCount(1, $files);
        $line = (string) file_get_contents($files[0]);

        self::assertStringContainsString('0193a1b2-7c3d-7e4f-8a9b-0c1d2e3f4a5b', $line);
        self::assertStringNotContainsString('0722 000 111', $line);
        self::assertStringNotContainsString('wanjiru@example.com', $line);
        self::assertStringNotContainsString('"x"', $line);

        array_map('unlink', $files);
        rmdir($dir);
    }
}
