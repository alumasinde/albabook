<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use App\Core\Http\RequestContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/** Adds request_id and host group to every log line. */
final class RequestContextProcessor implements ProcessorInterface
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;
        if ($this->context->requestId !== null) {
            $extra['request_id'] = $this->context->requestId;
        }
        if ($this->context->hostGroup !== null) {
            $extra['host'] = $this->context->hostGroup->value;
        }

        return $record->with(extra: $extra);
    }
}
