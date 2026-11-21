<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use App\Core\Config\Config;
use App\Core\Http\RequestContext;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

final class LoggerFactory
{
    public static function create(Config $config, RequestContext $context, string $basePath, string $channel = 'app'): LoggerInterface
    {
        $level = Level::fromName($config->string('logging.level', 'info'));

        $handler = $config->string('logging.channel', 'file') === 'stderr'
            ? new StreamHandler('php://stderr', $level)
            : new RotatingFileHandler(
                $basePath . '/' . $config->string('logging.path', 'storage/logs/albabook.log'),
                $config->int('logging.max_files', 14),
                $level,
                true,
                0664,
            );

        $handler->setFormatter(
            $config->string('logging.format', 'line') === 'json'
                ? new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true)
                : new LineFormatter("[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n", 'Y-m-d\TH:i:s.uP', true, true)
        );

        $logger = new Logger($channel, [$handler]);
        $logger->setTimezone(new \DateTimeZone('UTC'));
        // Processors run last-added first: context is added, then everything is masked.
        $logger->pushProcessor(new PiiMaskingProcessor());
        $logger->pushProcessor(new RequestContextProcessor($context));

        return $logger;
    }
}
