<?php

declare(strict_types=1);

namespace App\Shared\Mail;

/** In-memory transport for tests. */
final class ArrayMailer implements Mailer
{
    /** @var list<MailMessage> */
    private array $messages = [];

    public function send(MailMessage $message): void
    {
        $this->messages[] = $message;
    }

    /** @return list<MailMessage> */
    public function messages(): array
    {
        return $this->messages;
    }

    public function last(): ?MailMessage
    {
        return $this->messages[count($this->messages) - 1] ?? null;
    }
}
