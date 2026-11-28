<?php

declare(strict_types=1);

namespace App\Modules\Tax\Etims;

/** KRA rejected or failed a call; the message is safe to show staff. */
final class EtimsException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $resultCode = '')
    {
        parent::__construct($message);
    }
}
