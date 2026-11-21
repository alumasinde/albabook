<?php

declare(strict_types=1);

namespace App\Shared\Mail;

final readonly class MailMessage
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $textBody,
        public ?string $replyTo = null,
    ) {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Mail recipient is not a valid address.');
        }
        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Mail reply-to is not a valid address.');
        }
        if (trim($subject) === '') {
            throw new \InvalidArgumentException('Mail subject must not be empty.');
        }
    }
}
