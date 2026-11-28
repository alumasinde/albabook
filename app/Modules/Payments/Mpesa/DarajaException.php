<?php

declare(strict_types=1);

namespace App\Modules\Payments\Mpesa;

/** Daraja refused or failed a call; the message is safe to show staff. */
final class DarajaException extends \RuntimeException
{
}
