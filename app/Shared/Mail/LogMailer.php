<?php

declare(strict_types=1);

namespace App\Shared\Mail;

/**
 * Development transport: appends the message to a private log file. Outside
 * local/testing ConfigValidator refuses to boot with this transport, so a
 * production deployment cannot silently discard verification links.
 */
final class LogMailer implements Mailer
{
    public function __construct(
        private readonly string $logPath,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly bool $production,
    ) {}

    public function send(MailMessage $message): void
    {
        if ($this->production) {
            throw new \LogicException('LogMailer must not be used in production.');
        }

        $directory = dirname($this->logPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Mail log directory could not be created.');
        }

        $record = [
            'created_at' => gmdate('c'),
            'from' => "{$this->fromName} <{$this->fromAddress}>",
            'to' => $message->to,
            'reply_to' => $message->replyTo,
            'subject' => $message->subject,
            'body' => $message->textBody,
        ];
        $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        if (file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Mail could not be written.');
        }
        @chmod($this->logPath, 0600);
    }
}
