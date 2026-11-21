<?php

declare(strict_types=1);

namespace App\Shared\Mail;

interface Mailer
{
    public function send(MailMessage $message): void;
}
