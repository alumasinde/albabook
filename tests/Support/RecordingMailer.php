<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Mail\Mailer;
use App\Shared\Mail\MailMessage;

/** Captures outgoing mail so tests can read tokens out of the link. */
final class RecordingMailer implements Mailer
{
    /** @var list<MailMessage> */
    public array $messages = [];

    public function send(MailMessage $message): void
    {
        $this->messages[] = $message;
    }

    public function last(): ?MailMessage
    {
        return $this->messages === [] ? null : $this->messages[array_key_last($this->messages)];
    }

    public function clear(): void
    {
        $this->messages = [];
    }
}
