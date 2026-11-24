<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

/**
 * Permanent refusal: the recipient may never be messaged on this route (e.g.
 * outside the platform account's allowed destinations). The dispatcher
 * marks the row skipped instead of retrying it.
 */
final class RecipientNotAllowedException extends \RuntimeException
{
}
