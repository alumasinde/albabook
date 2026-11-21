<?php

declare(strict_types=1);

namespace App\Shared\Mail;

/**
 * Minimal SMTP transport for production (submission port, STARTTLS or
 * implicit TLS, AUTH LOGIN). No external dependency — the protocol
 * exchange is a handful of commands over a socket.
 */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption, // 'tls' | 'ssl' | ''
        private readonly ?string $username,
        private readonly ?string $password,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly int $timeoutSeconds = 15,
    ) {}

    public function send(MailMessage $message): void
    {
        $remote = $this->encryption === 'ssl' ? "ssl://{$this->host}" : $this->host;
        $socket = @fsockopen($remote, $this->port, $errno, $errstr, $this->timeoutSeconds);
        if ($socket === false) {
            throw new \RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($socket, $this->timeoutSeconds);

        try {
            $this->expect($socket, [220]);

            $ehlo = $this->ehloName();
            $this->command($socket, "EHLO {$ehlo}", [250]);

            if ($this->encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('SMTP STARTTLS negotiation failed.');
                }
                $this->command($socket, "EHLO {$ehlo}", [250]);
            }

            if ($this->username !== null && $this->username !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($this->username), [334]);
                $this->command($socket, base64_encode((string) $this->password), [235]);
            }

            $this->command($socket, "MAIL FROM:<{$this->fromAddress}>", [250]);
            $this->command($socket, "RCPT TO:<{$message->to}>", [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $this->command($socket, $this->dataBlock($message) . "\r\n.", [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    private function ehloName(): string
    {
        $host = gethostname();

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }

    /** Dot-stuffed, CRLF-normalised message per RFC 5321 §4.5.2. */
    private function dataBlock(MailMessage $message): string
    {
        $from = sprintf('"%s" <%s>', addslashes($this->fromName), $this->fromAddress);
        $headers = [
            "From: {$from}",
            "To: <{$message->to}>",
            'Subject: ' . $this->encodeHeader($message->subject),
            'Date: ' . gmdate('D, d M Y H:i:s O'),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        if ($message->replyTo !== null) {
            $headers[] = "Reply-To: <{$message->replyTo}>";
        }

        $body = rtrim(chunk_split(base64_encode($message->textBody)), "\r\n");

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) === 1
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }

    /**
     * @param resource $socket
     * @param list<int> $expected
     */
    private function command($socket, string $line, array $expected): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $expected);
    }

    /**
     * @param resource $socket
     * @param list<int> $expected
     */
    private function expect($socket, array $expected): void
    {
        // Multi-line replies continue while the 4th char is '-'.
        $response = '';
        do {
            $line = fgets($socket, 512);
            if ($line === false) {
                throw new \RuntimeException('SMTP connection closed unexpectedly.');
            }
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new \RuntimeException('SMTP rejected command: ' . trim($response));
        }
    }
}
